<?php
/**
 * telegram_api.php — Прив'язка клієнтів/персоналу до Telegram-бота, розсилки, нагадування
 *
 * Дії:
 *   generate_link        — одноразове посилання прив'язки для КЛІЄНТА (персонал, telegram.manage)
 *   unlink                — відв'язати Telegram клієнта (персонал, telegram.manage)
 *   generate_staff_link  — одноразове посилання прив'язки СВОГО акаунта (будь-який залогінений)
 *   unlink_staff         — відв'язати СВІЙ Telegram (будь-який залогінений)
 *   broadcast_clients     — розсилка своїм клієнтам клубу (telegram.broadcast)
 *   broadcast_owners      — розсилка всім власникам клубів (SuperAdmin)
 *   get_settings          — глобальні налаштування нагадувань (SuperAdmin)
 *   save_settings          — зберегти глобальні налаштування (SuperAdmin)
 *   get_stats             — статистика прив'язки (по клубу, або по всіх клубах для SuperAdmin поза клубом)
 *   cron_send_reminders  — щоденний крон: нагадування про закінчення абонемента (публічна, секретний ключ)
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';
$pdo    = Database::get();

// Публічні дії — не потребують авторизації
$publicActions = ['cron_send_reminders'];

if (!in_array($action, $publicActions)) {
    $sess = Auth::requireAuth();
}

try { switch ($action) {

    // ════ ЗГЕНЕРУВАТИ ПОСИЛАННЯ ПРИВ'ЯЗКИ КЛІЄНТА ═════════════
    case 'generate_link':
        $clubId = (int)($input['club_id'] ?? $_GET['club_id'] ?? $sess['active_club_id'] ?? 0);
        if (!$clubId) Response::error('Не обрано клуб', 400);
        if (!Auth::can($sess, $clubId, 'telegram.manage')) Response::forbidden();

        $clientId = (int)($input['client_id'] ?? 0);
        if (!$clientId) Response::error('Вкажіть client_id');

        $client = $pdo->prepare("SELECT id FROM clients WHERE id=? AND club_id=? LIMIT 1");
        $client->execute([$clientId, $clubId]);
        if (!$client->fetchColumn()) Response::error('Клієнта не знайдено', 404);

        if (!defined('TELEGRAM_BOT_USERNAME') || !TELEGRAM_BOT_USERNAME) {
            Response::error('Telegram-бот не налаштовано на сервері', 500);
        }

        $pdo->prepare("DELETE FROM client_telegram_links WHERE client_id=? AND used_at IS NULL")
            ->execute([$clientId]);

        $token = bin2hex(random_bytes(16));
        $pdo->prepare("
            INSERT INTO client_telegram_links (client_id, club_id, token, expires_at, created_at)
            VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW())
        ")->execute([$clientId, $clubId, $token]);

        Response::ok([
            'token' => $token,
            'link'  => 'https://t.me/' . TELEGRAM_BOT_USERNAME . '?start=' . $token,
        ], 'Посилання створено (дійсне 24 год)');


    // ════ ВІДВ'ЯЗАТИ КЛІЄНТА ═══════════════════════════════════
    case 'unlink':
        $clubId = (int)($input['club_id'] ?? $_GET['club_id'] ?? $sess['active_club_id'] ?? 0);
        if (!$clubId) Response::error('Не обрано клуб', 400);
        if (!Auth::can($sess, $clubId, 'telegram.manage')) Response::forbidden();

        $clientId = (int)($input['client_id'] ?? 0);
        if (!$clientId) Response::error('Вкажіть client_id');

        $stmt = $pdo->prepare("UPDATE clients SET telegram_id=NULL WHERE id=? AND club_id=?");
        $stmt->execute([$clientId, $clubId]);
        if (!$stmt->rowCount()) Response::error('Клієнта не знайдено', 404);

        Response::ok([], 'Telegram відв\'язано');


    // ════ ЗГЕНЕРУВАТИ ПОСИЛАННЯ ПРИВ'ЯЗКИ ПЕРСОНАЛУ (self) ═════
    case 'generate_staff_link':
        if (!defined('TELEGRAM_BOT_USERNAME') || !TELEGRAM_BOT_USERNAME) {
            Response::error('Telegram-бот не налаштовано на сервері', 500);
        }

        $userId = (int)$sess['user_id'];

        $pdo->prepare("DELETE FROM staff_telegram_links WHERE user_id=? AND used_at IS NULL")
            ->execute([$userId]);

        $token = bin2hex(random_bytes(16));
        $pdo->prepare("
            INSERT INTO staff_telegram_links (user_id, token, expires_at, created_at)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW())
        ")->execute([$userId, $token]);

        Response::ok([
            'token' => $token,
            'link'  => 'https://t.me/' . TELEGRAM_BOT_USERNAME . '?start=' . $token,
        ], 'Посилання створено (дійсне 24 год)');


    // ════ ВІДВ'ЯЗАТИ СВІЙ TELEGRAM ══════════════════════════════
    case 'unlink_staff':
        $pdo->prepare("UPDATE sys_users SET telegram_id=NULL WHERE id=?")
            ->execute([(int)$sess['user_id']]);
        Response::ok([], 'Telegram відв\'язано');


    // ════ РОЗСИЛКА КЛІЄНТАМ КЛУБУ (owner/telegram.broadcast) ═══
    case 'broadcast_clients':
        $clubId = (int)($input['club_id'] ?? $_GET['club_id'] ?? $sess['active_club_id'] ?? 0);
        if (!$clubId) Response::error('Не обрано клуб', 400);
        if (!Auth::can($sess, $clubId, 'telegram.broadcast')) Response::forbidden();

        $text = trim($input['text'] ?? '');
        if (!$text) Response::error('Введіть текст повідомлення');

        $stmt = $pdo->prepare("SELECT telegram_id FROM clients WHERE club_id=? AND telegram_id IS NOT NULL");
        $stmt->execute([$clubId]);
        $chatIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($chatIds as $chatId) {
            Telegram::sendMessage($chatId, $text);
        }

        Response::ok(['sent' => count($chatIds)], 'Розсилку надіслано');


    // ════ РОЗСИЛКА ВЛАСНИКАМ КЛУБІВ (SuperAdmin) ════════════════
    case 'broadcast_owners':
        if (!Auth::isSuperAdmin($sess)) Response::forbidden();

        $text = trim($input['text'] ?? '');
        if (!$text) Response::error('Введіть текст повідомлення');

        $stmt = $pdo->query("
            SELECT DISTINCT u.telegram_id
            FROM sys_user_clubs uc
            JOIN sys_roles r ON r.id = uc.role_id AND r.slug = 'owner'
            JOIN sys_users u ON u.id = uc.user_id
            WHERE uc.is_active = 1 AND u.telegram_id IS NOT NULL
            UNION
            SELECT DISTINCT u.telegram_id
            FROM sys_clubs c
            JOIN sys_users u ON u.id = c.owner_id
            WHERE u.telegram_id IS NOT NULL
        ");
        $chatIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($chatIds as $chatId) {
            Telegram::sendMessage($chatId, $text);
        }

        Response::ok(['sent' => count($chatIds)], 'Розсилку надіслано');


    // ════ НАЛАШТУВАННЯ НАГАДУВАНЬ (SuperAdmin) ══════════════════
    case 'get_settings':
        if (!Auth::isSuperAdmin($sess)) Response::forbidden();

        $row = $pdo->query("SELECT reminders_enabled, reminder_days_before FROM telegram_settings WHERE id=1")->fetch();

        Response::ok([
            'reminders_enabled'    => (bool)($row['reminders_enabled'] ?? true),
            'reminder_days_before' => (int)($row['reminder_days_before'] ?? 1),
            'bot_username'         => defined('TELEGRAM_BOT_USERNAME') ? TELEGRAM_BOT_USERNAME : null,
            'bot_configured'       => defined('TELEGRAM_BOT_TOKEN') && !!TELEGRAM_BOT_TOKEN,
        ]);


    case 'save_settings':
        if (!Auth::isSuperAdmin($sess)) Response::forbidden();

        $enabled = !empty($input['reminders_enabled']) ? 1 : 0;
        $days    = max(0, min(30, (int)($input['reminder_days_before'] ?? 1)));

        $pdo->prepare("
            INSERT INTO telegram_settings (id, reminders_enabled, reminder_days_before)
            VALUES (1, ?, ?)
            ON DUPLICATE KEY UPDATE reminders_enabled = VALUES(reminders_enabled), reminder_days_before = VALUES(reminder_days_before)
        ")->execute([$enabled, $days]);

        Response::ok([], 'Налаштування збережено');


    // ════ СТАТИСТИКА ПРИВ'ЯЗКИ ══════════════════════════════════
    case 'get_stats':
        $myRow = $pdo->prepare("SELECT telegram_id FROM sys_users WHERE id=?");
        $myRow->execute([(int)$sess['user_id']]);
        $myLinked = (bool)$myRow->fetchColumn();

        $requestedClubId = (int)($input['club_id'] ?? $_GET['club_id'] ?? 0);
        $platformWide = Auth::isSuperAdmin($sess) && !$requestedClubId && empty($sess['active_club_id']);

        if ($platformWide) {
            $rows = $pdo->query("
                SELECT c.id AS club_id, c.name AS club_name,
                       COUNT(cl.id) AS total_clients,
                       COUNT(cl.telegram_id) AS linked_clients
                FROM sys_clubs c
                LEFT JOIN clients cl ON cl.club_id = c.id
                GROUP BY c.id, c.name
                ORDER BY c.name
            ")->fetchAll();
            Response::ok(['stats' => $rows, 'my_linked' => $myLinked, 'platform_wide' => true]);
        }

        $clubId = $requestedClubId ?: (int)($sess['active_club_id'] ?? 0);
        if (!$clubId) Response::error('Не обрано клуб', 400);
        Auth::requireClubAccess($sess, $clubId, 30);

        $stmt = $pdo->prepare("
            SELECT c.id AS club_id, c.name AS club_name,
                   COUNT(cl.id) AS total_clients,
                   COUNT(cl.telegram_id) AS linked_clients
            FROM sys_clubs c
            LEFT JOIN clients cl ON cl.club_id = c.id
            WHERE c.id = ?
            GROUP BY c.id, c.name
        ");
        $stmt->execute([$clubId]);

        Response::ok(['stats' => $stmt->fetchAll(), 'my_linked' => $myLinked, 'platform_wide' => false]);


    // ════ КРОН: НАГАДУВАННЯ ПРО ЗАКІНЧЕННЯ АБОНЕМЕНТА ═════════
    // Виклик: GET /api/telegram_api.php?action=cron_send_reminders&key=...
    // Рахуємо строго з end_date (НЕ з status — client_invoices.status не оновлюється кроном).
    case 'cron_send_reminders':
        $key = $_GET['key'] ?? '';
        if (!defined('CRON_SECRET') || !hash_equals(CRON_SECRET, $key)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }

        $settings = $pdo->query("SELECT reminders_enabled, reminder_days_before FROM telegram_settings WHERE id=1")->fetch();
        if ($settings && !$settings['reminders_enabled']) {
            echo json_encode(['ok' => true, 'sent' => 0, 'reason' => 'disabled']);
            exit;
        }
        $daysLeft  = (int)($settings['reminder_days_before'] ?? 1);
        $notifType = $daysLeft === 0 ? 'expiry_today' : "expiry_{$daysLeft}d";

        $sent = 0;
        $stmt = $pdo->prepare("
            SELECT ci.id AS invoice_id, ci.tariff_name, ci.end_date,
                   c.id AS client_id, c.telegram_id
            FROM client_invoices ci
            JOIN clients c ON c.id = ci.client_id
            WHERE c.telegram_id IS NOT NULL
              AND ci.status NOT IN ('cancelled', 'frozen')
              AND ci.end_date = DATE_ADD(CURDATE(), INTERVAL ? DAY)
              AND NOT EXISTS (
                  SELECT 1 FROM telegram_notifications_log l
                  WHERE l.client_id = c.id AND l.invoice_id = ci.id AND l.notif_type = ?
              )
        ");
        $stmt->execute([$daysLeft, $notifType]);

        foreach ($stmt->fetchAll() as $row) {
            $text = $daysLeft === 0
                ? "⏰ Ваш абонемент «{$row['tariff_name']}» закінчується сьогодні."
                : "⏰ Ваш абонемент «{$row['tariff_name']}» закінчується через {$daysLeft} дн. (" . date('d.m.Y', strtotime($row['end_date'])) . ").";

            $result = Telegram::sendMessage($row['telegram_id'], $text);

            // Логуємо спробу незалежно від успіху — інакше невдала відправка
            // (заблокований бот тощо) ретраїлась би на кожному запуску крону.
            $pdo->prepare("
                INSERT INTO telegram_notifications_log (client_id, invoice_id, notif_type, sent_at)
                VALUES (?, ?, ?, NOW())
            ")->execute([$row['client_id'], $row['invoice_id'], $notifType]);

            if (!empty($result['ok'])) $sent++;
        }

        echo json_encode(['ok' => true, 'sent' => $sent]);
        exit;


    default:
        Response::error("Невідома дія: {$action}", 400);

}} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}
