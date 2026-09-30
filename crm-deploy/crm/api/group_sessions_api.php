<?php
/**
 * group_sessions_api.php — Розклад групових занять
 *
 * Дії (owner/manager, group_sessions.manage):
 *   get_list          — список занять за період/тренером/статусом
 *   get_one           — одне заняття + повний ростер
 *   get_trainers      — активні тренери для вибору при плануванні заняття
 *                        (не потребує trainers.manage — так само, як
 *                        get_checkin_trainers у visits_api.php)
 *   create            — запланувати заняття
 *   update            — редагувати (лише поки status='scheduled')
 *   cancel            — скасувати заняття
 *   add_client        — записати клієнта в ростер
 *   remove_client     — прибрати клієнта з ростеру (лише поки 'booked')
 *   mark_attendance   — відмітити 'attended' (створює реальний visits-рядок
 *                        і списує відвідування з абонемента) або 'no_show'
 *   complete_session  — завершити заняття → нарахувати тренеру комісію
 *
 * Дії (trainer — лише свої заняття):
 *   my_schedule       — власний розклад майбутніх занять
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$sess   = Auth::requireAuth();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';
$pdo    = Database::get();

$clubId = (int)($input['club_id'] ?? $_GET['club_id'] ?? $sess['active_club_id'] ?? 0);
if (!$clubId) Response::error('Не обрано клуб', 400);

$access  = Auth::requireClubAccess($sess, $clubId, 30);
$isOwner = Auth::isOwner($sess, $access);
$userId  = (int)$sess['user_id'];

function myTrainerId(PDO $pdo, int $clubId, int $userId): int {
    $s = $pdo->prepare("SELECT id FROM club_trainers WHERE club_id=? AND user_id=? LIMIT 1");
    $s->execute([$clubId, $userId]);
    return (int)($s->fetchColumn() ?: 0);
}

// Тип заняття, зал і час завершення з форми; перевірка накладок залу/тренера.
function gs_resolveForm(PDO $pdo, int $clubId, array $input, array $base, ?int $excludeId): array {
    $typeId = array_key_exists('class_type_id', $input) ? ((int)$input['class_type_id'] ?: null) : ($base['class_type_id'] ?? null);
    $roomId = array_key_exists('room_id', $input) ? ((int)$input['room_id'] ?: null) : ($base['room_id'] ?? null);
    $type = null;
    if ($typeId) {
        $t = $pdo->prepare("SELECT * FROM class_types WHERE id=? AND club_id=?");
        $t->execute([$typeId, $clubId]);
        $type = $t->fetch();
        if (!$type) Response::error('Тип заняття не знайдено');
    }
    if ($roomId) {
        $r = $pdo->prepare("SELECT id FROM club_rooms WHERE id=? AND club_id=?");
        $r->execute([$roomId, $clubId]);
        if (!$r->fetchColumn()) Response::error('Зал не знайдено');
    }
    return [$typeId, $roomId, $type];
}

function gs_checkConflict(PDO $pdo, int $clubId, string $date, string $start, string $end, int $trainerId, ?int $roomId, ?int $excludeId): void {
    if (strlen($start) === 5) $start .= ':00';
    if (strlen($end) === 5)   $end   .= ':00';
    if ($end <= $start) Response::error('Час завершення має бути пізніше за час початку');
    if ($c = Booking::findConflict($pdo, $clubId, $date, $start, $end, $trainerId, $roomId, $excludeId)) Response::error($c, 409);
}

function loadSession(PDO $pdo, int $clubId, int $sessionId): ?array {
    $s = $pdo->prepare("SELECT * FROM group_sessions WHERE id=? AND club_id=? LIMIT 1");
    $s->execute([$sessionId, $clubId]);
    return $s->fetch() ?: null;
}

try { switch ($action) {

// ════ СПИСОК ЗАНЯТЬ ══════════════════════════════════════════════
case 'get_list':
    if (!Auth::can($sess, $clubId, 'group_sessions.view')) Response::forbidden();

    $dateFrom  = trim($input['date_from']  ?? $_GET['date_from']  ?? date('Y-m-d'));
    $dateTo    = trim($input['date_to']    ?? $_GET['date_to']    ?? date('Y-m-d', strtotime('+30 days')));
    $trainerId = (int)($input['trainer_id'] ?? $_GET['trainer_id'] ?? 0);
    $status    = trim($input['status']      ?? $_GET['status']    ?? '');

    $where  = ['gs.club_id = ?', 'gs.session_date BETWEEN ? AND ?'];
    $params = [$clubId, $dateFrom, $dateTo];
    if ($trainerId) { $where[] = 'gs.trainer_id = ?'; $params[] = $trainerId; }
    $roomId = (int)($input['room_id'] ?? 0);
    if ($roomId) { $where[] = 'gs.room_id = ?'; $params[] = $roomId; }
    $kind = trim($input['kind'] ?? '');
    if (in_array($kind, ['group', 'personal'], true)) { $where[] = 'gs.kind = ?'; $params[] = $kind; }

    // Повторюваний розклад добудовується "ліниво" при перегляді (як autoUnfreezeExpired)
    if (Auth::can($sess, $clubId, 'group_sessions.manage')) Booking::generate($pdo, $clubId);
    if (in_array($status, ['scheduled', 'completed', 'canceled'], true)) {
        $where[] = 'gs.status = ?'; $params[] = $status;
    }

    $stmt = $pdo->prepare("
        SELECT gs.*, u.full_name AS trainer_name,
               r.name AS room_name, t.color AS color,
               (SELECT COUNT(*) FROM group_session_clients gsc
                WHERE gsc.session_id = gs.id AND gsc.status IN ('booked','attended')) AS roster_count,
               (SELECT COUNT(*) FROM group_session_clients gsc
                WHERE gsc.session_id = gs.id AND gsc.status = 'attended') AS attended_count,
               (SELECT COUNT(*) FROM group_session_clients gsc
                WHERE gsc.session_id = gs.id AND gsc.status = 'waitlist') AS waitlist_count,
               (SELECT GROUP_CONCAT(c.full_name SEPARATOR ', ') FROM group_session_clients gsc
                JOIN clients c ON c.id = gsc.client_id
                WHERE gsc.session_id = gs.id AND gs.kind = 'personal' AND gsc.status IN ('booked','attended','no_show')) AS personal_client
        FROM group_sessions gs
        JOIN club_trainers ct ON ct.id = gs.trainer_id
        JOIN sys_users u ON u.id = ct.user_id
        LEFT JOIN club_rooms r ON r.id = gs.room_id
        LEFT JOIN class_types t ON t.id = gs.class_type_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY gs.session_date ASC, gs.start_time ASC
        LIMIT 1000
    ");
    $stmt->execute($params);
    Response::ok(['sessions' => $stmt->fetchAll()]);


// ════ ТРЕНЕРИ ДЛЯ ВИБОРУ ПРИ ПЛАНУВАННІ ══════════════════════════
case 'get_trainers':
    if (!Auth::can($sess, $clubId, 'group_sessions.view')) Response::forbidden();
    $stmt = $pdo->prepare("
        SELECT ct.id, u.full_name
        FROM club_trainers ct
        JOIN sys_users u ON u.id = ct.user_id
        WHERE ct.club_id=? AND ct.is_active=1
        ORDER BY u.full_name
    ");
    $stmt->execute([$clubId]);
    Response::ok(['trainers' => $stmt->fetchAll()]);


// ════ ОДНЕ ЗАНЯТТЯ + РОСТЕР ══════════════════════════════════════
case 'get_one':
    if (!Auth::can($sess, $clubId, 'group_sessions.view')) Response::forbidden();
    $sessionId = (int)($input['session_id'] ?? $_GET['session_id'] ?? 0);
    if (!$sessionId) Response::error('Вкажіть session_id');

    $session = loadSession($pdo, $clubId, $sessionId);
    if (!$session) Response::error('Заняття не знайдено', 404);

    $trStmt = $pdo->prepare("
        SELECT u.full_name FROM club_trainers ct JOIN sys_users u ON u.id=ct.user_id
        WHERE ct.id=? LIMIT 1
    ");
    $trStmt->execute([$session['trainer_id']]);
    $session['trainer_name'] = $trStmt->fetchColumn();
    if ($session['room_id']) {
        $rs = $pdo->prepare("SELECT name FROM club_rooms WHERE id=?");
        $rs->execute([$session['room_id']]);
        $session['room_name'] = $rs->fetchColumn() ?: null;
    }
    $bcfg = Booking::settings($pdo, $clubId);
    $session['cancel_deadline_at'] = date('Y-m-d H:i:s', Booking::sessionStartTs($session) - $bcfg['cancel_deadline_minutes'] * 60);

    $rosterStmt = $pdo->prepare("
        SELECT gsc.id, gsc.client_id, gsc.invoice_id, gsc.visit_id, gsc.status, gsc.checked_in_at,
               gsc.source, gsc.canceled_at, gsc.canceled_by, gsc.created_at,
               c.full_name AS client_name, c.phone AS client_phone,
               ci.tariff_name
        FROM group_session_clients gsc
        JOIN clients c ON c.id = gsc.client_id
        LEFT JOIN client_invoices ci ON ci.id = gsc.invoice_id
        WHERE gsc.session_id = ?
        ORDER BY FIELD(gsc.status,'attended','booked','no_show','waitlist','canceled'), gsc.created_at ASC
    ");
    $rosterStmt->execute([$sessionId]);

    Response::ok(['session' => $session, 'roster' => $rosterStmt->fetchAll()]);


// ════ СТВОРИТИ ЗАНЯТТЯ ═══════════════════════════════════════════
case 'create':
    if (!Auth::can($sess, $clubId, 'group_sessions.manage')) Response::forbidden();

    $trainerId   = (int)($input['trainer_id']   ?? 0);
    $name        = trim($input['name']          ?? '');
    $sessionDate = trim($input['session_date']  ?? '');
    $startTime   = trim($input['start_time']    ?? '');
    $endTime     = trim($input['end_time']      ?? '') ?: null;
    $capacity    = ($input['capacity'] ?? '') !== '' ? max(1, (int)$input['capacity']) : null;
    $notes       = trim($input['notes']         ?? '') ?: null;

    if (!$trainerId)                                          Response::error('Вкажіть тренера');
    if (!$name && !empty($input['class_type_id'])) {
        $tn = $pdo->prepare("SELECT name FROM class_types WHERE id=? AND club_id=?");
        $tn->execute([(int)$input['class_type_id'], $clubId]);
        $name = (string)$tn->fetchColumn();
    }
    if (!$name)                                                Response::error("Вкажіть назву заняття");
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate))    Response::error('Невірний формат дати');
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime))   Response::error('Невірний формат часу початку');

    $chk = $pdo->prepare("SELECT id FROM club_trainers WHERE id=? AND club_id=? AND is_active=1 LIMIT 1");
    $chk->execute([$trainerId, $clubId]);
    if (!$chk->fetchColumn()) Response::error('Тренера не знайдено', 404);

    [$typeId, $roomId, $type] = gs_resolveForm($pdo, $clubId, $input, [], null);
    if (!$endTime) $endTime = Booking::addMinutes(strlen($startTime) === 5 ? "$startTime:00" : $startTime, (int)($type['duration_min'] ?? 60));
    if ($capacity === null && $type && $type['capacity'] !== null) $capacity = (int)$type['capacity'];
    gs_checkConflict($pdo, $clubId, $sessionDate, $startTime, $endTime, $trainerId, $roomId, null);

    $pdo->prepare("
        INSERT INTO group_sessions
            (club_id, kind, class_type_id, trainer_id, room_id, name, session_date, start_time, end_time, capacity, notes, created_by)
        VALUES (?,'group',?,?,?,?,?,?,?,?,?,?)
    ")->execute([$clubId, $typeId, $trainerId, $roomId, $name, $sessionDate, $startTime, $endTime, $capacity, $notes, $userId]);

    Response::ok(['id' => (int)$pdo->lastInsertId()], 'Заняття заплановано');


// ════ РЕДАГУВАТИ ЗАНЯТТЯ ═════════════════════════════════════════
case 'update':
    if (!Auth::can($sess, $clubId, 'group_sessions.manage')) Response::forbidden();
    $sessionId = (int)($input['session_id'] ?? 0);
    if (!$sessionId) Response::error('Вкажіть session_id');

    $session = loadSession($pdo, $clubId, $sessionId);
    if (!$session) Response::error('Заняття не знайдено', 404);
    if ($session['status'] !== 'scheduled') Response::error('Редагувати можна лише заплановане заняття', 409);

    $trainerId   = (int)($input['trainer_id']   ?? $session['trainer_id']);
    $name        = trim($input['name']          ?? $session['name']);
    $sessionDate = trim($input['session_date']  ?? $session['session_date']);
    $startTime   = trim($input['start_time']    ?? $session['start_time']);
    $endTime     = trim($input['end_time']      ?? '') ?: null;
    $capacity    = ($input['capacity'] ?? '') !== '' ? max(1, (int)$input['capacity']) : null;
    $notes       = trim($input['notes']         ?? '') ?: null;

    if (!$name) Response::error("Вкажіть назву заняття");
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate))  Response::error('Невірний формат дати');
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime)) Response::error('Невірний формат часу початку');

    [$typeId, $roomId, $type] = gs_resolveForm($pdo, $clubId, $input, $session, $sessionId);
    if (!$endTime) $endTime = Booking::addMinutes(strlen($startTime) === 5 ? "$startTime:00" : $startTime, (int)($type['duration_min'] ?? 60));
    if ($session['kind'] === 'personal') $capacity = 1;
    gs_checkConflict($pdo, $clubId, $sessionDate, $startTime, $endTime, $trainerId, $roomId, $sessionId);

    $pdo->prepare("
        UPDATE group_sessions
        SET trainer_id=?, class_type_id=?, room_id=?, name=?, session_date=?, start_time=?, end_time=?, capacity=?, notes=?
        WHERE id=?
    ")->execute([$trainerId, $typeId, $roomId, $name, $sessionDate, $startTime, $endTime, $capacity, $notes, $sessionId]);
    // Місткість збільшили — віддаємо звільнені місця листу очікування
    while (Booking::promoteWaitlist($pdo, $clubId, $sessionId)) {}

    Response::ok([], 'Збережено');


// ════ СКАСУВАТИ ЗАНЯТТЯ ══════════════════════════════════════════
case 'cancel':
    if (!Auth::can($sess, $clubId, 'group_sessions.manage')) Response::forbidden();
    $sessionId = (int)($input['session_id'] ?? 0);
    if (!$sessionId) Response::error('Вкажіть session_id');

    $session = loadSession($pdo, $clubId, $sessionId);
    if (!$session) Response::error('Заняття не знайдено', 404);
    if ($session['status'] === 'completed') Response::error('Завершене заняття скасувати не можна', 409);

    $affected = $pdo->prepare("SELECT client_id FROM group_session_clients WHERE session_id=? AND status IN ('booked','waitlist')");
    $affected->execute([$sessionId]);
    $affectedIds = $affected->fetchAll(PDO::FETCH_COLUMN);

    $pdo->prepare("UPDATE group_sessions SET status='canceled' WHERE id=?")->execute([$sessionId]);
    $pdo->prepare("
        UPDATE group_session_clients SET status='canceled', canceled_at=NOW(), canceled_by='club'
        WHERE session_id=? AND status IN ('booked','waitlist')
    ")->execute([$sessionId]);
    BookingBot::notifySessionCanceled($pdo, $sessionId, $affectedIds);
    Response::ok([], 'Заняття скасовано');


// ════ ЗАПИСАТИ КЛІЄНТА В РОСТЕР ══════════════════════════════════
case 'add_client':
    if (!Auth::can($sess, $clubId, 'group_sessions.manage')) Response::forbidden();
    $sessionId = (int)($input['session_id'] ?? 0);
    $clientId  = (int)($input['client_id']  ?? 0);
    if (!$sessionId || !$clientId) Response::error('Вкажіть session_id і client_id');

    try {
        $res = Booking::book($pdo, $clubId, $sessionId, $clientId, 'staff', 'admin', $userId);
    } catch (BookingException $e) {
        Response::error($e->getMessage(), 409);
    }
    Response::ok(
        ['id' => $res['roster_id'], 'invoice_id' => $res['invoice_id'], 'status' => $res['status']],
        $res['status'] === 'waitlist' ? 'Місць немає — клієнта додано в лист очікування' : 'Клієнта записано'
    );


// ════ ПРИБРАТИ КЛІЄНТА З РОСТЕРУ ═════════════════════════════════
case 'remove_client':
    if (!Auth::can($sess, $clubId, 'group_sessions.manage')) Response::forbidden();
    $rosterId = (int)($input['roster_id'] ?? 0);
    if (!$rosterId) Response::error('Вкажіть roster_id');

    // Після дедлайну скасування заборонене всім, крім власника (force)
    $force = !empty($input['force']) && $isOwner;
    try {
        $res = Booking::cancel($pdo, $clubId, $rosterId, 'staff', $force);
    } catch (BookingException $e) {
        Response::error($e->getMessage(), 409, ['can_force' => $isOwner]);
    }
    Response::ok($res, $res['promoted_client_id'] ? 'Запис скасовано, місце отримав перший з листа очікування' : 'Запис скасовано');


// ════ ВІДМІТИТИ ПРИСУТНІСТЬ ══════════════════════════════════════
case 'mark_attendance':
    if (!Auth::can($sess, $clubId, 'group_sessions.manage')) Response::forbidden();
    $rosterId = (int)($input['roster_id'] ?? 0);
    $newStatus = trim($input['status'] ?? '');
    if (!$rosterId) Response::error('Вкажіть roster_id');
    if (!in_array($newStatus, ['attended', 'no_show'], true)) Response::error('status має бути attended або no_show');

    $rStmt = $pdo->prepare("
        SELECT gsc.id, gsc.client_id, gsc.invoice_id, gsc.status AS current_status,
               gs.id AS session_id, gs.trainer_id, gs.status AS session_status, gs.kind
        FROM group_session_clients gsc
        JOIN group_sessions gs ON gs.id = gsc.session_id
        WHERE gsc.id=? AND gs.club_id=? LIMIT 1
    ");
    $rStmt->execute([$rosterId, $clubId]);
    $roster = $rStmt->fetch();
    if (!$roster) Response::error('Запис не знайдено', 404);
    if ($roster['session_status'] !== 'scheduled') Response::error('Заняття вже завершене або скасоване', 409);
    if ($roster['current_status'] === 'attended') Response::error('Присутність уже відмічено', 409);
    if (in_array($roster['current_status'], ['waitlist', 'canceled'], true)) Response::error('Клієнт не записаний на заняття (лист очікування / скасовано)', 409);

    if ($newStatus === 'attended') {
        $trStmt = $pdo->prepare("
            SELECT u.full_name FROM club_trainers ct JOIN sys_users u ON u.id=ct.user_id
            WHERE ct.id=? LIMIT 1
        ");
        $trStmt->execute([$roster['trainer_id']]);
        $trainerName = $trStmt->fetchColumn() ?: null;

        // method='manual' — той самий, перевірений тег, що й для ручної
        // відмітки в visits_api.php; провенанс "це було групове заняття"
        // і так однозначно видно через group_session_clients.visit_id
        // (не ризикуємо новим значенням, якщо visits.method — ENUM у БД).
        $visitId = Attendance::recordVisit(
            $pdo, $clubId, (int)$roster['client_id'],
            $roster['invoice_id'] ?: null, (int)$roster['trainer_id'], $trainerName,
            'manual', $sess
        );

        $pdo->prepare("
            UPDATE group_session_clients
            SET status='attended', visit_id=?, checked_in_at=NOW()
            WHERE id=?
        ")->execute([$visitId, $rosterId]);

        // Персональне тренування: нарахування тренеру — як за персональне відвідування,
        // і заняття одразу завершується (у ньому лише один клієнт).
        if ($roster['kind'] === 'personal') {
            Attendance::createTrainerEarning($pdo, $clubId, $visitId, $roster['invoice_id'] ? (int)$roster['invoice_id'] : null, (int)$roster['trainer_id']);
            $pdo->prepare("UPDATE group_sessions SET status='completed' WHERE id=?")->execute([$roster['session_id']]);
        }

        Response::ok(['visit_id' => $visitId], 'Присутність відмічено');
    } else {
        $pdo->prepare("UPDATE group_session_clients SET status='no_show' WHERE id=?")->execute([$rosterId]);
        Response::ok([], 'Позначено як "не прийшов"');
    }


// ════ ЗАВЕРШИТИ ЗАНЯТТЯ ══════════════════════════════════════════
case 'complete_session':
    if (!Auth::can($sess, $clubId, 'group_sessions.manage')) Response::forbidden();
    $sessionId = (int)($input['session_id'] ?? 0);
    if (!$sessionId) Response::error('Вкажіть session_id');

    $session = loadSession($pdo, $clubId, $sessionId);
    if (!$session) Response::error('Заняття не знайдено', 404);
    if ($session['status'] !== 'scheduled') Response::error('Заняття вже завершене або скасоване', 409);

    $pdo->prepare("UPDATE group_sessions SET status='completed' WHERE id=?")->execute([$sessionId]);
    if ($session['kind'] !== 'personal') Attendance::createGroupSessionEarning($pdo, $clubId, $sessionId);

    Response::ok([], 'Заняття завершено, нарахування тренеру зафіксовано');


// ════ МІЙ РОЗКЛАД (тренер) ═══════════════════════════════════════
case 'my_schedule':
    $tid = myTrainerId($pdo, $clubId, $userId);
    if (!$tid) Response::error('Профіль тренера не знайдено', 404);

    $stmt = $pdo->prepare("
        SELECT gs.*,
               (SELECT COUNT(*) FROM group_session_clients gsc
                WHERE gsc.session_id = gs.id AND gsc.status IN ('booked','attended')) AS roster_count
        FROM group_sessions gs
        WHERE gs.trainer_id = ? AND gs.club_id = ? AND gs.session_date >= CURDATE()
          AND gs.status = 'scheduled'
        ORDER BY gs.session_date ASC, gs.start_time ASC
        LIMIT 100
    ");
    $stmt->execute([$tid, $clubId]);
    Response::ok(['sessions' => $stmt->fetchAll()]);


default:
    Response::error("Невідома дія: {$action}", 400);

}} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}
