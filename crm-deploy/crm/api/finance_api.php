<?php
/**
 * finance_api.php — API модуля "Фінанси"
 *
 * Дії:
 *   get_summary      — зведений звіт за період
 *   get_cashflow     — журнал руху коштів
 *   get_income       — надходження (абонементи + товари) за період
 *   get_expenses     — витрати клубу
 *   add_expense      — записати витрату
 *   update_expense   — редагувати витрату
 *   delete_expense   — видалити витрату
 *   get_deposits     — операції з депозитами клієнтів
 *   add_deposit      — поповнити депозит клієнта
 *   get_expense_cats — категорії витрат (для автодоповнення)
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

// ── Дашборд (рівень 30 — доступно всім) ────────────────
if ($action === 'get_dashboard') {
    $access = Auth::requireClubAccess($sess, $clubId, 30);
        $period = trim($input['period'] ?? 'today');
        switch ($period) {
            case 'yesterday':
                $d1 = $d2 = date('Y-m-d', strtotime('-1 day'));
                break;
            case 'week':
                $d1 = date('Y-m-d', strtotime('-6 days'));
                $d2 = date('Y-m-d');
                break;
            default: // today
                $d1 = $d2 = date('Y-m-d');
        }

        // Каса — залишок готівки клубу (каса + сейф) за весь час діяльності.
        // Рахуємо з club_cashflow — єдиного журналу готівки, який
        // Recalc::cashflowSyncPayment/-Sale/-Expense() наповнюють з
        // club_payments/product_sales/club_expenses.
        $cash = $pdo->prepare("
            SELECT location,
              COALESCE(SUM(CASE WHEN type IN('income','transfer_in') THEN amount ELSE 0 END),0) -
              COALESCE(SUM(CASE WHEN type IN('expense','encashment','transfer_out') THEN amount ELSE 0 END),0) +
              COALESCE(SUM(CASE WHEN type='adjustment' THEN amount ELSE 0 END),0) AS bal
            FROM club_cashflow WHERE club_id=? AND payment_method='cash' GROUP BY location
        ");
        $cash->execute([$clubId]);
        $cashByLocation = ['register' => 0.0, 'safe' => 0.0];
        foreach ($cash->fetchAll() as $row) {
            $cashByLocation[$row['location']] = (float)$row['bal'];
        }

        // Абонементи — кількість ПРОДАНИХ за період (не оплат: оплата частинами
        // й повернення не збільшують цифру). Скасовані не рахуються.
        $inv = $pdo->prepare("
            SELECT COUNT(*) AS cnt, COALESCE(SUM(ci.price),0) AS total
            FROM client_invoices ci
            WHERE ci.club_id=? AND ci.status <> 'cancelled' AND DATE(ci.created_at) BETWEEN ? AND ?
        ");
        $inv->execute([$clubId, $d1, $d2]);
        $i = $inv->fetch();

        // Відвідування
        $vis = $pdo->prepare("
            SELECT COUNT(*) AS cnt
            FROM visits
            WHERE club_id=? AND DATE(visited_at) BETWEEN ? AND ?
        ");
        $vis->execute([$clubId, $d1, $d2]);
        $v = $vis->fetch();

        // Оплати (всі, сума)
        $pay = $pdo->prepare("
            SELECT COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS total
            FROM club_payments
            WHERE club_id=? AND DATE(created_at) BETWEEN ? AND ?
        ");
        $pay->execute([$clubId, $d1, $d2]);
        $p = $pay->fetch();

        // Продажі товарів
        $sal = $pdo->prepare("
            SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS total
            FROM product_sales
            WHERE club_id=? AND DATE(created_at) BETWEEN ? AND ?
        ");
        $sal->execute([$clubId, $d1, $d2]);
        $s = $sal->fetch();

        // Прихід товарів (підтверджені)
        $arr = $pdo->prepare("
            SELECT COUNT(*) AS cnt, COALESCE(SUM(total_cost),0) AS total
            FROM product_arrivals
            WHERE club_id=? AND status IN('paid','unpaid') AND DATE(created_at) BETWEEN ? AND ?
        ");
        $arr->execute([$clubId, $d1, $d2]);
        $a = $arr->fetch();

        // Без права "Перегляд фінансів" (тренер за замовчуванням) — лише відвідування й
        // кількість абонементів, без грошей клубу (каса, оплати, продажі, закупівлі).
        if (!Auth::can($sess, $clubId, 'finance.view')) {
            Response::ok(['data' => [
                'invoices' => ['count' => (int)$i['cnt']],
                'visits'   => ['count' => (int)$v['cnt']],
            ], 'restricted' => true]);
        }

        Response::ok(['data' => [
            'cash'     => [
                'amount'   => $cashByLocation['register'] + $cashByLocation['safe'],
                'register' => $cashByLocation['register'],
                'safe'     => $cashByLocation['safe'],
            ],
            'invoices' => ['count' => (int)$i['cnt'],  'amount' => (float)$i['total']],
            'visits'   => ['count' => (int)$v['cnt']],
            'payments' => ['count' => (int)$p['cnt'],  'amount' => (float)$p['total']],
            'sales'    => ['count' => (int)$s['cnt'],  'amount' => (float)$s['total']],
            'arrivals' => ['count' => (int)$a['cnt'],  'amount' => (float)$a['total']],
        ]]);
}


// ── Тренд дашборду (рівень 30) ───────────────────────────
if ($action === 'get_dashboard_trend') {
    Auth::requireClubAccess($sess, $clubId, 30);
    $period = trim($input['period'] ?? 'today');

    // Визначаємо поточний і попередній діапазони (7 днів кожен)
    switch ($period) {
        case 'yesterday':
            $curEnd   = date('Y-m-d', strtotime('-1 day'));
            $curStart = $curEnd;
            break;
        case 'week':
            $curEnd   = date('Y-m-d');
            $curStart = date('Y-m-d', strtotime('-6 days'));
            break;
        default: // today
            $curEnd   = $curStart = date('Y-m-d');
    }
    // Попередній: той самий розмір вікна до curStart
    $days     = (int)round((strtotime($curEnd) - strtotime($curStart)) / 86400) + 1;
    $prevEnd  = date('Y-m-d', strtotime($curStart . ' -1 day'));
    $prevStart= date('Y-m-d', strtotime($prevEnd . ' -' . ($days - 1) . ' days'));

    // Будуємо набір дат для поточного і попереднього діапазону
    function buildDays(string $from, string $to): array {
        $out = []; $d = strtotime($from);
        while ($d <= strtotime($to)) { $out[] = date('Y-m-d', $d); $d += 86400; }
        return $out;
    }

    // Отримуємо зведені дані по одному дню
    function dayStats(PDO $pdo, int $clubId, string $d1, string $d2): array {
        // cash — готівкові надходження (абонементи + товари) мінус готівкові витрати за день
        $r = $pdo->prepare("SELECT
            COALESCE((SELECT SUM(amount) FROM club_payments WHERE club_id=? AND payment_method='cash' AND DATE(created_at) BETWEEN ? AND ?),0) +
            COALESCE((SELECT SUM(total_amount) FROM product_sales WHERE club_id=? AND payment_method='cash' AND DATE(created_at) BETWEEN ? AND ?),0) -
            COALESCE((SELECT SUM(amount) FROM club_expenses WHERE club_id=? AND payment_method='cash' AND expense_date BETWEEN ? AND ?),0) AS amt");
        $r->execute([$clubId,$d1,$d2, $clubId,$d1,$d2, $clubId,$d1,$d2]); $cash = (float)$r->fetchColumn();

        // Продані абонементи (як у get_dashboard), а не оплати
        $r = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(ci.price),0) AS a
            FROM client_invoices ci WHERE ci.club_id=? AND ci.status <> 'cancelled' AND DATE(ci.created_at) BETWEEN ? AND ?");
        $r->execute([$clubId,$d1,$d2]); $inv = $r->fetch();

        $r = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE club_id=? AND DATE(visited_at) BETWEEN ? AND ?");
        $r->execute([$clubId,$d1,$d2]); $vis = (int)$r->fetchColumn();

        $r = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS a
            FROM club_payments WHERE club_id=? AND DATE(created_at) BETWEEN ? AND ?");
        $r->execute([$clubId,$d1,$d2]); $pay = $r->fetch();

        $r = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS a
            FROM product_sales WHERE club_id=? AND DATE(created_at) BETWEEN ? AND ?");
        $r->execute([$clubId,$d1,$d2]); $sal = $r->fetch();

        $r = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(total_cost),0) AS a
            FROM product_arrivals WHERE club_id=? AND status IN('paid','unpaid') AND DATE(created_at) BETWEEN ? AND ?");
        $r->execute([$clubId,$d1,$d2]); $arr = $r->fetch();

        return [
            'date'     => $d1,
            'cash'     => ['amount' => $cash],
            'invoices' => ['count'  => (int)$inv['c'], 'amount' => (float)$inv['a']],
            'visits'   => ['count'  => $vis],
            'payments' => ['count'  => (int)$pay['c'], 'amount' => (float)$pay['a']],
            'sales'    => ['count'  => (int)$sal['c'], 'amount' => (float)$sal['a']],
            'arrivals' => ['count'  => (int)$arr['c'], 'amount' => (float)$arr['a']],
        ];
    }

    $curDays  = buildDays($curStart, $curEnd);
    $prevDays = buildDays($prevStart, $prevEnd);

    $current  = array_map(fn($d) => dayStats($pdo, $clubId, $d, $d), $curDays);
    $previous = array_map(fn($d) => dayStats($pdo, $clubId, $d, $d), $prevDays);

    if (!Auth::can($sess, $clubId, 'finance.view')) {
        // Те саме обмеження, що й у get_dashboard: без грошових показників.
        $strip = fn(array $day) => array_intersect_key($day, array_flip(['date', 'day', 'visits', 'invoices']));
        $current  = array_map(fn($d) => ['invoices' => ['count' => $d['invoices']['count'] ?? 0]] + $strip($d), $current);
        $previous = array_map(fn($d) => ['invoices' => ['count' => $d['invoices']['count'] ?? 0]] + $strip($d), $previous);
    }
    Response::ok(['trend' => ['current' => $current, 'previous' => $previous]]);
}


$access = Auth::requireClubAccess($sess, $clubId, 50);

// Дефолтний період — поточний місяць
$dateFrom = trim($input['date_from'] ?? $_GET['date_from'] ?? date('Y-m-01'));
$dateTo   = trim($input['date_to']   ?? $_GET['date_to']   ?? date('Y-m-d'));

try { switch ($action) {

    // ════ ЗВЕДЕНИЙ ЗВІТ ═══════════════════════════════════════
    case 'get_summary':
        // Надходження від абонементів
        $invRow = $pdo->prepare("
            SELECT
                COUNT(DISTINCT ci.id)              AS invoices_count,
                COALESCE(SUM(cp.amount), 0)        AS invoices_income,
                SUM(cp.payment_method = 'cash')    AS cash_count,
                SUM(cp.payment_method = 'card')    AS card_count,
                SUM(cp.payment_method = 'terminal')AS terminal_count,
                SUM(cp.payment_method = 'deposit') AS deposit_count,
                COALESCE(SUM(CASE WHEN cp.payment_method='cash'    THEN cp.amount ELSE 0 END), 0) AS cash_sum,
                COALESCE(SUM(CASE WHEN cp.payment_method='card'    THEN cp.amount ELSE 0 END), 0) AS card_sum,
                COALESCE(SUM(CASE WHEN cp.payment_method='terminal'THEN cp.amount ELSE 0 END), 0) AS terminal_sum
            FROM club_payments cp
            LEFT JOIN client_invoices ci ON ci.id = cp.invoice_id
            WHERE cp.club_id = ?
              AND DATE(cp.created_at) BETWEEN ? AND ?
        ");
        $invRow->execute([$clubId, $dateFrom, $dateTo]);
        $inv = $invRow->fetch();

        // Надходження від товарів
        $prodRow = $pdo->prepare("
            SELECT
                COUNT(*)                           AS sales_count,
                COALESCE(SUM(total_amount), 0)     AS sales_income,
                COALESCE(SUM(profit), 0)           AS sales_profit,
                COALESCE(SUM(CASE WHEN payment_method='cash'    THEN total_amount ELSE 0 END), 0) AS cash_sum,
                COALESCE(SUM(CASE WHEN payment_method='card'    THEN total_amount ELSE 0 END), 0) AS card_sum,
                COALESCE(SUM(CASE WHEN payment_method='terminal'THEN total_amount ELSE 0 END), 0) AS terminal_sum
            FROM product_sales
            WHERE club_id = ?
              AND DATE(created_at) BETWEEN ? AND ?
        ");
        $prodRow->execute([$clubId, $dateFrom, $dateTo]);
        $prod = $prodRow->fetch();

        // Витрати
        $expRow = $pdo->prepare("
            SELECT
                COUNT(*)              AS expenses_count,
                COALESCE(SUM(amount), 0) AS expenses_total
            FROM club_expenses
            WHERE club_id = ?
              AND expense_date BETWEEN ? AND ?
        ");
        $expRow->execute([$clubId, $dateFrom, $dateTo]);
        $exp = $expRow->fetch();

        // Поповнення депозитів
        $depRow = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) AS deposits_total
            FROM client_deposits
            WHERE club_id = ?
              AND operation = 'top_up'
              AND DATE(created_at) BETWEEN ? AND ?
        ");
        $depRow->execute([$clubId, $dateFrom, $dateTo]);
        $dep = $depRow->fetch();

        // Витрати по категоріях
        $catRow = $pdo->prepare("
            SELECT category, SUM(amount) AS total
            FROM club_expenses
            WHERE club_id = ?
              AND expense_date BETWEEN ? AND ?
            GROUP BY category
            ORDER BY total DESC
        ");
        $catRow->execute([$clubId, $dateFrom, $dateTo]);

        $totalIncome  = (float)$inv['invoices_income'] + (float)$prod['sales_income'];
        $totalExpense = (float)$exp['expenses_total'];
        $netProfit    = $totalIncome - $totalExpense;

        Response::ok([
            'summary' => [
                'date_from'        => $dateFrom,
                'date_to'          => $dateTo,
                'total_income'     => $totalIncome,
                'total_expense'    => $totalExpense,
                'net_profit'       => $netProfit,
                'invoices_income'  => (float)$inv['invoices_income'],
                'invoices_count'   => (int)$inv['invoices_count'],
                'products_income'  => (float)$prod['sales_income'],
                'products_count'   => (int)$prod['sales_count'],
                'products_profit'  => (float)$prod['sales_profit'],
                'deposits_top_up'  => (float)$dep['deposits_total'],
                'expenses_total'   => $totalExpense,
                'expenses_count'   => (int)$exp['expenses_count'],
                // По методах оплати (всього)
                'by_method' => [
                    'cash'     => (float)$inv['cash_sum']     + (float)$prod['cash_sum'],
                    'card'     => (float)$inv['card_sum']     + (float)$prod['card_sum'],
                    'terminal' => (float)$inv['terminal_sum'] + (float)$prod['terminal_sum'],
                ],
            ],
            'expenses_by_category' => $catRow->fetchAll(),
        ]);


    // ════ НАДХОДЖЕННЯ (ПЛАТЕЖІ) ═══════════════════════════════
    case 'get_income':
        $page    = max(1, (int)($input['page'] ?? 1));
        $perPage = 30;
        $offset  = ($page - 1) * $perPage;
        $source  = trim($input['source'] ?? ''); // 'invoice' | 'product' | ''

        // Платежі за абонементи
        if ($source === '' || $source === 'invoice') {
            $invStmt = $pdo->prepare("
                SELECT
                    cp.id, 'invoice' AS source_type,
                    cp.amount, cp.payment_method,
                    cp.admin_name, cp.notes, cp.created_at,
                    c.full_name AS client_name,
                    ci.tariff_name AS description
                FROM club_payments cp
                LEFT JOIN client_invoices ci ON ci.id = cp.invoice_id
                LEFT JOIN clients c ON c.id = cp.client_id
                WHERE cp.club_id = ?
                  AND DATE(cp.created_at) BETWEEN ? AND ?
                ORDER BY cp.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $invStmt->execute([$clubId, $dateFrom, $dateTo, $perPage, $offset]);
            $invoicePayments = $invStmt->fetchAll();
        } else {
            $invoicePayments = [];
        }

        // Продажі товарів
        if ($source === '' || $source === 'product') {
            $prodStmt = $pdo->prepare("
                SELECT
                    ps.id, 'product' AS source_type,
                    ps.total_amount AS amount, ps.payment_method,
                    ps.admin_name, ps.notes, ps.created_at,
                    ps.client_name, ps.product_name AS description
                FROM product_sales ps
                WHERE ps.club_id = ?
                  AND DATE(ps.created_at) BETWEEN ? AND ?
                ORDER BY ps.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $prodStmt->execute([$clubId, $dateFrom, $dateTo, $perPage, $offset]);
            $productSales = $prodStmt->fetchAll();
        } else {
            $productSales = [];
        }

        // Об'єднуємо і сортуємо
        $all = array_merge($invoicePayments, $productSales);
        usort($all, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        $all = array_slice($all, 0, $perPage);

        Response::ok(['income' => $all]);


    // ════ ВИТРАТИ ═════════════════════════════════════════════
    case 'get_expenses':
        $page    = max(1, (int)($input['page'] ?? 1));
        $perPage = 30;
        $offset  = ($page - 1) * $perPage;
        $cat     = trim($input['category'] ?? '');

        $where  = ['club_id = ?', 'expense_date BETWEEN ? AND ?'];
        $params = [$clubId, $dateFrom, $dateTo];
        if ($cat) { $where[] = 'category = ?'; $params[] = $cat; }

        $whereSQL = implode(' AND ', $where);

        $stmt = $pdo->prepare("
            SELECT * FROM club_expenses
            WHERE {$whereSQL}
            ORDER BY expense_date DESC, created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$perPage, $offset]));

        $total = (int)$pdo->prepare("SELECT COUNT(*) FROM club_expenses WHERE {$whereSQL}")
            ->execute($params) ?
            $pdo->prepare("SELECT COUNT(*) FROM club_expenses WHERE {$whereSQL}")
                ->execute($params) : 0;

        // Підсумок
        $sumStmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount),0) FROM club_expenses WHERE {$whereSQL}
        ");
        $sumStmt->execute($params);

        Response::ok([
            'expenses' => $stmt->fetchAll(),
            'total'    => (float)$sumStmt->fetchColumn(),
        ]);


    // ════ ДОДАТИ ВИТРАТУ ══════════════════════════════════════
    case 'add_expense':
        if (!Auth::can($sess, $clubId, 'finance.manage')) Response::forbidden();

        $description = trim($input['description'] ?? '');
        $amount      = (float)($input['amount'] ?? 0);
        $category    = trim($input['category']    ?? '');
        $date        = trim($input['expense_date']?? date('Y-m-d'));

        if (!$description) Response::error('Введіть опис витрати');
        if ($amount <= 0)  Response::error('Сума має бути більше 0');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

        $pdo->prepare("
            INSERT INTO club_expenses
                (club_id, category, description, amount,
                 expense_date, payment_method,
                 admin_id, admin_name, notes)
            VALUES (?,?,?,?, ?,?, ?,?,?)
        ")->execute([
            $clubId,
            htmlspecialchars($category, ENT_NOQUOTES, 'UTF-8') ?: null,
            htmlspecialchars($description, ENT_NOQUOTES, 'UTF-8'),
            $amount, $date,
            trim($input['payment_method'] ?? 'cash'),
            $sess['user_id'], $sess['full_name'] ?? null,
            trim($input['notes'] ?? '') ?: null,
        ]);
        Recalc::cashflowSyncExpense($pdo, (int)$pdo->lastInsertId());

        // Telegram: сповіщення власника клубу (не блокує основний потік)
        try {
            $catLabel = $category ?: 'без категорії';
            Telegram::notifyClubOwners($clubId, "🧾 Витрата: " . number_format($amount, 2) . " грн, категорія {$catLabel}");
        } catch (Throwable $e) {
            error_log('[Finance] Telegram notify failed: ' . $e->getMessage());
        }

        Response::ok(['id' => (int)$pdo->lastInsertId()], 'Витрату записано');


    // ════ РЕДАГУВАТИ ВИТРАТУ ══════════════════════════════════
    case 'update_expense':
        if (!Auth::can($sess, $clubId, 'finance.manage')) Response::forbidden();

        $id          = (int)($input['id'] ?? 0);
        $description = trim($input['description'] ?? '');
        $amount      = (float)($input['amount'] ?? 0);

        if (!$id)          Response::error('Не вказано id');
        if (!$description) Response::error('Введіть опис');
        if ($amount <= 0)  Response::error('Сума має бути > 0');

        $check = $pdo->prepare("SELECT source FROM club_expenses WHERE id=? AND club_id=?");
        $check->execute([$id, $clubId]);
        $srcRow = $check->fetch();
        if (!$srcRow) Response::error('Витрату не знайдено', 404);
        if ($srcRow['source'] !== 'manual') {
            Response::error('Це системний запис, створений автоматично (напр. виплата тренеру або оплачений прихід товару) — редагувати вручну не можна.', 409);
        }

        $date = trim($input['expense_date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

        $pdo->prepare("
            UPDATE club_expenses SET
                category       = ?,
                description    = ?,
                amount         = ?,
                expense_date   = ?,
                payment_method = ?,
                notes          = ?
            WHERE id = ? AND club_id = ?
        ")->execute([
            trim($input['category'] ?? '') ?: null,
            htmlspecialchars($description, ENT_NOQUOTES, 'UTF-8'),
            $amount, $date,
            trim($input['payment_method'] ?? 'cash'),
            trim($input['notes'] ?? '') ?: null,
            $id, $clubId,
        ]);
        Recalc::cashflowSyncExpense($pdo, $id);

        Response::ok([], 'Збережено');


    // ════ ВИДАЛИТИ ВИТРАТУ ════════════════════════════════════
    case 'delete_expense':
        if (!Auth::can($sess, $clubId, 'finance.delete')) Response::forbidden('Видалення — лише власник');

        $id = (int)($input['id'] ?? 0);
        if (!$id) Response::error('Не вказано id');

        $check = $pdo->prepare("SELECT source FROM club_expenses WHERE id=? AND club_id=?");
        $check->execute([$id, $clubId]);
        $srcRow = $check->fetch();
        if (!$srcRow) Response::error('Витрату не знайдено', 404);
        if ($srcRow['source'] !== 'manual') {
            Response::error('Це системний запис, створений автоматично (напр. виплата тренеру або оплачений прихід товару) — видалити вручну не можна.', 409);
        }

        $stmt = $pdo->prepare("DELETE FROM club_expenses WHERE id=? AND club_id=?");
        $stmt->execute([$id, $clubId]);
        if (!$stmt->rowCount()) Response::error('Витрату не знайдено', 404);
        Recalc::cashflowSyncExpense($pdo, $id);

        Response::ok([], 'Видалено');


    // ════ ДЕПОЗИТИ КЛІЄНТІВ ═══════════════════════════════════
    case 'get_deposits':
        $page      = max(1, (int)($input['page'] ?? 1));
        $perPage   = 30;
        $offset    = ($page - 1) * $perPage;
        $depClient = (int)($input['client_id'] ?? 0);

        $depWhere  = ['cd.club_id = ?', 'DATE(cd.created_at) BETWEEN ? AND ?'];
        $depParams = [$clubId, $dateFrom, $dateTo];
        if ($depClient) { $depWhere[] = 'cd.client_id = ?'; $depParams[] = $depClient; }
        $depWhereSQL = implode(' AND ', $depWhere);

        $stmt = $pdo->prepare("
            SELECT
                cd.id, cd.amount, cd.operation,
                cd.payment_method, cd.admin_name,
                cd.notes, cd.created_at,
                c.full_name AS client_name, c.phone AS client_phone
            FROM client_deposits cd
            JOIN clients c ON c.id = cd.client_id
            WHERE {$depWhereSQL}
            ORDER BY cd.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([...$depParams, $perPage, $offset]);

        // Підсумок
        $sumStmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS top_up,
                COALESCE(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END), 0) AS write_off
            FROM client_deposits
            WHERE club_id = ?
              AND DATE(created_at) BETWEEN ? AND ?
        ");
        $sumStmt->execute([$clubId, $dateFrom, $dateTo]);

        Response::ok([
            'deposits' => $stmt->fetchAll(),
            'summary'  => $sumStmt->fetch(),
        ]);


    // ════ ПОПОВНИТИ ДЕПОЗИТ КЛІЄНТА ═══════════════════════════
    case 'add_deposit':
        if (!Auth::can($sess, $clubId, 'finance.manage')) Response::forbidden();

        $clientId = (int)($input['client_id'] ?? 0);
        $amount   = (float)($input['amount']   ?? 0);
        $method   = trim($input['payment_method'] ?? 'cash');

        if (!$clientId) Response::error('Оберіть клієнта');
        if ($amount <= 0) Response::error('Сума має бути більше 0');

        // Перевіряємо клієнта
        $clientStmt = $pdo->prepare("
            SELECT id, full_name, balance FROM clients
            WHERE id=? AND club_id=? LIMIT 1
        ");
        $clientStmt->execute([$clientId, $clubId]);
        $client = $clientStmt->fetch();
        if (!$client) Response::error('Клієнта не знайдено');

        $pdo->prepare("
            INSERT INTO client_deposits
                (club_id, client_id, amount, operation,
                 payment_method, admin_id, admin_name, notes)
            VALUES (?,?, ?, 'top_up', ?,?,?,?)
        ")->execute([
            $clubId, $clientId, $amount, $method,
            $sess['user_id'], $sess['full_name'] ?? null,
            trim($input['notes'] ?? '') ?: null,
        ]);
        Recalc::cashflowSyncDeposit($pdo, (int)$pdo->lastInsertId());
        Recalc::clientBalance($pdo, $clientId);

        // Новий баланс
        $newBalance = (float)$pdo->query(
            "SELECT balance FROM clients WHERE id={$clientId}"
        )->fetchColumn();

        Response::ok([
            'new_balance' => $newBalance,
        ], "Поповнено на " . number_format($amount, 2) . " грн. Баланс: " .
            number_format($newBalance, 2) . " грн");


    // ════ РЕДАГУВАННЯ ДЕПОЗИТУ (лише ручні поповнення top_up) ═
    case 'update_deposit':
        if (!Auth::can($sess, $clubId, 'finance.manage')) Response::forbidden();

        $id     = (int)($input['id'] ?? 0);
        $amount = (float)($input['amount'] ?? 0);
        $method = trim($input['payment_method'] ?? 'cash');
        $notes  = trim($input['notes'] ?? '');
        if (!$id) Response::error('Не вказано id');
        if ($amount <= 0) Response::error('Сума має бути більше 0');

        $chk = $pdo->prepare("
            SELECT client_id, operation, (DATE(created_at) = CURDATE()) AS is_today
            FROM client_deposits WHERE id=? AND club_id=? LIMIT 1
        ");
        $chk->execute([$id, $clubId]);
        $old = $chk->fetch();
        if (!$old) Response::error('Запис не знайдено', 404);
        if ($old['operation'] !== 'top_up')
            Response::error('Редагувати можна лише ручні поповнення депозиту', 403);
        if (!Auth::isOwner($sess, $access) && !$old['is_today'])
            Response::error('Редагувати депозит можна лише в день внесення', 403);

        $pdo->prepare("
            UPDATE client_deposits SET amount=?, payment_method=?, notes=?
            WHERE id=? AND club_id=?
        ")->execute([$amount, $method, $notes ?: null, $id, $clubId]);
        Recalc::cashflowSyncDeposit($pdo, $id);
        Recalc::clientBalance($pdo, (int)$old['client_id']);

        Response::ok([], 'Депозит оновлено');


    // ════ ВИДАЛЕННЯ ДЕПОЗИТУ (лише ручні поповнення top_up) ═══
    case 'delete_deposit':
        if (!Auth::can($sess, $clubId, 'finance.manage')) Response::forbidden();

        $id = (int)($input['id'] ?? 0);
        if (!$id) Response::error('Не вказано id');

        $chk = $pdo->prepare("
            SELECT client_id, operation, (DATE(created_at) = CURDATE()) AS is_today
            FROM client_deposits WHERE id=? AND club_id=? LIMIT 1
        ");
        $chk->execute([$id, $clubId]);
        $old = $chk->fetch();
        if (!$old) Response::error('Запис не знайдено', 404);

        // Власник може видалити БУДЬ-який запис депозиту клієнта (включно з
        // нарахуваннями від сертифікатів/повернень і за будь-яку дату) —
        // менеджеру, як і раніше, дозволено лише ручні поповнення того ж дня.
        $isDepositOwner = Auth::isOwner($sess, $access);
        if (!$isDepositOwner && $old['operation'] !== 'top_up')
            Response::error('Видалити можна лише ручні поповнення депозиту', 403);
        if (!$isDepositOwner && !$old['is_today'])
            Response::error('Видалити депозит можна лише в день внесення', 403);

        $pdo->prepare("DELETE FROM client_deposits WHERE id=? AND club_id=?")->execute([$id, $clubId]);
        Recalc::cashflowSyncDeposit($pdo, $id);
        Recalc::clientBalance($pdo, (int)$old['client_id']);

        Response::ok([], 'Депозит видалено');


    // ════ КАТЕГОРІЇ ВИТРАТ ════════════════════════════════════
    case 'get_expense_cats':
        $stmt = $pdo->prepare("
            SELECT DISTINCT category
            FROM club_expenses
            WHERE club_id = ? AND category IS NOT NULL
            ORDER BY category
        ");
        $stmt->execute([$clubId]);
        Response::ok(['categories' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);


    default:
        Response::error("Невідома дія: {$action}", 400);

}} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}
