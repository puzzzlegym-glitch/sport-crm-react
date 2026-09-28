<?php
/**
 * support_api.php — Переписка клубу з підтримкою Sport CRM (тікети + чат)
 *
 * Дії:
 *   list_tickets    — список звернень (клуб бачить свої; SuperAdmin поза club-режимом — усі)
 *   get_ticket      — деталі звернення + повідомлення
 *   create_ticket   — нове звернення (club-режим, будь-яка роль з support.view)
 *   send_message    — надіслати повідомлення в наявне звернення
 *   update_status   — змінити статус звернення (SuperAdmin — будь-який статус;
 *                     клуб — лише закрити своє звернення, status='closed')
 *   delete_ticket   — видалити звернення (клуб — своє; SuperAdmin — будь-яке)
 *   unread_count    — кількість звернень з непрочитаним (для дзвіночка в хедері)
 *
 * Спільна логіка додавання повідомлення (статус/непрочитане/Telegram-сповіщення) —
 * у Support::postMessage(), яку так само використовує telegram_webhook_api.php
 * для відповідей прямо з Telegram-бота (Reply на повідомлення про звернення).
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';
$pdo    = Database::get();
$sess   = Auth::requireAuth();

// Глобальний інбокс підтримки — SuperAdmin, який не перебуває в режимі конкретного клубу.
$isAdminInbox = Auth::isSuperAdmin($sess) && !$sess['active_club_id'];

/**
 * Завантажує звернення й перевіряє доступ: SuperAdmin поза клубом бачить усе,
 * інакше звернення має належати активному клубу сесії.
 */
