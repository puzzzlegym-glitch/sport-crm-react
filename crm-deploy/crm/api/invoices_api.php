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
// Рівно 5 статусів: future / active / frozen / finished / cancelled.
// frozen/cancelled лишаються ручними станами (керуються діями freeze/cancel/restore).
$EFFECTIVE_STATUS_SQL = "(CASE
    WHEN ci.status = 'cancelled' THEN 'cancelled'
    WHEN ci.status = 'frozen' THEN 'frozen'
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
                   COALESCE(has_trainer, 0)        AS has_trainer
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
        if ($status && in_array($status, ['future','active','frozen','finished','cancelled'])) {
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
                {$EFFECTIVE_STATUS_SQL} AS status, ci.sale_type, ci.freeze_days, ci.freeze_start, ci.freeze_current_days,
                ci.trainer_id, ci.trainer_name, ci.admin_name,
                ci.created_at,
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
                   COALESCE(t.freeze_days_min, 0) AS tariff_freeze_min
            FROM client_invoices ci
            JOIN clients c ON c.id = ci.client_id
            LEFT JOIN tariffs t ON t.id = ci.tariff_id
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

        // Борг по інших абонементах — продаж нового заборонено, поки не розрахований попередній
        $debtStmt = $pdo->prepare("
            SELECT SUM(GREATEST(price - paid_amount, 0)) FROM client_invoices
            WHERE client_id = ? AND club_id = ? AND status IN ('active','frozen')
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
        $saleTypeStmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM({$EFFECTIVE_STATUS_SQL} IN ('active','frozen')) AS has_active
            FROM client_invoices ci
            WHERE ci.client_id = ? AND ci.club_id = ?
        ");
        $saleTypeStmt->execute([$clientId, $clubId]);
        $prevInv = $saleTypeStmt->fetch();
        $saleType = 'new';
        if ((int)$prevInv['total'] > 0) {
            $saleType = ((int)$prevInv['has_active'] > 0) ? 'renewal' : 'return';
        }

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
            if (empty($tariff['has_trainer'])) Response::error('Цей тариф не передбачає призначення тренера');
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
                     visits_total, visits_used, status, sale_type,
                     trainer_id, trainer_name,
                     trainer_narah_type, trainer_narah_amount,
                     admin_id, admin_name,
                     created_by, notes)
                VALUES (?,?,?,?, ?,?, ?,?, ?,0,'active',?, ?,?, ?,?, ?,?, ?,?)
            ")->execute([
                $clubId, $clientId, $tariffId, $tariff['name'],
                $finalPrice, 0,
                $startDate, $endDate,
                $tariff['visits_limit'] ?: null, $saleType,
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

        if ($trainerId && empty($tariff['has_trainer'])) {
            Response::error('Цей тариф не передбачає призначення тренера');
        }

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
                trainer_id   = ?,
                trainer_name = ?,
                notes        = ?
            WHERE id = ? AND club_id = ?
        ")->execute([
            $tariffId, $tariffName, $startDate, $endDate,
            $price, $visitsTotal, $visitsUsed,
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
    // Правила:
    //  • перший день заморозки — не раніше НАСТУПНОГО дня після заявки (поточний день
    //    уже почався й у заморозку не входить) або пізніше на вимогу клієнта;
    //  • до першого дня абонемент активний ("запланована заморозка"), 'frozen' вмикає
    //    Recalc::autoUnfreezeExpired у перший день і знімає після останнього;
    //  • end_date подовжується від ПОТОЧНОЇ дати кінця: +N днів заморозки;
    //  • freeze_days — сума всіх заморозок абонемента, freeze_current_days — поточної.
    // Якщо абонемент уже заморожено — ця дія РОЗМОРОЖУЄ (зараховує використані дні).
    case 'freeze':
        if (!Auth::can($sess, $clubId, 'invoices.cancel')) Response::forbidden();

        $id         = (int)($input['id']    ?? 0);
        $freezeDays = (int)($input['days'] ?? 0);
        if (!$id) Response::error('Не вказано id');

        $inv = inv_loadFreezable($pdo, $id, $clubId);
        $today = date('Y-m-d');

        if ($inv['status'] === 'frozen') {
            // ── РОЗМОРОЖУЄМО ─────────────────────────────────
            // Використано днів — від першого дня заморозки до вчора включно; сьогодні
            // клієнт уже активний (може тренуватись у день розморозки).
            $current = inv_currentFreezeDays($inv);
            $used    = max(0, min($current, (int)((strtotime($today) - strtotime($inv['freeze_start'])) / 86400)));
            $unused  = $current - $used;
            $newEnd  = date('Y-m-d', strtotime($inv['end_date'] . " -{$unused} days"));
            $pdo->prepare("
                UPDATE client_invoices SET
                    status = 'active', freeze_days = GREATEST(0, freeze_days - ?),
                    freeze_start = NULL, freeze_current_days = NULL, end_date = ?
                WHERE id = ?
            ")->execute([$unused, $newEnd, $id]);
            Recalc::unlockInvoiceTrainerEarnings($pdo, $id);
            Response::ok(['new_end_date' => $newEnd], "Розморожено. Використано {$used} дн. заморозки. Термін дії: до {$newEnd}.");
        }

        // ── ЗАМОРОЖУЄМО ──────────────────────────────────────
        if ($inv['freeze_start']) {
            Response::error('Заморозку вже заплановано з ' . $inv['freeze_start'] . '. Змініть кількість днів або відмініть її.');
        }
        if ($freezeDays < 1) Response::error('Вкажіть кількість днів заморозки');

        $tomorrow    = date('Y-m-d', strtotime('+1 day'));
        $freezeStart = trim($input['freeze_start'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $freezeStart)) $freezeStart = $tomorrow;
        if ($freezeStart < $tomorrow) {
            Response::error("Заморозка починається не раніше наступного дня після заявки — з {$tomorrow}.");
        }
        // У межах дії абонементу: не в перший день і не пізніше передостаннього.
        $minFreezeStart = max($tomorrow, date('Y-m-d', strtotime($inv['start_date'] . ' +1 day')));
        $maxFreezeStart = date('Y-m-d', strtotime($inv['end_date'] . ' -1 day'));
        if ($freezeStart < $minFreezeStart || $freezeStart > $maxFreezeStart) {
            Response::error("Заморозка можлива лише в межах дії абонементу: з {$minFreezeStart} по {$maxFreezeStart}");
        }

        inv_checkFreezeLimits($pdo, $inv, $freezeDays, (int)$inv['freeze_days'] + $freezeDays);

        $newEnd = date('Y-m-d', strtotime($inv['end_date'] . " +{$freezeDays} days"));
        $pdo->prepare("
            UPDATE client_invoices SET
                freeze_days = freeze_days + ?, freeze_start = ?, freeze_current_days = ?, end_date = ?
            WHERE id = ?
        ")->execute([$freezeDays, $freezeStart, $freezeDays, $newEnd, $id]);
        Recalc::autoUnfreezeExpired($pdo, $clubId);
        Recalc::unlockInvoiceTrainerEarnings($pdo, $id);

        $lastDay = date('Y-m-d', strtotime($freezeStart . ' +' . ($freezeDays - 1) . ' days'));
        Response::ok(['new_end_date' => $newEnd],
            "Заморозку заплановано на {$freezeDays} дн.: з {$freezeStart} по {$lastDay}. До цього клієнт може відвідувати клуб. Новий термін дії: до {$newEnd}.");


    // ════ ВІДМІНИТИ ЗАМОРОЗКУ (повне скасування, без урахування використаних днів) ════
    case 'cancel_freeze':
        if (!Auth::can($sess, $clubId, 'invoices.cancel')) Response::forbidden();

        $id = (int)($input['id'] ?? 0);
        if (!$id) Response::error('Не вказано id');

        $inv = inv_loadFreezable($pdo, $id, $clubId);
        if (!$inv['freeze_start']) Response::error('Абонемент не заморожений і заморозку не заплановано');

        $current     = inv_currentFreezeDays($inv);
        $revertedEnd = date('Y-m-d', strtotime($inv['end_date'] . " -{$current} days"));

        // Не можна відміняти, якщо є відвідування, які через це опиняться поза межами терміну абонементу
        $visitStmt = $pdo->prepare("SELECT MAX(visited_at) FROM visits WHERE invoice_id = ?");
        $visitStmt->execute([$id]);
        $lastVisit = $visitStmt->fetchColumn();
        if ($lastVisit && substr($lastVisit, 0, 10) > $revertedEnd) {
            Response::error("Неможливо відмінити заморозку: є відвідування від " . substr($lastVisit, 0, 10) . ", яке вийде за межі терміну абонементу після скасування. Скористайтесь «Розморозити» — це коректно врахує використані дні заморозки.");
        }

        $pdo->prepare("
            UPDATE client_invoices SET
                status = 'active', freeze_days = GREATEST(0, freeze_days - ?),
                freeze_start = NULL, freeze_current_days = NULL, end_date = ?
            WHERE id = ?
        ")->execute([$current, $revertedEnd, $id]);
        Recalc::unlockInvoiceTrainerEarnings($pdo, $id);

        Response::ok(['new_end_date' => $revertedEnd], "Заморозку відмінено. Термін абонементу: до {$revertedEnd}.");


    // ════ ЗМІНИТИ КІЛЬКІСТЬ ДНІВ ПОТОЧНОЇ ЗАМОРОЗКИ (напр. на прохання клієнта) ═
    case 'update_freeze_days':
        if (!Auth::can($sess, $clubId, 'invoices.cancel')) Response::forbidden();

        $id      = (int)($input['id']   ?? 0);
        $newDays = (int)($input['days'] ?? -1);
        if (!$id) Response::error('Не вказано id');
        if ($newDays < 1) Response::error('Вкажіть кількість днів заморозки');

        $inv = inv_loadFreezable($pdo, $id, $clubId);
        if (!$inv['freeze_start']) Response::error('Абонемент не заморожений і заморозку не заплановано');

        $current = inv_currentFreezeDays($inv);
        // Уже минулі дні заморозки не "повернути": не менше, ніж уже використано + сьогодні.
        $usedIncl = max(0, (int)((strtotime(date('Y-m-d')) - strtotime($inv['freeze_start'])) / 86400) + 1);
        if ($inv['status'] === 'frozen' && $newDays < $usedIncl) {
            Response::error("Заморозка вже триває {$usedIncl} дн. (включно з сьогодні). Щоб завершити її раніше — натисніть «Розморозити».");
        }

        $delta = $newDays - $current;
        inv_checkFreezeLimits($pdo, $inv, $newDays, (int)$inv['freeze_days'] + $delta);
        $newEnd = date('Y-m-d', strtotime($inv['end_date'] . ($delta >= 0 ? " +{$delta}" : " {$delta}") . ' days'));

        // Не можна зменшувати, якщо це виштовхне вже зафіксовані відвідування за межі нового терміну
        $visitStmt = $pdo->prepare("SELECT MAX(visited_at) FROM visits WHERE invoice_id = ?");
        $visitStmt->execute([$id]);
        $lastVisit = $visitStmt->fetchColumn();
        if ($lastVisit && substr($lastVisit, 0, 10) > $newEnd) {
            Response::error("Неможливо змінити на {$newDays} дн.: є відвідування від " . substr($lastVisit, 0, 10) . ", яке вийде за межі терміну абонементу.");
        }

        $pdo->prepare("
            UPDATE client_invoices SET
                freeze_days = GREATEST(0, freeze_days + ?), freeze_current_days = ?, end_date = ?
            WHERE id = ?
        ")->execute([$delta, $newDays, $newEnd, $id]);
        Recalc::autoUnfreezeExpired($pdo, $clubId);
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
    ?int   $shiftId = null
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
        $pdo->prepare("
            INSERT INTO client_deposits
                (club_id, client_id, amount, operation, invoice_id, admin_id, admin_name)
            VALUES (?,?,-?,'pay_invoice',?,?,?)
        ")->execute([
            $clubId, $clientId, $amount,
            $invoiceId, $sess['user_id'], $sess['full_name'] ?? null,
        ]);
        Recalc::clientBalance($pdo, $clientId);
    }

    // Примітка: оплата абонемента НЕ створює нарахування тренеру.
    // Заробіток тренера виникає виключно з факту відвідування —
    // див. self_createTrainerEarning() в visits_api.php. trainer_ledger
    // (стара модель "оплата = заробіток") заморожена як історичний
    // журнал і нових 'earn'-записів більше не отримує.

    return $paymentId;
}

// ── Заморозка: хелпери ───────────────────────────────────────
function inv_loadFreezable(PDO $pdo, int $id, int $clubId): array {
    $stmt = $pdo->prepare("
        SELECT * FROM client_invoices
        WHERE id = ? AND club_id = ? AND status IN ('active','frozen')
        LIMIT 1
    ");
    $stmt->execute([$id, $clubId]);
    $inv = $stmt->fetch();
    if (!$inv) Response::error('Абонемент не знайдено або він не активний');
    return $inv;
}

// Тривалість поточної заморозки (для записів до появи freeze_current_days — загальна).
function inv_currentFreezeDays(array $inv): int {
    return (int)($inv['freeze_current_days'] ?? $inv['freeze_days']);
}

// Ліміти тарифу: мінімум — на одну заморозку, максимум — на весь абонемент (сума заморозок).
function inv_checkFreezeLimits(PDO $pdo, array $inv, int $days, int $totalDays): void {
    if (!$inv['tariff_id']) return;
    $st = $pdo->prepare("SELECT freeze_days_max, freeze_days_min FROM tariffs WHERE id=? LIMIT 1");
    $st->execute([$inv['tariff_id']]);
    $t = $st->fetch() ?: [];
    $max = (int)($t['freeze_days_max'] ?? 0);
    $min = (int)($t['freeze_days_min'] ?? 0);
    if ($min > 0 && $days < $min) Response::error("Мінімум заморозки для цього тарифу: {$min} дн.");
    if ($max > 0 && $totalDays > $max) {
        $left = max(0, $max - ((int)$inv['freeze_days'] - inv_currentFreezeDays($inv) * ($inv['freeze_start'] ? 1 : 0)));
        Response::error("Максимум заморозки для цього тарифу: {$max} дн. на абонемент. Доступно: {$left} дн.");
    }
}
