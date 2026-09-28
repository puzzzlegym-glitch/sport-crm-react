<?php
/**
 * acquiring_api.php — API модуля "Еквайринг" (оплата карткою через термінал)
 *
 * Зберігає підключення клубу до Device Manager Proxy API від Вчасно.Каса
 * (https://wiki-kasa.vchasno.ua/uk/DeviceManager/Functionality/Cloud&devices) —
 * клуб сам реєструє й оплачує кабінет Вчасно.Каса, ставить у себе Device
 * Manager і підключає до нього POS-термінал ПриватБанку. CRM зберігає лише
 * API-токен (X-AP-DM-PROXY-TOKEN) і назву пристрою — фізичне підключення
 * термінала повністю на боці клубу, CRM його не стосується.
 *
 * Виклик самої оплати (POST https://kasa.vchasno.ua/ws/ap/dm-proxy,
 * type:3 "pay") ЩЕ НЕ РЕАЛІЗОВАНО — це заготовка під наступний крок.
 * Фіскалізація чека лишається на Checkbox (CheckboxService.php) — Device
 * Manager тут лише для проведення оплати на фізичному терміналі.
 *
 * Дії:
 *   get_settings    — поточні налаштування клубу (без розшифрованого токена)
 *   save_settings   — зберегти device_name/dm_proxy_token/is_active
 *   test_connection — заглушка (виклик DM Proxy API ще не реалізовано)
 *
 * Права: prro.manage (та сама сторінка "ПРРО / Термінали", той самий власник).
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once __DIR__ . '/../classes/CheckboxService.php'; // лише заради encrypt/decrypt, див. коментар у міграції

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$sess   = Auth::requireAuth();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';
$pdo    = Database::get();

$clubId = (int)($input['club_id'] ?? $_GET['club_id'] ?? $sess['active_club_id'] ?? 0);
if (!$clubId) Response::error('Не обрано клуб', 400);

if (!Auth::can($sess, $clubId, 'prro.manage')) Response::forbidden();

try { switch ($action) {

    // ════ ОТРИМАТИ НАЛАШТУВАННЯ ═══════════════════════════════
    case 'get_settings':
        $stmt = $pdo->prepare('
            SELECT club_id, provider, device_name, is_active,
                   (dm_proxy_token_enc IS NOT NULL AND dm_proxy_token_enc != "") AS has_token
            FROM club_acquiring_settings WHERE club_id = ? LIMIT 1
        ');
        $stmt->execute([$clubId]);
        $settings = $stmt->fetch();

        Response::ok(['settings' => $settings ?: [
            'club_id' => $clubId, 'provider' => 'vchasno_kasa',
            'device_name' => null, 'is_active' => 0, 'has_token' => 0,
        ]]);


    // ════ ЗБЕРЕГТИ НАЛАШТУВАННЯ ═══════════════════════════════
    case 'save_settings':
        $deviceName = trim($input['device_name'] ?? '');
        $proxyToken = (string)($input['dm_proxy_token'] ?? '');
        $isActive   = (int)(bool)($input['is_active'] ?? 0);

        // Як і в prro_api.php: порожнє поле токена в формі означає "не змінювати",
        // а не "видалити" — фронт ніколи не отримує розшифрований токен назад.
        $stmt = $pdo->prepare('SELECT dm_proxy_token_enc FROM club_acquiring_settings WHERE club_id = ? LIMIT 1');
        $stmt->execute([$clubId]);
        $existing = $stmt->fetch();

        $proxyTokenEnc = $proxyToken !== ''
            ? CheckboxService::encrypt($proxyToken)
            : ($existing['dm_proxy_token_enc'] ?? null);

        $pdo->prepare('
            INSERT INTO club_acquiring_settings
                (club_id, provider, device_name, dm_proxy_token_enc, is_active)
            VALUES (?, "vchasno_kasa", ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                device_name        = VALUES(device_name),
                dm_proxy_token_enc = VALUES(dm_proxy_token_enc),
                is_active          = VALUES(is_active)
        ')->execute([$clubId, $deviceName ?: null, $proxyTokenEnc, $isActive]);

        Response::ok([], 'Налаштування еквайрингу збережено');


    // ════ ПЕРЕВІРИТИ ПІДКЛЮЧЕННЯ (заглушка) ═════════════════════
    case 'test_connection':
        Response::error(
            'Виклик Device Manager Proxy API ще не реалізовано в CRM — наразі це лише зберігання ' .
            'токена й назви пристрою. Перевірити токен можна вручну в кабінеті Вчасно.Каса.',
            501
        );


    default:
        Response::error("Невідома дія: {$action}", 400);

}} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}
