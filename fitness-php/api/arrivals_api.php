<?php
/**
 * arrivals_api.php — API журналу приходів товарів
 *
 * Дії:
 *   get_list    — список операцій з фільтрами
 *   get_stats   — статистика за період
 *   update      — редагування запису (менеджер+)
 *   delete      — видалення запису (менеджер+, Recalc::adjustStock() відкочує склад)
 *   confirm     — підтвердити pending (змінити статус на paid/unpaid, додати на склад)
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$sess   = Auth::requireAuth();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';
$pdo    = Database::get();

$clubId   = (int)($input['club_id'] ?? $_GET['club_id'] ?? $sess['active_club_id'] ?? 0);
if (!$clubId) Response::error('Не обрано клуб', 400);

Auth::requireClubAccess($sess, $clubId, 50);

try { switch ($action) {

    // ════ СПИСОК ══════════════════════════════════════════════
    case 'get_list':
        $dateFrom  = trim($input['date_from'] ?? '');
        $dateTo    = trim($input['date_to']   ?? '');
        $operation = trim($input['operation'] ?? '');
        $status    = trim($input['status']    ?? '');
        $search    = trim($input['search']    ?? '');
        $page      = max(1, (int)($input['page'] ?? 1));
        $perPage   = 30;
        $offset    = ($page - 1) * $perPage;

        $where  = ['pa.club_id = ?'];
        $params = [$clubId];

        if ($dateFrom !== '' && $dateTo !== '') {
            $where[]  = 'DATE(pa.created_at) BETWEEN ? AND ?';
            $params[] = $dateFrom;
            $params[] = $dateTo;
        }

        $allowedOps = ['arrival','overdue','repack','transfer'];
        $allowedSts = ['paid','unpaid','pending'];

        if ($operation && in_array($operation, $allowedOps)) {
            $where[]  = 'pa.operation = ?';
            $params[] = $operation;
        }
        if ($status && in_array($status, $allowedSts)) {
            $where[]  = 'pa.status = ?';
            $params[] = $status;
        }
        if ($search) {
            $where[]  = 'pa.product_name LIKE ?';
            $params[] = '%' . $search . '%';
        }

        $whereSQL = implode(' AND ', $where);

        $countStmt = $pdo->prepare("
            SELECT COUNT(*) FROM product_arrivals pa WHERE {$whereSQL}
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT
                pa.id, pa.product_id, pa.product_name,
                pa.quantity, pa.operation, pa.status, pa.payment_method, pa.expected_qty,
                pa.purchase_price, pa.sale_price, pa.total_cost,
                pa.supplier, pa.admin_name, pa.notes, pa.created_at,
                p.category, p.stock_qty AS current_stock
            FROM product_arrivals pa
            LEFT JOIN products p ON p.id = pa.product_id
            WHERE {$whereSQL}
            ORDER BY pa.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$perPage, $offset]));

        Response::ok([
            'arrivals'   => $stmt->fetchAll(),
            'pagination' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => max(1, (int)ceil($total / $perPage)),
            ],
        ]);


    // ════ СТАТИСТИКА ══════════════════════════════════════════
    case 'get_stats':
        $dateFrom = trim($input['date_from'] ?? date('Y-m-01'));
        $dateTo   = trim($input['date_to']   ?? date('Y-m-d'));

        $stmt = $pdo->prepare("
            SELECT
                COUNT(*)                                               AS total_ops,
                SUM(operation = 'arrival' AND status != 'pending')    AS arrivals_count,
                SUM(operation = 'arrival' AND status = 'pending')     AS pending_count,
                SUM(operation = 'arrival' AND status = 'unpaid')      AS unpaid_count,
                SUM(operation IN ('overdue','repack','transfer'))      AS minus_count,
                COALESCE(SUM(
                    CASE WHEN operation = 'arrival' AND status != 'pending'
                    THEN total_cost ELSE 0 END
                ), 0)                                                  AS total_cost,
                COALESCE(SUM(
                    CASE WHEN operation = 'arrival' AND status = 'unpaid'
                    THEN total_cost ELSE 0 END
                ), 0)                                                  AS unpaid_cost
            FROM product_arrivals
            WHERE club_id = ? AND DATE(created_at) BETWEEN ? AND ?
        ");
        $stmt->execute([$clubId, $dateFrom, $dateTo]);
        Response::ok(['stats' => $stmt->fetch()]);


    // ════ ПІДТВЕРДИТИ PENDING ═════════════════════════════════
    // Змінює статус pending → paid/unpaid і додає товар на склад
    case 'confirm':
        if (!Auth::can($sess, $clubId, 'arrivals.edit')) Response::forbidden();

        $id        = (int)($input['id']     ?? 0);
        $newStatus = trim($input['status']  ?? '');
        $qty       = (int)($input['quantity'] ?? 0); // фактична к-сть (може відрізнятись)
        $paymentMethod = trim($input['payment_method'] ?? 'cash');
        if (!in_array($paymentMethod, ['cash','card','terminal','transfer'])) $paymentMethod = 'cash';

        if (!$id) Response::error('Не вказано id');
        if (!in_array($newStatus, ['paid','unpaid'])) Response::error('Статус: paid або unpaid');

        $stmt = $pdo->prepare("
            SELECT * FROM product_arrivals
            WHERE id = ? AND club_id = ? AND status = 'pending' AND operation = 'arrival'
            LIMIT 1
        ");
        $stmt->execute([$id, $clubId]);
        $arr = $stmt->fetch();
        if (!$arr) Response::error('Запис не знайдено або не є pending');

        $actualQty = $qty > 0 ? $qty : (int)$arr['expected_qty'];
        $actualCost = $arr['purchase_price'] * $actualQty;

        $pdo->beginTransaction();
        try {
            // Оновлюємо запис — тепер реальна кількість і статус
            $pdo->prepare("
                UPDATE product_arrivals SET
                    status         = ?,
                    payment_method = ?,
                    quantity       = ?,
                    expected_qty   = NULL,
                    total_cost     = purchase_price * ?
                WHERE id = ?
            ")->execute([$newStatus, $paymentMethod, $actualQty, $actualQty, $id]);

            // pending (нуль впливу на склад) → paid/unpaid (operation='arrival' завжди тут) = +qty
            Recalc::adjustStock($pdo, $arr['product_id'], $actualQty);

            // Оплачений прихід — це витрата клубу (захищений системний запис,
            // джерело=product_arrival, див. finance_api.php та add_arrival).
            if ($newStatus === 'paid') {
                $pdo->prepare("
                    INSERT INTO club_expenses
                        (club_id, category, description, amount,
                         expense_date, payment_method,
                         admin_id, admin_name, notes, source, source_id)
                    VALUES (?, 'Закупівля товару', ?, ?, CURDATE(), ?, ?, ?, NULL, 'product_arrival', ?)
                ")->execute([
                    $clubId,
                    "Прихід: {$arr['product_name']} ({$actualQty} шт.)",
                    $actualCost, $paymentMethod,
                    $sess['user_id'], $sess['full_name'] ?? null,
                    $id,
                ]);
                Recalc::cashflowSyncExpense($pdo, (int)$pdo->lastInsertId());
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::serverError($e->getMessage());
        }

        $newStock = (int)$pdo->prepare("SELECT stock_qty FROM products WHERE id=? LIMIT 1")
            ->execute([$arr['product_id']]) ?
            (int)$pdo->query("SELECT stock_qty FROM products WHERE id={$arr['product_id']}")->fetchColumn() : 0;

        $label = $newStatus === 'paid' ? 'Оплачено' : 'Не оплачено';
        Response::ok(['new_stock' => $newStock], "Підтверджено ({$label}). Склад: {$newStock} шт.");


    // ════ РЕДАГУВАННЯ ЗАПИСУ ═════════════════════════════════
    case 'update':
        if (!Auth::can($sess, $clubId, 'arrivals.edit')) Response::forbidden();

        $id        = (int)($input['id'] ?? 0);
        if (!$id) Response::error('Не вказано id');

        $qty       = (int)($input['quantity']       ?? 0);
        $price     = (float)($input['purchase_price'] ?? 0);
        $total     = (float)($input['total_cost']   ?? 0);
        $operation = trim($input['operation']       ?? '');
        $notes     = trim($input['notes']           ?? '');
        $status    = trim($input['status']          ?? 'paid');

        if ($qty < 1) Response::error('Кількість має бути > 0');
        if (!in_array($operation, ['arrival','overdue','repack','transfer']))
            Response::error('Невірний тип операції');
        if ($operation === 'arrival' && !in_array($status, ['paid','unpaid','pending']))
            Response::error('Невірний статус');

        // Для не-arrival статус не має сенсу — фіксуємо paid
        if ($operation !== 'arrival') $status = 'paid';

        // Перевірка що запис належить цьому клубу + поточний стан (для звірки з club_expenses)
        $chk = $pdo->prepare("SELECT * FROM product_arrivals WHERE id = ? AND club_id = ? LIMIT 1");
        $chk->execute([$id, $clubId]);
        $old = $chk->fetch();
        if (!$old) Response::error('Запис не знайдено', 404);

        $paymentMethod = trim($input['payment_method'] ?? $old['payment_method'] ?? 'cash');
        if (!in_array($paymentMethod, ['cash','card','terminal','transfer'])) $paymentMethod = 'cash';

        $wasPaid = $old['operation'] === 'arrival' && $old['status'] === 'paid';
        $isPaid  = $operation === 'arrival' && $status === 'paid';

        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE product_arrivals SET
                    quantity       = ?,
                    purchase_price = ?,
                    total_cost     = ?,
                    operation      = ?,
                    status         = ?,
                    payment_method = ?,
                    notes          = ?
                WHERE id = ? AND club_id = ?
            ")->execute([$qty, $price, $total, $operation, $status, $paymentMethod, $notes, $id, $clubId]);

            // Склад: відкочуємо старий вплив, застосовуємо новий (pending = нуль впливу).
            $oldStockEffect = $old['status'] !== 'pending' ? Recalc::arrivalStockSign($old['operation']) * (int)$old['quantity'] : 0;
            $newStockEffect = $status !== 'pending' ? Recalc::arrivalStockSign($operation) * $qty : 0;
            Recalc::adjustStock($pdo, (int)$old['product_id'], $newStockEffect - $oldStockEffect);

            // Звіряємо пов'язану системну витрату (source=product_arrival) зі станом приходу.
            if ($isPaid) {
                $expStmt = $pdo->prepare("SELECT id FROM club_expenses WHERE source='product_arrival' AND source_id=? LIMIT 1");
                $expStmt->execute([$id]);
                $expId = $expStmt->fetchColumn();

                $description = "Прихід: {$old['product_name']} ({$qty} шт.)";
                if ($expId) {
                    $pdo->prepare("
                        UPDATE club_expenses SET description=?, amount=?, payment_method=?
                        WHERE id=?
                    ")->execute([$description, abs($total), $paymentMethod, $expId]);
                    Recalc::cashflowSyncExpense($pdo, (int)$expId);
                } else {
                    $pdo->prepare("
                        INSERT INTO club_expenses
                            (club_id, category, description, amount,
                             expense_date, payment_method,
                             admin_id, admin_name, notes, source, source_id)
                        VALUES (?, 'Закупівля товару', ?, ?, CURDATE(), ?, ?, ?, NULL, 'product_arrival', ?)
                    ")->execute([
                        $clubId, $description, abs($total), $paymentMethod,
                        $sess['user_id'], $sess['full_name'] ?? null,
                        $id,
                    ]);
                    Recalc::cashflowSyncExpense($pdo, (int)$pdo->lastInsertId());
                }
            } elseif ($wasPaid) {
                // Прихід перестав бути "оплаченим приходом" — прибираємо пов'язану витрату.
                $expStmt = $pdo->prepare("SELECT id FROM club_expenses WHERE source='product_arrival' AND source_id=? LIMIT 1");
                $expStmt->execute([$id]);
                $expIdToRemove = $expStmt->fetchColumn();

                $pdo->prepare("DELETE FROM club_expenses WHERE source='product_arrival' AND source_id=?")
                    ->execute([$id]);
                if ($expIdToRemove) Recalc::cashflowSyncExpense($pdo, (int)$expIdToRemove);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::serverError($e->getMessage());
        }

        Response::ok([], 'Запис оновлено');


    // ════ ВИДАЛЕННЯ ЗАПИСУ ════════════════════════════════════
    case 'delete':
        if (!Auth::can($sess, $clubId, 'arrivals.delete')) Response::forbidden();

        $id = (int)($input['id'] ?? 0);
        if (!$id) Response::error('Не вказано id');

        $chk = $pdo->prepare("SELECT * FROM product_arrivals WHERE id = ? AND club_id = ? LIMIT 1");
        $chk->execute([$id, $clubId]);
        $arrRow = $chk->fetch();
        if (!$arrRow) Response::error('Запис не знайдено', 404);

        $pdo->beginTransaction();
        try {
            // Прибираємо пов'язану системну витрату (якщо прихід був оплачений)
            $expStmt = $pdo->prepare("SELECT id FROM club_expenses WHERE source='product_arrival' AND source_id=? LIMIT 1");
            $expStmt->execute([$id]);
            $expIdToRemove = $expStmt->fetchColumn();

            $pdo->prepare("DELETE FROM club_expenses WHERE source='product_arrival' AND source_id=?")
                ->execute([$id]);
            if ($expIdToRemove) Recalc::cashflowSyncExpense($pdo, (int)$expIdToRemove);

            // Відкочуємо вплив на склад перед видаленням запису
            if ($arrRow['status'] !== 'pending') {
                Recalc::adjustStock($pdo, (int)$arrRow['product_id'], -Recalc::arrivalStockSign($arrRow['operation']) * (int)$arrRow['quantity']);
            }
            $pdo->prepare("DELETE FROM product_arrivals WHERE id = ? AND club_id = ?")
                ->execute([$id, $clubId]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::serverError($e->getMessage());
        }

        Response::ok([], 'Запис видалено. Склад перераховано.');


    default:
        Response::error("Невідома дія: {$action}", 400);

}} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}
