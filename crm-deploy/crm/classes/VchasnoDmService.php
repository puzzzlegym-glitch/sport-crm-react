<?php
/**
 * VchasnoDmService.php — оплата карткою через POS-термінал ПриватБанку,
 * підключений до Device Manager (Вчасно.Каса), через хмарний DM Proxy API.
 *
 * Документація: https://wiki-kasa.vchasno.ua/uk/DeviceManager/Functionality/API
 * та .../PayAPI (звірено 2026-09-03). Реальний ендпоінт — POST
 * https://kasa.vchasno.ua/ws/ap/dm-proxy: тіло запиту "обгортає" метод/url/body
 * виклику самого Device Manager (аналог реверс-проксі), Вчасно тримає постійне
 * з'єднання з ДМ у клубі — тому кредитному бекенду не треба "пробивати" мережу
 * клубу, досить знати club_acquiring_settings.dm_proxy_token_enc + device_name.
 *
 * Фіскалізація чека НЕ входить у цей клас — вона й далі йде через Checkbox
 * (CheckboxService::maybeFiscalize), незалежно від оплати на терміналі. Цей
 * клас відповідає ТІЛЬКИ за списання коштів з картки через фізичний термінал.
 *
 * За аналогією з CheckboxService — статичний клас-сервіс без DI-контейнера,
 * підключається окремим require_once. Секрет (dm_proxy_token) шифрується й
 * розшифровується тим самим CheckboxService::encrypt/decrypt (AES-256-CBC,
 * PRRO_ENCRYPTION_KEY) — заводити окремий ключ шифрування для цього єдиного
 * поля сенсу немає, див. коментар у sql/2026-09-02_club_acquiring_settings.sql.
 *
 * Методи:
 *   charge($pdo, $clubId, $amount, $tag) — провести оплату карткою (task: 1)
 *
 * Це лише "цеглинка" виклику оплати — оркестрація (виклик з форми продажу,
 * запис club_payments, обробка помилок у UI) сюди НЕ входить і ще не написана,
 * так само як maybeFiscalize() у CheckboxService — окрема надбудова.
 */

class VchasnoDmService
{
    private const PROXY_URL = 'https://kasa.vchasno.ua/ws/ap/dm-proxy';

    // ── Налаштування клубу ──────────────────────────────────────────────

    private static function getSettings(PDO $pdo, int $clubId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM club_acquiring_settings WHERE club_id = ? LIMIT 1');
        $stmt->execute([$clubId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ── UUID v4 для transaction_id (ідемпотентність запиту на термінал) ──

    /**
     * Документація каже "UUID (v5)", але й уточнює, що будь-яке не-UUID
     * значення ДМ замінить власним — тобто приймається коректний UUID
     * будь-якої версії. v4 — найпростіший спосіб згенерувати його в PHP
     * без зовнішніх бібліотек.
     */
    private static function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    // ── HTTP: виклик хмарного проксі Вчасно.Каса ────────────────────────

    /**
     * @param string $dmMethod HTTP-метод для самого Device Manager (GET/POST) —
     *                          проксі-запит до kasa.vchasno.ua завжди POST,
     *                          $dmMethod лише "обгортається" всередину body.
     * @throws RuntimeException при мережевій помилці, недійсному токені або
     *                          не-2xx відповіді проксі.
     */
    private static function proxyRequest(string $token, string $dmMethod, string $dmUrl, ?array $dmBody = null): array
    {
        $envelope = ['method' => $dmMethod, 'url' => $dmUrl];
        if ($dmBody !== null) {
            $envelope['body'] = $dmBody;
        }

        $ch = curl_init(self::PROXY_URL);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            // ДМ за замовчуванням чекає прикладання картки до 300с (в налаштуваннях
            // термінала) — рекомендований таймаут облікової системи: +10с запасу.
            CURLOPT_TIMEOUT        => 320,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-AP-DM-PROXY-TOKEN: ' . $token,
            ],
            CURLOPT_POSTFIELDS => json_encode($envelope, JSON_UNESCAPED_UNICODE),
        ]);
        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $errMsg = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException("Вчасно DM Proxy: мережева помилка — {$errMsg}");
        }
        if ($status === 401) {
            throw new RuntimeException('Вчасно DM Proxy: недійсний або відсутній API-токен');
        }
        $data = json_decode((string)$raw, true);
        if ($status < 200 || $status >= 300) {
            $msg = is_array($data) ? ($data['reason'] ?? $raw) : $raw;
            throw new RuntimeException("Вчасно DM Proxy: HTTP {$status} — {$msg}");
        }
        // Проблеми зв'язку ПРОКСІ ↔ ДМ (пристрій вимкнено, немає інтернету в
        // клубі тощо) повертаються 200-кою з "reason"/"code" замість task_status —
        // це НЕ відповідь від терміналу, а помилка на рівні самого з'єднання.
        if (isset($data['code']) && !array_key_exists('task_status', (array)$data)) {
            throw new RuntimeException(($data['reason'] ?? 'Немає зв\'язку з Device Manager') . " (код {$data['code']})");
        }
        return is_array($data) ? $data : [];
    }

    // ── Оплата карткою через термінал (task: 1) ──────────────────────────

    /**
     * @param float       $amount сума в грн (2 знаки після коми)
     * @param string|null $tag    власний UUID для ідемпотентності; якщо не
     *                            передано — генерується новий на кожен виклик
     * @return array{
     *   success: bool, transaction_id: ?string, rrn: ?string, cancel_id: ?string,
     *   cardmask: ?string, paysys: ?string, errortxt: ?string, raw: array
     * } rrn/cancel_id знадобляться для майбутніх повернення/скасування — тут
     *   лише повертаються, самі ці операції в класі не реалізовано.
     * @throws RuntimeException якщо еквайринг не підключено/вимкнено для
     *                          клубу, або при мережевій помилці зв'язку з ДМ.
     *                          Помилку САМОЇ оплати (картку відхилено банком
     *                          тощо) виняток не кидає — про це каже success=false.
     */
    public static function charge(PDO $pdo, int $clubId, float $amount, ?string $tag = null): array
    {
        $settings = self::getSettings($pdo, $clubId);
        if (!$settings || !(int)$settings['is_active'] || empty($settings['dm_proxy_token_enc'])) {
            throw new RuntimeException('Еквайринг не підключено або вимкнено для цього клубу');
        }
        if (empty($settings['device_name'])) {
            throw new RuntimeException('Не вказано назву пристрою (device) для еквайрингу');
        }

        $token = CheckboxService::decrypt($settings['dm_proxy_token_enc']);

        $body = [
            'device'         => $settings['device_name'],
            'transaction_id' => $tag ?: self::generateUuidV4(),
            'type'           => 3,
            'pay'            => [
                'task' => 1,
                'sum'  => round($amount, 2),
            ],
        ];

        $resp = self::proxyRequest($token, 'POST', '/dm/execute', $body);
        $info = $resp['info'] ?? [];

        return [
            'success'        => (int)($resp['task_status'] ?? 0) === 1 && (int)($resp['res'] ?? 1) === 0,
            'transaction_id' => $resp['transaction_id'] ?? null,
            'rrn'            => $info['refundid'] ?? null,
            'cancel_id'      => $info['cancelid'] ?? null,
            'cardmask'       => $info['cardmask'] ?? null,
            'paysys'         => $info['paysys'] ?? null,
            'errortxt'       => $resp['errortxt'] ?? null,
            'raw'            => $resp,
        ];
    }
}
