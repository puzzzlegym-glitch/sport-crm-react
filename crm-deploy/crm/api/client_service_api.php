<?php
/**
 * client_service_api.php — API сторінки "Клієнтський сервіс" (застосунок DRIVEHUB
 * + Telegram-боти, вкладка в CRM для клубу).
 *
 * Дії:
 *   get_stats         — зведена статистика клубу (клієнти, Telegram, застосунок)
 *   get_client_status — статус ОДНОГО клієнта в застосунку DRIVEHUB (для картки клієнта)
 *
 * Дані застосунку (app_users/app_users_verified) читаються з бази DRIVEHUB через
 * DriveHubBridge.php (див. app/core/DriveHubBridge.php) — виключно на читання,
 * ідентифікація по телефону. Якщо DRIVEHUB_DB_* constants ще не задані в config.php —
 * ці поля повертаються з connected=false, решта статистики (свої дані CRM) працює як завжди.
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$sess   = Auth::requireAuth();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';

$clubId = (int)($input['club_id'] ?? $_GET['club_id'] ?? $sess['active_club_id'] ?? 0);
if (!$clubId) Response::error('Не обрано клуб. Оберіть клуб у шапці.', 400);
if (!Auth::can($sess, $clubId, 'clients.view')) Response::forbidden();

$pdo = Database::get();

try { switch ($action) {

    // ════ ЗВЕДЕНА СТАТИСТИКА КЛУБУ ══════════════════════════════
    case 'get_stats':
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total_clients,
                COUNT(telegram_id) AS telegram_linked,
                GROUP_CONCAT(RIGHT(phone_normalized, 10) SEPARATOR ',') AS phones
            FROM clients
            WHERE club_id = ?
        ");
        $stmt->execute([$clubId]);
        $row = $stmt->fetch();

        $totalClients   = (int)($row['total_clients'] ?? 0);
        $telegramLinked = (int)($row['telegram_linked'] ?? 0);
        $phones10       = !empty($row['phones']) ? array_filter(explode(',', $row['phones'])) : [];

        $hub = getDriveHubClubStats($phones10);

        Response::ok([
            'total_clients'       => $totalClients,
            'telegram_linked'     => $telegramLinked,
            'hub_connected'       => $hub['connected'],
            'app_users'           => $hub['app_users'],
            'app_users_verified'  => $hub['app_users_verified'],
        ]);


    // ════ СТАТУС ОДНОГО КЛІЄНТА В DRIVEHUB ══════════════════════
    case 'get_client_status':
        $clientId = (int)($input['client_id'] ?? 0);
        if (!$clientId) Response::error('Вкажіть client_id');

        $stmt = $pdo->prepare("SELECT phone_normalized, telegram_id FROM clients WHERE id=? AND club_id=? LIMIT 1");
        $stmt->execute([$clientId, $clubId]);
        $client = $stmt->fetch();
        if (!$client) Response::error('Клієнта не знайдено', 404);

        $phone10 = $client['phone_normalized'] ? substr($client['phone_normalized'], -10) : '';
        $hub = getDriveHubClientStatus($phone10);

        Response::ok([
            'crm_telegram_linked' => !empty($client['telegram_id']),
            'hub_connected'       => $hub['connected'],
            'is_app_user'         => $hub['is_app_user'],
            'is_verified'         => $hub['is_verified'],
            'has_hub_telegram'    => $hub['has_hub_telegram'],
        ]);


    default:
        Response::error("Невідома дія: {$action}", 400);

}} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}
