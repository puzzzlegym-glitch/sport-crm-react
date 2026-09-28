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

Auth::requireClubAccess($sess, $clubId, 30);
// Заморозки: вмикаємо заплановані / знімаємо завершені перед перевіркою абонемента.
Recalc::autoUnfreezeExpired($pdo, $clubId);
$userId = (int)$sess['user_id'];

function myTrainerId(PDO $pdo, int $clubId, int $userId): int {
    $s = $pdo->prepare("SELECT id FROM club_trainers WHERE club_id=? AND user_id=? LIMIT 1");
    $s->execute([$clubId, $userId]);
    return (int)($s->fetchColumn() ?: 0);
}

function loadSession(PDO $pdo, int $clubId, int $sessionId): ?array {
    $s = $pdo->prepare("SELECT * FROM group_sessions WHERE id=? AND club_id=? LIMIT 1");
    $s->execute([$sessionId, $clubId]);
    return $s->fetch() ?: null;
}

/** Пояснення, чому групове заняття не списано з абонемента (null-invoice). */
function groupNoInvoiceWarning(PDO $pdo, int $clubId, int $clientId): string {
    return Attendance::hasOtherActiveInvoice($pdo, $clubId, $clientId)
        ? 'Немає абонемента на групові заняття (персональний чи «лише зал» їх не покриває) — заняття не списано з абонемента.'
        : 'Немає активного абонемента на групові заняття — заняття не списано з абонемента.';
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
    if (in_array($status, ['scheduled', 'completed', 'canceled'], true)) {
        $where[] = 'gs.status = ?'; $params[] = $status;
    }

    $stmt = $pdo->prepare("
        SELECT gs.*, u.full_name AS trainer_name,
               (SELECT COUNT(*) FROM group_session_clients gsc
                WHERE gsc.session_id = gs.id AND gsc.status IN ('booked','attended')) AS roster_count,
               (SELECT COUNT(*) FROM group_session_clients gsc
                WHERE gsc.session_id = gs.id AND gsc.status = 'attended') AS attended_count
        FROM group_sessions gs
        JOIN club_trainers ct ON ct.id = gs.trainer_id
        JOIN sys_users u ON u.id = ct.user_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY gs.session_date ASC, gs.start_time ASC
        LIMIT 300
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

    $rosterStmt = $pdo->prepare("
        SELECT gsc.id, gsc.client_id, gsc.invoice_id, gsc.visit_id, gsc.status, gsc.checked_in_at,
               c.full_name AS client_name, c.phone AS client_phone,
               ci.tariff_name
        FROM group_session_clients gsc
        JOIN clients c ON c.id = gsc.client_id
        LEFT JOIN client_invoices ci ON ci.id = gsc.invoice_id
        WHERE gsc.session_id = ?
        ORDER BY gsc.created_at ASC
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
    if (!$name)                                                Response::error("Вкажіть назву заняття");
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate))    Response::error('Невірний формат дати');
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime))   Response::error('Невірний формат часу початку');

    $chk = $pdo->prepare("SELECT id FROM club_trainers WHERE id=? AND club_id=? AND is_active=1 LIMIT 1");
    $chk->execute([$trainerId, $clubId]);
    if (!$chk->fetchColumn()) Response::error('Тренера не знайдено', 404);

    $pdo->prepare("
        INSERT INTO group_sessions
            (club_id, trainer_id, name, session_date, start_time, end_time, capacity, notes, created_by)
        VALUES (?,?,?,?,?,?,?,?,?)
    ")->execute([$clubId, $trainerId, $name, $sessionDate, $startTime, $endTime, $capacity, $notes, $userId]);

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

    $pdo->prepare("
        UPDATE group_sessions
        SET trainer_id=?, name=?, session_date=?, start_time=?, end_time=?, capacity=?, notes=?
        WHERE id=?
    ")->execute([$trainerId, $name, $sessionDate, $startTime, $endTime, $capacity, $notes, $sessionId]);

    Response::ok([], 'Збережено');


// ════ СКАСУВАТИ ЗАНЯТТЯ ══════════════════════════════════════════
case 'cancel':
    if (!Auth::can($sess, $clubId, 'group_sessions.manage')) Response::forbidden();
    $sessionId = (int)($input['session_id'] ?? 0);
    if (!$sessionId) Response::error('Вкажіть session_id');

    $session = loadSession($pdo, $clubId, $sessionId);
    if (!$session) Response::error('Заняття не знайдено', 404);
    if ($session['status'] === 'completed') Response::error('Завершене заняття скасувати не можна', 409);

    $pdo->prepare("UPDATE group_sessions SET status='canceled' WHERE id=?")->execute([$sessionId]);
    Response::ok([], 'Заняття скасовано');


// ════ ЗАПИСАТИ КЛІЄНТА В РОСТЕР ══════════════════════════════════
case 'add_client':
    if (!Auth::can($sess, $clubId, 'group_sessions.manage')) Response::forbidden();
    $sessionId = (int)($input['session_id'] ?? 0);
    $clientId  = (int)($input['client_id']  ?? 0);
    if (!$sessionId || !$clientId) Response::error('Вкажіть session_id і client_id');

    $session = loadSession($pdo, $clubId, $sessionId);
    if (!$session) Response::error('Заняття не знайдено', 404);
    if ($session['status'] !== 'scheduled') Response::error('Можна записувати лише на заплановане заняття', 409);

    $cStmt = $pdo->prepare("SELECT id, status FROM clients WHERE id=? AND club_id=? LIMIT 1");
    $cStmt->execute([$clientId, $clubId]);
    $client = $cStmt->fetch();
    if (!$client) Response::error('Клієнта не знайдено', 404);
    if ($client['status'] === 'blocked') Response::error('Клієнт заблокований', 403);

    if ($session['capacity']) {
        $cntStmt = $pdo->prepare("
            SELECT COUNT(*) FROM group_session_clients
            WHERE session_id=? AND status IN ('booked','attended')
        ");
        $cntStmt->execute([$sessionId]);
        if ((int)$cntStmt->fetchColumn() >= (int)$session['capacity']) {
            Response::error('Заняття заповнене — місткість вичерпано', 409);
        }
    }

    // Групове заняття покривають лише «Групові» / «Універсальний» — персональний НІКОЛИ.
    $invoice   = Attendance::findActiveInvoice($pdo, $clubId, $clientId, 'group');
    $invoiceId = $invoice['id'] ?? null;

    try {
        $pdo->prepare("
            INSERT INTO group_session_clients (session_id, client_id, invoice_id, status, created_by)
            VALUES (?,?,?,'booked',?)
        ")->execute([$sessionId, $clientId, $invoiceId, $userId]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') Response::error('Клієнт вже записаний на це заняття', 409);
        throw $e;
    }

    $rosterId = (int)$pdo->lastInsertId(); // до наступного запиту — SELECT скидає lastInsertId
    $warning  = $invoiceId ? null : groupNoInvoiceWarning($pdo, $clubId, $clientId);
    Response::ok(
        ['id' => $rosterId, 'invoice_id' => $invoiceId, 'warning' => $warning],
        $warning ? "Клієнта додано. ⚠ $warning" : 'Клієнта додано до заняття'
    );


// ════ ПРИБРАТИ КЛІЄНТА З РОСТЕРУ ═════════════════════════════════
case 'remove_client':
    if (!Auth::can($sess, $clubId, 'group_sessions.manage')) Response::forbidden();
    $rosterId = (int)($input['roster_id'] ?? 0);
    if (!$rosterId) Response::error('Вкажіть roster_id');

    $rStmt = $pdo->prepare("
        SELECT gsc.id, gsc.status FROM group_session_clients gsc
        JOIN group_sessions gs ON gs.id = gsc.session_id
        WHERE gsc.id=? AND gs.club_id=? LIMIT 1
    ");
    $rStmt->execute([$rosterId, $clubId]);
    $roster = $rStmt->fetch();
    if (!$roster) Response::error('Запис не знайдено', 404);
    if ($roster['status'] !== 'booked') Response::error('Прибрати можна лише клієнта зі статусом "booked"', 409);

    $pdo->prepare("DELETE FROM group_session_clients WHERE id=?")->execute([$rosterId]);
    Response::ok([], 'Клієнта прибрано із заняття');


// ════ ВІДМІТИТИ ПРИСУТНІСТЬ ══════════════════════════════════════
case 'mark_attendance':
    if (!Auth::can($sess, $clubId, 'group_sessions.manage')) Response::forbidden();
    $rosterId = (int)($input['roster_id'] ?? 0);
    $newStatus = trim($input['status'] ?? '');
    if (!$rosterId) Response::error('Вкажіть roster_id');
    if (!in_array($newStatus, ['attended', 'no_show'], true)) Response::error('status має бути attended або no_show');

    $rStmt = $pdo->prepare("
        SELECT gsc.id, gsc.client_id, gsc.invoice_id, gsc.status AS current_status,
               gs.id AS session_id, gs.trainer_id, gs.status AS session_status
        FROM group_session_clients gsc
        JOIN group_sessions gs ON gs.id = gsc.session_id
        WHERE gsc.id=? AND gs.club_id=? LIMIT 1
    ");
    $rStmt->execute([$rosterId, $clubId]);
    $roster = $rStmt->fetch();
    if (!$roster) Response::error('Запис не знайдено', 404);
    if ($roster['session_status'] !== 'scheduled') Response::error('Заняття вже завершене або скасоване', 409);
    if ($roster['current_status'] === 'attended') Response::error('Присутність уже відмічено', 409);

    if ($newStatus === 'attended') {
        $trStmt = $pdo->prepare("
            SELECT u.full_name FROM club_trainers ct JOIN sys_users u ON u.id=ct.user_id
            WHERE ct.id=? LIMIT 1
        ");
        $trStmt->execute([$roster['trainer_id']]);
        $trainerName = $trStmt->fetchColumn() ?: null;

        // Абонемент шукаємо заново на момент заняття: записаний при бронюванні міг
        // закінчитись/замерзнути, а клієнт міг докупити новий.
        $invoice = Attendance::findActiveInvoice($pdo, $clubId, (int)$roster['client_id'], 'group');
        if ($invoice && $invoice['visits_total'] && $invoice['visits_used'] >= $invoice['visits_total']) {
            $invoice = null;
        }
        $roster['invoice_id'] = $invoice['id'] ?? null;
        $warning = $invoice ? null : groupNoInvoiceWarning($pdo, $clubId, (int)$roster['client_id']);

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
            SET status='attended', visit_id=?, invoice_id=?, checked_in_at=NOW()
            WHERE id=?
        ")->execute([$visitId, $roster['invoice_id'], $rosterId]);

        Response::ok(
            ['visit_id' => $visitId, 'invoice_id' => $roster['invoice_id'], 'warning' => $warning],
            $warning ? "Присутність відмічено. ⚠ $warning" : 'Присутність відмічено'
        );
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
    Attendance::createGroupSessionEarning($pdo, $clubId, $sessionId);

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
