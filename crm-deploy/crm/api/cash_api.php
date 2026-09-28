<?php
/**
 * cash_api.php — API каси готівки
 *
 * Правила доступу:
 *   Owner (80+) — повний доступ завжди
 *   Адмін — операції тільки якщо його зміна відкрита (opened_by = user_id)
 *   Перегляд — доступний всім
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once __DIR__ . '/../classes/CashShiftService.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$sess   = Auth::requireAuth();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';
$pdo    = Database::get();

$clubId  = (int)($input['club_id'] ?? $_GET['club_id'] ?? $sess['active_club_id'] ?? 0);
if (!$clubId) Response::error('Не обрано клуб', 400);

$access   = Auth::requireClubAccess($sess, $clubId, 30);
$isOwner  = Auth::isOwner($sess, $access);
$userId   = (int)$sess['user_id'];

// ── Хелпери ──────────────────────────────────────────────────
// Баланс каси і закриття зміни — CashShiftService (спільний з cron/auto_close_shifts.php).

// Owner завжди проходить. Адмін — тільки якщо його зміна відкрита.
function requireOwnShift(PDO $pdo, int $clubId, int $userId, bool $isOwner): array {
    if ($isOwner) return CashShiftService::getOpenShift($pdo, $clubId) ?? [];
    $shift = CashShiftService::getOpenShift($pdo, $clubId);
    if (!$shift) Response::error('Зміна не відкрита. Відкрийте зміну перед початком роботи.', 403);
    if ((int)$shift['opened_by'] !== $userId)
        Response::error("Зараз відкрита зміна адміністратора «{$shift['opened_name']}». Ви не можете проводити операції в чужій зміні.", 403);
    return $shift;
}

try { switch ($action) {

    // ════ ПІДСУМОК ════════════════════════════════════════════
    case 'get_summary':
        $dateFrom   = $input['date_from'] ?? date('Y-m-01');
        $dateTo     = $input['date_to']   ?? date('Y-m-d');
        $balance    = CashShiftService::balance($pdo, $clubId, 'register');
        $safeBalance = CashShiftService::balance($pdo, $clubId, 'safe');
        $per = $pdo->prepare("
            SELECT COALESCE(SUM(CASE WHEN type IN('income','transfer_in') THEN amount ELSE 0 END),0) AS period_in,
                   COALESCE(SUM(CASE WHEN type IN('expense','encashment','transfer_out') THEN amount ELSE 0 END),0) AS period_out
            FROM club_cashflow
            WHERE club_id=? AND payment_method='cash' AND location='register' AND DATE(created_at) BETWEEN ? AND ?
        ");
        $per->execute([$clubId, $dateFrom, $dateTo]);
        $p = $per->fetch();
        Response::ok([
            'balance'         => round($balance, 2),
            'safe_balance'    => round($safeBalance, 2),
            'period_income'   => round((float)$p['period_in'], 2),
            'period_expenses' => round((float)$p['period_out'], 2),
            'period_profit'   => round((float)$p['period_in'] - (float)$p['period_out'], 2),
            'shift'           => CashShiftService::getOpenShift($pdo, $clubId),
        ]);


    // ════ ЖУРНАЛ ══════════════════════════════════════════════
    case 'get_list':
        $dateFrom   = $input['date_from'] ?? date('Y-m-01');
        $dateTo     = $input['date_to']   ?? date('Y-m-d');
        $typeFilter = trim($input['type'] ?? '');
        $location   = trim($input['location'] ?? 'register');
        if (!in_array($location, ['register','safe'])) $location = 'register';
        $page       = max(1, (int)($input['page'] ?? 1));
        $perPage    = 50;
        $offset     = ($page - 1) * $perPage;

        $where  = ["c1.club_id=?", "c1.payment_method='cash'", "c1.location=?", "DATE(c1.created_at) BETWEEN ? AND ?"];
        $params = [$clubId, $location, $dateFrom, $dateTo];
        if ($typeFilter === 'transfer') {
            $where[] = "c1.type IN('transfer_in','transfer_out')";
        } elseif ($typeFilter && in_array($typeFilter, ['income','expense','transfer_in','transfer_out','encashment','adjustment'])) {
            $where[] = 'c1.type=?'; $params[] = $typeFilter;
        }
        $whereSQL = implode(' AND ', $where);
        $cntWhere = str_replace('c1.', '', $whereSQL);

        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM club_cashflow WHERE {$cntWhere}");
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT c1.id, c1.type, c1.category, c1.description, c1.amount,
                   c1.source, c1.notes, c1.admin_name, c1.created_at, c1.shift_id, c1.location,
              (SELECT COALESCE(SUM(CASE WHEN c2.type IN('income','transfer_in') THEN c2.amount ELSE 0 END),0) -
                      COALESCE(SUM(CASE WHEN c2.type IN('expense','encashment','transfer_out') THEN c2.amount ELSE 0 END),0) +
                      COALESCE(SUM(CASE WHEN c2.type='adjustment' THEN c2.amount ELSE 0 END),0)
               FROM club_cashflow c2
               WHERE c2.club_id=? AND c2.payment_method='cash' AND c2.location=?
                 AND (c2.created_at < c1.created_at OR (c2.created_at=c1.created_at AND c2.id<=c1.id))
              ) AS running_balance
            FROM club_cashflow c1 WHERE {$whereSQL}
            ORDER BY c1.created_at DESC LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge([$clubId, $location], $params, [$perPage, $offset]));
        Response::ok([
            'rows'       => $stmt->fetchAll(),
            'pagination' => ['total'=>$total,'page'=>$page,'per_page'=>$perPage,'pages'=>max(1,(int)ceil($total/$perPage))],
        ]);


    // ════ РУЧНА ВИТРАТА (пише в club_expenses; Recalc::cashflowSyncExpense дзеркалить у club_cashflow) ══
    case 'add_expense':
        if (!Auth::can($sess, $clubId, 'cash.record')) Response::forbidden();
        $shift  = requireOwnShift($pdo, $clubId, $userId, $isOwner);
        $amount = round((float)($input['amount'] ?? 0), 2);
        $desc   = trim($input['description'] ?? '');
        $cat    = trim($input['category']    ?? 'Інше');
        if ($amount <= 0) Response::error('Сума має бути більше 0');
        if (!$desc)       Response::error('Вкажіть опис витрати');
        $pdo->prepare("
            INSERT INTO club_expenses
              (club_id,category,description,amount,expense_date,payment_method,shift_id,admin_id,admin_name)
            VALUES (?,?,?,?, CURDATE(), 'cash', ?,?,?)
        ")->execute([$clubId,$cat,$desc,$amount,$shift['id']??null,$userId,$sess['name']??'Адмін']);
        Recalc::cashflowSyncExpense($pdo, (int)$pdo->lastInsertId());
        Response::ok([], 'Витрату записано');


    // ════ ІНКАСАЦІЯ В СЕЙФ (переказ register → safe) ═════════
    case 'encashment':
        if (!Auth::can($sess, $clubId, 'cash.record')) Response::forbidden();
        $shift  = requireOwnShift($pdo, $clubId, $userId, $isOwner);
        $amount = round((float)($input['amount'] ?? 0), 2);
        $desc   = trim($input['description'] ?? 'Інкасація в сейф');
        if ($amount <= 0) Response::error('Сума має бути більше 0');
        $balance = CashShiftService::balance($pdo, $clubId, 'register');
        if ($amount > $balance) Response::error("В касі лише {$balance} грн. Неможливо вилучити {$amount} грн.");
        $name = $sess['name'] ?? 'Адмін';
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                INSERT INTO club_cashflow
                  (club_id,type,category,description,amount,payment_method,source,shift_id,location,admin_id,admin_name)
                VALUES (?,'transfer_out','Інкасація',?,?,'cash','transfer',?,'register',?,?)
            ")->execute([$clubId,$desc,$amount,$shift['id']??null,$userId,$name]);
            $pdo->prepare("
                INSERT INTO club_cashflow
                  (club_id,type,category,description,amount,payment_method,source,location,admin_id,admin_name)
                VALUES (?,'transfer_in','Інкасація',?,?,'cash','transfer','safe',?,?)
            ")->execute([$clubId,$desc,$amount,$userId,$name]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); Response::serverError($e->getMessage()); }

        // Telegram: сповіщення власника клубу (не блокує основний потік)
        try {
            Telegram::notifyClubOwners($clubId, "💵 Інкасація в сейф: " . number_format($amount, 2) . " грн ({$desc})");
        } catch (Throwable $e) {
            error_log('[Cash] Telegram notify failed: ' . $e->getMessage());
        }

        Response::ok([], 'Інкасацію проведено');


    // ════ ПОПОВНЕННЯ КАСИ З СЕЙФА (переказ safe → register) ═════
    case 'refill_from_safe':
        if (!Auth::can($sess, $clubId, 'cash.record')) Response::forbidden();
        $shift  = requireOwnShift($pdo, $clubId, $userId, $isOwner);
        $amount = round((float)($input['amount'] ?? 0), 2);
        $desc   = trim($input['description'] ?? 'Поповнення каси з сейфа');
        if ($amount <= 0) Response::error('Сума має бути більше 0');
        $safeBalance = CashShiftService::balance($pdo, $clubId, 'safe');
        if ($amount > $safeBalance) Response::error("В сейфі лише {$safeBalance} грн. Неможливо забрати {$amount} грн.");
        $name = $sess['name'] ?? 'Адмін';
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                INSERT INTO club_cashflow
                  (club_id,type,category,description,amount,payment_method,source,location,admin_id,admin_name)
                VALUES (?,'transfer_out','Поповнення каси',?,?,'cash','transfer','safe',?,?)
            ")->execute([$clubId,$desc,$amount,$userId,$name]);
            $pdo->prepare("
                INSERT INTO club_cashflow
                  (club_id,type,category,description,amount,payment_method,source,shift_id,location,admin_id,admin_name)
                VALUES (?,'transfer_in','Поповнення каси',?,?,'cash','transfer',?,'register',?,?)
            ")->execute([$clubId,$desc,$amount,$shift['id']??null,$userId,$name]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); Response::serverError($e->getMessage()); }
        Response::ok([], 'Касу поповнено з сейфа');


    // ════ КОРИГУВАННЯ БАЛАНСУ (стартовий залишок / розбіжність при перерахунку) ══
    case 'adjust_balance':
        if (!Auth::can($sess, $clubId, 'cash.record')) Response::forbidden();
        $location = trim($input['location'] ?? 'register');
        if (!in_array($location, ['register','safe'])) Response::error('Невірна локація');
        $amount = round((float)($input['amount'] ?? 0), 2);
        $desc   = trim($input['notes'] ?? '') ?: 'Коригування балансу';
        if ($amount == 0) Response::error('Сума коригування не може бути 0');
        $shiftId = null;
        if ($location === 'register') {
            $shift = requireOwnShift($pdo, $clubId, $userId, $isOwner);
            $shiftId = $shift['id'] ?? null;
        }
        $pdo->prepare("
            INSERT INTO club_cashflow
              (club_id,type,category,description,amount,payment_method,source,shift_id,location,admin_id,admin_name)
            VALUES (?,'adjustment','Коригування',?,?,'cash','adjustment',?,?,?,?)
        ")->execute([$clubId,$desc,$amount,$shiftId,$location,$userId,$sess['name']??'Адмін']);
        Response::ok([], 'Баланс скориговано');


    // ════ ВИДАЛЕННЯ (isOwner) ════════════════════════════════
    case 'delete':
        if (!Auth::can($sess, $clubId, 'cash.delete')) Response::forbidden();
        $id = (int)($input['id'] ?? 0);
        if (!$id) Response::error('Не вказано id');
        $rowSt = $pdo->prepare("SELECT * FROM club_cashflow WHERE id=? AND club_id=? LIMIT 1");
        $rowSt->execute([$id, $clubId]);
        $cf = $rowSt->fetch();
        if (!$cf) Response::error('Запис не знайдено', 404);

        if ($cf['source'] === 'expense' && $cf['source_id']) {
            // Витрата тепер живе в club_expenses — Recalc::cashflowSyncExpense
            // сам прибере дзеркальний рядок.
            $expSt = $pdo->prepare("SELECT source FROM club_expenses WHERE id=? AND club_id=? LIMIT 1");
            $expSt->execute([$cf['source_id'], $clubId]);
            $exp = $expSt->fetch();
            if (!$exp || $exp['source'] !== 'manual') Response::error('Витрату не знайдено або вона системна (не підлягає видаленню)', 404);
            $pdo->prepare("DELETE FROM club_expenses WHERE id=? AND club_id=?")->execute([$cf['source_id'], $clubId]);
            Recalc::cashflowSyncExpense($pdo, (int)$cf['source_id']);
        } elseif (in_array($cf['source'], ['manual','transfer','adjustment'])) {
            $pdo->prepare("DELETE FROM club_cashflow WHERE id=? AND club_id=?")->execute([$id, $clubId]);
        } else {
            Response::error('Цей запис не можна видалити напряму', 403);
        }
        Response::ok([], 'Запис видалено');


    // ════ ПОТОЧНА ЗМІНА ══════════════════════════════════════
    case 'get_shift':
        Response::ok([
            'shift'        => CashShiftService::getOpenShift($pdo, $clubId),
            'balance'      => CashShiftService::balance($pdo, $clubId, 'register'),
            'safe_balance' => CashShiftService::balance($pdo, $clubId, 'safe'),
        ]);


    // ════ ВІДКРИТИ ЗМІНУ ═════════════════════════════════════
    case 'open_shift':
        if (!Auth::can($sess, $clubId, 'cash.record')) Response::forbidden();
        $existing = CashShiftService::getOpenShift($pdo, $clubId);
        if ($existing) Response::error("Зміна вже відкрита адміністратором «{$existing['opened_name']}» о {$existing['opened_at']}. Спочатку закрийте поточну зміну.");
        $balance = CashShiftService::balance($pdo, $clubId, 'register');
        $name    = $sess['full_name'] ?? $sess['name'] ?? 'Адмін';
        try {
            $pdo->prepare("INSERT INTO cash_shifts (club_id,opened_by,opened_name,balance_open,notes) VALUES (?,?,?,?,?)")
                ->execute([$clubId, $userId, $name, $balance, trim($input['notes']??'')?:null]);
        } catch (PDOException $e) {
            // Запобіжник на випадок гонки між перевіркою вище і цим INSERT:
            // uniq_cash_shifts_one_open_per_club (UNIQUE на генерованій
            // колонці, не тригер) не дозволить дві відкриті зміни на клуб.
            if ($e->getCode() === '23000') {
                Response::error('У клубі вже є відкрита зміна');
            }
            throw $e;
        }
        Response::ok(['balance_open' => $balance], 'Зміну відкрито');


    // ════ ЗАКРИТИ ЗМІНУ ══════════════════════════════════════
    case 'close_shift':
        if (!Auth::can($sess, $clubId, 'cash.record')) Response::forbidden();
        $shiftId = (int)($input['shift_id'] ?? 0);
        if (!$shiftId) Response::error('Не вказано shift_id');
        $shiftRow = $pdo->prepare("SELECT * FROM cash_shifts WHERE id=? AND club_id=? AND status='open' LIMIT 1");
        $shiftRow->execute([$shiftId, $clubId]);
        $shift = $shiftRow->fetch();
        if (!$shift) Response::error('Активну зміну не знайдено');
        if (!$isOwner && (int)$shift['opened_by'] !== $userId)
            Response::error('Ви можете закрити лише свою зміну. Зверніться до власника клубу.', 403);
        $name         = $sess['full_name'] ?? $sess['name'] ?? 'Адмін';
        $balanceClose = CashShiftService::close($pdo, $shift, $userId, $name, 'manual', trim($input['notes']??'')?:null);
        $totals = $pdo->prepare("
            SELECT COALESCE(SUM(CASE WHEN type IN('income','transfer_in') THEN amount ELSE 0 END),0) AS inc,
                   COALESCE(SUM(CASE WHEN type IN('expense','encashment','transfer_out') THEN amount ELSE 0 END),0) AS exp
            FROM club_cashflow WHERE club_id=? AND payment_method='cash' AND location='register' AND shift_id=?
        ");
        $totals->execute([$clubId, $shiftId]);
        $t = $totals->fetch();
        Response::ok([
            'balance_open'  => (float)$shift['balance_open'],
            'balance_close' => $balanceClose,
            'income_shift'  => (float)$t['inc'],
            'expense_shift' => (float)$t['exp'],
        ], 'Зміну закрито');


    // ════ ЖУРНАЛ ЗМІН ════════════════════════════════════════
    case 'get_shifts':
        $dateFrom = $input['date_from'] ?? date('Y-m-01');
        $dateTo   = $input['date_to']   ?? date('Y-m-d');
        $page     = max(1, (int)($input['page'] ?? 1));
        $perPage  = 30; $offset = ($page-1)*$perPage;
        $cntSt = $pdo->prepare("SELECT COUNT(*) FROM cash_shifts WHERE club_id=? AND DATE(opened_at) BETWEEN ? AND ?");
        $cntSt->execute([$clubId,$dateFrom,$dateTo]);
        $total = (int)$cntSt->fetchColumn();
        $st = $pdo->prepare("
            SELECT id,opened_name,opened_at,balance_open,closed_name,closed_at,
                   balance_close,income_shift,expense_shift,notes,status,closed_reason
            FROM cash_shifts WHERE club_id=? AND DATE(opened_at) BETWEEN ? AND ?
            ORDER BY opened_at DESC LIMIT ? OFFSET ?
        ");
        $st->execute([$clubId,$dateFrom,$dateTo,$perPage,$offset]);
        Response::ok([
            'rows'       => $st->fetchAll(),
            'pagination' => ['total'=>$total,'page'=>$page,'per_page'=>$perPage,'pages'=>max(1,(int)ceil($total/$perPage))],
        ]);


    default:
        Response::error("Невідома дія: {$action}", 400);

}} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}
