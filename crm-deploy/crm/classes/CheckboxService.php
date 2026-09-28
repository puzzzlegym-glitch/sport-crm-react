<?php
/**
 * CheckboxService.php — інтеграція з фіскальним сервісом Checkbox (ПРРО)
 *
 * Документація: специфікація Checkbox RESTful API (офіційний PDF, звірено
 * 2026-08-31) — https://wiki.checkbox.ua/uk/api. Реальні ендпоінти:
 *   POST /cashier/signin, POST /shifts, POST /shifts/close,
 *   POST /receipts/sell, GET /receipts/{id}, GET /receipts/{id}/png.
 * "Синхронізація касира з ДПС" з ТЗ — це РУЧНА дія в кабінеті my.checkbox.ua
 * (розділ «Касири»), а НЕ виклик API — такого ендпоінта в специфікації немає.
 * Так само немає GET-ендпоінта для перевірки статусу поточної зміни.
 *
 * За аналогією з Billing:: — статичний клас-сервіс, без DI-контейнера,
 * підключається окремим require_once (бо app/bootstrap.php підключає лише
 * Auth/Database/Response/Telegram/Billing, а не цей новий клас).
 *
 * Методи:
 *   authenticate($clubId)                 — авторизація касира, кеш токена в БД
 *   openShiftIfNeeded($clubId)             — відкрити зміну (ідемпотентно)
 *   createReceipt($clubId, $paymentData)   — фіскальний чек продажу
 *   testConnection($clubId)                — перевірка логіна/пароля/ключа
 *                                             (реальний виклик signin, без побічних дій)
 *   getReceiptStatus($clubId, $receiptId)  — статус чека + посилання
 *   maybeFiscalize(...)                    — оркестратор: рішення + виклик +
 *                                             запис статусу в club_payments
 *
 * Оплата в CRM ЗАВЖДИ проходить незалежно від Checkbox (ТЗ 4.3) — жоден
 * метод тут не кидає виняток назовні з maybeFiscalize(), лише пише
 * fiscal_status='failed' і чекає на cron/retry_fiscal_receipts.php.
 */

class CheckboxService
{
    private const API_BASE = 'https://api.checkbox.ua/api/v1';

    /** Способи оплати CRM → тип оплати в чеку Checkbox (CASH | CASHLESS). */
    private const PAYMENT_TYPE_MAP = [
        'cash'     => 'CASH',
        'card'     => 'CASHLESS',
        'terminal' => 'CASHLESS',
        'deposit'  => 'CASHLESS',
        'transfer' => 'CASHLESS',
        'other'    => 'CASHLESS',
    ];

    // ── Шифрування чутливих полів (license_key, cashier_password, токен) ──

    private static function requireEncryptionKey(): string
    {
        if (!defined('PRRO_ENCRYPTION_KEY') || strlen(PRRO_ENCRYPTION_KEY) < 16) {
            throw new RuntimeException(
                'PRRO_ENCRYPTION_KEY не визначено в app/config.php — див. коментар ' .
                'у sql/2026-08-30_club_prro_settings.sql'
            );
        }
        return PRRO_ENCRYPTION_KEY;
    }

