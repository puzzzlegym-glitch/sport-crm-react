<?php
/**
 * payments_api.php — API оплат за абонементи
 *
 * Дії:
 *   get_list    — список оплат з фільтрами (дата, спосіб, тариф, клієнт, client_id)
 *   get_summary — підсумок по методах оплати за період
 *
 * Права: manager (50+)
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

$access = Auth::requireClubAccess($sess, $clubId, 50);

try { switch ($action) {

    // ════ СПИСОК ОПЛАТ ════════════════════════════════════════
    case 'get_list':
        $dateFrom  = trim($input['date_from'] ?? $_GET['date_from'] ?? date('Y-m-01'));
        $dateTo    = trim($input['date_to']   ?? $_GET['date_to']   ?? date('Y-m-d'));
        $method    = trim($input['method']    ?? $_GET['method']    ?? '');
        $tariffId  = (int)($input['tariff_id'] ?? $_GET['tariff_id'] ?? 0);
        $search    = trim($input['search']     ?? $_GET['search']    ?? '');
        $clientId  = (int)($input['client_id'] ?? $_GET['client_id'] ?? 0);
        $page      = max(1, (int)($input['page'] ?? 1));
        $perPage   = 30;
        $offset    = ($page - 1) * $perPage;

        $where  = ['cp.club_id = ?', 'DATE(cp.created_at) BETWEEN ? AND ?'];
        $params = [$clubId, $dateFrom, $dateTo];

        if ($method && in_array($method, ['cash','card','terminal','deposit','transfer','free','other'])) {
            $where[]  = 'cp.payment_method = ?';
            $params[] = $method;
        }
        if ($tariffId) {
            $where[]  = 'ci.tariff_id = ?';
            $params[] = $tariffId;
        }
        if ($clientId) {
            $where[]  = 'cp.client_id = ?';
            $params[] = $clientId;
        }
        if ($search) {
            $where[]  = 'c.full_name LIKE ?';
            $params[] = '%' . $search . '%';
        }

        $whereSQL = implode(' AND ', $where);

        $countStmt = $pdo->prepare("
            SELECT COUNT(*) FROM club_payments cp
            JOIN clients c ON c.id = cp.client_id
            LEFT JOIN client_invoices ci ON ci.id = cp.invoice_id
            WHERE {$whereSQL}
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT
                cp.id, cp.amount, cp.payment_method,
                cp.admin_name, cp.trainer_name, cp.notes,
                cp.created_at,
                cp.fiscal_status, cp.fiscal_receipt_url,
                c.id       AS client_id,
                c.full_name AS client_name,
                c.phone     AS client_phone,
                ci.id       AS invoice_id,
                ci.tariff_name,
                ci.tariff_id,
                ci.start_date, ci.end_date
            FROM club_payments cp
            JOIN clients c ON c.id = cp.client_id
            LEFT JOIN client_invoices ci ON ci.id = cp.invoice_id
            WHERE {$whereSQL}
            ORDER BY cp.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$perPage, $offset]));

        // Підсумок по методах за той самий фільтр (без пагінації)
        $sumStmt = $pdo->prepare("
            SELECT
                cp.payment_method,
                COUNT(*)    AS cnt,
                SUM(cp.amount) AS total
            FROM club_payments cp
            JOIN clients c ON c.id = cp.client_id
            LEFT JOIN client_invoices ci ON ci.id = cp.invoice_id
            WHERE {$whereSQL}
            GROUP BY cp.payment_method
        ");
        $sumStmt->execute($params);

        Response::ok([
            'payments'  => $stmt->fetchAll(),
            'summary'   => $sumStmt->fetchAll(),
            'pagination'=> [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => max(1, (int)ceil($total / $perPage)),
            ],
        ]);


    // ════ РЕДАГУВАННЯ ОПЛАТИ ════════════════════════════════
    case 'update':
        if (!Auth::can($sess, $clubId, 'payments.edit')) Response::error('Немає прав', 403);

        $id     = (int)($input['id'] ?? 0);
        $amount = (float)($input['amount'] ?? 0);
        $method = trim($input['payment_method'] ?? '');
        $notes  = trim($input['notes'] ?? '');

        if (!$id)    Response::error('Не вказано id');
        if ($amount <= 0) Response::error('Невірна сума');
        if (!in_array($method, ['cash','card','terminal','deposit','transfer','free','other']))
            Response::error('Невірний спосіб оплати');

        // Перевіряємо що оплата належить клубу
        $chk = $pdo->prepare("
            SELECT cp.id, cp.client_id, cp.invoice_id, cp.amount, cp.payment_method,
                   (DATE(cp.created_at) = CURDATE()) AS is_today
            FROM club_payments cp
            JOIN clients c ON c.id = cp.client_id
            WHERE cp.id = ? AND c.club_id = ? LIMIT 1
        ");
        $chk->execute([$id, $clubId]);
        $old = $chk->fetch();
        if (!$old) Response::error('Оплату не знайдено', 404);
        if (!Auth::isOwner($sess, $access) && !$old['is_today']) {
            Response::error('Редагувати оплату можна лише в день продажу', 403);
        }

        // Депозит клієнта не перераховується автоматично при UPDATE club_payments.
        // Якщо старий і/або новий спосіб оплати — депозит, треба явно скоригувати:
        // повернути стару суму (якщо була депозитом) і списати нову (якщо стає депозитом).
        $depositDelta = 0.0;
        if ($old['payment_method'] === 'deposit') $depositDelta += (float)$old['amount'];
        if ($method === 'deposit') $depositDelta -= $amount;

        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE club_payments
                SET amount = ?, payment_method = ?, notes = ?
                WHERE id = ?
            ")->execute([$amount, $method, $notes, $id]);
            if ($old['invoice_id']) {
                Recalc::invoicePaidAmount($pdo, (int)$old['invoice_id']);
                Recalc::invoiceStatus($pdo, (int)$old['invoice_id']);
            }
            Recalc::cashflowSyncPayment($pdo, $id);

            if ($depositDelta != 0.0) {
                $pdo->prepare("
                    INSERT INTO client_deposits
                        (club_id, client_id, amount, operation, invoice_id, admin_id, admin_name, notes)
                    VALUES (?,?,?,'correction',?,?,?,?)
                ")->execute([
                    $clubId, $old['client_id'], $depositDelta, $old['invoice_id'],
                    $sess['user_id'], $sess['full_name'] ?? null,
                    'Коригування депозиту через редагування оплати #' . $id,
                ]);
                // Recalc::clientBalance перераховує SUM(amount) з нуля (як і сам
                // trg_deposit_after_insert) — виклик не подвоює коригування.
                Recalc::clientBalance($pdo, (int)$old['client_id']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::serverError($e->getMessage());
        }

        Response::ok([], 'Оплату оновлено');


    // ════ ВИДАЛЕННЯ ОПЛАТИ ══════════════════════════════════
    case 'delete':
        if (!Auth::can($sess, $clubId, 'payments.delete')) Response::forbidden();
        $id = (int)($input['id'] ?? 0);
        if (!$id) Response::error('Не вказано id');

        $chk = $pdo->prepare("
            SELECT cp.id, cp.client_id, cp.invoice_id, cp.amount, cp.payment_method,
                   (DATE(cp.created_at) = CURDATE()) AS is_today
            FROM club_payments cp
            JOIN clients c ON c.id = cp.client_id
            WHERE cp.id = ? AND c.club_id = ? LIMIT 1
        ");
        $chk->execute([$id, $clubId]);
        $old = $chk->fetch();
        if (!$old) Response::error('Оплату не знайдено', 404);
        if (!Auth::isOwner($sess, $access) && !$old['is_today']) {
            Response::error('Видалити оплату можна лише в день продажу', 403);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM club_payments WHERE id = ?")->execute([$id]);
            if ($old['invoice_id']) {
                Recalc::invoicePaidAmount($pdo, (int)$old['invoice_id']);
                Recalc::invoiceStatus($pdo, (int)$old['invoice_id']);
            }
            Recalc::cashflowSyncPayment($pdo, $id);

            // Якщо видалена оплата була депозитом — повертаємо суму на баланс клієнта.
            if ($old['payment_method'] === 'deposit') {
                $refund = (float)$old['amount'];
                $pdo->prepare("
                    INSERT INTO client_deposits
                        (club_id, client_id, amount, operation, invoice_id, admin_id, admin_name, notes)
                    VALUES (?,?,?,'correction',?,?,?,?)
                ")->execute([
                    $clubId, $old['client_id'], $refund, $old['invoice_id'],
                    $sess['user_id'], $sess['full_name'] ?? null,
                    'Повернення на депозит через видалення оплати #' . $id,
                ]);
                Recalc::clientBalance($pdo, (int)$old['client_id']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::serverError($e->getMessage());
        }

        Response::ok([], 'Оплату видалено');


    // ════ СПИСОК ТАРИФІВ ДЛЯ ФІЛЬТРУ ════════════════════════
    case 'get_tariffs':
        $stmt = $pdo->prepare("
            SELECT DISTINCT t.id, t.name
            FROM tariffs t
            JOIN client_invoices ci ON ci.tariff_id = t.id
            WHERE t.club_id = ?
            ORDER BY t.name
        ");
        $stmt->execute([$clubId]);
        Response::ok(['tariffs' => $stmt->fetchAll()]);


    default:
        Response::error("Невідома дія: {$action}", 400);

}} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}
