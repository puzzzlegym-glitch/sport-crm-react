<?php
/**
 * products_api.php — API модуля "Товари"
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$sess = Auth::requireAuth();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';

$pdo = Database::get();
$clubId = (int)($input['club_id'] ?? $_GET['club_id'] ?? $sess['active_club_id'] ?? 0);

if (!$clubId) {
    Response::error('Не обрано клуб', 400);
}

Auth::requireClubAccess($sess, $clubId, 50);

try {
    switch ($action) {

        // ════ КАТЕГОРІЇ ═══════════════════════════════════════════
        case 'get_categories':
            $stmt = $pdo->prepare("
                SELECT DISTINCT category
                FROM products
                WHERE club_id = ? AND category IS NOT NULL AND category != ''
                ORDER BY category
            ");
            $stmt->execute([$clubId]);
            Response::ok(['categories' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
            break;

        // ════ СПИСОК ТОВАРІВ ══════════════════════════════════════
        case 'get_list':
            $search = trim($input['search'] ?? $_GET['search'] ?? '');
            $category = trim($input['category'] ?? $_GET['category'] ?? '');
            $lowStock = (bool)($input['low_stock'] ?? $_GET['low_stock'] ?? false);
            $inactive = (bool)($input['inactive'] ?? false);

            $where = ['p.club_id = ?'];
            $params = [$clubId];

            if (!$inactive) {
                $where[] = 'p.is_active = 1';
            }
            if ($search) {
                $where[] = '(p.name LIKE ? OR p.barcode LIKE ? OR p.supplier LIKE ?)';
                $like = '%' . $search . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
            if ($category) {
                $where[] = 'p.category = ?';
                $params[] = $category;
            }
            if ($lowStock) {
                $where[] = 'p.stock_qty <= p.stock_min';
            }

            $whereSQL = implode(' AND ', $where);

            $stmt = $pdo->prepare("
                SELECT
                    p.id, p.name, p.category, p.supplier, p.barcode,
                    p.purchase_price, p.sale_price, p.stock_qty, p.stock_min,
                    p.photo_url, p.is_active, p.created_at,
                    COALESCE((SELECT SUM(ps.quantity) FROM product_sales ps WHERE ps.product_id = p.id AND ps.club_id = p.club_id), 0) AS total_sold,
                    COALESCE((SELECT SUM(ps.total_amount) FROM product_sales ps WHERE ps.product_id = p.id AND ps.club_id = p.club_id), 0) AS total_revenue,
                    CASE
                        WHEN p.stock_qty <= 0 THEN 'out'
                        WHEN p.stock_qty <= p.stock_min THEN 'low'
                        ELSE 'ok'
                    END AS stock_status
                FROM products p
                WHERE {$whereSQL}
                ORDER BY p.category, p.name
            ");
            $stmt->execute($params);
            Response::ok(['products' => $stmt->fetchAll()]);
            break;

        // ════ ОДИН ТОВАР ══════════════════════════════════════════
        case 'get_one':
            $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
            if (!$id) Response::error('Не вказано id');

            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND club_id = ? LIMIT 1");
            $stmt->execute([$id, $clubId]);
            $product = $stmt->fetch();

            if (!$product) Response::error('Товар не знайдено', 404);

            // Останні приходи
            $arrStmt = $pdo->prepare("
                SELECT quantity, purchase_price, supplier, admin_name, notes, created_at
                FROM product_arrivals
                WHERE product_id = ? AND club_id = ?
                ORDER BY created_at DESC LIMIT 10
            ");
            $arrStmt->execute([$id, $clubId]);

            // Останні продажі
            $salStmt = $pdo->prepare("
                SELECT quantity, sale_price, total_amount, client_name,
                       payment_method, admin_name, created_at
                FROM product_sales
                WHERE product_id = ? AND club_id = ?
                ORDER BY created_at DESC LIMIT 10
            ");
            $salStmt->execute([$id, $clubId]);

            // Кількість проданого в цьому клубі
            $soldStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(quantity),0) FROM product_sales WHERE product_id=? AND club_id=?"
            );
            $soldStmt->execute([$id, $clubId]);
            $product['total_sold'] = (int)$soldStmt->fetchColumn();

            Response::ok([
                'product' => $product,
                'arrivals' => $arrStmt->fetchAll(),
                'sales' => $salStmt->fetchAll(),
            ]);
            break;

        // ════ СТВОРИТИ ТОВАР ══════════════════════════════════════
        case 'create':
            if (!Auth::can($sess, $clubId, 'products.edit')) Response::forbidden();
            Billing::requireWriteAccess($clubId);

            $name = trim($input['name'] ?? '');
            if (strlen($name) < 1) Response::error('Введіть назву товару');

            $salePrice = (float)($input['sale_price'] ?? 0);
            if ($salePrice < 0) Response::error('Ціна продажу не може бути від\'ємною');

            $pdo->prepare("
                INSERT INTO products
                    (club_id, name, category, supplier, barcode,
                     purchase_price, sale_price, stock_qty, stock_min,
                     photo_url, description, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $clubId,
                htmlspecialchars($name, ENT_NOQUOTES, 'UTF-8'),
                trim($input['category'] ?? '') ?: null,
                trim($input['supplier'] ?? '') ?: null,
                trim($input['barcode'] ?? '') ?: null,
                (float)($input['purchase_price'] ?? 0),
                $salePrice,
                max(0, (int)($input['stock_qty'] ?? 0)),
                max(0, (int)($input['stock_min'] ?? 0)),
                trim($input['photo_url'] ?? '') ?: null,
                trim($input['description'] ?? '') ?: null,
                $sess['user_id'],
            ]);

            Response::ok(['id' => (int)$pdo->lastInsertId()], 'Товар додано');
            break;

        // ════ РЕДАГУВАТИ ТОВАР ════════════════════════════════════
        case 'update':
            if (!Auth::can($sess, $clubId, 'products.edit')) Response::forbidden();
            $id = (int)($input['id'] ?? 0);
            $name = trim($input['name'] ?? '');

            if (!$id) Response::error('Не вказано id');
            if (!strlen($name)) Response::error('Введіть назву товару');

            $check = $pdo->prepare("SELECT 1 FROM products WHERE id=? AND club_id=?");
            $check->execute([$id, $clubId]);
            if (!$check->fetchColumn()) Response::error('Товар не знайдено', 404);

            $pdo->prepare("
                UPDATE products SET
                    name = ?, category = ?, supplier = ?, barcode = ?,
                    purchase_price = ?, sale_price = ?, stock_min = ?,
                    photo_url = ?, description = ?
                WHERE id = ? AND club_id = ?
            ")->execute([
                htmlspecialchars($name, ENT_NOQUOTES, 'UTF-8'),
                trim($input['category'] ?? '') ?: null,
                trim($input['supplier'] ?? '') ?: null,
                trim($input['barcode'] ?? '') ?: null,
                (float)($input['purchase_price'] ?? 0),
                (float)($input['sale_price'] ?? 0),
                max(0, (int)($input['stock_min'] ?? 0)),
                trim($input['photo_url'] ?? '') ?: null,
                trim($input['description'] ?? '') ?: null,
                $id, $clubId,
            ]);

            Response::ok([], 'Збережено');
            break;

        // ════ ТОГЛ АКТИВНОСТІ ═════════════════════════════════════
        case 'toggle_active':
            if (!Auth::can($sess, $clubId, 'products.delete')) Response::forbidden();
            $id = (int)($input['id'] ?? 0);
            if (!$id) Response::error('Не вказано id');

            $stmt = $pdo->prepare("SELECT is_active FROM products WHERE id=? AND club_id=?");
            $stmt->execute([$id, $clubId]);
            $row = $stmt->fetch();
            if (!$row) Response::error('Товар не знайдено', 404);

            $new = $row['is_active'] ? 0 : 1;

            $pdo->prepare("UPDATE products SET is_active=? WHERE id=?")
                ->execute([$new, $id]);

            Response::ok(['is_active' => $new],
                $new ? 'Товар активовано' : 'Товар деактивовано');
            break;

        // ════ ПРИХІД / СПИСАННЯ ТОВАРУ ════════════════════════════
        case 'add_arrival':
            if (!Auth::can($sess, $clubId, 'arrivals.create')) Response::forbidden();

            $productId = (int)($input['product_id'] ?? 0);
            $qty = (int)($input['quantity'] ?? 0);
            $operation = trim($input['operation'] ?? 'arrival');
            $status = trim($input['status'] ?? 'paid');
            $paymentMethod = trim($input['payment_method'] ?? 'cash');

            $allowedOps = ['arrival', 'overdue', 'repack', 'transfer'];
            $allowedStatuses = ['paid', 'unpaid', 'pending'];
            $allowedMethods = ['cash', 'card', 'terminal', 'transfer'];

            if (!$productId) Response::error('Оберіть товар');
            if ($qty < 1) Response::error('Кількість має бути більше 0');
            if (!in_array($operation, $allowedOps)) Response::error('Невірна операція');
            if (!in_array($status, $allowedStatuses)) Response::error('Невірний статус');
            if (!in_array($paymentMethod, $allowedMethods)) $paymentMethod = 'cash';

            if ($operation !== 'arrival') $status = 'paid';

            $prodStmt = $pdo->prepare("
                SELECT id, name, purchase_price, sale_price, stock_qty
                FROM products WHERE id=? AND club_id=? LIMIT 1
            ");
            $prodStmt->execute([$productId, $clubId]);
            $prod = $prodStmt->fetch();

            if (!$prod) Response::error('Товар не знайдено');

            // Перевірка залишку для операцій списання
            if (in_array($operation, ['overdue','repack','transfer']) && $prod['stock_qty'] < $qty) {
                Response::error("Недостатньо товару на складі. Залишок: {$prod['stock_qty']} шт.");
            }

            $purchasePrice = (float)($input['purchase_price'] ?? $prod['purchase_price']);
            $salePrice = (float)($input['sale_price'] ?? $prod['sale_price']);

            $totalCost = in_array($operation, ['overdue','repack','transfer'])
                ? -($purchasePrice * $qty)
                : ($purchasePrice * $qty);

            $pdo->beginTransaction();
            try {
                $pdo->prepare("
                    INSERT INTO product_arrivals
                        (club_id, product_id, product_name, quantity, operation, status, payment_method, expected_qty,
                         purchase_price, sale_price, supplier, total_cost,
                         admin_id, admin_name, notes)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ")->execute([
                    $clubId, $productId, $prod['name'], $qty,
                    $operation, $status, $paymentMethod, ($status === 'pending' ? (int)($input['expected_qty'] ?? $qty) : null),
                    $purchasePrice, $salePrice,
                    trim($input['supplier'] ?? '') ?: ($prod['supplier'] ?? null),
                    $totalCost,
                    $sess['user_id'], $sess['full_name'] ?? null,
                    trim($input['notes'] ?? '') ?: null,
                ]);

                $arrivalId = (int)$pdo->lastInsertId();

                // Оновлення ціни тільки при нормальному приході
                if ($operation === 'arrival' && $status !== 'pending') {
                    $pdo->prepare("
                        UPDATE products
                        SET purchase_price = ?, sale_price = ?
                        WHERE id = ?
                    ")->execute([$purchasePrice, $salePrice, $productId]);
                }

                // === ЗАЛИШОК НА СКЛАДІ ===
                if ($status !== 'pending') {
                    Recalc::adjustStock($pdo, $productId, Recalc::arrivalStockSign($operation) * $qty);
                }

                // Оплачений прихід — це витрата клубу. Захищений системний запис
                // (source != 'manual', див. finance_api.php) — редагувати/видаляти
                // вручну не можна, лише через сам прихід (arrivals_api.php).
                if ($operation === 'arrival' && $status === 'paid') {
                    $pdo->prepare("
                        INSERT INTO club_expenses
                            (club_id, category, description, amount,
                             expense_date, payment_method,
                             admin_id, admin_name, notes, source, source_id)
                        VALUES (?, 'Закупівля товару', ?, ?, CURDATE(), ?, ?, ?, NULL, 'product_arrival', ?)
                    ")->execute([
                        $clubId,
                        "Прихід: {$prod['name']} ({$qty} шт.)",
                        $totalCost, $paymentMethod,
                        $sess['user_id'], $sess['full_name'] ?? null,
                        $arrivalId,
                    ]);
                    Recalc::cashflowSyncExpense($pdo, (int)$pdo->lastInsertId());
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                Response::serverError($e->getMessage());
            }

            $newStock = (int)$pdo->query("SELECT stock_qty FROM products WHERE id = $productId")->fetchColumn();

            $opLabels = ['arrival'=>'Прихід','overdue'=>'Прострочка','repack'=>'Розфасування','transfer'=>'Перенесення'];
            $msg = $status === 'pending'
                ? "Замовлення зафіксовано. Очікується {$qty} шт."
                : "{$opLabels[$operation]} оформлено. Залишок: {$newStock} шт.";

            Response::ok(['new_stock' => $newStock, 'total_cost' => abs($totalCost)], $msg);
            break;

        // ════ ЖУРНАЛ ПРИХОДІВ ═════════════════════════════════════
        case 'get_arrivals':
            $dateFrom = trim($input['date_from'] ?? date('Y-m-01'));
            $dateTo = trim($input['date_to'] ?? date('Y-m-d'));
            $page = max(1, (int)($input['page'] ?? 1));
            $perPage = 30;
            $offset = ($page - 1) * $perPage;

            $stmt = $pdo->prepare("
                SELECT pa.*, p.category
                FROM product_arrivals pa
                LEFT JOIN products p ON p.id = pa.product_id
                WHERE pa.club_id = ? 
                  AND DATE(pa.created_at) BETWEEN ? AND ?
                ORDER BY pa.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$clubId, $dateFrom, $dateTo, $perPage, $offset]);

            $totalStmt = $pdo->prepare("
                SELECT COUNT(*) FROM product_arrivals 
                WHERE club_id=? AND DATE(created_at) BETWEEN ? AND ?
            ");
            $totalStmt->execute([$clubId, $dateFrom, $dateTo]);
            $total = (int)$totalStmt->fetchColumn();

            $sumStmt = $pdo->prepare("
                SELECT COALESCE(SUM(total_cost), 0) AS total_cost
                FROM product_arrivals
                WHERE club_id=? AND DATE(created_at) BETWEEN ? AND ?
            ");
            $sumStmt->execute([$clubId, $dateFrom, $dateTo]);

            Response::ok([
                'arrivals' => $stmt->fetchAll(),
                'total' => $total,
                'total_cost' => (float)$sumStmt->fetchColumn(),
            ]);
            break;

        // ════ ПРОДАЖ ТОВАРУ ═══════════════════════════════════════
        case 'sell':
            if (!Auth::can($sess, $clubId, 'sales.create')) Response::forbidden();

            $productId = (int)($input['product_id'] ?? 0);
            $qty       = (int)($input['quantity'] ?? 0);

            if (!$productId) Response::error('Оберіть товар');
            if ($qty < 1)    Response::error('Кількість має бути більше 0');

            // Отримуємо товар
            $prodStmt = $pdo->prepare("
                SELECT id, name, purchase_price, sale_price, stock_qty 
                FROM products 
                WHERE id = ? AND club_id = ? AND is_active = 1 
                LIMIT 1
            ");
            $prodStmt->execute([$productId, $clubId]);
            $prod = $prodStmt->fetch(PDO::FETCH_ASSOC);

            if (!$prod) {
                Response::error('Товар не знайдено або неактивний', 404);
            }

            if ($prod['stock_qty'] < $qty) {
                Response::error("Недостатньо товару на складі. Залишок: {$prod['stock_qty']} шт.");
            }

            $salePrice = (float)($input['sale_price'] ?? $prod['sale_price']);
            $discount  = max(0, (float)($input['discount'] ?? 0));
            $total     = round(max(0, $salePrice * $qty - $discount), 2);
            $profit    = round($total - $prod['purchase_price'] * $qty, 2);

            $clientId   = !empty($input['client_id']) ? (int)$input['client_id'] : null;
            $clientName = trim($input['client_name'] ?? '') ?: null;
            $method     = trim($input['payment_method'] ?? 'cash');

            $allowedMethods = ['cash','card','terminal','deposit','other'];
            if (!in_array($method, $allowedMethods)) {
                $method = 'cash';
            }

            if ($method === 'deposit' && !$clientId) {
                Response::error('При оплаті депозитом клієнт обов\'язковий');
            }

            // Клієнт має належати цьому клубу — інакше можна списати чужий депозит.
            if ($clientId) {
                $cs = $pdo->prepare("SELECT status FROM clients WHERE id=? AND club_id=? LIMIT 1");
                $cs->execute([$clientId, $clubId]);
                $clientStatus = $cs->fetchColumn();
                if ($clientStatus === false) Response::error('Клієнта не знайдено', 404);
                if ($clientStatus === 'blocked') Response::error('Клієнт заблокований — продаж товару недоступний');
            }

            // Перевірка балансу депозиту
            if ($method === 'deposit' && $clientId) {
                $balStmt = $pdo->prepare("SELECT balance FROM clients WHERE id = ? LIMIT 1");
                $balStmt->execute([$clientId]);
                $balance = (float)($balStmt->fetchColumn() ?? 0);

                if ($balance < $total) {
                    Response::error("Недостатньо коштів на депозиті. Баланс: " . number_format($balance, 2) . " грн");
                }
            }

            $saleId = null;
            $pdo->beginTransaction();
            try {
                // Запис продажу
                $insertSale = $pdo->prepare("
                    INSERT INTO product_sales
                        (club_id, product_id, product_name, client_id, client_name,
                         quantity, purchase_price, sale_price, discount,
                         total_amount, profit, payment_method,
                         admin_id, admin_name, notes)
                    VALUES (?,?,?,?,?, ?,?,?,?, ?,?,?, ?,?,?)
                ");
                $insertSale->execute([
                    $clubId, $productId, $prod['name'], $clientId, $clientName,
                    $qty, $prod['purchase_price'], $salePrice, $discount,
                    $total, $profit, $method,
                    $sess['user_id'], $sess['full_name'] ?? null,
                    trim($input['notes'] ?? '') ?: null,
                ]);

                $saleId = (int)$pdo->lastInsertId();
                Recalc::cashflowSyncSale($pdo, $saleId);
                Recalc::adjustStock($pdo, $productId, -$qty);

                // Якщо оплата депозитом — списуємо з балансу клієнта
                if ($method === 'deposit' && $clientId) {
                    $balNow = $pdo->prepare("SELECT balance FROM clients WHERE id = ? LIMIT 1");
                    $balNow->execute([$clientId]);
                    $currentBalance = (float)($balNow->fetchColumn() ?? 0);
                    $balanceAfter   = round($currentBalance - $total, 2);

                    $pdo->prepare("
                        INSERT INTO client_deposits 
                            (club_id, client_id, amount, balance_after, operation, sale_id, admin_id, admin_name)
                        VALUES (?,?,-?,?,'pay_product',?,?,?)
                    ")->execute([
                        $clubId, $clientId, $total, $balanceAfter,
                        $saleId, $sess['user_id'], $sess['full_name'] ?? null
                    ]);
                    Recalc::clientBalance($pdo, $clientId);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                Response::serverError('Помилка при проведенні продажу: ' . $e->getMessage());
            }

            // Отримуємо актуальний залишок після транзакції
            $stockNow = $pdo->prepare("SELECT stock_qty FROM products WHERE id = ? LIMIT 1");
            $stockNow->execute([$productId]);
            $newStock = (int)($stockNow->fetchColumn() ?? 0);

            Response::ok([
                'sale_id'   => $saleId,
                'total'     => $total,
                'new_stock' => $newStock,
                'profit'    => $profit
            ], "Продано {$qty} шт. Виручка: " . number_format($total, 2) . " грн");

            break;

        // ════ ЖУРНАЛ ПРОДАЖІВ ═════════════════════════════════════
        case 'get_sales':
            $dateFrom = trim($input['date_from'] ?? date('Y-m-01'));
            $dateTo   = trim($input['date_to']   ?? date('Y-m-d'));
            $page     = max(1, (int)($input['page'] ?? 1));
            $perPage  = 30;
            $offset   = ($page - 1) * $perPage;

            $stmt = $pdo->prepare("
                SELECT ps.id, ps.product_name, ps.quantity,
                       ps.sale_price, ps.discount, ps.total_amount, ps.profit,
                       ps.payment_method, ps.client_name,
                       ps.admin_name, ps.notes, ps.created_at,
                       p.category
                FROM product_sales ps
                LEFT JOIN products p ON p.id = ps.product_id
                WHERE ps.club_id = ?
                  AND DATE(ps.created_at) BETWEEN ? AND ?
                ORDER BY ps.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$clubId, $dateFrom, $dateTo, $perPage, $offset]);

            $sumStmt = $pdo->prepare("
                SELECT COUNT(*) AS cnt, SUM(total_amount) AS revenue, SUM(profit) AS profit
                FROM product_sales
                WHERE club_id=? AND DATE(created_at) BETWEEN ? AND ?
            ");
            $sumStmt->execute([$clubId, $dateFrom, $dateTo]);

            $methodStmt = $pdo->prepare("
                SELECT payment_method, SUM(total_amount) AS total
                FROM product_sales
                WHERE club_id=? AND DATE(created_at) BETWEEN ? AND ?
                GROUP BY payment_method
            ");
            $methodStmt->execute([$clubId, $dateFrom, $dateTo]);

            Response::ok([
                'sales'     => $stmt->fetchAll(),
                'summary'   => $sumStmt->fetch(),
                'by_method' => $methodStmt->fetchAll(),
            ]);
            break;

        // ════ СТАТИСТИКА ══════════════════════════════════════════
        case 'get_stats':
            $row = $pdo->prepare("
                SELECT
                    COUNT(*)                           AS total_products,
                    SUM(is_active = 1)                 AS active_products,
                    SUM(stock_qty <= 0)                AS out_of_stock,
                    SUM(stock_qty > 0 AND stock_qty <= stock_min) AS low_stock,
                    SUM(stock_qty * purchase_price)    AS stock_value
                FROM products WHERE club_id = ?
            ");
            $row->execute([$clubId]);
            $stats = $row->fetch();

            $monthRow = $pdo->prepare("
                SELECT COUNT(*) AS sold_count, SUM(total_amount) AS revenue, SUM(profit) AS profit
                FROM product_sales
                WHERE club_id=?
                  AND MONTH(created_at)=MONTH(NOW())
                  AND YEAR(created_at)=YEAR(NOW())
            ");
            $monthRow->execute([$clubId]);
            $month = $monthRow->fetch();

            Response::ok(['stats' => array_merge(
                array_map(fn($v) => $v ?? 0, $stats),
                [
                    'month_sold'    => (int)($month['sold_count'] ?? 0),
                    'month_revenue' => (float)($month['revenue']  ?? 0),
                    'month_profit'  => (float)($month['profit']   ?? 0),
                ]
            )]);
            break;

        default:
            Response::error("Невідома дія: {$action}", 400);
    }
} catch (PDOException $e) {
    Response::serverError('DB Error: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}