    public static function encrypt(string $plain): string
    {
        $key = self::requireEncryptionKey();
        $iv  = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) throw new RuntimeException('Помилка шифрування');
        return base64_encode($iv . $cipher);
    }

    public static function decrypt(?string $encoded): ?string
    {
        if ($encoded === null || $encoded === '') return null;
        $key  = self::requireEncryptionKey();
        $raw  = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 17) return null;
        $iv     = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain  = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $plain === false ? null : $plain;
    }

    // ── Налаштування клубу ──────────────────────────────────────────────

    private static function getSettings(PDO $pdo, int $clubId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM club_prro_settings WHERE club_id = ? LIMIT 1');
        $stmt->execute([$clubId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ── HTTP ──────────────────────────────────────────────────────────

    /**
     * @throws RuntimeException при мережевій помилці або не-2xx відповіді.
     */
    private static function request(string $method, string $path, array $headers = [], ?array $body = null): array
    {
        $ch = curl_init(self::API_BASE . $path);
        $defaultHeaders = ['Content-Type: application/json', 'Accept: application/json'];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => array_merge($defaultHeaders, $headers),
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $raw     = curl_exec($ch);
        $errno   = curl_errno($ch);
        $errMsg  = curl_error($ch);
        $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException("Checkbox: мережева помилка — {$errMsg}");
        }
        $data = json_decode((string)$raw, true);
        if ($status < 200 || $status >= 300) {
            $msg = is_array($data) ? ($data['message'] ?? $raw) : $raw;
            throw new RuntimeException("Checkbox: HTTP {$status} — {$msg}");
        }
        return is_array($data) ? $data : [];
    }

    // ── 1. Авторизація касира (з кешем токена) ──────────────────────────

    /** @throws RuntimeException якщо ПРРО не підключено або дані невірні. */
    public static function authenticate(PDO $pdo, int $clubId): array
    {
        $settings = self::getSettings($pdo, $clubId);
        if (!$settings) {
            throw new RuntimeException('ПРРО не підключено для цього клубу');
        }

        $licenseKey = self::decrypt($settings['license_key_enc']);
        if (!$licenseKey) {
            throw new RuntimeException('Не вказано ліцензійний ключ ПРРО');
        }

        // Кешований токен ще дійсний — використовуємо його.
        if (
            !empty($settings['access_token_enc'])
            && !empty($settings['access_token_expires_at'])
            && strtotime($settings['access_token_expires_at']) > time() + 30
        ) {
            return [
                'access_token' => self::decrypt($settings['access_token_enc']),
                'license_key'  => $licenseKey,
            ];
        }

        $login    = $settings['cashier_login'] ?? '';
        $password = self::decrypt($settings['cashier_password_enc']);
        if (!$login || !$password) {
            throw new RuntimeException('Не вказано логін/пароль касира');
        }

        // /cashier/signin авторизує логіном/паролем — X-License-Key тут не потрібен
        // (він ідентифікує касу лише для наступних запитів — зміни/чеки).
        $resp = self::request('POST', '/cashier/signin', [
            'X-Client-Name: Sport CRM',
        ], ['login' => $login, 'password' => $password]);

        $token = $resp['access_token'] ?? null;
        if (!$token) {
            throw new RuntimeException('Checkbox не повернув токен авторизації');
        }

        // Токен Checkbox живе довго, але про всяк випадок кешуємо на 12 год.
        $expiresAt = date('Y-m-d H:i:s', time() + 12 * 3600);
        $pdo->prepare('UPDATE club_prro_settings SET access_token_enc = ?, access_token_expires_at = ? WHERE club_id = ?')
            ->execute([self::encrypt($token), $expiresAt, $clubId]);

        return ['access_token' => $token, 'license_key' => $licenseKey];
    }

    // ── 2. Відкриття зміни (онлайн-режим — без фіскального коду) ───────

    /**
     * У специфікації Checkbox немає GET-ендпоінта для перевірки статусу поточної
     * зміни — тому просто відкриваємо зміну щоразу. Якщо вона вже відкрита (тим
     * самим чи іншим касиром на тій же касі), Checkbox повертає HTTP 400 з
     * повідомленням "Каса зайнята..." — це очікувано, ковтаємо як "вже готово".
     * Будь-яку іншу помилку (401/403/мережа) прокидаємо далі — це реальна проблема.
     */
    public static function openShiftIfNeeded(PDO $pdo, int $clubId): void
    {
        $auth    = self::authenticate($pdo, $clubId);
        $headers = self::authHeaders($auth);

        try {
            self::request('POST', '/shifts', $headers, []);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 400')) {
                return; // зміна вже відкрита — не помилка
            }
            throw $e;
        }
    }

    private static function authHeaders(array $auth): array
    {
        return [
            'Authorization: Bearer ' . $auth['access_token'],
            'X-License-Key: ' . $auth['license_key'],
            'X-Client-Name: Sport CRM',
        ];
    }

    // ── 3. Створення фіскального чека продажу ───────────────────────────

    /**
     * @param array $paymentData ['amount' => float (грн), 'payment_method' => string,
     *                            'description' => string]
     * @return array ['receipt_id' => string, 'status' => string]
     */
    public static function createReceipt(PDO $pdo, int $clubId, array $paymentData): array
    {
        self::openShiftIfNeeded($pdo, $clubId);
        $auth    = self::authenticate($pdo, $clubId);
        $headers = self::authHeaders($auth);

        $amount     = (float)$paymentData['amount'];
        $amountCoin = (int)round($amount * 100);
        $method     = $paymentData['payment_method'] ?? 'cash';
        $payType    = self::PAYMENT_TYPE_MAP[$method] ?? 'CASHLESS';
        $description = $paymentData['description'] ?? 'Послуги спортивного клубу';

        $body = [
            'goods' => [[
                'good' => [
                    'name'  => mb_substr($description, 0, 200),
                    'price' => $amountCoin,
                ],
                'quantity' => 1000, // 1.000 од. (Checkbox рахує кількість у тисячних)
            ]],
            'payments' => [[
                'type'  => $payType,
                'value' => $amountCoin,
                'label' => $method === 'cash' ? 'Готівка' : 'Безготівкова оплата',
            ]],
        ];

        $resp = self::request('POST', '/receipts/sell', $headers, $body);
        return [
            'receipt_id' => $resp['id'] ?? '',
            'status'     => $resp['status'] ?? 'pending',
        ];
    }

    // ── 4. Перевірка підключення ─────────────────────────────────────────

    /**
     * "Синхронізація касира з ДПС" з ТЗ — насправді ручна дія в кабінеті
     * my.checkbox.ua (розділ «Касири»), а не виклик API — там такого
     * ендпоінта немає. Тут натомість реальна перевірка: чи справді
     * логін/пароль/ключ ліцензії коректні (виклик /cashier/signin).
     */
    public static function testConnection(PDO $pdo, int $clubId): void
    {
        self::authenticate($pdo, $clubId);

        $pdo->prepare('
            UPDATE club_prro_settings
            SET last_sync_at = NOW(), last_sync_status = "ok", last_sync_error = NULL
            WHERE club_id = ?
        ')->execute([$clubId]);
    }

    // ── 5. Статус чека (PDF/QR) ──────────────────────────────────────────

    public static function getReceiptStatus(PDO $pdo, int $clubId, string $receiptId): array
    {
        $auth    = self::authenticate($pdo, $clubId);
        $headers = self::authHeaders($auth);
        return self::request('GET', '/receipts/' . urlencode($receiptId), $headers);
    }

    // ── Оркестратор: рішення "фіскалізувати чи ні" + виконання ──────────

    /**
     * Викликається одразу після запису оплати в club_payments (ТЗ 4.2, 4.4).
     * Ніколи не кидає виняток — оплата в CRM важливіша за фіскалізацію.
     */
    public static function maybeFiscalize(
        PDO $pdo,
        int $clubId,
        int $paymentId,
        string $paymentMethod,
        float $amount,
        string $description,
        bool $manualSkip
    ): void {
        if ($manualSkip) {
            self::markStatus($pdo, $paymentId, 'skipped_manual');
            return;
        }

        $settings = self::getSettings($pdo, $clubId);
        if (!$settings || !(int)$settings['is_active']) {
            return; // ПРРО не підключено/вимкнено — мовчки пропускаємо (не помилка)
        }

        $stmt = $pdo->prepare('
            SELECT auto_fiscalize FROM club_prro_payment_methods
            WHERE club_id = ? AND payment_method = ? LIMIT 1
        ');
        $stmt->execute([$clubId, $paymentMethod]);
        $autoFiscalize = (int)$stmt->fetchColumn();
        if (!$autoFiscalize) {
            return; // спосіб оплати не обрано для автофіскалізації
        }

        self::markStatus($pdo, $paymentId, 'pending');

        try {
            $result = self::createReceipt($pdo, $clubId, [
                'amount'         => $amount,
                'payment_method' => $paymentMethod,
                'description'    => $description,
            ]);
            $pdo->prepare('
                UPDATE club_payments
                SET fiscal_status = "sent", fiscal_receipt_id = ?, fiscal_error = NULL
                WHERE id = ?
            ')->execute([$result['receipt_id'], $paymentId]);
        } catch (Throwable $e) {
            error_log('[CheckboxService] club_id=' . $clubId . ' payment_id=' . $paymentId . ' — ' . $e->getMessage());
            $pdo->prepare('
                UPDATE club_payments
                SET fiscal_status = "failed", fiscal_error = ?, fiscal_attempts = fiscal_attempts + 1
                WHERE id = ?
            ')->execute([mb_substr($e->getMessage(), 0, 2000), $paymentId]);
        }
    }

    private static function markStatus(PDO $pdo, int $paymentId, string $status): void
    {
        $pdo->prepare('UPDATE club_payments SET fiscal_status = ? WHERE id = ?')
            ->execute([$status, $paymentId]);
    }
}
