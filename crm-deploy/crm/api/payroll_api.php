<?php
/**
 * payroll_api.php — Нарахування зарплат співробітникам
 *
 * Компоненти (будь-яка комбінація прапорців):
 *   pay_month  — фіксований оклад/місяць
 *   pay_day    — ставка за зміну × кількість закритих cash_shifts цього співробітника за місяць
 *                (лічильник рахує ЗАКРИТІ зміни, поле "days_worked" далі — авто-підказка,
 *                редагована вручну на фронтенді; pay_hour лишається в схемі, але не в UI)
 *   pct_tovar  — % від власних продажів товарів співробітника за місяць (admin_id),
 *                понад pct_tovar_min
 *   pct_abon   — % від власних оплат абонементів співробітника за місяць (admin_id),
 *                понад pct_abon_min
 *   plan_*     — бонус % від суми (власні товари+абонементи − plan_amount), якщо перевищено
 *
 * Усі %-компоненти і plan рахуються по admin_id конкретного співробітника, НЕ по клубу
 * в цілому — критично, коли на добу відкривається кілька змін різними адмінами.
 * Повернені чеки (sale_orders.status='returned') у % від товарів не входять.
 *
 * Виплата (pay_payroll) створює захищену витрату club_expenses (source='staff_payroll'):
 * готівка — зменшує касу (у відкриту зміну), картка — лише облік у Фінансах.
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

$access = Auth::requireClubAccess($sess, $clubId, 30);
if (!Auth::can($sess, $clubId, 'users.manage')) Response::forbidden();

function assertUserInClub(PDO $pdo, int $userId, int $clubId): void {
    $s = $pdo->prepare("SELECT 1 FROM sys_user_clubs WHERE user_id=? AND club_id=? LIMIT 1");
    $s->execute([$userId, $clubId]);
    if (!$s->fetchColumn()) Response::error('Співробітника не знайдено в клубі', 404);
}

function monthRange(string $month): array {
    $d1 = $month . '-01';
    return [$d1, date('Y-m-t', strtotime($d1))];
}

function countWorkedShifts(PDO $pdo, int $clubId, int $userId, string $month): array {
    [$d1, $d2] = monthRange($month);
    $s = $pdo->prepare("SELECT COUNT(*) FROM cash_shifts WHERE club_id=? AND opened_by=? AND status='closed' AND DATE(opened_at) BETWEEN ? AND ?");
    $s->execute([$clubId, $userId, $d1, $d2]);
    $closed = (int)$s->fetchColumn();
    $s2 = $pdo->prepare("SELECT COUNT(*) FROM cash_shifts WHERE club_id=? AND opened_by=? AND status!='closed' AND DATE(opened_at) BETWEEN ? AND ?");
    $s2->execute([$clubId, $userId, $d1, $d2]);
    $hasOpen = (bool)$s2->fetchColumn();
    return ['shifts_worked' => $closed, 'has_open_shift' => $hasOpen];
}

function calcComponents(PDO $pdo, int $clubId, int $userId, array $cfg, string $month,
                        float $daysWorked, float $hoursWorked): array {
    [$d1, $d2] = monthRange($month);

    $payMonth = $cfg['pay_month_on'] ? round((float)$cfg['pay_month_amount'], 2) : 0;
    $payDay   = $cfg['pay_day_on']   ? round((float)$cfg['pay_day_amount']  * $daysWorked,  2) : 0;
    $payHour  = $cfg['pay_hour_on']  ? round((float)$cfg['pay_hour_amount'] * $hoursWorked, 2) : 0;

    $planOn = !empty($cfg['plan_on']);

    // Продажі/оплати рахуються ЛИШЕ на цього співробітника (admin_id — хто фактично
    // провів продаж/оплату, записується в product_sales/club_payments кожним запитом),
    // а не по клубу в цілому — інакше при кількох адмінах/змінах на день % рахувався б
    // усім з однієї спільної суми.
    $tovarSum = 0;
    if (($cfg['pct_tovar_on'] && $cfg['pct_tovar_value'] > 0) || $planOn) {
        $s = $pdo->prepare("
            SELECT COALESCE(SUM(ps.total_amount),0)
            FROM product_sales ps
            LEFT JOIN sale_orders so ON so.id = ps.order_id
            WHERE ps.club_id=? AND ps.admin_id=? AND DATE(ps.created_at) BETWEEN ? AND ?
              AND (so.id IS NULL OR so.status <> 'returned')
        ");
        $s->execute([$clubId, $userId, $d1, $d2]);
        $tovarSum = (float)$s->fetchColumn();
    }
    $pctTovar = ($cfg['pct_tovar_on'] && $cfg['pct_tovar_value'] > 0)
        ? round(max(0, $tovarSum - (float)($cfg['pct_tovar_min'] ?? 0)) * (float)$cfg['pct_tovar_value'] / 100, 2) : 0;

    $abonSum = 0;
    if (($cfg['pct_abon_on'] && $cfg['pct_abon_value'] > 0) || $planOn) {
        $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM club_payments WHERE club_id=? AND admin_id=? AND invoice_id IS NOT NULL AND DATE(created_at) BETWEEN ? AND ?");
        $s->execute([$clubId, $userId, $d1, $d2]);
        $abonSum = (float)$s->fetchColumn();
    }
    $pctAbon = ($cfg['pct_abon_on'] && $cfg['pct_abon_value'] > 0)
        ? round(max(0, $abonSum - (float)($cfg['pct_abon_min'] ?? 0)) * (float)$cfg['pct_abon_value'] / 100, 2) : 0;

    $planFact  = $planOn ? round($tovarSum + $abonSum, 2) : 0;
    $planBonus = 0;
    if ($planOn && $cfg['plan_bonus_pct'] > 0) {
        $excess = max(0, $planFact - (float)$cfg['plan_amount']);
        $planBonus = round($excess * (float)$cfg['plan_bonus_pct'] / 100, 2);
    }

    return [
        'pay_month' => $payMonth, 'pay_day'  => $payDay,  'pay_hour' => $payHour,
        'pct_tovar' => $pctTovar, 'pct_abon' => $pctAbon,
        'plan_fact' => $planFact, 'plan_bonus' => $planBonus,
        'total'     => round($payMonth + $payDay + $payHour + $pctTovar + $pctAbon + $planBonus, 2),
    ];
}

try { switch ($action) {

case 'get_team':
    $s = $pdo->prepare("SELECT u.id, u.full_name, r.name_ua AS role_name, r.slug AS role_slug FROM sys_user_clubs uc JOIN sys_users u ON u.id=uc.user_id JOIN sys_roles r ON r.id=uc.role_id WHERE uc.club_id=? AND uc.is_active=1 AND r.slug!='superadmin' ORDER BY r.level DESC, u.full_name ASC");
    $s->execute([$clubId]);
    Response::ok(['team' => $s->fetchAll()]);

case 'get_settings':
    $userId = (int)($input['user_id'] ?? $_GET['user_id'] ?? 0);
    if (!$userId) Response::error('Вкажіть user_id');
    assertUserInClub($pdo, $userId, $clubId);
    $s = $pdo->prepare("SELECT * FROM staff_salary_settings WHERE user_id=? AND club_id=? LIMIT 1");
    $s->execute([$userId, $clubId]);
    Response::ok(['settings' => $s->fetch() ?: null]);

case 'save_settings':
    $userId = (int)($input['user_id'] ?? 0);
    if (!$userId) Response::error('Вкажіть user_id');
    assertUserInClub($pdo, $userId, $clubId);

    $f = [
        'pay_month_on'     => (int)(bool)($input['pay_month_on']     ?? 0),
        'pay_month_amount' => round((float)($input['pay_month_amount'] ?? 0), 2),
        'pay_day_on'       => (int)(bool)($input['pay_day_on']        ?? 0),
        'pay_day_amount'   => round((float)($input['pay_day_amount']   ?? 0), 2),
        'pay_hour_on'      => (int)(bool)($input['pay_hour_on']       ?? 0),
        'pay_hour_amount'  => round((float)($input['pay_hour_amount']  ?? 0), 2),
        'pct_tovar_on'     => (int)(bool)($input['pct_tovar_on']      ?? 0),
        'pct_tovar_value'  => round((float)($input['pct_tovar_value']  ?? 0), 4),
        'pct_tovar_min'    => round((float)($input['pct_tovar_min']    ?? 0), 2),
        'pct_abon_on'      => (int)(bool)($input['pct_abon_on']       ?? 0),
        'pct_abon_value'   => round((float)($input['pct_abon_value']   ?? 0), 4),
        'pct_abon_min'     => round((float)($input['pct_abon_min']     ?? 0), 2),
        'plan_on'          => (int)(bool)($input['plan_on']           ?? 0),
        'plan_amount'      => round((float)($input['plan_amount']      ?? 0), 2),
        'plan_bonus_pct'   => round((float)($input['plan_bonus_pct']   ?? 0), 4),
        'notes'            => trim($input['notes'] ?? ''),
    ];
    $cols    = implode(',', array_keys($f));
    $vals    = implode(',', array_fill(0, count($f), '?'));
    $updates = implode(',', array_map(fn($k) => "$k=VALUES($k)", array_keys($f)));
    $pdo->prepare("INSERT INTO staff_salary_settings (user_id,club_id,$cols,updated_at) VALUES (?,?,$vals,NOW()) ON DUPLICATE KEY UPDATE $updates,updated_at=NOW()")
        ->execute([$userId, $clubId, ...array_values($f)]);
    Response::ok([], 'Налаштування збережено');

case 'get_worked_shifts':
    $userId = (int)($input['user_id'] ?? $_GET['user_id'] ?? 0);
    $month  = trim($input['month']    ?? $_GET['month']   ?? '');
    if (!$userId) Response::error('Вкажіть user_id');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) Response::error('Невірний формат місяця');
    assertUserInClub($pdo, $userId, $clubId);
    Response::ok(countWorkedShifts($pdo, $clubId, $userId, $month));

case 'calc_preview':
    $userId      = (int)($input['user_id']      ?? 0);
    $month       = trim($input['month']          ?? '');
    $daysWorked  = round((float)($input['days_worked']  ?? 0), 1);
    $hoursWorked = round((float)($input['hours_worked'] ?? 0), 2);
    if (!$userId) Response::error('Вкажіть user_id');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) Response::error('Невірний формат місяця');
    assertUserInClub($pdo, $userId, $clubId);
    $s = $pdo->prepare("SELECT * FROM staff_salary_settings WHERE user_id=? AND club_id=? LIMIT 1");
    $s->execute([$userId, $clubId]);
    $cfg = $s->fetch();
    if (!$cfg) Response::error('Налаштування не знайдено. Спочатку збережіть налаштування.');
    $calc = calcComponents($pdo, $clubId, $userId, $cfg, $month, $daysWorked, $hoursWorked);
    Response::ok(['preview' => array_merge($calc, ['cfg' => $cfg])]);

case 'create_payroll':
    $userId      = (int)($input['user_id']       ?? 0);
    $month       = trim($input['month']           ?? '');
    $daysWorked  = round((float)($input['days_worked']  ?? 0), 1);
    $hoursWorked = round((float)($input['hours_worked'] ?? 0), 2);
    $notes       = trim($input['notes']           ?? '');
    if (!$userId) Response::error('Вкажіть user_id');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) Response::error('Невірний формат місяця');
    assertUserInClub($pdo, $userId, $clubId);
    $s = $pdo->prepare("SELECT * FROM staff_salary_settings WHERE user_id=? AND club_id=? LIMIT 1");
    $s->execute([$userId, $clubId]);
    $cfg = $s->fetch();
    if (!$cfg) Response::error('Налаштування не знайдено');

    // Захист від перезапису вже виплаченого/частково виплаченого нарахування:
    // без цієї перевірки повторний виклик (перегенерація прев'ю → створення)
    // перезаписав би total_amount, а вже зафіксований paid_amount лишився б
    // як був — сума до виплати могла б стати від'ємною або статус розʼїхатись.
    $existing = $pdo->prepare("SELECT status, paid_amount FROM staff_payroll WHERE user_id=? AND club_id=? AND period_month=? LIMIT 1");
    $existing->execute([$userId, $clubId, $month]);
    $prev = $existing->fetch();
    if ($prev && $prev['status'] !== 'pending') {
        Response::error('Нарахування за цей місяць вже частково або повністю виплачене — перерахунок заблоковано. Спочатку скасуйте виплату, якщо потрібно перерахувати.', 409);
    }

    $c = calcComponents($pdo, $clubId, $userId, $cfg, $month, $daysWorked, $hoursWorked);
    $pdo->prepare("INSERT INTO staff_payroll (user_id,club_id,period_month,pay_month,pay_day,pay_hour,pct_tovar,pct_abon,plan_bonus,days_worked,hours_worked,total_amount,paid_amount,status,notes,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,'pending',?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE pay_month=VALUES(pay_month),pay_day=VALUES(pay_day),pay_hour=VALUES(pay_hour),pct_tovar=VALUES(pct_tovar),pct_abon=VALUES(pct_abon),plan_bonus=VALUES(plan_bonus),days_worked=VALUES(days_worked),hours_worked=VALUES(hours_worked),total_amount=VALUES(total_amount),notes=VALUES(notes),updated_at=NOW()")
        ->execute([$userId, $clubId, $month, $c['pay_month'], $c['pay_day'], $c['pay_hour'], $c['pct_tovar'], $c['pct_abon'], $c['plan_bonus'], $daysWorked, $hoursWorked, $c['total'], $notes, $sess['user_id'] ?? null]);
    Response::ok(['total_amount' => $c['total']], 'Нарахування зафіксовано');

case 'get_payroll':
    $userId = (int)($input['user_id'] ?? $_GET['user_id'] ?? 0);
    $month  = trim($input['month']   ?? $_GET['month']   ?? '');
    $where  = ['sp.club_id=?']; $params = [$clubId];
    if ($userId) { $where[] = 'sp.user_id=?'; $params[] = $userId; }
    if (preg_match('/^\d{4}-\d{2}$/', $month)) { $where[] = 'sp.period_month=?'; $params[] = $month; }
    $s = $pdo->prepare("SELECT sp.*,u.full_name,r.name_ua AS role_name FROM staff_payroll sp JOIN sys_users u ON u.id=sp.user_id LEFT JOIN sys_user_clubs uc ON uc.user_id=sp.user_id AND uc.club_id=sp.club_id LEFT JOIN sys_roles r ON r.id=uc.role_id WHERE ".implode(' AND ',$where)." ORDER BY sp.period_month DESC,u.full_name ASC LIMIT 200");
    $s->execute($params);
    Response::ok(['payroll' => $s->fetchAll()]);

case 'get_summary':
    $month  = trim($input['month']   ?? $_GET['month']   ?? '');
    $userId = (int)($input['user_id'] ?? $_GET['user_id'] ?? 0);
    $where  = ['club_id=?']; $params = [$clubId];
    if ($userId) { $where[] = 'user_id=?'; $params[] = $userId; }
    if (preg_match('/^\d{4}-\d{2}$/', $month)) { $where[] = 'period_month=?'; $params[] = $month; }
    $s = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) AS total_accrued,COALESCE(SUM(paid_amount),0) AS total_paid,COALESCE(SUM(total_amount-paid_amount),0) AS total_pending,COUNT(*) AS cnt FROM staff_payroll WHERE ".implode(' AND ',$where));
    $s->execute($params);
    Response::ok(['summary' => $s->fetch()]);

case 'pay_payroll':
    $payrollId = (int)($input['payroll_id'] ?? 0);
    $amount    = round((float)($input['amount'] ?? 0), 2);
    $method    = ($input['payment_method'] ?? 'cash') === 'card' ? 'card' : 'cash';
    if (!$payrollId) Response::error('Вкажіть payroll_id');
    if ($amount <= 0) Response::error('Сума має бути > 0');

    // Готівкова виплата — у відкриту зміну каси (інакше звіт зміни її не бачить)
    $shiftId = null;
    if ($method === 'cash') {
        $sh = $pdo->prepare("SELECT id FROM cash_shifts WHERE club_id=? AND status='open' LIMIT 1");
        $sh->execute([$clubId]);
        $shiftId = (int)$sh->fetchColumn() ?: null;
    }

    $pdo->beginTransaction();
    // FOR UPDATE — подвійне натискання "Виплатити" не проведе виплату двічі
    $row = $pdo->prepare("
        SELECT sp.*, u.full_name FROM staff_payroll sp JOIN sys_users u ON u.id = sp.user_id
        WHERE sp.id=? AND sp.club_id=? LIMIT 1 FOR UPDATE
    ");
    $row->execute([$payrollId, $clubId]);
    $p = $row->fetch();
    $err = null;
    if (!$p)                          $err = 'Нарахування не знайдено';
    elseif ($p['status'] === 'paid')  $err = 'Вже виплачено';
    else {
        $canPay = round((float)$p['total_amount'] - (float)$p['paid_amount'], 2);
        if ($amount > $canPay) $err = "Максимум до виплати: {$canPay} грн";
    }
    if ($err) { $pdo->rollBack(); Response::error($err); }

    $newPaid   = round((float)$p['paid_amount'] + $amount, 2);
    $newStatus = $newPaid >= (float)$p['total_amount'] ? 'paid' : 'partial';
    $pdo->prepare("UPDATE staff_payroll SET paid_amount=?,status=?,updated_at=NOW() WHERE id=?")
        ->execute([$newPaid, $newStatus, $payrollId]);

    // Захищений системний запис витрати (не редагується/не видаляється вручну, як trainer_payout)
    $pdo->prepare("
        INSERT INTO club_expenses
            (club_id, category, description, amount, expense_date, payment_method, shift_id,
             admin_id, admin_name, notes, source, source_id)
        VALUES (?, 'Зарплата персоналу', ?, ?, CURDATE(), ?, ?, ?, ?, NULL, 'staff_payroll', ?)
    ")->execute([
        $clubId, "Зарплата {$p['full_name']} за {$p['period_month']}", $amount, $method, $shiftId,
        $sess['user_id'], $sess['full_name'] ?? null, $payrollId,
    ]);
    Recalc::cashflowSyncExpense($pdo, (int)$pdo->lastInsertId());
    $pdo->commit();
    Response::ok(['paid_amount' => $newPaid, 'status' => $newStatus], 'Виплату зафіксовано');

// ════ ІСТОРІЯ ВИПЛАТ ЗА НАРАХУВАННЯМ ═══════════════════════════
case 'get_payouts':
    $payrollId = (int)($input['payroll_id'] ?? 0);
    $st = $pdo->prepare("
        SELECT id, amount, payment_method, expense_date, admin_name, description
        FROM club_expenses WHERE club_id=? AND source='staff_payroll' AND source_id=?
        ORDER BY id DESC
    ");
    $st->execute([$clubId, $payrollId]);
    Response::ok(['payouts' => $st->fetchAll()]);

// ════ СТОРНО ВИПЛАТИ (лише власник, з причиною) ═══════════════
case 'reverse_payout':
    if (!Auth::isOwner($sess, $access)) Response::forbidden('Сторно — лише власник клубу');
    $expenseId = (int)($input['id'] ?? 0);
    $reason    = trim($input['reason'] ?? '');
    if (!$expenseId) Response::error('Вкажіть id');
    if ($reason === '') Response::error('Вкажіть причину сторно');
    $pdo->beginTransaction();
    try {
        Payouts::reverseStaffPayout($pdo, $clubId, $expenseId, $reason, $sess);
        $pdo->commit();
    } catch (RuntimeException $ex) {
        $pdo->rollBack();
        Response::error($ex->getMessage());
    }
    Response::ok([], 'Сторно проведено');

default:
    Response::error('Невідома дія', 400);

}} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('payroll_api: ' . $e->getMessage());
    Response::error('Помилка сервера', 500);
}
