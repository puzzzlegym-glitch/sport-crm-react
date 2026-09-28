<?php
/**
 * sell_api.php — Продаж товару
 * Розмістити: /public_html/api/sell_api.php
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Auth ──────────────────────────────────────────────────
$sess   = Auth::requireAuth();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$pdo    = Database::get();

$clubId = (int)($sess['active_club_id'] ?? 0);
if (!$clubId) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Не обрано клуб']); exit; }

$access   = Auth::requireClubAccess($sess, $clubId, 50);
$isOwner  = Auth::isOwner($sess, $access);
$userId   = (int)$sess['user_id'];
if (!Auth::can($sess, $clubId, 'sales.create')) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Немає прав']); exit; }

// ── Вхідні дані ───────────────────────────────────────────
$productId = (int)($input['product_id'] ?? 0);
$qty       = max(1, (int)($input['quantity'] ?? 1));
$method    = trim($input['payment_method'] ?? 'cash');
$clientId  = !empty($input['client_id']) ? (int)$input['client_id'] : null;
$clientName= trim($input['client_name'] ?? '') ?: null;
$discount  = max(0, (float)($input['discount'] ?? 0));
$notes     = trim($input['notes'] ?? '') ?: null;

if (!$productId) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Оберіть товар']); exit; }

// ── Перевірка статусу клієнта ────────────────────────────
if ($clientId) {
    $cs = $pdo->prepare("SELECT status FROM clients WHERE id=? AND club_id=? LIMIT 1");
    $cs->execute([$clientId, $clubId]);
    if ($cs->fetchColumn() === 'blocked') {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'Клієнт заблокований — продаж товару недоступний']);
        exit;
    }
}

if (!in_array($method, ['cash','card','terminal','deposit','other'])) $method = 'cash';
if ($method === 'deposit' && !$clientId) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'При оплаті депозитом клієнт обов\'язковий']);
    exit;
}

// ── Перевірка зміни для cash-продажу ─────────────────────
$shiftId = null;
if ($method === 'cash') {
    $st = $pdo->prepare("SELECT * FROM cash_shifts WHERE club_id=? AND status='open' LIMIT 1");
    $st->execute([$clubId]);
    $shift = $st->fetch();
    if (!$isOwner) {
        if (!$shift) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Зміна не відкрита. Відкрийте зміну перед продажем.']); exit; }
        if ((int)$shift['opened_by'] !== $userId) { http_response_code(403); echo json_encode(['success'=>false,'error'=>"Зараз відкрита зміна адміністратора «{$shift['opened_name']}». Ви не можете проводити операції в чужій зміні."]); exit; }
    }
    if ($shift) $shiftId = (int)$shift['id'];
}

// ── Товар ─────────────────────────────────────────────────
$s = $pdo->prepare("SELECT id, name, purchase_price, sale_price, stock_qty FROM products WHERE id=? AND club_id=? AND is_active=1 LIMIT 1");
$s->execute([$productId, $clubId]);
$prod = $s->fetch();

if (!$prod) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Товар не знайдено']); exit; }
if ($prod['stock_qty'] < $qty) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>"Залишок: {$prod['stock_qty']} шт."]);
    exit;
}

$salePrice = (float)($input['sale_price'] ?? $prod['sale_price']);
$total     = round(max(0, $salePrice * $qty - $discount), 2);
$profit    = round($total - $prod['purchase_price'] * $qty, 2);

// ── Перевірка депозиту ────────────────────────────────────
if ($method === 'deposit' && $clientId) {
    $b = $pdo->prepare("SELECT balance FROM clients WHERE id=? LIMIT 1");
    $b->execute([$clientId]);
    $balance = (float)($b->fetchColumn() ?? 0);
    if ($balance < $total) {
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'Недостатньо коштів на депозиті. Баланс: ' . number_format($balance,2) . ' грн']);
        exit;
    }
}

// ── Транзакція ────────────────────────────────────────────
$pdo->beginTransaction();
try {
    // 1. Чек з 1 позиції (sale_orders) — щоб одиночний продаж тут теж потрапляв
    //    в історію на сторінці "Продаж товарів" (sql/2026-08-25_sale_orders.sql)
    $numStmt = $pdo->prepare("SELECT COALESCE(MAX(order_number),0)+1 FROM sale_orders WHERE club_id=?");
    $numStmt->execute([$clubId]);
    $orderNumber = (int)$numStmt->fetchColumn();

    $orderIns = $pdo->prepare("
        INSERT INTO sale_orders
            (club_id, order_number, client_id, client_name, items_count,
             subtotal, discount_amount, total_amount, payment_method, status,
             shift_id, admin_id, admin_name, notes)
        VALUES (?,?,?,?,1, ?,?,?,?, 'completed', ?,?,?,?)
    ");
    $orderIns->execute([
        $clubId, $orderNumber, $clientId, $clientName,
        $salePrice * $qty, $discount, $total, $method,
        $shiftId, $sess['user_id'], $sess['full_name'] ?? null, $notes,
    ]);
    $orderId = (int)$pdo->lastInsertId();

    // 2. Запис продажу (позиція чека)
    $ins = $pdo->prepare("
        INSERT INTO product_sales
            (order_id, club_id, product_id, product_name, client_id, client_name,
             quantity, purchase_price, sale_price, discount,
             total_amount, profit, payment_method, shift_id, admin_id, admin_name, notes)
        VALUES (?,?,?,?,?,?, ?,?,?,?, ?,?,?, ?,?,?,?)
    ");
    $ins->execute([
        $orderId, $clubId, $productId, $prod['name'], $clientId, $clientName,
        $qty, $prod['purchase_price'], $salePrice, $discount,
        $total, $profit, $method, $shiftId,
        $sess['user_id'], $sess['full_name'] ?? null, $notes,
    ]);
    $saleId = (int)$pdo->lastInsertId();
    Recalc::cashflowSyncSale($pdo, $saleId);
    Recalc::adjustStock($pdo, $productId, -$qty);

    // 3. Депозит
    if ($method === 'deposit' && $clientId) {
        $bNow = $pdo->prepare("SELECT balance FROM clients WHERE id=? LIMIT 1");
        $bNow->execute([$clientId]);
        $balAfter = round((float)($bNow->fetchColumn() ?? 0) - $total, 2);

        $pdo->prepare("
            INSERT INTO client_deposits (club_id, client_id, amount, balance_after, operation, sale_id, admin_id, admin_name)
            VALUES (?,?,?,?,'pay_product',?,?,?)
        ")->execute([$clubId, $clientId, -$total, $balAfter, $saleId, $sess['user_id'], $sess['full_name'] ?? null]);
        Recalc::clientBalance($pdo, $clientId);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    exit;
}

// ── Новий залишок ─────────────────────────────────────────
$sn = $pdo->prepare("SELECT stock_qty FROM products WHERE id=? LIMIT 1");
$sn->execute([$productId]);
$newStock = (int)($sn->fetchColumn() ?? 0);

echo json_encode([
    'success'   => true,
    'message'   => "Продано {$qty} шт. Виручка: " . number_format($total, 2) . " грн",
    'sale_id'   => $saleId,
    'total'     => $total,
    'new_stock' => $newStock,
], JSON_UNESCAPED_UNICODE);
