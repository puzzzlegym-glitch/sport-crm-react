<?php
/**
 * prro_api.php — API модуля "ПРРО / Checkbox"
 *
 * Дії:
 *   get_settings          — підключення клубу до Checkbox (без розшифрованих секретів)
 *   save_settings         — зберегти license_key/cashier_login/cashier_password/is_active
 *   sync_cashier          — синхронізувати касира з ДПС
 *   get_payment_methods   — способи оплати, що фіскалізуються автоматично
 *   save_payment_methods  — зберегти способи оплати
 *
 * Права: prro.manage (лише owner, рівень 80) — і на фронтенді, і тут (ТЗ розділ 7).
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once __DIR__ . '/../classes/CheckboxService.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$sess   = Auth::requireAuth();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';
$pdo    = Database::get();

$clubId = (int)($input['club_id'] ?? $_GET['club_id'] ?? $sess['active_club_id'] ?? 0);
if (!$clubId) Response::error('Не обрано клуб', 400);

if (!Auth::can($sess, $clubId, 'prro.manage')) Response::forbidden();

$PAYMENT_METHODS = ['cash', 'card', 'terminal', 'deposit', 'other'];

try { switch ($action) {

    // ════ ОТРИМАТИ НАЛАШТУВАННЯ ═══════════════════════════════
    case 'get_settings':
        $stmt = $pdo->prepare('
            SELECT club_id, provider, cashier_login, cash_register_id, cashier_id,
                   is_active, last_sync_at, last_sync_status, last_sync_error,
                   (license_key_enc IS NOT NULL AND license_key_enc != "")     AS has_license_key,
                   (cashier_password_enc IS NOT NULL AND cashier_password_enc != "") AS has_password
            FROM club_prro_settings WHERE club_id = ? LIMIT 1
        ');
        $stmt->execute([$clubId]);
        $settings = $stmt->fetch();

        Response::ok(['settings' => $settings ?: [
            'club_id' => $clubId, 'provider' => 'checkbox', 'cashier_login' => null,
            'cash_register_id' => null, 'cashier_id' => null, 'is_active' => 0,
            'last_sync_at' => null, 'last_sync_status' => null, 'last_sync_error' => null,
            'has_license_key' => 0, 'has_password' => 0,
        ]]);


    // ════ ЗБЕРЕГТИ НАЛАШТУВАННЯ ═══════════════════════════════
    case 'save_settings':
        $cashierLogin   = trim($input['cashier_login'] ?? '');
        $cashierPass    = (string)($input['cashier_password'] ?? '');
        $licenseKey     = trim($input['license_key'] ?? '');
        $cashRegisterId = trim($input['cash_register_id'] ?? '');
        $cashierId      = trim($input['cashier_id'] ?? '');
        $isActive       = (int)(bool)($input['is_active'] ?? 0);

        // Існуючий рядок — щоб не затерти секрети, якщо поле лишили порожнім
        // (фронт ніколи не отримує розшифрований пароль/ключ назад, тож
        // порожнє поле в формі означає "не змінювати", а не "видалити").
        $stmt = $pdo->prepare('SELECT license_key_enc, cashier_password_enc FROM club_prro_settings WHERE club_id = ? LIMIT 1');
        $stmt->execute([$clubId]);
        $existing = $stmt->fetch();

        $licenseKeyEnc = $licenseKey !== ''
            ? CheckboxService::encrypt($licenseKey)
            : ($existing['license_key_enc'] ?? null);
        $cashierPassEnc = $cashierPass !== ''
            ? CheckboxService::encrypt($cashierPass)
            : ($existing['cashier_password_enc'] ?? null);

        $pdo->prepare('
            INSERT INTO club_prro_settings
                (club_id, provider, license_key_enc, cashier_login, cashier_password_enc,
                 cash_register_id, cashier_id, is_active,
                 access_token_enc, access_token_expires_at)
            VALUES (?, "checkbox", ?, ?, ?, ?, ?, ?, NULL, NULL)
            ON DUPLICATE KEY UPDATE
                license_key_enc      = VALUES(license_key_enc),
                cashier_login        = VALUES(cashier_login),
                cashier_password_enc = VALUES(cashier_password_enc),
                cash_register_id     = VALUES(cash_register_id),
                cashier_id           = VALUES(cashier_id),
                is_active            = VALUES(is_active),
                access_token_enc     = NULL,
                access_token_expires_at = NULL
        ')->execute([
            $clubId, $licenseKeyEnc, $cashierLogin ?: null, $cashierPassEnc,
            $cashRegisterId ?: null, $cashierId ?: null, $isActive,
        ]);

        Response::ok([], 'Налаштування ПРРО збережено');


    // ════ ПЕРЕВІРИТИ ПІДКЛЮЧЕННЯ ════════════════════════════════
    // Примітка: "синхронізація з ДПС" з ТЗ — ручна дія в кабінеті
    // my.checkbox.ua (розділ «Касири»), API для неї немає. Тут — реальна
    // перевірка логіна/пароля/ключа через /cashier/signin.
    case 'sync_cashier':
        try {
            CheckboxService::testConnection($pdo, $clubId);
            Response::ok([], 'З\'єднання з Checkbox працює');
        } catch (Throwable $e) {
            $pdo->prepare('
                UPDATE club_prro_settings
                SET last_sync_at = NOW(), last_sync_status = "error", last_sync_error = ?
                WHERE club_id = ?
            ')->execute([mb_substr($e->getMessage(), 0, 2000), $clubId]);
            Response::error('Помилка підключення: ' . $e->getMessage());
        }


    // ════ СПОСОБИ ОПЛАТИ ДЛЯ АВТОФІСКАЛІЗАЦІЇ ══════════════════
    case 'get_payment_methods':
        $stmt = $pdo->prepare('SELECT payment_method, auto_fiscalize FROM club_prro_payment_methods WHERE club_id = ?');
        $stmt->execute([$clubId]);
        $saved = [];
        foreach ($stmt->fetchAll() as $row) {
            $saved[$row['payment_method']] = (int)$row['auto_fiscalize'];
        }
        $methods = [];
        foreach ($PAYMENT_METHODS as $m) {
            $methods[] = ['payment_method' => $m, 'auto_fiscalize' => $saved[$m] ?? 0];
        }
        Response::ok(['methods' => $methods]);


    case 'save_payment_methods':
        $methods = $input['methods'] ?? [];
        if (!is_array($methods)) Response::error('Невірний формат');

        $pdo->beginTransaction();
        try {
            $upsert = $pdo->prepare('
                INSERT INTO club_prro_payment_methods (club_id, payment_method, auto_fiscalize)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE auto_fiscalize = VALUES(auto_fiscalize)
            ');
            foreach ($methods as $row) {
                $method = $row['payment_method'] ?? '';
                if (!in_array($method, $PAYMENT_METHODS, true)) continue;
                $upsert->execute([$clubId, $method, (int)(bool)($row['auto_fiscalize'] ?? 0)]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Response::ok([], 'Способи оплати збережено');


    default:
        Response::error("Невідома дія: {$action}", 400);

}} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}
