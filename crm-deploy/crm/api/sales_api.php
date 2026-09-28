<?php
/**
 * sales_api.php — API продажів товарів
 *
 * Дії (стара, порядкова модель — "Товари → Продажі", 1 рядок = 1 товар):
 *   get_list — список з фільтрами, групування по даті + підсумки
 *   update   — редагування (canWrite): qty, sale_price, discount, method, notes
 *   delete   — видалення (isOwner); Recalc::adjustStock() автоматично поверне stock
 *
 * Дії (нова, чекова модель — сторінка "Продаж товарів", sale_orders):
 *   get_orders   — список чеків з фільтрами (дата/діапазон, спосіб оплати, статус, пошук, client_id)
 *   get_order    — один чек з позиціями (product_sales.order_id = id)
 *   create_order — оформлення чека з N позицій (кошик / сканер / швидкий продаж)
 *   return_order — повернення чека: товар назад на склад, status='returned', чек лишається в історії
 *
 * Схема: sql/2026-08-25_sale_orders.sql (застосувати перед деплоєм цього файлу).
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';

// ── Хелпери для повернень ──────────────────────────────────────
function sale_adjustStock(PDO $pdo, int $clubId, int $orderId, int $sign): void {
    $items = $pdo->prepare("SELECT product_id, quantity FROM product_sales WHERE order_id=? AND club_id=?");
    $items->execute([$orderId, $clubId]);
    foreach ($items->fetchAll() as $it) {
        Recalc::adjustStock($pdo, $it['product_id'], $sign * (int)$it['quantity']);
    }
}

// Записує фактичне повернення коштів клієнту при підтвердженні повернення.
function sale_bookRefund(PDO $pdo, array $order, string $method, ?string $location, array $sess, int $clubId, bool $isOwner): void {
    $amount = (float)$order['total_amount'];
    if ($amount <= 0) return;

    if ($method === 'cash') {
        $loc = in_array($location, ['register','safe'], true) ? $location : 'register';
        $shiftId = null;
        if ($loc === 'register') {
            $sh = $pdo->prepare("SELECT id, opened_by FROM cash_shifts WHERE club_id=? AND status='open' LIMIT 1");
            $sh->execute([$clubId]);
            $shift = $sh->fetch();
            if (!$isOwner) {
                if (!$shift) Response::error('Зміна не відкрита. Відкрийте зміну перед поверненням готівки.', 403);
                if ((int)$shift['opened_by'] !== (int)$sess['user_id'])
                    Response::error('Зараз відкрита зміна іншого адміністратора.', 403);
            }
            if ($shift) $shiftId = (int)$shift['id'];
        }
        $pdo->prepare("
            INSERT INTO club_cashflow
              (club_id, type, category, description, amount, payment_method,
               source, source_id, shift_id, location, admin_id, admin_name)
            VALUES
              (?, 'expense', 'Повернення товару', ?, ?, 'cash', 'return', ?, ?, ?, ?, ?)
        ")->execute([
            $clubId, "Повернення чек №{$order['order_number']}", $amount,
            $order['id'], $shiftId, $loc, $sess['user_id'], $sess['full_name'] ?? null,
        ]);
    } elseif ($method === 'deposit' && $order['client_id']) {
        $b = $pdo->prepare("SELECT balance FROM clients WHERE id=? LIMIT 1");
        $b->execute([$order['client_id']]);
        $balance = round((float)($b->fetchColumn() ?? 0) + $amount, 2);
        $pdo->prepare("
            INSERT INTO client_deposits
              (club_id, client_id, amount, balance_after, operation, sale_id, admin_id, admin_name, notes)
            VALUES (?,?,?,?, 'refund', ?,?,?,?)
        ")->execute([
            $clubId, $order['client_id'], $amount, $balance, $order['id'],
            $sess['user_id'], $sess['full_name'] ?? null, "Повернення чек №{$order['order_number']}",
        ]);
        Recalc::clientBalance($pdo, (int)$order['client_id']);
    }
    // 'other' — повернення поза системою (картка/термінал), в облік не пишемо.
}

// Реверсія рефанду при підтвердженні скасування повернення.
function sale_reverseRefund(PDO $pdo, array $order): void {
    if ($order['refund_method'] === 'cash') {
        $pdo->prepare("DELETE FROM club_cashflow WHERE source='return' AND source_id=? AND club_id=?")
            ->execute([$order['id'], $order['club_id']]);
    } elseif ($order['refund_method'] === 'deposit') {
        $pdo->prepare("DELETE FROM client_deposits WHERE operation='refund' AND sale_id=? AND club_id=?")
            ->execute([$order['id'], $order['club_id']]);
        if ($order['client_id']) Recalc::clientBalance($pdo, (int)$order['client_id']);
    }
}

try {
    $sess   = Auth::requireAuth();
    $pdo    = Database::get();
    $clubId   = (int)$sess['active_club_id'];
    $access = Auth::requireClubAccess($sess, $clubId, 30);

    switch ($action) {

        // ════ СПИСОК ══════════════════════════════════════════════
        case 'get_list':
            $dateFrom = $input['date_from'] ?? $_GET['date_from'] ?? date('Y-m-01');
            $dateTo   = $input['date_to']   ?? $_GET['date_to']   ?? date('Y-m-d');
            $method   = $input['method']    ?? $_GET['method']    ?? '';
            $page     = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
            $perPage  = 100;
            $offset   = ($page - 1) * $perPage;

            $where  = ['ps.club_id = ?', 'DATE(ps.created_at) BETWEEN ? AND ?'];
            $params = [$clubId, $dateFrom, $dateTo];

            if ($method) {
                $where[]  = 'ps.payment_method = ?';
                $params[] = $method;
            }

            $whereSQL = implode(' AND ', $where);

            $stmt = $pdo->prepare("
                SELECT ps.id, ps.product_name, ps.client_name,
                       ps.quantity, ps.purchase_price, ps.sale_price,
                       ps.discount, ps.total_amount, ps.profit,
                       ps.payment_method, ps.admin_name, ps.notes,
                       ps.created_at, DATE(ps.created_at) AS sale_date,
                       p.photo_url
                FROM product_sales ps
                LEFT JOIN products p ON p.id = ps.product_id
                WHERE {$whereSQL}
                ORDER BY ps.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([...$params, $perPage, $offset]);
            $rows = $stmt->fetchAll();

            // Підсумки за фільтром
            $sumStmt = $pdo->prepare("
                SELECT COUNT(*) AS cnt,
                       COALESCE(SUM(total_amount),0) AS revenue,
                       COALESCE(SUM(profit),0) AS profit
                FROM product_sales ps
                WHERE {$whereSQL}
            ");
            $sumStmt->execute($params);
            $summary = $sumStmt->fetch();

            // Підсумки по методу оплати
            $mStmt = $pdo->prepare("
                SELECT payment_method, COALESCE(SUM(total_amount),0) AS total
                FROM product_sales ps
                WHERE {$whereSQL}
                GROUP BY payment_method
            ");
            $mStmt->execute($params);

            Response::ok([
                'sales'      => $rows,
                'summary'    => $summary,
                'by_method'  => $mStmt->fetchAll(),
            ]);
            break;

        // ════ РЕДАГУВАННЯ ═════════════════════════════════════════
        case 'update':
            if (!Auth::can($sess, $clubId, 'sales.create')) Response::forbidden();

            $id  = (int)($input['id'] ?? 0);
            if (!$id) Response::error('Не вказано id');

            // Перевіряємо належність клубу
            $row = $pdo->prepare("SELECT * FROM product_sales WHERE id=? AND club_id=? LIMIT 1");
            $row->execute([$id, $clubId]);
            $sale = $row->fetch();
            if (!$sale) Response::error('Запис не знайдено', 404);

            $qty       = max(1, (int)($input['quantity']       ?? $sale['quantity']));
            $salePrice = max(0, (float)($input['sale_price']   ?? $sale['sale_price']));
            $discount  = max(0, (float)($input['discount']     ?? $sale['discount']));
            $method    = $input['payment_method'] ?? $sale['payment_method'];
            $notes     = trim($input['notes'] ?? $sale['notes'] ?? '');

            $allowed = ['cash','card','terminal','deposit','other'];
            if (!in_array($method, $allowed, true)) $method = 'cash';

            $total  = round(max(0, $salePrice * $qty - $discount), 2);
            $profit = round($total - (float)$sale['purchase_price'] * $qty, 2);

            // Перевірка залишку при зміні qty
            $qtyDiff = $qty - (int)$sale['quantity'];
            if ($qtyDiff > 0 && $sale['product_id']) {
                $stockRow = $pdo->prepare("SELECT stock_qty FROM products WHERE id=? AND club_id=? LIMIT 1");
                $stockRow->execute([$sale['product_id'], $clubId]);
                $stock = (int)($stockRow->fetchColumn() ?? 0);
                if ($stock < $qtyDiff) {
                    Response::error("Недостатньо на складі. Доступно: {$stock} шт.");
                }
            }

            $pdo->prepare("
                UPDATE product_sales
                SET quantity=?, sale_price=?, discount=?, total_amount=?,
                    profit=?, payment_method=?, notes=?
                WHERE id=? AND club_id=?
            ")->execute([$qty, $salePrice, $discount, $total, $profit,
                         $method, $notes ?: null, $id, $clubId]);
            Recalc::cashflowSyncSale($pdo, $id);
            Recalc::adjustStock($pdo, $sale['product_id'], -$qtyDiff);

            Response::ok([], 'Збережено');
            break;

        // ════ ВИДАЛЕННЯ ═══════════════════════════════════════════
        case 'delete':
            if (!Auth::can($sess, $clubId, 'sales.delete')) Response::forbidden();

            $id = (int)($input['id'] ?? 0);
            if (!$id) Response::error('Не вказано id');

            $check = $pdo->prepare("SELECT product_id, quantity FROM product_sales WHERE id=? AND club_id=? LIMIT 1");
            $check->execute([$id, $clubId]);
            $saleRow = $check->fetch();
            if (!$saleRow) Response::error('Запис не знайдено', 404);

            $pdo->prepare("DELETE FROM product_sales WHERE id=? AND club_id=?")
                ->execute([$id, $clubId]);
            Recalc::cashflowSyncSale($pdo, $id);
            Recalc::adjustStock($pdo, $saleRow['product_id'], (int)$saleRow['quantity']);

            Response::ok([], 'Продаж видалено');
            break;

        // ════ ЧЕКИ (нова модель): СПИСОК ═════════════════════════
        case 'get_orders':
            $date     = trim($input['date']      ?? $_GET['date']      ?? '');
            $dateFrom = trim($input['date_from'] ?? $_GET['date_from'] ?? '');
            $dateTo   = trim($input['date_to']   ?? $_GET['date_to']   ?? '');
            $method   = trim($input['method']    ?? $_GET['method']    ?? '');
            $status   = trim($input['status']    ?? $_GET['status']    ?? '');
            $search   = trim($input['search']    ?? $_GET['search']    ?? '');
            $clientId = (int)($input['client_id'] ?? $_GET['client_id'] ?? 0);

            $where  = ['so.club_id = ?'];
            $params = [$clubId];

            if ($date !== '') {
                $where[]  = 'DATE(so.created_at) = ?';
                $params[] = $date;
            } else {
                if ($dateFrom !== '') { $where[] = 'DATE(so.created_at) >= ?'; $params[] = $dateFrom; }
                if ($dateTo   !== '') { $where[] = 'DATE(so.created_at) <= ?'; $params[] = $dateTo; }
            }
            if ($method !== '') { $where[] = 'so.payment_method = ?'; $params[] = $method; }
            if ($status !== '') { $where[] = 'so.status = ?'; $params[] = $status; }
            if ($clientId) { $where[] = 'so.client_id = ?'; $params[] = $clientId; }
            if ($search !== '') {
                $where[]  = '(so.order_number = ? OR so.client_name LIKE ?
                              OR EXISTS (SELECT 1 FROM product_sales ps WHERE ps.order_id = so.id AND ps.product_name LIKE ?))';
                $params[] = ctype_digit($search) ? (int)$search : 0;
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
            }

            $whereSQL = implode(' AND ', $where);
            $stmt = $pdo->prepare("
                SELECT so.id, so.order_number, so.client_id, so.client_name, so.items_count,
                       so.subtotal, so.discount_amount, so.total_amount, so.payment_method,
                       so.status, so.admin_name, so.return_reason, so.returned_at, so.created_at,
                       so.refund_method, so.refund_location,
                       so.return_requested_by_name, so.cancel_reason, so.cancel_requested_by_name,
                       (SELECT ps.product_name FROM product_sales ps
                        WHERE ps.order_id = so.id ORDER BY ps.id LIMIT 1) AS first_product_name
                FROM sale_orders so
                WHERE {$whereSQL}
                ORDER BY so.created_at DESC
                LIMIT 300
            ");
            $stmt->execute($params);
            Response::ok(['orders' => $stmt->fetchAll()]);
            break;

        // ════ ЧЕКИ: ОДИН ЧЕК З ПОЗИЦІЯМИ ═════════════════════════
        case 'get_order':
            $id = (int)($input['id'] ?? 0);
            if (!$id) Response::error('Не вказано id');

            $stmt = $pdo->prepare("SELECT * FROM sale_orders WHERE id=? AND club_id=? LIMIT 1");
            $stmt->execute([$id, $clubId]);
            $order = $stmt->fetch();
            if (!$order) Response::error('Чек не знайдено', 404);

            $itemsStmt = $pdo->prepare("
                SELECT id, product_id, product_name, quantity, sale_price, discount, total_amount
                FROM product_sales WHERE order_id=? AND club_id=? ORDER BY id
            ");
            $itemsStmt->execute([$id, $clubId]);
            $order['items'] = $itemsStmt->fetchAll();

            Response::ok(['order' => $order]);
            break;

        // ════ ЧЕКИ: ОФОРМЛЕННЯ (кошик / сканер / швидкий продаж) ═
        case 'create_order':
            // level 50, як і sell_api.php — вище за базовий level 30 з початку файлу
            $access  = Auth::requireClubAccess($sess, $clubId, 50);
            $isOwner = Auth::isOwner($sess, $access);
            if (!Auth::can($sess, $clubId, 'sales.create')) Response::forbidden();

            $items = $input['items'] ?? [];
            if (!is_array($items) || count($items) === 0) Response::error('Додайте хоча б один товар');

            $method = trim($input['payment_method'] ?? 'cash');
            if (!in_array($method, ['cash','card','terminal','deposit','other'], true)) $method = 'cash';
            $clientId      = !empty($input['client_id']) ? (int)$input['client_id'] : null;
            $clientName    = trim($input['client_name'] ?? '') ?: null;
            $discountOrder = max(0, (float)($input['discount'] ?? 0));
            $notes         = trim($input['notes'] ?? '') ?: null;

            if ($method === 'deposit' && !$clientId) Response::error("При оплаті депозитом клієнт обов'язковий");

            if ($clientId) {
                $cs = $pdo->prepare("SELECT status FROM clients WHERE id=? AND club_id=? LIMIT 1");
                $cs->execute([$clientId, $clubId]);
                $clientStatus = $cs->fetchColumn();
                // Клієнт з іншого клубу (або неіснуючий) — інакше можна списати чужий депозит.
                if ($clientStatus === false) Response::error('Клієнта не знайдено', 404);
                if ($clientStatus === 'blocked') Response::error('Клієнт заблокований — продаж товару недоступний');
            }

            // Зміна для готівки (як у sell_api.php)
            $shiftId = null;
            if ($method === 'cash') {
                $st = $pdo->prepare("SELECT * FROM cash_shifts WHERE club_id=? AND status='open' LIMIT 1");
                $st->execute([$clubId]);
                $shift = $st->fetch();
                if (!$isOwner) {
                    if (!$shift) Response::error('Зміна не відкрита. Відкрийте зміну перед продажем.');
                    if ((int)$shift['opened_by'] !== (int)$sess['user_id']) {
                        Response::error("Зараз відкрита зміна адміністратора «{$shift['opened_name']}». Ви не можете проводити операції в чужій зміні.");
                    }
                }
                if ($shift) $shiftId = (int)$shift['id'];
            }

            // Перевірка товарів і залишків, підготовка позицій
            $prepared = [];
            $subtotal = 0;
            foreach ($items as $it) {
                $productId = (int)($it['product_id'] ?? 0);
                $qty       = max(1, (int)($it['quantity'] ?? 1));
                if (!$productId) Response::error('Оберіть товар для кожної позиції');

                $s = $pdo->prepare("SELECT id, name, purchase_price, sale_price, stock_qty FROM products WHERE id=? AND club_id=? AND is_active=1 LIMIT 1");
                $s->execute([$productId, $clubId]);
                $prod = $s->fetch();
                if (!$prod) Response::error('Товар не знайдено');
                if ($prod['stock_qty'] < $qty) Response::error("Залишок «{$prod['name']}»: {$prod['stock_qty']} шт.");

                $price    = (float)($it['sale_price'] ?? $prod['sale_price']);
                $discount = max(0, (float)($it['discount'] ?? 0));
                $total    = round(max(0, $price * $qty - $discount), 2);
                $subtotal += $price * $qty;

                $prepared[] = [
                    'product_id' => $prod['id'], 'name' => $prod['name'], 'purchase_price' => $prod['purchase_price'],
                    'qty' => $qty, 'price' => $price, 'discount' => $discount, 'total' => $total,
                ];
            }

            $discountAmount = array_sum(array_column($prepared, 'discount')) + $discountOrder;
            $orderTotal     = round(max(0, $subtotal - $discountAmount), 2);

            if ($method === 'deposit' && $clientId) {
                $b = $pdo->prepare("SELECT balance FROM clients WHERE id=? LIMIT 1");
                $b->execute([$clientId]);
                $balance = (float)($b->fetchColumn() ?? 0);
                if ($balance < $orderTotal) {
                    Response::error('Недостатньо коштів на депозиті. Баланс: ' . number_format($balance, 2) . ' грн');
                }
            }

            $pdo->beginTransaction();
            try {
                $numStmt = $pdo->prepare("SELECT COALESCE(MAX(order_number),0)+1 FROM sale_orders WHERE club_id=?");
                $numStmt->execute([$clubId]);
                $orderNumber = (int)$numStmt->fetchColumn();

                $ins = $pdo->prepare("
                    INSERT INTO sale_orders
                        (club_id, order_number, client_id, client_name, items_count,
                         subtotal, discount_amount, total_amount, payment_method, status,
                         shift_id, admin_id, admin_name, notes)
                    VALUES (?,?,?,?,?, ?,?,?,?, 'completed', ?,?,?,?)
                ");
                $ins->execute([
                    $clubId, $orderNumber, $clientId, $clientName, count($prepared),
                    $subtotal, $discountAmount, $orderTotal, $method,
                    $shiftId, $sess['user_id'], $sess['full_name'] ?? null, $notes,
                ]);
                $orderId = (int)$pdo->lastInsertId();

                $itemIns = $pdo->prepare("
                    INSERT INTO product_sales
                        (order_id, club_id, product_id, product_name, client_id, client_name,
                         quantity, purchase_price, sale_price, discount,
                         total_amount, profit, payment_method, shift_id, admin_id, admin_name, notes)
                    VALUES (?,?,?,?,?,?, ?,?,?,?, ?,?,?, ?,?,?,?)
                ");
                // Депозит: по одному рядку client_deposits на кожну позицію чека
                // (sale_id = id відповідного product_sales), як і в sell_api.php.
                // Знижку на весь чек (order-level, а не по позиціях) відносимо на
                // останню позицію, щоб сума списаних рядків точно дорівнювала
                // total_amount чека — без цього при знижці на чек депозит
                // списав би зайве (по повній ціні кожної позиції).
                $depositMode = ($method === 'deposit' && $clientId);
                $balance = null;
                if ($depositMode) {
                    $b = $pdo->prepare("SELECT balance FROM clients WHERE id=? LIMIT 1");
                    $b->execute([$clientId]);
                    $balance = (float)($b->fetchColumn() ?? 0);
                }
                $depositIns = $pdo->prepare("
                    INSERT INTO client_deposits (club_id, client_id, amount, balance_after, operation, sale_id, admin_id, admin_name)
                    VALUES (?,?,?,?,'pay_product',?,?,?)
                ");

                $count = count($prepared);
                foreach ($prepared as $i => $it) {
                    $profit = round($it['total'] - $it['purchase_price'] * $it['qty'], 2);
                    $itemIns->execute([
                        $orderId, $clubId, $it['product_id'], $it['name'], $clientId, $clientName,
                        $it['qty'], $it['purchase_price'], $it['price'], $it['discount'],
                        $it['total'], $profit, $method, $shiftId, $sess['user_id'], $sess['full_name'] ?? null, null,
                    ]);
                    $saleId = (int)$pdo->lastInsertId();
                    Recalc::cashflowSyncSale($pdo, $saleId);
                    Recalc::adjustStock($pdo, $it['product_id'], -$it['qty']);

                    if ($depositMode) {
                        $deduct = ($i === $count - 1) ? max(0, $it['total'] - $discountOrder) : $it['total'];
                        $balance = round($balance - $deduct, 2);
                        $depositIns->execute([$clubId, $clientId, -$deduct, $balance, $saleId, $sess['user_id'], $sess['full_name'] ?? null]);
                    }
                }
                if ($depositMode) Recalc::clientBalance($pdo, $clientId);

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                Response::serverError($e->getMessage());
            }

            Response::ok(
                ['order_id' => $orderId, 'order_number' => $orderNumber],
                "Чек №{$orderNumber} оформлено. Сума: " . number_format($orderTotal, 2) . ' грн'
            );
            break;

        // ════ ЧЕКИ: ПОВЕРНЕННЯ (ініціація) ═══════════════════════
        // Двоетапний процес: якщо ініціатор ТАКОЖ має право sales.return_confirm —
        // повернення виконується одразу; інакше чек висить status='pending_return'
        // до підтвердження діями confirm_return (окремий permission slug).
        case 'return_order':
            if (!Auth::can($sess, $clubId, 'sales.return')) Response::forbidden();
            $isOwner = Auth::isOwner($sess, $access);

            $id     = (int)($input['id'] ?? 0);
            $reason = trim($input['reason'] ?? '') ?: null;
            $refundMethod = trim($input['refund_method'] ?? 'other');
            if (!in_array($refundMethod, ['cash','deposit','other'], true)) $refundMethod = 'other';
            $refundLocation = trim($input['refund_location'] ?? 'register');
            if (!$id) Response::error('Не вказано id');

            $stmt = $pdo->prepare("
                SELECT *, (DATE(created_at) = CURDATE()) AS is_today,
                       DATEDIFF(CURDATE(), DATE(created_at)) AS days_since
                FROM sale_orders WHERE id=? AND club_id=? LIMIT 1
            ");
            $stmt->execute([$id, $clubId]);
            $order = $stmt->fetch();
            if (!$order) Response::error('Чек не знайдено', 404);
            if ($order['status'] !== 'completed') Response::error('Повернути можна лише виконаний чек');

            if ($isOwner) {
                if ((int)$order['days_since'] > 14) Response::error('Повернення можливе протягом 14 днів після продажу', 403);
            } elseif (!$order['is_today']) {
                Response::error('Повернення можливе лише в день продажу', 403);
            }

            $canConfirm = Auth::can($sess, $clubId, 'sales.return_confirm');
            $refundLoc  = $refundMethod === 'cash' ? $refundLocation : null;

            $pdo->beginTransaction();
            try {
                if ($canConfirm) {
                    sale_adjustStock($pdo, $clubId, $id, +1);
                    sale_bookRefund($pdo, $order, $refundMethod, $refundLocation, $sess, $clubId, $isOwner);
                    $pdo->prepare("
                        UPDATE sale_orders SET
                          status='returned', return_reason=?,
                          return_requested_at=NOW(), return_requested_by_id=?, return_requested_by_name=?,
                          returned_at=NOW(), returned_by_id=?, returned_by_name=?,
                          refund_method=?, refund_location=?
                        WHERE id=? AND club_id=?
                    ")->execute([
                        $reason, $sess['user_id'], $sess['full_name'] ?? null,
                        $sess['user_id'], $sess['full_name'] ?? null,
                        $refundMethod, $refundLoc, $id, $clubId,
                    ]);
                    $pdo->commit();
                    Response::ok([], "Чек №{$order['order_number']} повернено, товар зараховано на склад");
                } else {
                    $pdo->prepare("
                        UPDATE sale_orders SET
                          status='pending_return', return_reason=?,
                          return_requested_at=NOW(), return_requested_by_id=?, return_requested_by_name=?,
                          refund_method=?, refund_location=?
                        WHERE id=? AND club_id=?
                    ")->execute([
                        $reason, $sess['user_id'], $sess['full_name'] ?? null,
                        $refundMethod, $refundLoc, $id, $clubId,
                    ]);
                    $pdo->commit();
                    try {
                        Telegram::notifyClubOwners($clubId, "🔁 Чек №{$order['order_number']} очікує підтвердження повернення" . ($reason ? " ({$reason})" : ''));
                    } catch (Throwable $e) {
                        error_log('[Sales] Telegram notify failed: ' . $e->getMessage());
                    }
                    Response::ok([], "Чек №{$order['order_number']} відправлено на підтвердження повернення");
                }
            } catch (Throwable $e) {
                $pdo->rollBack();
                Response::serverError($e->getMessage());
            }
            break;


        // ════ ЧЕКИ: ПІДТВЕРДЖЕННЯ ПОВЕРНЕННЯ ═════════════════════
        case 'confirm_return':
            if (!Auth::can($sess, $clubId, 'sales.return_confirm')) Response::forbidden();
            $isOwner = Auth::isOwner($sess, $access);

            $id = (int)($input['id'] ?? 0);
            if (!$id) Response::error('Не вказано id');

            $stmt = $pdo->prepare("SELECT * FROM sale_orders WHERE id=? AND club_id=? LIMIT 1");
            $stmt->execute([$id, $clubId]);
            $order = $stmt->fetch();
            if (!$order) Response::error('Чек не знайдено', 404);
            if ($order['status'] !== 'pending_return') Response::error('Чек не очікує підтвердження повернення');

            $refundMethod = trim($input['refund_method'] ?? $order['refund_method'] ?? 'other');
            if (!in_array($refundMethod, ['cash','deposit','other'], true)) $refundMethod = 'other';
            $refundLocation = trim($input['refund_location'] ?? $order['refund_location'] ?? 'register');
            $refundLoc = $refundMethod === 'cash' ? $refundLocation : null;

            $pdo->beginTransaction();
            try {
                sale_adjustStock($pdo, $clubId, $id, +1);
                sale_bookRefund($pdo, $order, $refundMethod, $refundLocation, $sess, $clubId, $isOwner);
                $pdo->prepare("
                    UPDATE sale_orders SET
                      status='returned', returned_at=NOW(), returned_by_id=?, returned_by_name=?,
                      refund_method=?, refund_location=?
                    WHERE id=? AND club_id=?
                ")->execute([
                    $sess['user_id'], $sess['full_name'] ?? null,
                    $refundMethod, $refundLoc, $id, $clubId,
                ]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                Response::serverError($e->getMessage());
            }

            Response::ok([], "Повернення чека №{$order['order_number']} підтверджено");
            break;


        // ════ ЧЕКИ: СКАСУВАННЯ ПОВЕРНЕННЯ (ініціація) ════════════
        case 'cancel_return':
            if (!Auth::can($sess, $clubId, 'sales.return_cancel')) Response::forbidden();

            $id     = (int)($input['id'] ?? 0);
            $reason = trim($input['reason'] ?? '') ?: null;
            if (!$id) Response::error('Не вказано id');

            $stmt = $pdo->prepare("SELECT * FROM sale_orders WHERE id=? AND club_id=? LIMIT 1");
            $stmt->execute([$id, $clubId]);
            $order = $stmt->fetch();
            if (!$order) Response::error('Чек не знайдено', 404);
            if ($order['status'] !== 'returned') Response::error('Скасувати можна лише повернений чек');

            $canConfirm = Auth::can($sess, $clubId, 'sales.return_cancel_confirm');

            $pdo->beginTransaction();
            try {
                if ($canConfirm) {
                    sale_adjustStock($pdo, $clubId, $id, -1);
                    sale_reverseRefund($pdo, $order);
                    $pdo->prepare("
                        UPDATE sale_orders SET
                          status='completed', cancel_reason=?,
                          cancel_requested_at=NOW(), cancel_requested_by_id=?, cancel_requested_by_name=?,
                          cancel_confirmed_at=NOW(), cancel_confirmed_by_id=?, cancel_confirmed_by_name=?,
                          refund_method=NULL, refund_location=NULL
                        WHERE id=? AND club_id=?
                    ")->execute([
                        $reason, $sess['user_id'], $sess['full_name'] ?? null,
                        $sess['user_id'], $sess['full_name'] ?? null,
                        $id, $clubId,
                    ]);
                    $pdo->commit();
                    Response::ok([], "Повернення чека №{$order['order_number']} скасовано");
                } else {
                    $pdo->prepare("
                        UPDATE sale_orders SET
                          status='pending_cancel', cancel_reason=?,
                          cancel_requested_at=NOW(), cancel_requested_by_id=?, cancel_requested_by_name=?
                        WHERE id=? AND club_id=?
                    ")->execute([$reason, $sess['user_id'], $sess['full_name'] ?? null, $id, $clubId]);
                    $pdo->commit();
                    try {
                        Telegram::notifyClubOwners($clubId, "↩️ Скасування повернення чека №{$order['order_number']} очікує підтвердження");
                    } catch (Throwable $e) {
                        error_log('[Sales] Telegram notify failed: ' . $e->getMessage());
                    }
                    Response::ok([], "Скасування повернення чека №{$order['order_number']} відправлено на підтвердження");
                }
            } catch (Throwable $e) {
                $pdo->rollBack();
                Response::serverError($e->getMessage());
            }
            break;


        // ════ ЧЕКИ: ПІДТВЕРДЖЕННЯ СКАСУВАННЯ ПОВЕРНЕННЯ ══════════
        case 'confirm_cancel_return':
            if (!Auth::can($sess, $clubId, 'sales.return_cancel_confirm')) Response::forbidden();

            $id = (int)($input['id'] ?? 0);
            if (!$id) Response::error('Не вказано id');

            $stmt = $pdo->prepare("SELECT * FROM sale_orders WHERE id=? AND club_id=? LIMIT 1");
            $stmt->execute([$id, $clubId]);
            $order = $stmt->fetch();
            if (!$order) Response::error('Чек не знайдено', 404);
            if ($order['status'] !== 'pending_cancel') Response::error('Чек не очікує підтвердження скасування');

            $pdo->beginTransaction();
            try {
                sale_adjustStock($pdo, $clubId, $id, -1);
                sale_reverseRefund($pdo, $order);
                $pdo->prepare("
                    UPDATE sale_orders SET
                      status='completed',
                      cancel_confirmed_at=NOW(), cancel_confirmed_by_id=?, cancel_confirmed_by_name=?,
                      refund_method=NULL, refund_location=NULL
                    WHERE id=? AND club_id=?
                ")->execute([$sess['user_id'], $sess['full_name'] ?? null, $id, $clubId]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                Response::serverError($e->getMessage());
            }

            Response::ok([], "Скасування повернення чека №{$order['order_number']} підтверджено");
            break;


        // ════ ЧЕКИ: РЕДАГУВАННЯ ПОЗИЦІЇ ═══════════════════════════
        case 'update_item':
            if (!Auth::can($sess, $clubId, 'sales.edit')) Response::forbidden();
            $isOwner = Auth::isOwner($sess, $access);

            $itemId = (int)($input['id'] ?? 0);
            if (!$itemId) Response::error('Не вказано id');

            $itemStmt = $pdo->prepare("
                SELECT ps.*, so.status AS order_status, so.id AS order_id,
                       so.payment_method AS order_payment_method, so.client_id AS order_client_id,
                       so.order_number,
                       (DATE(so.created_at) = CURDATE()) AS is_today
                FROM product_sales ps
                JOIN sale_orders so ON so.id = ps.order_id
                WHERE ps.id=? AND ps.club_id=? LIMIT 1
            ");
            $itemStmt->execute([$itemId, $clubId]);
            $item = $itemStmt->fetch();
            if (!$item) Response::error('Позицію не знайдено', 404);
            if ($item['order_status'] !== 'completed') Response::error('Редагувати можна лише позицію виконаного чека');
            if (!$isOwner && !$item['is_today']) Response::error('Редагувати продану позицію можна лише в день продажу', 403);

            $qty       = max(1, (int)($input['quantity']     ?? $item['quantity']));
            $salePrice = max(0, (float)($input['sale_price'] ?? $item['sale_price']));
            $discount  = max(0, (float)($input['discount']   ?? $item['discount']));

            $qtyDiff = $qty - (int)$item['quantity'];
            if ($qtyDiff > 0 && $item['product_id']) {
                $stockRow = $pdo->prepare("SELECT stock_qty FROM products WHERE id=? AND club_id=? LIMIT 1");
                $stockRow->execute([$item['product_id'], $clubId]);
                $stock = (int)($stockRow->fetchColumn() ?? 0);
                if ($stock < $qtyDiff) Response::error("Недостатньо на складі. Доступно: {$stock} шт.");
            }

            $total      = round(max(0, $salePrice * $qty - $discount), 2);
            $profit     = round($total - (float)$item['purchase_price'] * $qty, 2);
            $totalDelta = round($total - (float)$item['total_amount'], 2);

            if ($item['order_payment_method'] === 'deposit' && $item['order_client_id'] && $totalDelta > 0) {
                $b = $pdo->prepare("SELECT balance FROM clients WHERE id=? LIMIT 1");
                $b->execute([$item['order_client_id']]);
                if ((float)($b->fetchColumn() ?? 0) < $totalDelta) {
                    Response::error('Недостатньо коштів на депозиті клієнта для збільшення суми');
                }
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare("
                    UPDATE product_sales SET quantity=?, sale_price=?, discount=?, total_amount=?, profit=?
                    WHERE id=? AND club_id=?
                ")->execute([$qty, $salePrice, $discount, $total, $profit, $itemId, $clubId]);
                Recalc::cashflowSyncSale($pdo, $itemId);
                Recalc::adjustStock($pdo, $item['product_id'], -$qtyDiff);

                $agg = $pdo->prepare("
                    SELECT COALESCE(SUM(sale_price*quantity),0) AS subtotal,
                           COALESCE(SUM(discount),0) AS discount_amount,
                           COALESCE(SUM(total_amount),0) AS total_amount
                    FROM product_sales WHERE order_id=? AND club_id=?
                ");
                $agg->execute([$item['order_id'], $clubId]);
                $sums = $agg->fetch();
                $pdo->prepare("
                    UPDATE sale_orders SET subtotal=?, discount_amount=?, total_amount=?
                    WHERE id=? AND club_id=?
                ")->execute([$sums['subtotal'], $sums['discount_amount'], $sums['total_amount'], $item['order_id'], $clubId]);

                if ($item['order_payment_method'] === 'deposit' && $item['order_client_id'] && $totalDelta != 0.0) {
                    $b = $pdo->prepare("SELECT balance FROM clients WHERE id=? LIMIT 1");
                    $b->execute([$item['order_client_id']]);
                    $balance = round((float)($b->fetchColumn() ?? 0) - $totalDelta, 2);
                    $pdo->prepare("
                        INSERT INTO client_deposits
                          (club_id, client_id, amount, balance_after, operation, sale_id, admin_id, admin_name, notes)
                        VALUES (?,?,?,?, 'correction', ?,?,?,?)
                    ")->execute([
                        $clubId, $item['order_client_id'], -$totalDelta, $balance, $itemId,
                        $sess['user_id'], $sess['full_name'] ?? null,
                        "Коригування депозиту через редагування позиції чека №{$item['order_number']}",
                    ]);
                    Recalc::clientBalance($pdo, (int)$item['order_client_id']);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                Response::serverError($e->getMessage());
            }

            Response::ok([], 'Позицію оновлено');
            break;

        default:
            Response::error("Невідома дія: {$action}", 400);
    }

} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 401);
} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}
