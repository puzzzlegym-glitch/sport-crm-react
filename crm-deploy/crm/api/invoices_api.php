<?php
/**
 * invoices_api.php — API модуля "Абонементи"
 *
 * Дії:
 *   get_list        — список абонементів з фільтрами
 *   get_one         — один абонемент + платежі по ньому
 *   create          — продати абонемент клієнту
 *   update          — редагувати абонемент
 *   cancel          — скасувати абонемент
 *   freeze          — заморозити / розморозити
 *   add_payment     — додати платіж (часткова оплата)
 *   get_payments    — платежі клубу (для фінансового звіту)
 *   get_stats       — статистика для дашборду
 *   get_tariffs     — список тарифів для форми продажу
 *
 * Права:
 *   Читати     → manager (50+)
 *   Продавати  → manager (50+)
 *   Скасовувати→ owner (80+)
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

$access   = Auth::requireClubAccess($sess, $clubId, 30); // тренер теж допускається
$roleLevel = (int)($access['level'] ?? 0);
$isTrainer = $roleLevel < 50 && $roleLevel >= 30;
$isOwner   = Auth::isOwner($sess, $access);
$userId    = (int)$sess['user_id'];

// Якщо запланований термін заморозки вже минув, а адмін не розморозив
// вручну — знімаємо заморозку автоматично, інакше абонемент "зависає"
// замороженим назавжди (див. Recalc::autoUnfreezeExpired).
Recalc::autoUnfreezeExpired($pdo, $clubId);

// Реальний статус абонементу — рахується з дат/відвідувань, а не зі збереженої
// колонки status (вона не оновлюється кроном і "зависає" на active після end_date).
// Статуси: future / active / frozen / finished / cancelled + pending ("Очікує оплати" —
// абонемент учасника групи, який ще не вніс мінімальну оплату, див. min_paid_to_activate).
// frozen/cancelled лишаються ручними станами (керуються діями freeze/cancel/restore).
$EFFECTIVE_STATUS_SQL = "(CASE
    WHEN ci.status = 'cancelled' THEN 'cancelled'
    WHEN ci.status = 'frozen' THEN 'frozen'
    WHEN ci.min_paid_to_activate IS NOT NULL AND ci.paid_amount < ci.min_paid_to_activate THEN 'pending'
    WHEN ci.start_date > CURDATE() THEN 'future'
    WHEN ci.end_date < CURDATE() THEN 'finished'
    WHEN ci.visits_total IS NOT NULL AND ci.visits_used >= ci.visits_total THEN 'finished'
    ELSE 'active'
END)";

// ── Хелпер: перевірка зміни для cash-операцій ────────────────
function inv_requireCashShift(PDO $pdo, int $clubId, int $userId, bool $isOwner): ?int {
    $st = $pdo->prepare("SELECT * FROM cash_shifts WHERE club_id=? AND status='open' LIMIT 1");
    $st->execute([$clubId]);
    $shift = $st->fetch();
    if ($isOwner) return $shift ? (int)$shift['id'] : null; // owner — без блокування
    if (!$shift) Response::error('Зміна не відкрита. Відкрийте зміну перед продажем.', 403);
    if ((int)$shift['opened_by'] !== $userId)
        Response::error("Зараз відкрита зміна адміністратора «{$shift['opened_name']}». Ви не можете проводити операції в чужій зміні.", 403);
    return (int)$shift['id'];
}

// ── Хелпер: тип продажу (new / renewal / return) ─────────────
function inv_saleType(PDO $pdo, int $clubId, int $clientId, string $effectiveStatusSql): string {
    $st = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM({$effectiveStatusSql} IN ('active','frozen')) AS has_active
        FROM client_invoices ci
        WHERE ci.client_id = ? AND ci.club_id = ?
    ");
    $st->execute([$clientId, $clubId]);
    $prev = $st->fetch();
    if ((int)$prev['total'] === 0) return 'new';
    return ((int)$prev['has_active'] > 0) ? 'renewal' : 'return';
}

// ── Хелпер: скільки занять з тренером дає тариф (null = тренера призначати не можна) ──
// Тариф на N відвідувань → N; безлімітний → tariffs.trainer_sessions (обов'язково).
function inv_trainerSessions(array $tariff): ?int {
    if (empty($tariff['has_trainer'])) return null;
    if (!empty($tariff['trainer_sessions'])) return (int)$tariff['trainer_sessions']; // змішаний тариф / безліміт
    return !empty($tariff['visits_limit']) ? (int)$tariff['visits_limit'] : null;    // усі N — з тренером
}
function inv_assertTrainerAllowed(array $tariff): void {
    if (empty($tariff['has_trainer'])) Response::error('Цей тариф не передбачає призначення тренера');
    if (inv_trainerSessions($tariff) === null)
        Response::error('Безлімітний тариф без кількості занять з тренером не можна прив\'язати до тренера. Вкажіть «Занять з тренером» у тарифі.');
}

// ── Хелпер: ім'я тренера клубу ───────────────────────────────
function inv_trainerName(PDO $pdo, int $clubId, ?int $trainerId): ?string {
    if (!$trainerId) return null;
    $st = $pdo->prepare("
        SELECT u.full_name FROM club_trainers ct
        JOIN sys_users u ON u.id = ct.user_id
        WHERE ct.id=? AND ct.club_id=? LIMIT 1
    ");
    $st->execute([$trainerId, $clubId]);
    return $st->fetchColumn() ?: null;
}

// ── Хелпер: група абонементів клубу ──────────────────────────
function inv_getGroup(PDO $pdo, int $clubId, int $groupId): array {
    $st = $pdo->prepare("SELECT * FROM invoice_groups WHERE id=? AND club_id=? LIMIT 1");
    $st->execute([$groupId, $clubId]);
    $g = $st->fetch();
    if (!$g) Response::error('Групу не знайдено', 404);
    return $g;
}

// Trainer ID поточного юзера (для фільтра)
$myTrainerClubId = 0;
if ($isTrainer) {
    $trStmt = $pdo->prepare("SELECT id FROM club_trainers WHERE club_id=? AND user_id=? LIMIT 1");
    $trStmt->execute([$clubId, (int)$sess['user_id']]);
    $myTrainerClubId = (int)($trStmt->fetchColumn() ?: 0);
}

try { switch ($action) {

    // ════ СПИСОК ТАРИФІВ (для форми продажу) ═════════════════
    case 'get_tariffs':
        $stmt = $pdo->prepare("
            SELECT id, name, category, duration_days, visits_limit,
                   price, description, color,
                   COALESCE(freeze_days_max, 0)   AS freeze_days_max,
                   COALESCE(prolong_sum, 0)        AS prolong_sum,
                   COALESCE(has_trainer, 0)        AS has_trainer,
                   trainer_sessions
            FROM tariffs
            WHERE club_id = ? AND is_active = 1
            ORDER BY sort_order, name
        ");
        $stmt->execute([$clubId]);
        Response::ok(['tariffs' => $stmt->fetchAll()]);


    // ════ СПИСОК АБОНЕМЕНТІВ ══════════════════════════════════
    case 'get_list':
        $search   = trim($input['search']  ?? $_GET['search']  ?? '');
        $status   = trim($input['status']  ?? $_GET['status']  ?? '');
        $page     = max(1, (int)($input['page'] ?? 1));
        $perPage  = 25;
        $offset   = ($page - 1) * $perPage;
        $orderBy  = in_array($input['order'] ?? '', ['created_at','end_date','paid_amount'])
                    ? $input['order'] : 'created_at';
        $orderDir = ($input['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $where  = ['ci.club_id = ?'];
        $params = [$clubId];

        // Тренер бачить лише своїх клієнтів
        if ($isTrainer) {
            if (!$myTrainerClubId) Response::ok(['invoices'=>[],'pagination'=>['total'=>0,'page'=>1,'per_page'=>25,'pages'=>1]]);
            $where[]  = 'ci.trainer_id = ?';
            $params[] = $myTrainerClubId;
        }

        if ($search) {
            $where[]  = '(c.full_name LIKE ? OR c.phone LIKE ?)';
            $like     = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($status && in_array($status, ['future','active','pending','frozen','finished','cancelled'])) {
            $where[]  = "{$EFFECTIVE_STATUS_SQL} = ?";
            $params[] = $status;
        }

        $whereSQL = implode(' AND ', $where);

        $countStmt = $pdo->prepare("
            SELECT COUNT(*) FROM client_invoices ci
            JOIN clients c ON c.id = ci.client_id
            WHERE {$whereSQL}
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT
                ci.id, ci.tariff_id, ci.tariff_name, ci.price, ci.paid_amount,
                ci.start_date, ci.end_date,
                ci.visits_total, ci.visits_used,
                {$EFFECTIVE_STATUS_SQL} AS status, ci.sale_type, ci.freeze_days, ci.freeze_start,
                ci.trainer_id, ci.trainer_name, ci.admin_name,
                ci.created_at, ci.group_id,
                c.id   AS client_id,
                c.full_name AS client_name,
                c.phone     AS client_phone,
                -- Чи є борг
                (ci.price - ci.paid_amount) AS debt,
                -- Днів залишилось
                DATEDIFF(ci.end_date, CURDATE()) AS days_left
            FROM client_invoices ci
            JOIN clients c ON c.id = ci.client_id
            WHERE {$whereSQL}
            ORDER BY ci.{$orderBy} {$orderDir}
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$perPage, $offset]));

        Response::ok([
            'invoices'   => $stmt->fetchAll(),
            'pagination' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => max(1, (int)ceil($total / $perPage)),
            ],
        ]);


    // ════ ОДИН АБОНЕМЕНТ ══════════════════════════════════════
    case 'get_one':
        if ($isTrainer && !$myTrainerClubId) Response::forbidden();
        $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
        if (!$id) Response::error('Не вказано id');

        $stmt = $pdo->prepare("
            SELECT ci.*, c.full_name AS client_name, c.phone AS client_phone,
                   c.balance AS client_balance,
                   DATEDIFF(ci.end_date, CURDATE()) AS days_left,
                   {$EFFECTIVE_STATUS_SQL} AS status,
                   COALESCE(t.freeze_days_max, 0) AS tariff_freeze_max,
                   COALESCE(t.freeze_days_min, 0) AS tariff_freeze_min,
                   g.name AS group_name
            FROM client_invoices ci
            JOIN clients c ON c.id = ci.client_id
            LEFT JOIN tariffs t ON t.id = ci.tariff_id
            LEFT JOIN invoice_groups g ON g.id = ci.group_id
            WHERE ci.id = ? AND ci.club_id = ?
              " . ($isTrainer ? "AND ci.trainer_id = {$myTrainerClubId}" : "") . "
            LIMIT 1
        ");
        $stmt->execute([$id, $clubId]);
        $invoice = $stmt->fetch();
        if (!$invoice) Response::error('Абонемент не знайдено', 404);

        // Платежі по цьому абонементу
        $payStmt = $pdo->prepare("
            SELECT id, amount, payment_method, admin_name, notes, created_at,
                   fiscal_status, fiscal_receipt_url
            FROM club_payments
            WHERE invoice_id = ? AND club_id = ?
            ORDER BY created_at DESC
        ");
        $payStmt->execute([$id, $clubId]);

        $invoice['trainer_sessions_used'] = Attendance::trainerSessionsUsed($pdo, $id);
        Response::ok([
            'invoice'  => $invoice,
            'payments' => $payStmt->fetchAll(),
        ]);


    // ════ ПРОДАТИ АБОНЕМЕНТ ═══════════════════════════════════
    case 'create':
        if (!Auth::can($sess, $clubId, 'invoices.sell')) Response::forbidden();
        Billing::requireWriteAccess($clubId);
        Billing::checkInvoiceLimit($clubId);

        $clientId  = (int)($input['client_id']  ?? 0);
        $tariffId  = (int)($input['tariff_id']  ?? 0);
        $startDate = trim($input['start_date']   ?? date('Y-m-d'));
        $paidNow   = (float)($input['paid_amount'] ?? 0);
        $method    = trim($input['payment_method'] ?? 'cash');

        if (!$clientId) Response::error('Оберіть клієнта');
        if (!$tariffId) Response::error('Оберіть тариф');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = date('Y-m-d');

        // Перевірка зміни якщо оплата готівкою
        $shiftId = null;
        if ($method === 'cash' && $paidNow > 0) {
            $shiftId = inv_requireCashShift($pdo, $clubId, $userId, $isOwner);
        }

        // Перевіряємо клієнта
        $clientStmt = $pdo->prepare("
            SELECT id, full_name, balance, status FROM clients
            WHERE id = ? AND club_id = ? LIMIT 1
        ");
        $clientStmt->execute([$clientId, $clubId]);
        $client = $clientStmt->fetch();
        if (!$client) Response::error('Клієнта не знайдено');
        if ($client['status'] === 'blocked') Response::error('Клієнт заблокований — продаж абонементів недоступний', 403);

        // Борг по інших абонементах — продаж нового заборонено, поки не розрахований попередній.
        // Групові абонементи не враховуються: там несплачений абонемент просто не активується
        // (min_paid_to_activate), а борг видно в картці групи.
        $debtStmt = $pdo->prepare("
            SELECT SUM(GREATEST(price - paid_amount, 0)) FROM client_invoices
            WHERE client_id = ? AND club_id = ? AND status IN ('active','frozen')
              AND group_id IS NULL
        ");
        $debtStmt->execute([$clientId, $clubId]);
        $existingDebt = (float)($debtStmt->fetchColumn() ?: 0);
        if ($existingDebt > 0.01) {
            Response::error('У клієнта непогашений борг ' . number_format($existingDebt, 0, '.', ' ') . ' грн за попередній абонемент. Продаж нового абонемента недоступний до повного розрахунку.', 409);
        }

        // Тип продажу — рахується системою автоматично, менеджер не обирає вручну
        // (інакше можна "намалювати" собі KPI продовжень).
        // renewal — у клієнта Є попередній абонемент зі статусом active/frozen (ще не завершився);
        // return  — є попередні абонементи, але жоден зараз не active/frozen;
        // new     — попередніх абонементів немає взагалі.
        $saleType = inv_saleType($pdo, $clubId, $clientId, $EFFECTIVE_STATUS_SQL);

        // Перевіряємо тариф
        $tariffStmt = $pdo->prepare("
            SELECT * FROM tariffs WHERE id = ? AND club_id = ? AND is_active = 1 LIMIT 1
        ");
        $tariffStmt->execute([$tariffId, $clubId]);
        $tariff = $tariffStmt->fetch();
        if (!$tariff) Response::error('Тариф не знайдено');

        // Розрахунок дат. -1, бо статус "активний" тримається включно по end_date
        // (інакше тариф на N днів фактично давав би N+1 днів дії).
        $endDate = date('Y-m-d', strtotime($startDate . ' +' . ($tariff['duration_days'] - 1) . ' days'));

        // Ціна зі знижкою
        $discount  = max(0, min(100, (float)($input['discount'] ?? 0)));
        $finalPrice = round($tariff['price'] * (1 - $discount / 100), 2);

        // Тренер — лише якщо тариф це дозволяє (tariffs.has_trainer)
        $trainerId   = (int)($input['trainer_id']   ?? 0) ?: null;
        $trainerName = null;
        if ($trainerId) {
            inv_assertTrainerAllowed($tariff);
            $trStmt = $pdo->prepare("
                SELECT u.full_name FROM club_trainers ct
                JOIN sys_users u ON u.id = ct.user_id
                WHERE ct.id=? AND ct.club_id=? LIMIT 1
            ");
            $trStmt->execute([$trainerId, $clubId]);
            $trainerName = $trStmt->fetchColumn() ?: null;
        }

        $pdo->beginTransaction();
        try {
            // Створюємо абонемент
            $pdo->prepare("
                INSERT INTO client_invoices
                    (club_id, client_id, tariff_id, tariff_name,
                     price, paid_amount,
                     start_date, end_date,
                     visits_total, visits_used, trainer_sessions_total, status, sale_type,
                     trainer_id, trainer_name,
                     trainer_narah_type, trainer_narah_amount,
                     admin_id, admin_name,
                     created_by, notes)
                VALUES (?,?,?,?, ?,?, ?,?, ?,0,?,'active',?, ?,?, ?,?, ?,?, ?,?)
            ")->execute([
                $clubId, $clientId, $tariffId, $tariff['name'],
                $finalPrice, 0,
                $startDate, $endDate,
                $tariff['visits_limit'] ?: null, inv_trainerSessions($tariff), $saleType,
                $trainerId, $trainerName,
                $tariff['narah_summ_type'] ?? null,
                $finalPrice, // trainer_narah_amount
                $sess['user_id'], $sess['full_name'] ?? null,
                $sess['user_id'],
                trim($input['notes'] ?? '') ?: null,
            ]);
            $invoiceId = (int)$pdo->lastInsertId();

            // Якщо є початкова оплата — записуємо платіж
            $paymentId = null;
            if ($paidNow > 0) {
                $payMethod = trim($input['payment_method'] ?? 'cash');
                $paymentId = self_addPayment($pdo, $clubId, $clientId, $invoiceId,
                    $paidNow, $payMethod, $sess,
                    $trainerId, $trainerName,
                    trim($input['notes'] ?? '') ?: null,
                    $shiftId);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::serverError($e->getMessage());
        }

        // Фіскалізація — після commit-у, оплата в CRM вже проведена незалежно
        // від результату (ТЗ 4.3). manual_skip_fiscal — ручний перемикач з форми продажу.
        if ($paymentId) {
            CheckboxService::maybeFiscalize(
                $pdo, $clubId, $paymentId, $payMethod, $paidNow,
                $tariff['name'], (bool)($input['manual_skip_fiscal'] ?? false)
            );
        }

        Response::ok(['id' => $invoiceId], 'Абонемент продано');
    case 'update':
        if (!Auth::can($sess, $clubId, 'invoices.sell')) Response::forbidden();

        $id = (int)($input['id'] ?? 0);
        if (!$id) Response::error('Не вказано id');

        $check = $pdo->prepare("SELECT * FROM client_invoices WHERE id=? AND club_id=?");
        $check->execute([$id, $clubId]);
        $inv = $check->fetch();
        if (!$inv) Response::error('Абонемент не знайдено', 404);

        $tariffId  = (int)($input['tariff_id']  ?? $inv['tariff_id']);
        $startDate = trim($input['start_date']  ?? $inv['start_date']);
        $trainerId = (int)($input['trainer_id'] ?? 0) ?: null;
        $notes     = trim($input['notes']        ?? '') ?: null;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate))
            Response::error('Невірний формат дати початку');

        // Підтягуємо дані тарифу
        $tStmt = $pdo->prepare("SELECT * FROM tariffs WHERE id=? AND club_id=? LIMIT 1");
        $tStmt->execute([$tariffId, $clubId]);
        $tariff = $tStmt->fetch();
        if (!$tariff) Response::error('Тариф не знайдено');

        // Автоматичні поля з тарифу — не редагуються вручну, завжди перераховуються системою.
        // -1, бо статус "активний" тримається включно по end_date.
        $endDate     = date('Y-m-d', strtotime($startDate . ' +' . ($tariff['duration_days'] - 1) . ' days'));
        $price       = (float)$tariff['price'];
        $visitsTotal = $tariff['visits_limit'] ?: null;
        $visitsUsed  = (int)$inv['visits_used'];

        $tariffName = $tariff['name'];

        // Абонемент учасника групи: тариф, дати і ціна спільні для всієї групи —
        // тут змінюються лише тренер і нотатки (решта — в картці групи).
        if (!empty($inv['group_id'])) {
            $tariffId    = $inv['tariff_id'];
            $tariffName  = $inv['tariff_name'];
            $startDate   = $inv['start_date'];
            $endDate     = $inv['end_date'];
            $price       = (float)$inv['price'];
            $visitsTotal = $inv['visits_total'];
        }

        if ($trainerId) inv_assertTrainerAllowed($tariff);
        $trainerSessionsTotal = !empty($inv['group_id']) ? $inv['trainer_sessions_total'] : inv_trainerSessions($tariff);

        // Тренер
        $trainerName = null;
        if ($trainerId) {
            $trStmt = $pdo->prepare("
                SELECT u.full_name FROM club_trainers ct
                JOIN sys_users u ON u.id = ct.user_id
                WHERE ct.id=? AND ct.club_id=? LIMIT 1
            ");
            $trStmt->execute([$trainerId, $clubId]);
            $trainerName = $trStmt->fetchColumn() ?: null;
        }

        $pdo->prepare("
            UPDATE client_invoices SET
                tariff_id    = ?,
                tariff_name  = ?,
                start_date   = ?,
                end_date     = ?,
                price        = ?,
                visits_total = ?,
                visits_used  = ?,
                trainer_sessions_total = ?,
                trainer_id   = ?,
                trainer_name = ?,
                notes        = ?
            WHERE id = ? AND club_id = ?
        ")->execute([
            $tariffId, $tariffName, $startDate, $endDate,
            $price, $visitsTotal, $visitsUsed, $trainerSessionsTotal,
            $trainerId, $trainerName, $notes,
            $id, $clubId,
        ]);
        Recalc::unlockInvoiceTrainerEarnings($pdo, $id);

        Response::ok([], 'Збережено');


    // ════ СКАСУВАТИ АБОНЕМЕНТ ═════════════════════════════════
    case 'cancel':
        if (!Auth::can($sess, $clubId, 'invoices.delete')) Response::forbidden('Скасування — лише власник');

        $id     = (int)($input['id'] ?? 0);
        $reason = trim($input['reason'] ?? '');
        if (!$id) Response::error('Не вказано id');
        if ($reason === '') Response::error('Вкажіть причину дострокового скасування');

        $chk = $pdo->prepare("SELECT {$EFFECTIVE_STATUS_SQL} AS status FROM client_invoices ci WHERE ci.id = ? AND ci.club_id = ? LIMIT 1");
        $chk->execute([$id, $clubId]);
        $effStatus = $chk->fetchColumn();
        if ($effStatus === false) Response::error('Абонемент не знайдено');
        if ($effStatus !== 'active') Response::error('Достроково скасувати можна лише діючий абонемент');

        $stmt = $pdo->prepare("
            UPDATE client_invoices SET
                status = 'cancelled',
                notes  = TRIM(CONCAT_WS(' ', notes, ?))
            WHERE id = ? AND club_id = ? AND status != 'cancelled'
        ");
        $stmt->execute(["[Дострокове припинення: {$reason}]", $id, $clubId]);
        if (!$stmt->rowCount()) Response::error('Абонемент не знайдено або вже скасований');
        Recalc::unlockInvoiceTrainerEarnings($pdo, $id);

        Response::ok([], 'Абонемент скасовано');


    // ════ ВІДНОВИТИ АБОНЕМЕНТ ═════════════════════════════════
    case 'restore':
        if (!Auth::can($sess, $clubId, 'invoices.delete')) Response::forbidden('Відновлення — лише власник');

        $id = (int)($input['id'] ?? 0);
        if (!$id) Response::error('Не вказано id');

        $stmt = $pdo->prepare("
            UPDATE client_invoices SET status = 'active'
            WHERE id = ? AND club_id = ? AND status = 'cancelled'
        ");
        $stmt->execute([$id, $clubId]);
        if (!$stmt->rowCount()) Response::error('Абонемент не знайдено або не є скасованим');
        Recalc::unlockInvoiceTrainerEarnings($pdo, $id);

        Response::ok([], 'Абонемент відновлено');


    // ════ ЗАМОРОЗКА ═══════════════════════════════════════════
    case 'freeze':
        if (!Auth::can($sess, $clubId, 'invoices.cancel')) Response::forbidden();

        $id        = (int)($input['id']    ?? 0);
        $freezeDays = (int)($input['days'] ?? 0);
        if (!$id) Response::error('Не вказано id');

        $stmt = $pdo->prepare("
            SELECT * FROM client_invoices
            WHERE id = ? AND club_id = ? AND status IN ('active','frozen')
            LIMIT 1
        ");
        $stmt->execute([$id, $clubId]);
        $inv = $stmt->fetch();
        if (!$inv) Response::error('Абонемент не знайдено');

        if ($inv['status'] === 'frozen') {
            // ── РОЗМОРОЖУЄМО ─────────────────────────────────
            // Рахуємо реально заморожені дні: від freeze_start до сьогодні
            $freezeStart = $inv['freeze_start'] ?: date('Y-m-d');
            $actualDays  = max(0, (int)((strtotime('today') - strtotime($freezeStart)) / 86400));
            // Не більше запланованого ліміту
            $usedFreeze  = min($actualDays, (int)$inv['freeze_days']);
            // Невикористані заморожені дні — прибираємо з end_date
            $unusedFreeze = (int)$inv['freeze_days'] - $usedFreeze;
            // end_date = поточний end_date - невикористані дні заморозки
            $newEnd = date('Y-m-d', strtotime($inv['end_date'] . " -{$unusedFreeze} days"));
            $pdo->prepare("
                UPDATE client_invoices SET
                    status       = 'active',
                    freeze_days  = ?,
                    freeze_start = NULL,
                    end_date     = ?
                WHERE id = ?
            ")->execute([$usedFreeze, $newEnd, $id]);
            Recalc::unlockInvoiceTrainerEarnings($pdo, $id);
            Response::ok(['new_end_date' => $newEnd], "Розморожено. Використано {$usedFreeze} дн. заморозки.");
        }

        // ── ЗАМОРОЖУЄМО ──────────────────────────────────────
        if ($freezeDays < 1) Response::error('Вкажіть кількість днів заморозки');

        // Дата початку заморозки (не раніше сьогодні)
        $freezeStartInput = trim($input['freeze_start'] ?? '');
        $today = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $freezeStartInput) || $freezeStartInput < $today) {
            $freezeStartInput = $today;
        }

        // Заморозка можлива лише в межах дії абонементу: з 2-го дня і по передостанній день (включно)
        $minFreezeStart = date('Y-m-d', strtotime($inv['start_date'] . ' +1 day'));
        $maxFreezeStart = date('Y-m-d', strtotime($inv['end_date'] . ' -1 day'));
        if ($freezeStartInput < $minFreezeStart || $freezeStartInput > $maxFreezeStart) {
            Response::error("Заморозка можлива лише в межах дії абонементу: з {$minFreezeStart} по {$maxFreezeStart}");
        }

        // Перевіряємо ліміти заморозки з тарифу (мін./макс. днів)
        if ($inv['tariff_id']) {
            $tariffRow = $pdo->prepare("SELECT freeze_days_max, freeze_days_min, duration_days FROM tariffs WHERE id=? LIMIT 1");
            $tariffRow->execute([$inv['tariff_id']]);
            $tariff = $tariffRow->fetch();
            $maxFreeze = (int)($tariff['freeze_days_max'] ?? 0);
            $minFreeze = (int)($tariff['freeze_days_min'] ?? 0);
            if ($maxFreeze > 0 && $freezeDays > $maxFreeze) {
                Response::error("Максимум заморозки для цього тарифу: {$maxFreeze} дн.");
            }
            if ($minFreeze > 0 && $freezeDays < $minFreeze) {
                Response::error("Мінімум заморозки для цього тарифу: {$minFreeze} дн.");
            }
        }

        // end_date = start_date + duration_days + (старі freeze_days + нові) + prolong_days
        $newFreezeDays = (int)$inv['freeze_days'] + $freezeDays;
        $prolongDays   = (int)($inv['prolong_days'] ?? 0);
        $durationDays  = isset($tariff['duration_days'])
            ? (int)$tariff['duration_days']
            : (int)(strtotime($inv['end_date']) - strtotime($inv['start_date'])) / 86400 - (int)$inv['freeze_days'] - $prolongDays;
        $newEnd = date('Y-m-d', strtotime($inv['start_date']
            . " +{$durationDays} days +{$newFreezeDays} days +{$prolongDays} days"));

        $pdo->prepare("
            UPDATE client_invoices SET
                status       = 'frozen',
                freeze_days  = ?,
                freeze_start = ?,
                end_date     = ?
            WHERE id = ?
        ")->execute([$newFreezeDays, $freezeStartInput, $newEnd, $id]);
        Recalc::unlockInvoiceTrainerEarnings($pdo, $id);

        Response::ok([], "Абонемент заморожено на {$freezeDays} дн. з {$freezeStartInput}.");


    // ════ ВІДМІНИТИ ЗАМОРОЗКУ (повне скасування, без урахування використаних днів) ════
    case 'cancel_freeze':
        if (!Auth::can($sess, $clubId, 'invoices.cancel')) Response::forbidden();

        $id = (int)($input['id'] ?? 0);
        if (!$id) Response::error('Не вказано id');

        $stmt = $pdo->prepare("
            SELECT * FROM client_invoices
            WHERE id = ? AND club_id = ? AND status = 'frozen'
            LIMIT 1
        ");
        $stmt->execute([$id, $clubId]);
        $inv = $stmt->fetch();
        if (!$inv) Response::error('Абонемент не знайдено або не заморожений');

        // Термін дії, який був би, якби заморозки не було взагалі
        $revertedEnd = date('Y-m-d', strtotime($inv['end_date'] . " -{$inv['freeze_days']} days"));

        // Не можна відміняти, якщо є відвідування, які через це опиняться поза межами терміну абонементу
        $visitStmt = $pdo->prepare("SELECT MAX(visited_at) FROM visits WHERE invoice_id = ?");
        $visitStmt->execute([$id]);
        $lastVisit = $visitStmt->fetchColumn();
        if ($lastVisit && substr($lastVisit, 0, 10) > $revertedEnd) {
            Response::error("Неможливо відмінити заморозку: є відвідування від " . substr($lastVisit, 0, 10) . ", яке вийде за межі терміну абонементу після скасування. Скористайтесь «Розморозити» — це коректно врахує використані дні заморозки.");
        }

        $pdo->prepare("
            UPDATE client_invoices SET
                status       = 'active',
                freeze_days  = 0,
                freeze_start = NULL,
                end_date     = ?
            WHERE id = ?
        ")->execute([$revertedEnd, $id]);
        Recalc::unlockInvoiceTrainerEarnings($pdo, $id);

        Response::ok(['new_end_date' => $revertedEnd], "Заморозку відмінено. Термін абонементу: до {$revertedEnd}.");


    // ════ ЗМІНИТИ КІЛЬКІСТЬ ДНІВ ЗАМОРОЗКИ (напр. на прохання клієнта) ═
    case 'update_freeze_days':
        if (!Auth::can($sess, $clubId, 'invoices.cancel')) Response::forbidden();

        $id      = (int)($input['id']   ?? 0);
        $newDays = (int)($input['days'] ?? -1);
        if (!$id) Response::error('Не вказано id');
        if ($newDays < 1) Response::error('Вкажіть кількість днів заморозки');

        $stmt = $pdo->prepare("
            SELECT * FROM client_invoices
            WHERE id = ? AND club_id = ? AND status = 'frozen'
            LIMIT 1
        ");
        $stmt->execute([$id, $clubId]);
        $inv = $stmt->fetch();
        if (!$inv) Response::error('Абонемент не знайдено або не заморожений');

        $tariff = null;
        if ($inv['tariff_id']) {
            $tariffRow = $pdo->prepare("SELECT freeze_days_max, freeze_days_min, duration_days FROM tariffs WHERE id=? LIMIT 1");
            $tariffRow->execute([$inv['tariff_id']]);
            $tariff = $tariffRow->fetch();
            $maxFreeze = (int)($tariff['freeze_days_max'] ?? 0);
            $minFreeze = (int)($tariff['freeze_days_min'] ?? 0);
            if ($maxFreeze > 0 && $newDays > $maxFreeze) {
                Response::error("Максимум заморозки для цього тарифу: {$maxFreeze} дн.");
            }
            if ($minFreeze > 0 && $newDays < $minFreeze) {
                Response::error("Мінімум заморозки для цього тарифу: {$minFreeze} дн.");
            }
        }

        $prolongDays  = (int)($inv['prolong_days'] ?? 0);
        $durationDays = isset($tariff['duration_days'])
            ? (int)$tariff['duration_days']
            : (int)(strtotime($inv['end_date']) - strtotime($inv['start_date'])) / 86400 - (int)$inv['freeze_days'] - $prolongDays;
        $newEnd = date('Y-m-d', strtotime($inv['start_date']
            . " +{$durationDays} days +{$newDays} days +{$prolongDays} days"));

        // Не можна зменшувати, якщо це виштовхне вже зафіксовані відвідування за межі нового терміну
        $visitStmt = $pdo->prepare("SELECT MAX(visited_at) FROM visits WHERE invoice_id = ?");
        $visitStmt->execute([$id]);
        $lastVisit = $visitStmt->fetchColumn();
        if ($lastVisit && substr($lastVisit, 0, 10) > $newEnd) {
            Response::error("Неможливо змінити на {$newDays} дн.: є відвідування від " . substr($lastVisit, 0, 10) . ", яке вийде за межі терміну абонементу.");
        }

        $pdo->prepare("
            UPDATE client_invoices SET
                freeze_days = ?,
                end_date    = ?
            WHERE id = ?
        ")->execute([$newDays, $newEnd, $id]);
        Recalc::unlockInvoiceTrainerEarnings($pdo, $id);

        Response::ok(['new_end_date' => $newEnd], "Кількість днів заморозки змінено на {$newDays}. Новий термін дії: до {$newEnd}.");


    // ════ ДОДАТИ ПЛАТІЖ ═══════════════════════════════════════
    case 'add_payment':
        if (!Auth::can($sess, $clubId, 'payments.create')) Response::forbidden();

        $invoiceId = (int)($input['invoice_id'] ?? 0);
        $amount    = (float)($input['amount']   ?? 0);
        $method    = trim($input['payment_method'] ?? 'cash');

        if (!$invoiceId) Response::error('Не вказано invoice_id');
        if ($amount <= 0) Response::error('Введіть суму');

        // Перевірка зміни якщо оплата готівкою
        $shiftId = null;
        if ($method === 'cash') {
            $shiftId = inv_requireCashShift($pdo, $clubId, $userId, $isOwner);
        }

        $invStmt = $pdo->prepare("SELECT ci.id, ci.client_id, ci.price, ci.paid_amount, ci.tariff_name FROM client_invoices ci WHERE ci.id=? AND ci.club_id=? LIMIT 1");
        $invStmt->execute([$invoiceId, $clubId]);
        $inv = $invStmt->fetch();
        if (!$inv) Response::error('Абонемент не знайдено');

        $pdo->beginTransaction();
        try {
            $paymentId = self_addPayment($pdo, $clubId, $inv['client_id'], $invoiceId,
                $amount, $method, $sess, null, null,
                trim($input['notes'] ?? '') ?: null, $shiftId);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::serverError($e->getMessage());
        }

        CheckboxService::maybeFiscalize(
            $pdo, $clubId, $paymentId, $method, $amount,
            $inv['tariff_name'] ?? 'Оплата абонемента', (bool)($input['manual_skip_fiscal'] ?? false)
        );

        $newPaid = $inv['paid_amount'] + $amount;
        Response::ok(['paid_amount' => $newPaid, 'debt' => max(0, $inv['price'] - $newPaid)], 'Платіж додано');


    // ════ СТАТИСТИКА ══════════════════════════════════════════
    case 'get_stats':
        // Активні абонементи
        $row = $pdo->prepare("
            SELECT
                SUM({$EFFECTIVE_STATUS_SQL} = 'future')                              AS future,
                SUM({$EFFECTIVE_STATUS_SQL} = 'active')                              AS active,
                SUM({$EFFECTIVE_STATUS_SQL} = 'frozen')                              AS frozen,
                SUM({$EFFECTIVE_STATUS_SQL} = 'finished')                            AS finished,
                SUM({$EFFECTIVE_STATUS_SQL} = 'active'
                    AND DATEDIFF(ci.end_date, CURDATE()) BETWEEN 0 AND 7)            AS expiring_soon,
                SUM({$EFFECTIVE_STATUS_SQL} = 'active' AND ci.price > ci.paid_amount) AS with_debt,
                SUM(CASE WHEN {$EFFECTIVE_STATUS_SQL} = 'active' AND ci.price > ci.paid_amount
                    THEN ci.price - ci.paid_amount ELSE 0 END)                       AS total_debt
            FROM client_invoices ci
            WHERE ci.club_id = ?
        ");
        $row->execute([$clubId]);
        $stats = $row->fetch();

        // Продажі цього місяця
        $monthRow = $pdo->prepare("
            SELECT
                COUNT(*)    AS sold_count,
                SUM(price)  AS sold_total
            FROM client_invoices
            WHERE club_id = ?
              AND MONTH(created_at) = MONTH(NOW())
              AND YEAR(created_at)  = YEAR(NOW())
        ");
        $monthRow->execute([$clubId]);
        $month = $monthRow->fetch();

        // Надходження цього місяця (реальні платежі)
        $incomeRow = $pdo->prepare("
            SELECT SUM(amount) AS income
            FROM club_payments
            WHERE club_id = ?
              AND MONTH(created_at) = MONTH(NOW())
              AND YEAR(created_at)  = YEAR(NOW())
        ");
        $incomeRow->execute([$clubId]);
        $income = $incomeRow->fetchColumn();

        Response::ok([
            'stats' => array_merge(
                array_map(fn($v) => $v ?? 0, $stats),
                [
                    'sold_count'  => (int)($month['sold_count']  ?? 0),
                    'sold_total'  => (float)($month['sold_total'] ?? 0),
                    'income_month'=> (float)($income             ?? 0),
                ]
            ),
        ]);


    // ════ ГРУПОВІ АБОНЕМЕНТИ ══════════════════════════════════
    // Група = договір (тариф, спільні дати, ціна учасника, мінімальна оплата,
    // ліміт місць). Кожен учасник має свій звичайний абонемент з group_id;
    // він не діє, доки учасник не оплатив min_paid_to_activate.

    case 'group_list':
        if ($isTrainer) Response::forbidden();
        $search = trim($input['search'] ?? '');
        $status = ($input['status'] ?? 'active') === 'closed' ? 'closed' : 'active';

        $where  = ['g.club_id = ?', 'g.status = ?'];
        $params = [$clubId, $status];
        if ($search !== '') {
            $where[]  = '(g.name LIKE ? OR oc.full_name LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        $whereSQL = implode(' AND ', $where);

        $stmt = $pdo->prepare("
            SELECT g.id, g.name, g.tariff_id, g.tariff_name, g.start_date, g.end_date,
                   g.member_price, g.min_payment, g.max_members, g.status, g.notes,
                   g.owner_client_id, oc.full_name AS owner_name, oc.phone AS owner_phone,
                   COUNT(ci.id)                                               AS members_count,
                   COALESCE(SUM(ci.price), 0)                                  AS total_price,
                   COALESCE(SUM(ci.paid_amount), 0)                            AS total_paid,
                   COALESCE(SUM(ci.paid_amount >= COALESCE(ci.min_paid_to_activate, 0)), 0) AS activated_count
            FROM invoice_groups g
            LEFT JOIN clients oc ON oc.id = g.owner_client_id
            LEFT JOIN client_invoices ci ON ci.group_id = g.id AND ci.status <> 'cancelled'
            WHERE {$whereSQL}
            GROUP BY g.id
            ORDER BY g.start_date DESC, g.id DESC
            LIMIT 200
        ");
        $stmt->execute($params);
        Response::ok(['groups' => $stmt->fetchAll()]);


    case 'group_get':
        if ($isTrainer) Response::forbidden();
        $groupId = (int)($input['id'] ?? 0);
        $group = inv_getGroup($pdo, $clubId, $groupId);

        $oc = $pdo->prepare("SELECT full_name, phone, balance FROM clients WHERE id=? AND club_id=?");
        $oc->execute([(int)$group['owner_client_id'], $clubId]);
        $owner = $oc->fetch() ?: null;

        $mStmt = $pdo->prepare("
            SELECT ci.id, ci.client_id, ci.price, ci.paid_amount, ci.min_paid_to_activate,
                   ci.start_date, ci.end_date, ci.visits_total, ci.visits_used,
                   ci.trainer_name, ci.freeze_days, ci.created_at,
                   {$EFFECTIVE_STATUS_SQL} AS status,
                   GREATEST(ci.price - ci.paid_amount, 0) AS debt,
                   c.full_name AS client_name, c.phone AS client_phone, c.balance AS client_balance
            FROM client_invoices ci
            JOIN clients c ON c.id = ci.client_id
            WHERE ci.group_id = ? AND ci.club_id = ?
            ORDER BY (ci.status = 'cancelled'), c.full_name
        ");
        $mStmt->execute([$groupId, $clubId]);

        Response::ok(['group' => $group, 'owner' => $owner, 'members' => $mStmt->fetchAll()]);


    case 'group_create':
        if (!Auth::can($sess, $clubId, 'invoices.sell')) Response::forbidden();
        Billing::requireWriteAccess($clubId);

        $name      = trim($input['name'] ?? '');
        $tariffId  = (int)($input['tariff_id'] ?? 0);
        $startDate = trim($input['start_date'] ?? date('Y-m-d'));
        if ($name === '') Response::error('Вкажіть назву групи');
        if (!$tariffId) Response::error('Оберіть тариф');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) Response::error('Невірна дата початку');

        $tStmt = $pdo->prepare("SELECT * FROM tariffs WHERE id=? AND club_id=? AND is_active=1 LIMIT 1");
        $tStmt->execute([$tariffId, $clubId]);
        $tariff = $tStmt->fetch();
        if (!$tariff) Response::error('Тариф не знайдено');

        $endDate     = date('Y-m-d', strtotime($startDate . ' +' . ($tariff['duration_days'] - 1) . ' days'));
        $memberPrice = isset($input['member_price']) && $input['member_price'] !== ''
            ? max(0, round((float)$input['member_price'], 2)) : (float)$tariff['price'];
        $minPayment  = isset($input['min_payment']) && $input['min_payment'] !== ''
            ? max(0, round((float)$input['min_payment'], 2)) : $memberPrice;
        if ($minPayment > $memberPrice) Response::error('Мінімальна оплата не може перевищувати вартість абонемента учасника');
        $maxMembers  = (int)($input['max_members'] ?? 0) ?: null;
        $ownerId     = (int)($input['owner_client_id'] ?? 0) ?: null;
        if ($ownerId) {
            $oc = $pdo->prepare("SELECT id FROM clients WHERE id=? AND club_id=?");
            $oc->execute([$ownerId, $clubId]);
            if (!$oc->fetchColumn()) Response::error('Контактну особу не знайдено');
        }

        $pdo->prepare("
            INSERT INTO invoice_groups
                (club_id, name, tariff_id, tariff_name, owner_client_id,
                 start_date, end_date, member_price, min_payment, max_members,
                 notes, created_by)
            VALUES (?,?,?,?,?, ?,?,?,?,?, ?,?)
        ")->execute([
            $clubId, $name, $tariffId, $tariff['name'], $ownerId,
            $startDate, $endDate, $memberPrice, $minPayment, $maxMembers,
            trim($input['notes'] ?? '') ?: null, $userId,
        ]);
        Response::ok(['id' => (int)$pdo->lastInsertId()], 'Групу створено');


    case 'group_update':
        if (!Auth::can($sess, $clubId, 'invoices.sell')) Response::forbidden();
        $groupId = (int)($input['id'] ?? 0);
        $group   = inv_getGroup($pdo, $clubId, $groupId);

        $name        = trim($input['name'] ?? $group['name']);
        $memberPrice = max(0, round((float)($input['member_price'] ?? $group['member_price']), 2));
        $minPayment  = max(0, round((float)($input['min_payment'] ?? $group['min_payment']), 2));
        $maxMembers  = array_key_exists('max_members', $input) ? ((int)$input['max_members'] ?: null) : $group['max_members'];
        $ownerId     = array_key_exists('owner_client_id', $input) ? ((int)$input['owner_client_id'] ?: null) : $group['owner_client_id'];
        $status      = ($input['status'] ?? $group['status']) === 'closed' ? 'closed' : 'active';
        $startDate   = trim($input['start_date'] ?? $group['start_date']);
        $notes       = array_key_exists('notes', $input) ? (trim($input['notes']) ?: null) : $group['notes'];

        if ($name === '') Response::error('Вкажіть назву групи');
        if ($minPayment > $memberPrice) Response::error('Мінімальна оплата не може перевищувати вартість абонемента учасника');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) Response::error('Невірна дата початку');

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM client_invoices WHERE group_id=? AND status <> 'cancelled'");
        $cnt->execute([$groupId]);
        $membersCount = (int)$cnt->fetchColumn();
        if ($maxMembers !== null && $membersCount > $maxMembers)
            Response::error("У групі вже {$membersCount} учасників — ліміт не може бути меншим");

        // Дати спільні для всіх: зміна дати початку зсуває абонементи всіх учасників,
        // тому дозволена лише поки ніхто з групи не мав відвідувань.
        $endDate = $group['end_date'];
        $datesChanged = $startDate !== $group['start_date'];
        if ($datesChanged) {
            $v = $pdo->prepare("SELECT COUNT(*) FROM visits v JOIN client_invoices ci ON ci.id = v.invoice_id WHERE ci.group_id=?");
            $v->execute([$groupId]);
            if ((int)$v->fetchColumn() > 0) Response::error('Учасники групи вже мають відвідування — дату початку змінити не можна');
            $days = (int)((strtotime($group['end_date']) - strtotime($group['start_date'])) / 86400);
            $endDate = date('Y-m-d', strtotime($startDate . " +{$days} days"));
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE invoice_groups SET
                    name=?, member_price=?, min_payment=?, max_members=?, owner_client_id=?,
                    status=?, start_date=?, end_date=?, notes=?
                WHERE id=? AND club_id=?
            ")->execute([
                $name, $memberPrice, $minPayment, $maxMembers, $ownerId,
                $status, $startDate, $endDate, $notes, $groupId, $clubId,
            ]);
            // Ціна і поріг активації — однакові для всіх учасників групи
            $pdo->prepare("
                UPDATE client_invoices SET price=?, min_paid_to_activate=?, trainer_narah_amount=?
                WHERE group_id=? AND club_id=? AND status <> 'cancelled'
            ")->execute([$memberPrice, $minPayment, $memberPrice, $groupId, $clubId]);
            if ($datesChanged) {
                $pdo->prepare("
                    UPDATE client_invoices SET start_date=?, end_date=?
                    WHERE group_id=? AND club_id=? AND status <> 'cancelled'
                ")->execute([$startDate, $endDate, $groupId, $clubId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::serverError($e->getMessage());
        }
        Response::ok([], 'Групу оновлено');


    case 'group_add_members':
        if (!Auth::can($sess, $clubId, 'invoices.sell')) Response::forbidden();
        Billing::requireWriteAccess($clubId);

        $groupId   = (int)($input['group_id'] ?? 0);
        $group     = inv_getGroup($pdo, $clubId, $groupId);
        if ($group['status'] !== 'active') Response::error('Група закрита — додавати учасників не можна');
        if ($group['end_date'] < date('Y-m-d')) Response::error('Термін дії групи вже завершився');

        $clientIds = array_values(array_unique(array_filter(array_map('intval', (array)($input['client_ids'] ?? [])))));
        if (!$clientIds) Response::error('Оберіть учасників');

        $tStmt = $pdo->prepare("SELECT * FROM tariffs WHERE id=? AND club_id=? LIMIT 1");
        $tStmt->execute([(int)$group['tariff_id'], $clubId]);
        $tariff = $tStmt->fetch() ?: [];

        $trainerId   = (int)($input['trainer_id'] ?? 0) ?: null;
        if ($trainerId) inv_assertTrainerAllowed($tariff);
        $trainerName = inv_trainerName($pdo, $clubId, $trainerId);

        // Ліміт місць
        $cnt = $pdo->prepare("SELECT client_id FROM client_invoices WHERE group_id=? AND status <> 'cancelled'");
        $cnt->execute([$groupId]);
        $existing = array_map('intval', $cnt->fetchAll(PDO::FETCH_COLUMN));
        $dupes = array_intersect($clientIds, $existing);
        if ($dupes) Response::error('Деякі з обраних клієнтів уже є в цій групі');
        if ($group['max_members'] !== null && count($existing) + count($clientIds) > (int)$group['max_members']) {
            $free = max(0, (int)$group['max_members'] - count($existing));
            Response::error("У групі залишилось місць: {$free}");
        }

        $cStmt = $pdo->prepare("SELECT id, full_name, status FROM clients WHERE id=? AND club_id=? LIMIT 1");
        $clients = [];
        foreach ($clientIds as $cid) {
            $cStmt->execute([$cid, $clubId]);
            $c = $cStmt->fetch();
            if (!$c) Response::error('Клієнта не знайдено');
            if ($c['status'] === 'blocked') Response::error("Клієнт «{$c['full_name']}» заблокований");
            $clients[] = $c;
        }

        $created = [];
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare("
                INSERT INTO client_invoices
                    (club_id, client_id, tariff_id, tariff_name, group_id, min_paid_to_activate,
                     price, paid_amount, start_date, end_date,
                     visits_total, visits_used, trainer_sessions_total, status, sale_type,
                     trainer_id, trainer_name, trainer_narah_type, trainer_narah_amount,
                     admin_id, admin_name, created_by, notes)
                VALUES (?,?,?,?,?,?, ?,0,?,?, ?,0,?,'active',?, ?,?,?,?, ?,?,?,?)
            ");
            foreach ($clients as $c) {
                Billing::checkInvoiceLimit($clubId);
                // Дати — спільні для всієї групи: учасник, що долучився пізніше,
                // отримує ті самі start_date/end_date.
                $ins->execute([
                    $clubId, (int)$c['id'], $group['tariff_id'], $group['tariff_name'], $groupId, $group['min_payment'],
                    $group['member_price'], $group['start_date'], $group['end_date'],
                    ($tariff['visits_limit'] ?? null) ?: null,
                    $tariff ? inv_trainerSessions($tariff) : null,
                    inv_saleType($pdo, $clubId, (int)$c['id'], $EFFECTIVE_STATUS_SQL),
                    $trainerId, $trainerName, $tariff['narah_summ_type'] ?? null, $group['member_price'],
                    $userId, $sess['full_name'] ?? null, $userId,
                    "Група: {$group['name']}",
                ]);
                $created[] = (int)$pdo->lastInsertId();
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        Response::ok(['invoice_ids' => $created], 'Учасників додано: ' . count($created));


    case 'group_remove_member':
        if (!Auth::can($sess, $clubId, 'invoices.sell')) Response::forbidden();
        $invoiceId = (int)($input['invoice_id'] ?? 0);
        $st = $pdo->prepare("SELECT id, group_id FROM client_invoices WHERE id=? AND club_id=? AND group_id IS NOT NULL");
        $st->execute([$invoiceId, $clubId]);
        if (!$st->fetch()) Response::error('Учасника не знайдено');

        // Прибрати можна лише "порожній" абонемент (без оплат і відвідувань).
        // Інакше — через звичайне дострокове скасування абонемента (з історією).
        $p = $pdo->prepare("SELECT (SELECT COUNT(*) FROM club_payments WHERE invoice_id=?) + (SELECT COUNT(*) FROM visits WHERE invoice_id=?)");
        $p->execute([$invoiceId, $invoiceId]);
        if ((int)$p->fetchColumn() > 0)
            Response::error('Учасник уже має оплати або відвідування — скасуйте його абонемент у картці абонемента');

        $pdo->prepare("DELETE FROM client_invoices WHERE id=? AND club_id=?")->execute([$invoiceId, $clubId]);
        Response::ok([], 'Учасника прибрано з групи');


    // Оплата учасників групи. payer_client_id:
    //   0/порожньо — кожен платить за себе (депозит списується з депозиту учасника);
    //   id клієнта — він платить за всіх обраних (депозит списується з його депозиту).
    case 'group_pay':
        if (!Auth::can($sess, $clubId, 'payments.create')) Response::forbidden();
        $groupId = (int)($input['group_id'] ?? 0);
        $group   = inv_getGroup($pdo, $clubId, $groupId);
        $method  = trim($input['payment_method'] ?? 'cash');
        $payerId = (int)($input['payer_client_id'] ?? 0) ?: null;
        $skipFiscal = (bool)($input['manual_skip_fiscal'] ?? false);

        $items = [];
        foreach ((array)($input['items'] ?? []) as $it) {
            $iid = (int)($it['invoice_id'] ?? 0);
            $amt = round((float)($it['amount'] ?? 0), 2);
            if ($iid && $amt > 0) $items[$iid] = $amt;
        }
        if (!$items) Response::error('Вкажіть суми оплати');

        $payer = null;
        if ($payerId) {
            $ps = $pdo->prepare("SELECT id, full_name, balance FROM clients WHERE id=? AND club_id=?");
            $ps->execute([$payerId, $clubId]);
            $payer = $ps->fetch();
            if (!$payer) Response::error('Платника не знайдено');
        }

        $invStmt = $pdo->prepare("
            SELECT ci.id, ci.client_id, ci.price, ci.paid_amount, c.full_name, c.balance
            FROM client_invoices ci JOIN clients c ON c.id = ci.client_id
            WHERE ci.id=? AND ci.club_id=? AND ci.group_id=? AND ci.status <> 'cancelled'
        ");
        $rows = [];
        $depositNeed = [];
        foreach ($items as $iid => $amt) {
            $invStmt->execute([$iid, $clubId, $groupId]);
            $r = $invStmt->fetch();
            if (!$r) Response::error('Абонемент учасника не знайдено в цій групі');
            $left = round((float)$r['price'] - (float)$r['paid_amount'], 2);
            if ($amt > $left + 0.001) Response::error("Сума для «{$r['full_name']}» більша за залишок ({$left} грн)");
            $rows[] = $r + ['amount' => $amt];
            $depKey = $payerId ?: (int)$r['client_id'];
            $depositNeed[$depKey] = ($depositNeed[$depKey] ?? 0) + $amt;
        }

        // Депозит: у кого списуємо — у того має вистачати коштів
        if ($method === 'deposit') {
            $bs = $pdo->prepare("SELECT full_name, balance FROM clients WHERE id=? AND club_id=?");
            foreach ($depositNeed as $cid => $need) {
                $bs->execute([$cid, $clubId]);
                $b = $bs->fetch();
                if ((float)$b['balance'] + 0.001 < $need)
                    Response::error("Недостатньо коштів на депозиті «{$b['full_name']}»: потрібно {$need} грн, є {$b['balance']} грн");
            }
        }

        $shiftId = $method === 'cash' ? inv_requireCashShift($pdo, $clubId, $userId, $isOwner) : null;

        $paymentIds = [];
        $pdo->beginTransaction();
        try {
            foreach ($rows as $r) {
                $note = "Група «{$group['name']}»" . ($payer ? ", оплатив(ла) {$payer['full_name']}" : '');
                $paymentIds[] = [
                    self_addPayment($pdo, $clubId, (int)$r['client_id'], (int)$r['id'],
                        $r['amount'], $method, $sess, null, null, $note, $shiftId, $payerId),
                    $r['amount'],
                ];
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        foreach ($paymentIds as [$pid, $amt]) {
            CheckboxService::maybeFiscalize($pdo, $clubId, $pid, $method, $amt, $group['tariff_name'], $skipFiscal);
        }
        Response::ok(['count' => count($paymentIds)], 'Оплату внесено');


    default:
        Response::error("Невідома дія: {$action}", 400);

}} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}

// ── Внутрішня функція: записати платіж ───────────────────────
function self_addPayment(
    PDO    $pdo,
    int    $clubId,
    int    $clientId,
    int    $invoiceId,
    float  $amount,
    string $method,
    array  $sess,
    ?int   $trainerId,
    ?string $trainerName,
    ?string $notes = null,
    ?int   $shiftId = null,
    ?int   $depositClientId = null  // чий депозит списувати (платник за учасника групи); null = власник абонемента
): int {
    $pdo->prepare("
        INSERT INTO club_payments
            (club_id, client_id, invoice_id, amount,
             payment_method, trainer_id, trainer_name,
             shift_id, admin_id, admin_name, notes)
        VALUES (?,?,?,?, ?,?,?, ?,?,?,?)
    ")->execute([
        $clubId, $clientId, $invoiceId, $amount,
        $method, $trainerId, $trainerName,
        $shiftId, $sess['user_id'], $sess['full_name'] ?? null,
        $notes,
    ]);
    $paymentId = (int)$pdo->lastInsertId();
    Recalc::invoicePaidAmount($pdo, $invoiceId);
    Recalc::invoiceStatus($pdo, $invoiceId);
    Recalc::cashflowSyncPayment($pdo, $paymentId);

    if ($method === 'deposit') {
        $depClient = $depositClientId ?: $clientId;
        $pdo->prepare("
            INSERT INTO client_deposits
                (club_id, client_id, amount, operation, invoice_id, admin_id, admin_name)
            VALUES (?,?,-?,'pay_invoice',?,?,?)
        ")->execute([
            $clubId, $depClient, $amount,
            $invoiceId, $sess['user_id'], $sess['full_name'] ?? null,
        ]);
        Recalc::clientBalance($pdo, $depClient);
    }

    // Примітка: оплата абонемента НЕ створює нарахування тренеру.
    // Заробіток тренера виникає виключно з факту відвідування —
    // див. self_createTrainerEarning() в visits_api.php. trainer_ledger
    // (стара модель "оплата = заробіток") заморожена як історичний
    // журнал і нових 'earn'-записів більше не отримує.

    return $paymentId;
}