function findTicket(PDO $pdo, int $ticketId, array $sess, bool $isAdminInbox): array
{
    $stmt = $pdo->prepare("
        SELECT t.id, t.club_id, t.subject, t.status, t.unread_by_club, t.unread_by_admin,
               t.last_message_at, t.created_at, c.name AS club_name
        FROM support_tickets t
        JOIN sys_clubs c ON c.id = t.club_id
        WHERE t.id = ?
        LIMIT 1
    ");
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch();
    if (!$ticket) Response::error('Звернення не знайдено', 404);

    if (!$isAdminInbox) {
        $clubId = (int)$sess['active_club_id'];
        if (!$clubId) Response::error('Не обрано клуб', 400);
        if ((int)$ticket['club_id'] !== $clubId) Response::forbidden();
    }

    return $ticket;
}

try { switch ($action) {

    // ════ ЛІЧИЛЬНИК НЕПРОЧИТАНОГО (дзвіночок у хедері) ══════════
    case 'unread_count':
        $unreadField = $isAdminInbox ? 'unread_by_admin' : 'unread_by_club';

        if ($isAdminInbox) {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM support_tickets WHERE {$unreadField} = 1")->fetchColumn();
        } else {
            $clubId = (int)$sess['active_club_id'];
            if (!$clubId) Response::ok(['count' => 0]);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE club_id = ? AND {$unreadField} = 1");
            $stmt->execute([$clubId]);
            $count = (int)$stmt->fetchColumn();
        }

        Response::ok(['count' => $count]);


    // ════ СПИСОК ЗВЕРНЕНЬ ══════════════════════════════════════
    case 'list_tickets':
        $status = trim($input['status'] ?? $_GET['status'] ?? '');
        $params = [];
        $where  = [];

        if ($isAdminInbox) {
            $sql = "
                SELECT t.id, t.subject, t.status, t.unread_by_club, t.unread_by_admin,
                       t.last_message_at, t.created_at, c.name AS club_name
                FROM support_tickets t
                JOIN sys_clubs c ON c.id = t.club_id
            ";
        } else {
            $clubId = (int)$sess['active_club_id'];
            if (!$clubId) Response::error('Не обрано клуб', 400);
            if (!Auth::can($sess, $clubId, 'support.view')) Response::forbidden();

            $sql = "
                SELECT t.id, t.subject, t.status, t.unread_by_club, t.unread_by_admin,
                       t.last_message_at, t.created_at
                FROM support_tickets t
            ";
            $where[]  = 't.club_id = ?';
            $params[] = $clubId;
        }

        if ($status) { $where[] = 't.status = ?'; $params[] = $status; }
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY t.last_message_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        Response::ok(['tickets' => $stmt->fetchAll()]);


    // ════ ДЕТАЛІ ЗВЕРНЕННЯ ═════════════════════════════════════
    case 'get_ticket':
        $ticketId = (int)($input['ticket_id'] ?? $_GET['ticket_id'] ?? 0);
        if (!$ticketId) Response::error('Вкажіть ticket_id');

        $ticket = findTicket($pdo, $ticketId, $sess, $isAdminInbox);

        // Перегляд позначає звернення прочитаним для тієї сторони, яка зараз дивиться.
        $unreadField = $isAdminInbox ? 'unread_by_admin' : 'unread_by_club';
        if ((int)$ticket[$unreadField] === 1) {
            $pdo->prepare("UPDATE support_tickets SET {$unreadField} = 0 WHERE id = ?")->execute([$ticketId]);
            $ticket[$unreadField] = 0;
        }

        $stmt = $pdo->prepare("
            SELECT m.id, m.sender_type, m.message, m.created_at,
                   u.full_name AS sender_name
            FROM support_messages m
            JOIN sys_users u ON u.id = m.sender_user_id
            WHERE m.ticket_id = ?
            ORDER BY m.created_at ASC, m.id ASC
        ");
        $stmt->execute([$ticketId]);

        Response::ok(['ticket' => $ticket, 'messages' => $stmt->fetchAll()]);


    // ════ НОВЕ ЗВЕРНЕННЯ (club-режим) ══════════════════════════
    case 'create_ticket':
        if ($isAdminInbox) Response::error('SuperAdmin поза клубом не може відкривати звернення', 400);

        $clubId = (int)$sess['active_club_id'];
        if (!$clubId) Response::error('Не обрано клуб', 400);
        if (!Auth::can($sess, $clubId, 'support.view')) Response::forbidden();

        $subject = trim(mb_substr($input['subject'] ?? '', 0, 191));
        $message = trim($input['message'] ?? '');
        if (!$subject || !$message) Response::error('Заповніть тему і опис питання');

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO support_tickets (club_id, created_by, subject, status, last_message_at, created_at)
                VALUES (?, ?, ?, 'open', NOW(), NOW())
            ");
            $stmt->execute([$clubId, $sess['user_id'], $subject]);
            $ticketId = (int)$pdo->lastInsertId();

            $pdo->prepare("
                INSERT INTO support_messages (ticket_id, sender_type, sender_user_id, message, created_at)
                VALUES (?, 'club', ?, ?, NOW())
            ")->execute([$ticketId, $sess['user_id'], $message]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $clubNameStmt = $pdo->prepare("SELECT name FROM sys_clubs WHERE id = ?");
        $clubNameStmt->execute([$clubId]);
        $clubName = $clubNameStmt->fetchColumn() ?: 'Клуб';

        $sent = Telegram::notifySuperAdmins(
            "🆘 Нове звернення від «{$clubName}»\n" .
            "Тема: {$subject}\n\n" .
            Support::notifyPreview($message)
        );
        Support::trackSent($pdo, $ticketId, $sent);

        Response::ok(['ticket_id' => $ticketId], 'Звернення надіслано');


    // ════ НОВЕ ПОВІДОМЛЕННЯ ═════════════════════════════════════
    case 'send_message':
        $ticketId = (int)($input['ticket_id'] ?? 0);
        if (!$ticketId) Response::error('Вкажіть ticket_id');

        $message = trim($input['message'] ?? '');
        if (!$message) Response::error('Введіть повідомлення');

        $ticket = findTicket($pdo, $ticketId, $sess, $isAdminInbox);
        if (!$isAdminInbox && $ticket['status'] === 'closed') {
            Response::error('Звернення закрито');
        }

        $senderType = $isAdminInbox ? 'admin' : 'club';
        Support::postMessage($pdo, $ticketId, $senderType, (int)$sess['user_id'], $message);

        Response::ok([], 'Повідомлення надіслано');


    // ════ ЗМІНА СТАТУСУ (SuperAdmin — будь-який; клуб — лише "закрити своє") ═══
    case 'update_status':
        $ticketId = (int)($input['ticket_id'] ?? 0);
        $status   = trim($input['status'] ?? '');
        if (!$ticketId) Response::error('Вкажіть ticket_id');
        if (!in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true)) {
            Response::error('Невірний статус');
        }
        if (!$isAdminInbox && $status !== 'closed') {
            // Клуб керує лише закриттям свого звернення — решту статусів виставляє підтримка.
            Response::forbidden();
        }

        $ticket = findTicket($pdo, $ticketId, $sess, $isAdminInbox);
        if (!$isAdminInbox && !Auth::can($sess, (int)$sess['active_club_id'], 'support.view')) {
            Response::forbidden();
        }

        if ($isAdminInbox) {
            $pdo->prepare("UPDATE support_tickets SET status = ?, unread_by_club = 1 WHERE id = ?")
                ->execute([$status, $ticketId]);

            $sent = Telegram::notifyClubOwners(
                $ticket['club_id'],
                "ℹ️ Статус звернення «{$ticket['subject']}» змінено: " . Support::statusLabel($status)
            );
            Support::trackSent($pdo, $ticketId, $sent);
        } else {
            // Клуб закрив сам — підтримці лише позначка непрочитаного в CRM, без Telegram-сповіщення собі ж.
            $pdo->prepare("UPDATE support_tickets SET status = ?, unread_by_admin = 1 WHERE id = ?")
                ->execute([$status, $ticketId]);
        }

        Response::ok([], 'Статус оновлено');


    // ════ ВИДАЛЕННЯ ЗВЕРНЕННЯ (клуб — своє; SuperAdmin — будь-яке) ═══
    case 'delete_ticket':
        $ticketId = (int)($input['ticket_id'] ?? 0);
        if (!$ticketId) Response::error('Вкажіть ticket_id');

        findTicket($pdo, $ticketId, $sess, $isAdminInbox); // перевірка доступу (club-scope або admin)
        if (!$isAdminInbox && !Auth::can($sess, (int)$sess['active_club_id'], 'support.view')) {
            Response::forbidden();
        }

        // support_messages і support_telegram_messages видаляються каскадом (ON DELETE CASCADE).
        $pdo->prepare("DELETE FROM support_tickets WHERE id = ?")->execute([$ticketId]);

        Response::ok([], 'Звернення видалено');


    default:
        Response::error("Невідома дія: {$action}", 400);

}} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}
