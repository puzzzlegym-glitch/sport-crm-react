<?php
/**
 * booking_api.php — налаштування запису на тренування (CRM).
 *
 * Перегляд (group_sessions.view):
 *   get_setup          — все для форм: правила, зали, типи занять, шаблони, тренери, тарифи
 *   slots              — вільні години тренера на дату (персональні)
 * Керування (group_sessions.manage):
 *   save_settings      — правила запису/скасування
 *   room_save          — створити/змінити зал (is_active=0 — вимкнути)
 *   type_save          — створити/змінити тип заняття (+ дозволені тарифи)
 *   template_save      — створити/змінити шаблон розкладу (перебудовує майбутні заняття)
 *   template_delete    — вимкнути шаблон (порожні майбутні заняття видаляються)
 *   availability_save  — робочі години тренера (повна заміна)
 *   generate           — добудувати розклад з шаблонів
 *   book_personal      — записати клієнта на персональне тренування
 *
 * Уся логіка правил — app/core/Booking.php.
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
$userId = (int)$sess['user_id'];

$canView   = Auth::can($sess, $clubId, 'group_sessions.view');
$canManage = Auth::can($sess, $clubId, 'group_sessions.manage');
if (!$canView) Response::forbidden();
if (!in_array($action, ['get_setup', 'slots'], true) && !$canManage) Response::forbidden();

function bk_time(string $t): string {
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t)) Response::error('Невірний формат часу');
    return strlen($t) === 5 ? "$t:00" : $t;
}
function bk_owned(PDO $pdo, string $table, int $id, int $clubId): void {
    $st = $pdo->prepare("SELECT 1 FROM {$table} WHERE id=? AND club_id=?");
    $st->execute([$id, $clubId]);
    if (!$st->fetchColumn()) Response::error('Запис не знайдено', 404);
}

try { switch ($action) {

case 'get_setup':
    $rooms = $pdo->prepare("SELECT * FROM club_rooms WHERE club_id=? ORDER BY is_active DESC, sort_order, name");
    $rooms->execute([$clubId]);

    $types = $pdo->prepare("SELECT * FROM class_types WHERE club_id=? ORDER BY is_active DESC, kind, sort_order, name");
    $types->execute([$clubId]);
    $types = $types->fetchAll();
    $tt = $pdo->prepare("SELECT ctt.class_type_id, ctt.tariff_id FROM class_type_tariffs ctt JOIN class_types ct ON ct.id=ctt.class_type_id WHERE ct.club_id=?");
    $tt->execute([$clubId]);
    $map = [];
    foreach ($tt->fetchAll() as $r) $map[(int)$r['class_type_id']][] = (int)$r['tariff_id'];
    foreach ($types as &$t) $t['tariff_ids'] = $map[(int)$t['id']] ?? [];
    unset($t);

    $tpl = $pdo->prepare("
        SELECT st.*, ct.name AS type_name, ct.color, u.full_name AS trainer_name, r.name AS room_name
        FROM schedule_templates st
        JOIN class_types ct ON ct.id = st.class_type_id
        JOIN club_trainers tr ON tr.id = st.trainer_id
        JOIN sys_users u ON u.id = tr.user_id
        LEFT JOIN club_rooms r ON r.id = st.room_id
        WHERE st.club_id=? AND st.is_active=1
        ORDER BY st.weekday, st.start_time
    ");
    $tpl->execute([$clubId]);

    $trainers = $pdo->prepare("
        SELECT ct.id, u.full_name FROM club_trainers ct JOIN sys_users u ON u.id=ct.user_id
        WHERE ct.club_id=? AND ct.is_active=1 ORDER BY u.full_name
    ");
    $trainers->execute([$clubId]);

    $av = $pdo->prepare("SELECT * FROM trainer_availability WHERE club_id=? ORDER BY trainer_id, weekday, start_time");
    $av->execute([$clubId]);

    $tariffs = $pdo->prepare("SELECT id, name FROM tariffs WHERE club_id=? AND is_active=1 ORDER BY sort_order, name");
    $tariffs->execute([$clubId]);

    Response::ok([
        'settings'     => Booking::settings($pdo, $clubId),
        'rooms'        => $rooms->fetchAll(),
        'types'        => $types,
        'templates'    => $tpl->fetchAll(),
        'trainers'     => $trainers->fetchAll(),
        'availability' => $av->fetchAll(),
        'tariffs'      => $tariffs->fetchAll(),
    ]);


case 'save_settings':
    Response::ok(['settings' => Booking::saveSettings($pdo, $clubId, $input['settings'] ?? [])], 'Правила збережено');


case 'room_save':
    $id   = (int)($input['id'] ?? 0);
    $name = trim($input['name'] ?? '');
    if ($name === '') Response::error('Вкажіть назву залу');
    $vals = [
        $name,
        ($input['capacity'] ?? '') !== '' ? max(1, (int)$input['capacity']) : null,
        trim($input['color'] ?? '') ?: null,
        isset($input['is_active']) ? ((int)$input['is_active'] ? 1 : 0) : 1,
        (int)($input['sort_order'] ?? 0),
    ];
    if ($id) {
        bk_owned($pdo, 'club_rooms', $id, $clubId);
        $pdo->prepare("UPDATE club_rooms SET name=?, capacity=?, color=?, is_active=?, sort_order=? WHERE id=?")
            ->execute(array_merge($vals, [$id]));
    } else {
        $pdo->prepare("INSERT INTO club_rooms (name, capacity, color, is_active, sort_order, club_id) VALUES (?,?,?,?,?,?)")
            ->execute(array_merge($vals, [$clubId]));
        $id = (int)$pdo->lastInsertId();
    }
    Response::ok(['id' => $id], 'Зал збережено');


case 'type_save':
    $id   = (int)($input['id'] ?? 0);
    $name = trim($input['name'] ?? '');
    $kind = ($input['kind'] ?? 'group') === 'personal' ? 'personal' : 'group';
    if ($name === '') Response::error('Вкажіть назву');
    $duration = max(5, min(600, (int)($input['duration_min'] ?? 60)));
    $capacity = $kind === 'personal' ? 1 : (($input['capacity'] ?? '') !== '' ? max(1, (int)$input['capacity']) : null);
    $roomId   = (int)($input['default_room_id'] ?? 0) ?: null;
    if ($roomId) bk_owned($pdo, 'club_rooms', $roomId, $clubId);
    $vals = [
        $name, $kind, $duration, $capacity, trim($input['color'] ?? '') ?: null, $roomId,
        trim($input['description'] ?? '') ?: null,
        isset($input['is_active']) ? ((int)$input['is_active'] ? 1 : 0) : 1,
    ];

    $pdo->beginTransaction();
    try {
        if ($id) {
            bk_owned($pdo, 'class_types', $id, $clubId);
            $pdo->prepare("UPDATE class_types SET name=?, kind=?, duration_min=?, capacity=?, color=?, default_room_id=?, description=?, is_active=? WHERE id=?")
                ->execute(array_merge($vals, [$id]));
        } else {
            $pdo->prepare("INSERT INTO class_types (name, kind, duration_min, capacity, color, default_room_id, description, is_active, club_id) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute(array_merge($vals, [$clubId]));
            $id = (int)$pdo->lastInsertId();
        }
        $pdo->prepare("DELETE FROM class_type_tariffs WHERE class_type_id=?")->execute([$id]);
        $chk = $pdo->prepare("SELECT 1 FROM tariffs WHERE id=? AND club_id=?");
        $ins = $pdo->prepare("INSERT INTO class_type_tariffs (class_type_id, tariff_id) VALUES (?,?)");
        foreach (array_unique(array_map('intval', (array)($input['tariff_ids'] ?? []))) as $tid) {
            $chk->execute([$tid, $clubId]);
            if ($chk->fetchColumn()) $ins->execute([$id, $tid]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    Response::ok(['id' => $id], 'Тип заняття збережено');


case 'template_save':
    $id        = (int)($input['id'] ?? 0);
    $typeId    = (int)($input['class_type_id'] ?? 0);
    $trainerId = (int)($input['trainer_id'] ?? 0);
    $roomId    = (int)($input['room_id'] ?? 0) ?: null;
    $weekdays  = array_values(array_unique(array_filter(array_map('intval', (array)($input['weekdays'] ?? [$input['weekday'] ?? 0])), fn($d) => $d >= 1 && $d <= 7)));
    $start     = bk_time(trim($input['start_time'] ?? ''));
    $validFrom = trim($input['valid_from'] ?? date('Y-m-d'));
    $validTo   = trim($input['valid_to'] ?? '') ?: null;
    if (!$typeId)    Response::error('Оберіть тип заняття');
    if (!$trainerId) Response::error('Оберіть тренера');
    if (!$weekdays)  Response::error('Оберіть день тижня');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom)) Response::error('Невірна дата початку дії');
    if ($validTo && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validTo)) Response::error('Невірна дата завершення дії');
    bk_owned($pdo, 'class_types', $typeId, $clubId);
    bk_owned($pdo, 'club_trainers', $trainerId, $clubId);
    if ($roomId) bk_owned($pdo, 'club_rooms', $roomId, $clubId);
    $kindSt = $pdo->prepare("SELECT kind FROM class_types WHERE id=?");
    $kindSt->execute([$typeId]);
    if ($kindSt->fetchColumn() === 'personal') Response::error('Для персональних тренувань задайте графік тренера, а не розклад');

    $duration = ($input['duration_min'] ?? '') !== '' ? max(5, (int)$input['duration_min']) : null;
    $capacity = ($input['capacity'] ?? '') !== '' ? max(1, (int)$input['capacity']) : null;

    $ids = [];
    if ($id) {
        bk_owned($pdo, 'schedule_templates', $id, $clubId);
        $pdo->prepare("
            UPDATE schedule_templates SET class_type_id=?, trainer_id=?, room_id=?, weekday=?, start_time=?,
                duration_min=?, capacity=?, valid_from=?, valid_to=? WHERE id=?
        ")->execute([$typeId, $trainerId, $roomId, $weekdays[0], $start, $duration, $capacity, $validFrom, $validTo, $id]);
        Booking::dropFutureEmpty($pdo, $clubId, $id);
        $ids[] = $id;
    } else {
        // Кілька днів тижня за раз ("Пн, Ср, Пт о 19:00") — окремий шаблон на кожен день
        $ins = $pdo->prepare("
            INSERT INTO schedule_templates
                (club_id, class_type_id, trainer_id, room_id, weekday, start_time, duration_min, capacity, valid_from, valid_to, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");
        foreach ($weekdays as $wd) {
            $ins->execute([$clubId, $typeId, $trainerId, $roomId, $wd, $start, $duration, $capacity, $validFrom, $validTo, $userId]);
            $ids[] = (int)$pdo->lastInsertId();
        }
    }
    $created = 0; $conflicts = [];
    foreach ($ids as $tid) {
        $g = Booking::generate($pdo, $clubId, null, $tid);
        $created += $g['created'];
        $conflicts = array_merge($conflicts, $g['conflicts']);
    }
    $msg = "Розклад збережено, створено занять: {$created}";
    if ($conflicts) $msg .= '. Пропущено через накладки: ' . count($conflicts);
    Response::ok(['created' => $created, 'conflicts' => $conflicts], $msg);


case 'template_delete':
    $id = (int)($input['id'] ?? 0);
    bk_owned($pdo, 'schedule_templates', $id, $clubId);
    $pdo->prepare("UPDATE schedule_templates SET is_active=0 WHERE id=?")->execute([$id]);
    $dropped = Booking::dropFutureEmpty($pdo, $clubId, $id);
    Response::ok(['dropped' => $dropped], "Шаблон вимкнено. Прибрано майбутніх порожніх занять: {$dropped}. Заняття, на які вже є записи, лишились — скасуйте їх вручну за потреби.");


case 'availability_save':
    $trainerId = (int)($input['trainer_id'] ?? 0);
    bk_owned($pdo, 'club_trainers', $trainerId, $clubId);
    $rows = [];
    foreach ((array)($input['rows'] ?? []) as $r) {
        $wd = (int)($r['weekday'] ?? 0);
        if ($wd < 1 || $wd > 7) Response::error('Невірний день тижня');
        $s = bk_time(trim($r['start_time'] ?? ''));
        $e = bk_time(trim($r['end_time'] ?? ''));
        if ($e <= $s) Response::error('Кінець робочого часу має бути пізніше за початок');
        $room = (int)($r['room_id'] ?? 0) ?: null;
        if ($room) bk_owned($pdo, 'club_rooms', $room, $clubId);
        $rows[] = [$wd, $s, $e, $room];
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM trainer_availability WHERE club_id=? AND trainer_id=?")->execute([$clubId, $trainerId]);
        $ins = $pdo->prepare("INSERT INTO trainer_availability (club_id, trainer_id, weekday, start_time, end_time, room_id) VALUES (?,?,?,?,?,?)");
        foreach ($rows as [$wd, $s, $e, $room]) $ins->execute([$clubId, $trainerId, $wd, $s, $e, $room]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    Response::ok([], 'Графік тренера збережено');


case 'generate':
    $g = Booking::generate($pdo, $clubId);
    Response::ok($g, "Створено занять: {$g['created']}" . ($g['conflicts'] ? '. Пропущено через накладки: ' . count($g['conflicts']) : ''));


case 'slots':
    $trainerId = (int)($input['trainer_id'] ?? 0);
    $typeId    = (int)($input['class_type_id'] ?? 0);
    $date      = trim($input['date'] ?? '');
    if (!$trainerId || !$typeId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) Response::error('Оберіть тип, тренера і дату');
    $t = $pdo->prepare("SELECT duration_min FROM class_types WHERE id=? AND club_id=?");
    $t->execute([$typeId, $clubId]);
    $dur = (int)$t->fetchColumn();
    if (!$dur) Response::error('Тип заняття не знайдено');
    Response::ok(['slots' => Booking::availableSlots($pdo, $clubId, $trainerId, $date, $dur)]);


case 'book_personal':
    $pdo->beginTransaction();
    try {
        $res = Booking::bookPersonal(
            $pdo, $clubId,
            (int)($input['class_type_id'] ?? 0), (int)($input['trainer_id'] ?? 0),
            trim($input['date'] ?? ''), trim($input['start_time'] ?? ''),
            (int)($input['client_id'] ?? 0), 'staff', 'admin', $userId,
            (int)($input['room_id'] ?? 0) ?: null
        );
        $pdo->commit();
    } catch (BookingException $e) {
        $pdo->rollBack();
        Response::error($e->getMessage(), 409);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    Response::ok($res, 'Клієнта записано на персональне тренування');


default:
    Response::error("Невідома дія: {$action}", 400);

}} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}
