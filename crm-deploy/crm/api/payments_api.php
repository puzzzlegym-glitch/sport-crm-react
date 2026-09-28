<?php
/**
 * payments_api.php — API оплат за абонементи
 *
 * Дії:
 *   get_list    — список оплат з фільтрами (дата, спосіб, тариф, клієнт, client_id)
 *   get_summary — підсумок по методах оплати за період
 *   update      — редагувати оплату (лише поки її зміна відкрита / у день продажу)
 *   delete      — видалити оплату (те саме обмеження)
 *   refund      — повернення (сторно) оплати: новий рядок з від'ємною сумою в ПОТОЧНІЙ зміні.
 *                 Так виправляються оплати закритих змін — закрита зміна не переписується.
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

// Оплата "закрита" (незмінна), якщо її зміна каси вже закрита, або — для оплат
// без зміни (картка/переказ/депозит) — якщо вона не сьогоднішня. Такі оплати
// не редагуються й не видаляються навіть власником: виправлення — лише
// поверненням (refund) у поточній зміні, як у Z-звітах касових систем.
$LOCKED_SQL = "(CASE WHEN cp.shift_id IS NOT NULL
        THEN COALESCE((SELECT s.status FROM cash_shifts s WHERE s.id = cp.shift_id), 'closed') <> 'open'
        ELSE DATE(cp.created_at) < CURDATE() END)";

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
                cp.shift_id, cp.refund_of_id, cp.refund_reason,
                {$LOCKED_SQL} AS is_locked,
                (SELECT COALESCE(-SUM(r.amount), 0) FROM club_payments r WHERE r.refund_of_id = cp.id) AS refunded_amount,
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
                   cp.refund_of_id,
                   (DATE(cp.created_at) = CURDATE()) AS is_today,
                   {$LOCKED_SQL} AS is_locked,
                   (SELECT COUNT(*) FROM club_payments r WHERE r.refund_of_id = cp.id) AS refunds_cnt
            FROM club_payments cp
            JOIN clients c ON c.id = cp.client_id
            WHERE cp.id = ? AND c.club_id = ? LIMIT 1
        ");
        $chk->execute([$id, $clubId]);
        $old = $chk->fetch();
        if (!$old) Response::error('Оплату не знайдено', 404);
        pay_assertMutable($old, 'Редагувати');
        if ($old['refund_of_id']) Response::error('Повернення не редагується. Видаліть його (поки зміна відкрита) і проведіть заново.', 409);
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
                   cp.refund_of_id,
                   (DATE(cp.created_at) = CURDATE()) AS is_today,
                   {$LOCKED_SQL} AS is_locked,
                   (SELECT COUNT(*) FROM club_payments r WHERE r.refund_of_id = cp.id) AS refunds_cnt
            FROM club_payments cp
            JOIN clients c ON c.id = cp.client_id
            WHERE cp.id = ? AND c.club_id = ? LIMIT 1
        ");
        $chk->execute([$id, $clubId]);
        $old = $chk->fetch();
        if (!$old) Response::error('Оплату не знайдено', 404);
        pay_assertMutable($old, 'Видалити');
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


    // ════ ПОВЕРНЕННЯ (СТОРНО) ОПЛАТИ ═════════════════════════
    // Виправлення оплат, зокрема із закритих змін: закрита зміна лишається
    // як була, а повернення — окремий рядок (amount < 0) у поточній зміні.
    case 'refund':
        if (!Auth::can($sess, $clubId, 'payments.delete')) Response::forbidden('Повернення оплати — лише власник');

        $id     = (int)($input['id'] ?? 0);
        $reason = trim($input['reason'] ?? '');
        if (!$id) Response::error('Не вказано id');
        if (mb_strlen($reason) < 3) Response::error('Вкажіть причину повернення');

        $chk = $pdo->prepare("
            SELECT cp.*, ci.tariff_name,
                   (SELECT COALESCE(-SUM(r.amount), 0) FROM club_payments r WHERE r.refund_of_id = cp.id) AS refunded
            FROM club_payments cp
            JOIN clients c ON c.id = cp.client_id
            LEFT JOIN client_invoices ci ON ci.id = cp.invoice_id
            WHERE cp.id = ? AND c.club_id = ? LIMIT 1
        ");
        $chk->execute([$id, $clubId]);
        $orig = $chk->fetch();
        if (!$orig) Response::error('Оплату не знайдено', 404);
        if ((float)$orig['amount'] <= 0 || $orig['refund_of_id']) Response::error('Це вже повернення — його не можна повернути');

        $available = round((float)$orig['amount'] - (float)$orig['refunded'], 2);
        $amount    = round((float)($input['amount'] ?? $available), 2);
        if ($amount <= 0) Response::error('Сума повернення має бути більше 0');
        if ($amount > $available + 0.001) {
            Response::error('Можна повернути не більше ' . number_format($available, 2, '.', ' ') . ' грн (решту вже повернуто)');
        }

        // Готівку фізично видають з каси — лише у відкриту зміну (і лише свою для не-власника).
        $shiftId = null;
        if ($orig['payment_method'] === 'cash') {
            $sh = $pdo->prepare("SELECT id, opened_by, opened_name FROM cash_shifts WHERE club_id=? AND status='open' LIMIT 1");
            $sh->execute([$clubId]);
            $shift = $sh->fetch();
            if (!$shift) Response::error('Відкрийте зміну каси — готівку повертають з поточної зміни.', 403);
            if (!Auth::isOwner($sess, $access) && (int)$shift['opened_by'] !== (int)$sess['user_id']) {
                Response::error("Зараз відкрита зміна адміністратора «{$shift['opened_name']}». Ви не можете проводити операції в чужій зміні.", 403);
            }
            $shiftId = (int)$shift['id'];
            $balance = (float)$pdo->query("
                SELECT COALESCE(SUM(CASE WHEN type IN('income','transfer_in') THEN amount ELSE 0 END),0)
                     - COALESCE(SUM(CASE WHEN type IN('expense','encashment','transfer_out') THEN amount ELSE 0 END),0)
                     + COALESCE(SUM(CASE WHEN type='adjustment' THEN amount ELSE 0 END),0)
                FROM club_cashflow WHERE club_id=" . (int)$clubId . " AND payment_method='cash' AND location='register'
            ")->fetchColumn();
            if ($amount > $balance + 0.001) {
                Response::error('У касі лише ' . number_format($balance, 2, '.', ' ') . ' грн — недостатньо для повернення готівкою.');
            }
        }

        $origDate = date('d.m.Y', strtotime($orig['created_at']));
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                INSERT INTO club_payments
                    (club_id, client_id, invoice_id, refund_of_id, refund_reason, amount,
                     payment_method, shift_id, admin_id, admin_name, notes)
                VALUES (?,?,?,?,?,?, ?,?,?,?,?)
            ")->execute([
                $clubId, $orig['client_id'], $orig['invoice_id'], $id, mb_substr($reason, 0, 255), -$amount,
                $orig['payment_method'], $shiftId, $sess['user_id'], $sess['full_name'] ?? null,
                mb_substr("Повернення оплати #{$id} від {$origDate}", 0, 255),
            ]);
            $refundId = (int)$pdo->lastInsertId();

            if ($orig['invoice_id']) {
                Recalc::invoicePaidAmount($pdo, (int)$orig['invoice_id']);
                Recalc::invoiceStatus($pdo, (int)$orig['invoice_id']);
            }
            Recalc::cashflowSyncPayment($pdo, $refundId);

            // Оплату депозитом повертаємо на депозит клієнта.
            if ($orig['payment_method'] === 'deposit') {
                $pdo->prepare("
                    INSERT INTO client_deposits
                        (club_id, client_id, amount, operation, invoice_id, admin_id, admin_name, notes)
                    VALUES (?,?,?,'refund',?,?,?,?)
                ")->execute([
                    $clubId, $orig['client_id'], $amount, $orig['invoice_id'],
                    $sess['user_id'], $sess['full_name'] ?? null,
                    mb_substr("Повернення оплати #{$id}: {$reason}", 0, 255),
                ]);
                Recalc::clientBalance($pdo, (int)$orig['client_id']);
            }

            // Чек продажу вже у ДПС — потрібен чек повернення. Автоматично його
            // не створюємо: позначка, щоб оформили в кабінеті Checkbox
            // (cron retry_fiscal_receipts бере лише 'failed', тож не чіпає).
            $needsFiscalReturn = $orig['fiscal_status'] === 'sent';
            if ($needsFiscalReturn) {
                $pdo->prepare("UPDATE club_payments SET fiscal_status = 'return_manual' WHERE id = ?")->execute([$refundId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::serverError($e->getMessage());
        }

        try {
            $clientName = $pdo->prepare("SELECT full_name FROM clients WHERE id=?");
            $clientName->execute([$orig['client_id']]);
            Telegram::notifyClubOwners($clubId,
                "↩️ Повернення оплати: " . number_format($amount, 2) . " грн (" . $orig['payment_method'] . "), клієнт "
                . htmlspecialchars((string)$clientName->fetchColumn(), ENT_NOQUOTES, 'UTF-8')
                . ", оплата від {$origDate}. Причина: " . htmlspecialchars($reason, ENT_NOQUOTES, 'UTF-8')
                . ". Провів(ла): " . ($sess['full_name'] ?? '—')
            );
        } catch (Throwable $e) {
            error_log('[Payments] Telegram refund notify failed: ' . $e->getMessage());
        }

        Response::ok([
            'refund_id'           => $refundId,
            'needs_fiscal_return' => $needsFiscalReturn,
        ], $needsFiscalReturn
            ? 'Повернення проведено. Оплата була фіскалізована — оформіть чек повернення в кабінеті Checkbox.'
            : 'Повернення проведено');


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

// Оплати закритої зміни (або минулого дня, якщо без зміни) і оплати, по яких уже є
// повернення, не змінюються — лише поверненням (refund).
function pay_assertMutable(array $p, string $verb): void {
    if ((int)$p['is_locked']) {
        Response::error("{$verb} не можна: оплата належить до закритої зміни каси (або минулого дня). Скористайтесь «Повернення» — воно буде проведене в поточній зміні.", 409, ['reason' => 'locked']);
    }
    if ((int)$p['refunds_cnt'] > 0) {
        Response::error("{$verb} не можна: по цій оплаті вже є повернення.", 409, ['reason' => 'has_refunds']);
    }
}
