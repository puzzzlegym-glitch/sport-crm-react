<?php
/**
 * saas_api.php — Управління SAAS білінгом (тільки SuperAdmin)
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$sess = Auth::requireAuth();
if (($sess['global_level'] ?? 0) < 100) Response::forbidden('Тільки SuperAdmin');

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';
$pdo    = Database::get();

try { switch ($action) {

    // ════ ОГЛЯД ═══════════════════════════════════════════════
    case 'get_overview':
        $plans = $pdo->query("SELECT id, slug, name, price_monthly FROM saas_plans ORDER BY sort_order")->fetchAll();

        $subs = $pdo->query("SELECT status, COUNT(*) AS cnt FROM saas_subscriptions GROUP BY status")->fetchAll();
        $subByStatus = [];
        foreach ($subs as $s) $subByStatus[$s['status']] = (int)$s['cnt'];

        $revenue = $pdo->query("
            SELECT
                SUM(CASE WHEN MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW()) THEN amount ELSE 0 END) AS month,
                SUM(amount) AS total
            FROM saas_payments WHERE status='success'
        ")->fetch();

        $recentPayments = $pdo->query("
            SELECT sp.amount, sp.gateway, sp.created_at,
                   c.name AS club_name, p.name AS plan_name
            FROM saas_payments sp
            LEFT JOIN saas_invoices i ON i.id = sp.invoice_id
            LEFT JOIN sys_clubs c ON c.id = sp.club_id
            LEFT JOIN saas_plans p ON p.id = i.plan_id
            WHERE sp.status='success'
            ORDER BY sp.created_at DESC LIMIT 10
        ")->fetchAll();

        Response::ok([
            'plans'           => $plans,
            'subs_by_status'  => $subByStatus,
            'revenue_month'   => (float)($revenue['month'] ?? 0),
            'revenue_total'   => (float)($revenue['total'] ?? 0),
            'recent_payments' => $recentPayments,
        ]);


    // ════ ПЛАНИ ═══════════════════════════════════════════════
    case 'get_plans':
        $stmt = $pdo->query("
            SELECT id, slug, name, price_monthly,
                   discount_percent, discount_label, discount_valid_until,
                   clients_limit, users_limit, invoices_limit,
                   trial_days, is_free, allowed_pages,
                   features, is_active, sort_order
            FROM saas_plans ORDER BY sort_order
        ");
        $plans = $stmt->fetchAll();
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM saas_subscriptions WHERE plan_id=? AND status IN ('trial','active')");
        foreach ($plans as &$p) {
            $p['features']      = json_decode($p['features']      ?? '[]',   true);
            $p['allowed_pages'] = json_decode($p['allowed_pages'] ?? 'null', true);
            $cntStmt->execute([$p['id']]);
            $p['clubs_count'] = (int)$cntStmt->fetchColumn();
        }
        Response::ok(['plans' => $plans]);


    case 'update_plan':
        $id = (int)($input['id'] ?? 0);

        $allowedPages = isset($input['allowed_pages']) && is_array($input['allowed_pages'])
            ? json_encode(array_values($input['allowed_pages']))
            : null;

        $discountPercent    = max(0, min(100, (int)($input['discount_percent'] ?? 0)));
        $discountLabel      = htmlspecialchars(trim($input['discount_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?: null;
        $discountValidUntil = trim($input['discount_valid_until'] ?? '') ?: null;
        if (!$discountPercent) { $discountLabel = null; $discountValidUntil = null; }

        if (!$id) {
            $name = htmlspecialchars(trim($input['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            if (!$name) Response::error('Введіть назву плану');

            $isFree = (int)(bool)($input['is_free'] ?? 0);
            if ($isFree) $pdo->query("UPDATE saas_plans SET is_free = 0");

            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));
            $pdo->prepare("
                INSERT INTO saas_plans
                    (slug, name, price_monthly, discount_percent, discount_label, discount_valid_until,
                     clients_limit, users_limit,
                     invoices_limit, trial_days, is_free, is_active, allowed_pages, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    (SELECT COALESCE(MAX(sort_order),0)+1 FROM saas_plans sp))
            ")->execute([
                $slug,
                $name,
                (float)($input['price_monthly'] ?? 0),
                $discountPercent,
                $discountLabel,
                $discountValidUntil,
                ($input['clients_limit']  !== '' && $input['clients_limit']  !== null) ? (int)$input['clients_limit']  : null,
                ($input['users_limit']    !== '' && $input['users_limit']    !== null) ? (int)$input['users_limit']    : null,
                ($input['invoices_limit'] !== '' && $input['invoices_limit'] !== null) ? (int)$input['invoices_limit'] : null,
                max(0, (int)($input['trial_days'] ?? 0)),
                $isFree,
                (int)(bool)($input['is_active'] ?? 1),
                $allowedPages,
            ]);
            Response::ok(['id' => (int)$pdo->lastInsertId()], 'Plan created');
        }

        $isFree = (int)(bool)($input['is_free'] ?? 0);
        if ($isFree) {
            $pdo->prepare("UPDATE saas_plans SET is_free = 0 WHERE id != ?")->execute([$id]);
        }

        $pdo->prepare("
            UPDATE saas_plans SET
                name                 = ?,
                price_monthly        = ?,
                discount_percent     = ?,
                discount_label       = ?,
                discount_valid_until = ?,
                clients_limit        = ?,
                users_limit          = ?,
                invoices_limit       = ?,
                trial_days           = ?,
                is_free              = ?,
                is_active            = ?,
                allowed_pages        = ?
            WHERE id = ?
        ")->execute([
            htmlspecialchars(trim($input['name'] ?? ''), ENT_QUOTES, 'UTF-8'),
            (float)($input['price_monthly'] ?? 0),
            $discountPercent,
            $discountLabel,
            $discountValidUntil,
            ($input['clients_limit'] !== '' && $input['clients_limit'] !== null) ? (int)$input['clients_limit'] : null,
            ($input['users_limit']   !== '' && $input['users_limit']   !== null) ? (int)$input['users_limit']   : null,
            ($input['invoices_limit']!== '' && $input['invoices_limit']!== null) ? (int)$input['invoices_limit']: null,
            max(0, (int)($input['trial_days'] ?? 0)),
            $isFree,
            (int)(bool)($input['is_active'] ?? 1),
            $allowedPages,
            $id,
        ]);
        Response::ok([], 'Plan updated');


    // ════ ПІДПИСКИ ════════════════════════════════════════════
    case 'get_subscriptions':
        // Лінивий авто-перехід протермінованих тріалів у trial_expired.
        // Окремого cron на хостингу немає, тож синхронізуємо статус тут —
        // при кожному завантаженні списку підписок SuperAdmin'ом.
        $pdo->exec("
            UPDATE saas_subscriptions
            SET status = 'trial_expired', updated_at = NOW()
            WHERE status = 'trial' AND trial_ends_at IS NOT NULL AND trial_ends_at < CURDATE()
        ");
        $pdo->exec("
            UPDATE sys_clubs c
            JOIN saas_subscriptions s ON s.club_id = c.id
            SET c.subscription_status = 'trial_expired'
            WHERE c.subscription_status = 'trial' AND s.status = 'trial_expired'
        ");

        $status  = trim($input['status'] ?? $_GET['status'] ?? '');
        $page    = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
        $perPage = 25;
        $offset  = ($page - 1) * $perPage;

        $where  = ['1=1'];
        $params = [];
        if ($status) { $where[] = 's.status = ?'; $params[] = $status; }
        $whereSQL = implode(' AND ', $where);

        $stmt = $pdo->prepare("
            SELECT
                s.id, s.club_id, s.plan_id, s.status, s.trial_ends_at,
                s.current_period_start, s.current_period_end,
                s.auto_renew, s.admin_notes, s.updated_at,
                GREATEST(0, DATEDIFF(s.trial_ends_at, NOW())) AS trial_days_left,
                c.name AS club_name, c.city,
                u.full_name AS owner_name, u.email AS owner_email,
                p.name AS plan_name, p.price_monthly,
                (SELECT SUM(sp.amount) FROM saas_payments sp
                 WHERE sp.club_id = c.id AND sp.status='success') AS total_paid
            FROM saas_subscriptions s
            JOIN sys_clubs c ON c.id = s.club_id
            JOIN sys_users u ON u.id = c.owner_id
            JOIN saas_plans p ON p.id = s.plan_id
            WHERE {$whereSQL}
            ORDER BY s.updated_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$perPage, $offset]));

        $cntStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM saas_subscriptions s JOIN sys_clubs c ON c.id=s.club_id WHERE {$whereSQL}"
        );
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();

        Response::ok([
            'subscriptions' => $stmt->fetchAll(),
            'pagination'    => [
                'total'    => $total,
                'page'     => $page,
                'pages'    => max(1, (int)ceil($total / $perPage)),
                'per_page' => $perPage,
            ],
        ]);


    // Ручне редагування підписки SuperAdmin'ом (план / статус / дата кінця тріалу).
    // Раніше таких змін не було в інтерфейсі — правили напряму в БД, через що план і
    // тріал розходились (дата продовжена, а plan_id лишався від Free/expired стану).
    case 'update_subscription':
        $id = (int)($input['id'] ?? 0);
        if (!$id) Response::error('Не вказано підписку');

        $sub = $pdo->prepare("SELECT club_id FROM saas_subscriptions WHERE id = ? LIMIT 1");
        $sub->execute([$id]);
        $clubId = $sub->fetchColumn();
        if (!$clubId) Response::error('Підписку не знайдено');

        $planId = (int)($input['plan_id'] ?? 0);
        $plan = $pdo->prepare("SELECT id FROM saas_plans WHERE id = ?");
        $plan->execute([$planId]);
        if (!$plan->fetchColumn()) Response::error('Оберіть коректний план');

        $status = trim($input['status'] ?? '');
        if (!in_array($status, ['trial', 'active', 'trial_expired', 'past_due', 'cancelled', 'deleted'], true)) {
            Response::error('Некоректний статус');
        }

        $trialEndsAt = trim($input['trial_ends_at'] ?? '');
        $trialEndsAt = $trialEndsAt !== '' ? $trialEndsAt . ' 23:59:59' : null;

        $currentPeriodEnd = trim($input['current_period_end'] ?? '');
        $currentPeriodEnd = $currentPeriodEnd !== '' ? $currentPeriodEnd . ' 23:59:59' : null;

        $adminNotes = trim($input['admin_notes'] ?? '') ?: null;

        $pdo->prepare("
            UPDATE saas_subscriptions SET
                plan_id             = ?,
                status              = ?,
                trial_ends_at       = ?,
                current_period_end  = ?,
                admin_notes         = ?,
                updated_at          = NOW()
            WHERE id = ?
        ")->execute([$planId, $status, $trialEndsAt, $currentPeriodEnd, $adminNotes, $id]);

        $pdo->prepare("UPDATE sys_clubs SET subscription_status = ? WHERE id = ?")
            ->execute([$status, $clubId]);

        Response::ok([], 'Підписку оновлено');


    // ════ РАХУНКИ ═════════════════════════════════════════════
    case 'get_invoices':
        $page    = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
        $perPage = 30;
        $offset  = ($page - 1) * $perPage;

        $stmt = $pdo->prepare("
            SELECT
                i.id, i.amount, i.status, i.period_start, i.period_end,
                i.paid_at, i.payment_gateway, i.notes, i.created_at,
                c.name AS club_name, p.name AS plan_name
            FROM saas_invoices i
            JOIN sys_clubs c ON c.id = i.club_id
            JOIN saas_plans p ON p.id = i.plan_id
            ORDER BY i.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$perPage, $offset]);

        $total = (int)$pdo->query("SELECT COUNT(*) FROM saas_invoices")->fetchColumn();

        Response::ok([
            'invoices'   => $stmt->fetchAll(),
            'pagination' => [
                'total'    => $total,
                'page'     => $page,
                'pages'    => max(1, (int)ceil($total / $perPage)),
                'per_page' => $perPage,
            ],
        ]);


    // ════ РЕДАГУВАННЯ РАХУНКУ ═════════════════════════════════
    case 'update_invoice':
        $id = (int)($input['id'] ?? 0);
        if (!$id) Response::error('ID not specified');

        $amount = (float)($input['amount'] ?? 0);
        if ($amount < 0) Response::error('Amount cannot be negative');

        $status = in_array($input['status'] ?? '', ['draft', 'paid', 'void'], true)
                  ? $input['status'] : 'draft';

        $periodStart = trim($input['period_start'] ?? '') ?: null;
        $periodEnd   = trim($input['period_end'] ?? '') ?: null;
        $notes       = htmlspecialchars(trim($input['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?: null;

        $current = $pdo->prepare("SELECT status, paid_at FROM saas_invoices WHERE id = ?");
        $current->execute([$id]);
        $row = $current->fetch();
        if (!$row) Response::error('Рахунок не знайдено');

        $paidAt = $row['paid_at'];
        if ($status === 'paid' && !$paidAt) $paidAt = date('Y-m-d H:i:s');
        if ($status !== 'paid') $paidAt = null;

        $pdo->prepare("
            UPDATE saas_invoices SET
                amount       = ?,
                status       = ?,
                period_start = ?,
                period_end   = ?,
                notes        = ?,
                paid_at      = ?
            WHERE id = ?
        ")->execute([$amount, $status, $periodStart, $periodEnd, $notes, $paidAt, $id]);

        Response::ok([], 'Рахунок оновлено');


    // ════ ВИДАЛЕННЯ РАХУНКУ ═══════════════════════════════════
    case 'delete_invoice':
        $id = (int)($input['id'] ?? 0);
        if (!$id) Response::error('ID not specified');

        // Оплачений рахунок може мати пов'язаний платіж у saas_payments (invoice_id NOT NULL),
        // тож видаляємо його разом з рахунком — інакше FK-обмеження валить видалення в 500.
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM saas_payments WHERE invoice_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM saas_invoices WHERE id = ?")->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Response::ok([], 'Рахунок видалено');


    // ════ ПРОМОКОДИ ═══════════════════════════════════════════
    // Одноразові коди на знижку для оплати SaaS-тарифу (не клієнтські
    // абонементи) — max_uses завжди 1, не налаштовується.
    case 'get_promo_codes':
        $rows = $pdo->query("
            SELECT pc.*, p.name AS plan_name, c.name AS used_club_name
            FROM saas_promo_codes pc
            LEFT JOIN saas_plans p ON p.id = pc.plan_id
            LEFT JOIN sys_clubs c ON c.id = pc.used_club_id
            ORDER BY pc.created_at DESC
        ")->fetchAll();
        Response::ok(['promo_codes' => $rows]);


    case 'save_promo_code':
        $id = (int)($input['id'] ?? 0);

        $code = strtoupper(trim($input['code'] ?? ''));
        if (!preg_match('/^[A-Z0-9_-]{3,32}$/', $code)) {
            Response::error('Код: 3-32 символи, латиниця/цифри/дефіс/підкреслення');
        }

        $discountType = ($input['discount_type'] ?? '') === 'fixed' ? 'fixed' : 'percent';
        $discountValue = (float)($input['discount_value'] ?? 0);
        if ($discountValue <= 0) Response::error('Вкажіть суму/відсоток знижки');
        if ($discountType === 'percent') $discountValue = min(100, $discountValue);

        $planId = ($input['plan_id'] !== '' && $input['plan_id'] !== null) ? (int)$input['plan_id'] : null;
        $validUntil = trim($input['valid_until'] ?? '') ?: null;
        $isActive = (int)(bool)($input['is_active'] ?? 1);
        $notes = htmlspecialchars(trim($input['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?: null;

        $dup = $pdo->prepare("SELECT id FROM saas_promo_codes WHERE code = ? AND id != ?");
        $dup->execute([$code, $id]);
        if ($dup->fetchColumn()) Response::error('Такий код вже існує');

        if (!$id) {
            $pdo->prepare("
                INSERT INTO saas_promo_codes
                    (code, discount_type, discount_value, plan_id, valid_until, is_active, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([$code, $discountType, $discountValue, $planId, $validUntil, $isActive, $notes]);
            Response::ok(['id' => (int)$pdo->lastInsertId()], 'Промокод створено');
        }

        $pdo->prepare("
            UPDATE saas_promo_codes SET
                code = ?, discount_type = ?, discount_value = ?,
                plan_id = ?, valid_until = ?, is_active = ?, notes = ?
            WHERE id = ?
        ")->execute([$code, $discountType, $discountValue, $planId, $validUntil, $isActive, $notes, $id]);
        Response::ok([], 'Промокод оновлено');


    // Розблокувати одноразовий код вручну (напр. клуб застосував промокод,
    // але оплата так і не пройшла) — без цього код лишається "спаленим" назавжди.
    case 'reset_promo_code':
        $id = (int)($input['id'] ?? 0);
        if (!$id) Response::error('ID not specified');
        $pdo->prepare("
            UPDATE saas_promo_codes
            SET is_used = 0, used_club_id = NULL, used_invoice_id = NULL, used_at = NULL
            WHERE id = ?
        ")->execute([$id]);
        Response::ok([], 'Промокод розблоковано');


    case 'delete_promo_code':
        $id = (int)($input['id'] ?? 0);
        if (!$id) Response::error('ID not specified');
        $pdo->prepare("DELETE FROM saas_promo_codes WHERE id = ?")->execute([$id]);
        Response::ok([], 'Промокод видалено');


    // ════ ПЛАТЕЖІ ═════════════════════════════════════════════
    case 'get_payments':
        $page    = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
        $perPage = 30;
        $offset  = ($page - 1) * $perPage;

        $total = (int)$pdo->query("SELECT COUNT(*) FROM saas_payments")->fetchColumn();

        if ($total === 0) {
            Response::ok([
                'payments'   => [],
                'summary'    => ['success' => 0, 'manual' => 0],
                'pagination' => ['total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => $perPage],
            ]);
        }

        $rows = $pdo->query("
            SELECT sp.id, sp.amount, sp.gateway,
                   sp.status,
                   COALESCE(sp.recorded_note,'') AS recorded_note,
                   sp.created_at,
                   COALESCE(c.name,'') AS club_name,
                   COALESCE(u.full_name,'') AS recorded_by_name,
                   COALESCE(p.name,'') AS plan_name
            FROM saas_payments sp
            LEFT JOIN sys_clubs c ON c.id = sp.club_id
            LEFT JOIN saas_invoices i ON i.id = sp.invoice_id
            LEFT JOIN saas_plans p ON p.id = i.plan_id
            LEFT JOIN sys_users u ON u.id = sp.recorded_by
            ORDER BY sp.created_at DESC
            LIMIT {$perPage} OFFSET {$offset}
        ")->fetchAll(PDO::FETCH_ASSOC);

        $sumStmt = $pdo->query("
            SELECT
                COALESCE(SUM(CASE WHEN status='success' THEN amount ELSE 0 END),0) AS success,
                COALESCE(SUM(CASE WHEN gateway='manual' AND status='success' THEN amount ELSE 0 END),0) AS manual
            FROM saas_payments
        ");
        $sumRow = $sumStmt ? $sumStmt->fetch(PDO::FETCH_ASSOC) : ['success'=>0,'manual'=>0];

        Response::ok([
            'payments'   => $rows ?: [],
            'summary'    => $sumRow ?: ['success'=>0,'manual'=>0],
            'pagination' => [
                'total'    => $total,
                'page'     => $page,
                'pages'    => max(1, (int)ceil($total / $perPage)),
                'per_page' => $perPage,
            ],
        ]);


    // ════ РЕДАГУВАННЯ ПЛАТЕЖУ ═════════════════════════════════
    case 'update_payment':
        $id      = (int)($input['id'] ?? 0);
        $amount  = (float)($input['amount'] ?? 0);
        $gateway = in_array($input['gateway'] ?? '', ['manual','wayforpay','liqpay'])
                   ? $input['gateway'] : 'manual';
        $status  = in_array($input['status'] ?? '', ['success','pending','failed','refunded'])
                   ? $input['status'] : 'success';
        $note    = htmlspecialchars(trim($input['note'] ?? ''), ENT_QUOTES, 'UTF-8');

        if (!$id)      Response::error('ID not specified');
        if ($amount < 0) Response::error('Amount cannot be negative');

        $invIdStmt = $pdo->prepare("SELECT invoice_id FROM saas_payments WHERE id=?");
        $invIdStmt->execute([$id]);
        $paymentInvoiceId = $invIdStmt->fetchColumn();

        $pdo->prepare("
            UPDATE saas_payments
            SET amount=?, gateway=?, status=?, recorded_note=?
            WHERE id=?
        ")->execute([$amount, $gateway, $status, $note, $id]);
        // Немає тригера на UPDATE saas_payments (лише INSERT/DELETE) — це
        // прогалина, що існувала й до цієї міграції; Recalc її закриває.
        if ($paymentInvoiceId) Recalc::saasInvoiceStatus($pdo, (int)$paymentInvoiceId);

        Response::ok([], 'Payment updated');


    // ════ ВИДАЛЕННЯ ПЛАТЕЖУ ═══════════════════════════════════
    case 'delete_payment':
        $id = (int)($input['id'] ?? 0);
        if (!$id) Response::error('ID not specified');

        $invIdStmt = $pdo->prepare("SELECT invoice_id FROM saas_payments WHERE id=?");
        $invIdStmt->execute([$id]);
        $paymentInvoiceId = $invIdStmt->fetchColumn();

        $pdo->prepare("DELETE FROM saas_payments WHERE id=?")->execute([$id]);
        if ($paymentInvoiceId) Recalc::saasInvoiceStatus($pdo, (int)$paymentInvoiceId);

        Response::ok([], 'Payment deleted');


    default:
        Response::error("Unknown action: {$action}", 400);

}} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError('ERR: ' . $e->getMessage() . ' line ' . $e->getLine());
}
