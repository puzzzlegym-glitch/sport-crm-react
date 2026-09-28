<?php
/**
 * billing_api.php — API білінгу
 *
 * Дії:
 *   get_plans        — список тарифів (публічна)
 *   get_my_billing   — білінг власника (авторизована)
 *   get_invoices     — рахунки клубу
 *   create_payment   — ініціювати оплату через WayForPay
 *   wfp_callback     — webhook від WayForPay (публічна, перевірка підпису)
 *   record_manual    — ручна оплата (SuperAdmin)
 *   admin_list       — всі клуби з підписками (SuperAdmin)
 *   admin_extend     — продовжити підписку вручну (SuperAdmin)
 *   cron_check       — щоденний крон (секретний ключ)
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';
$pdo    = Database::get();

// Публічні дії — не потребують авторизації
$publicActions = ['get_plans', 'wfp_callback', 'cron_check'];

// Промокод обмежений на одне-єдине використання (max_uses завжди 1, не
// налаштовується) — після застосування навсіди позначається is_used=1.
function self_validatePromo(PDO $pdo, string $code, int $planId): array
{
    $code = strtoupper(trim($code));
    if ($code === '') return [null, 'Введіть промокод'];

    $stmt = $pdo->prepare("SELECT * FROM saas_promo_codes WHERE code = ? LIMIT 1");
    $stmt->execute([$code]);
    $promo = $stmt->fetch();

    if (!$promo)                 return [null, 'Промокод не знайдено'];
    if (!$promo['is_active'])    return [null, 'Промокод вимкнено'];
    if ($promo['is_used'])       return [null, 'Промокод вже використано'];
    if ($promo['valid_until'] && $promo['valid_until'] < date('Y-m-d')) {
        return [null, 'Строк дії промокоду закінчився'];
    }
    if ($promo['plan_id'] && (int)$promo['plan_id'] !== $planId) {
        return [null, 'Промокод не діє на обраний тариф'];
    }
    return [$promo, null];
}

// Сума знижки за промокодом, застосована до вже порахованої суми $amount.
// Знижка не може "з'їсти" весь платіж — лишається щонайменше 1 грн до сплати.
function self_promoDiscountAmount(array $promo, float $amount): float
{
    $raw = $promo['discount_type'] === 'fixed'
        ? (float)$promo['discount_value']
        : round($amount * (float)$promo['discount_value'] / 100, 2);
    return min($raw, max(0, $amount - 1));
}

if (!in_array($action, $publicActions)) {
    $sess = Auth::requireAuth();
}

try { switch ($action) {

    // ── СПИСОК ПЛАНІВ (публічна) ─────────────────────────────
    case 'get_plans':
        $stmt = $pdo->query("
            SELECT id, slug, name, price_monthly,
                   discount_percent, discount_label, discount_valid_until,
                   is_free, clients_limit, users_limit, invoices_limit, features
            FROM saas_plans
            WHERE is_active = 1
            ORDER BY sort_order
        ");
        $plans = $stmt->fetchAll();
        foreach ($plans as &$p) {
            $p['features'] = json_decode($p['features'] ?? '[]', true);
            $p['discount_active']  = self_planDiscountActive($p);
            $p['price_effective']  = self_planEffectivePrice($p);
        }
        Response::ok(['plans' => $plans]);


    // ── МІЙ БІЛІНГ ──────────────────────────────────────────
    case 'get_my_billing':
        $clubId = (int)($sess['active_club_id'] ?? 0);
        if (!$clubId) Response::error('Не обрано клуб');

        if (!Auth::can($sess, $clubId, 'billing.manage')) Response::forbidden();

        $info = Billing::getInfo($clubId);
        if (!$info) {
            Response::ok(['no_subscription' => true]);
        }

        // Останні рахунки
        $invStmt = $pdo->prepare("
            SELECT i.id, i.amount, i.currency, i.status, i.period_start, i.period_end,
                   i.paid_at, i.payment_gateway, p.name AS plan_name
            FROM saas_invoices i
            JOIN saas_plans p ON p.id = i.plan_id
            WHERE i.club_id = ?
            ORDER BY i.created_at DESC
            LIMIT 12
        ");
        $invStmt->execute([$clubId]);

        Response::ok([
            'subscription' => $info,
            'invoices'     => $invStmt->fetchAll(),
        ]);


    // ── ENTITLEMENT-СТАН (план/usage/ліміти/permissions) ────
    // Доступно будь-якому активному члену клубу (не лише billing.manage) —
    // потрібне для UI-гейтів на Dashboard/ClientsPage/UsersPage.
    case 'get_entitlements':
        $clubId = (int)($sess['active_club_id'] ?? 0);
        if (!$clubId) Response::error('Не обрано клуб');
        Auth::requireClubAccess($sess, $clubId, 30);

        Response::ok(Billing::getEntitlements($clubId));


    // ── ПЕРЕВІРКА ПРОМОКОДУ (попередній перегляд знижки) ────
    case 'check_promo':
        $clubId = (int)($sess['active_club_id'] ?? 0);
        $planId = (int)($input['plan_id'] ?? 0);
        if (!$clubId) Response::error('Не обрано клуб');
        if (!Auth::can($sess, $clubId, 'billing.manage')) Response::forbidden();

        [$promo, $err] = self_validatePromo($pdo, $input['code'] ?? '', $planId);
        if ($err) Response::error($err);

        Response::ok([
            'code'           => $promo['code'],
            'discount_type'  => $promo['discount_type'],
            'discount_value' => (float)$promo['discount_value'],
        ]);


    // ── ІНІЦІЮВАТИ ОПЛАТУ (WayForPay) ───────────────────────
    case 'create_payment':
        $clubId  = (int)($sess['active_club_id'] ?? 0);
        $planId  = (int)($input['plan_id'] ?? 0);
        $months  = max(1, min(12, (int)($input['months'] ?? 1)));
        $promoCode = trim($input['promo_code'] ?? '');

        if (!$clubId) Response::error('Не обрано клуб');
        if (!$planId) Response::error('Не обрано план');
        if (!Auth::can($sess, $clubId, 'billing.manage')) Response::forbidden();

        $plan = $pdo->prepare("SELECT * FROM saas_plans WHERE id=? AND is_active=1");
        $plan->execute([$planId]);
        $plan = $plan->fetch();
        if (!$plan) Response::error('План не знайдено');

        // Перевірка лімітів при даунгрейді
        $limitError = checkPlanLimits($pdo, $clubId, $planId);
        if ($limitError) Response::error('Перевищено ліміти обраного плану: ' . $limitError);

        // Акційна ціна плану (якщо задана й ще діє) + знижка за кількість місяців
        $basePrice   = self_planEffectivePrice($plan);
        $discounts   = [1 => 0, 3 => 0.05, 6 => 0.10, 12 => 0.15];
        $disc        = $discounts[$months] ?? 0;
        $amount      = round($basePrice * $months * (1 - $disc), 2);

        // Промокод — валідується заново на сервері (клієнтський preview не довіряємо)
        $promo = null;
        $promoDiscount = 0;
        if ($promoCode !== '') {
            [$promo, $promoErr] = self_validatePromo($pdo, $promoCode, $planId);
            if ($promoErr) Response::error($promoErr);
            $promoDiscount = self_promoDiscountAmount($promo, $amount);
            $amount = round($amount - $promoDiscount, 2);
        }

        $periodStart = date('Y-m-d');
        $periodEnd   = date('Y-m-d', strtotime("+{$months} months"));

        // Якщо підписки немає — створити заглушку (буде активована після оплати)
        $subExists = $pdo->prepare("SELECT id FROM saas_subscriptions WHERE club_id = ? LIMIT 1");
        $subExists->execute([$clubId]);
        if (!$subExists->fetchColumn()) {
            $pdo->prepare("
                INSERT INTO saas_subscriptions
                    (club_id, plan_id, status, current_period_start, auto_renew, created_at, updated_at)
                VALUES (?, ?, 'pending', NOW(), 0, NOW(), NOW())
            ")->execute([$clubId, $planId]);
        }

        // Якщо підписки немає — створити чернетку
        $subExists = $pdo->prepare("SELECT id FROM saas_subscriptions WHERE club_id = ? LIMIT 1");
        $subExists->execute([$clubId]);
        if (!$subExists->fetchColumn()) {
            $pdo->prepare("
                INSERT INTO saas_subscriptions
                    (club_id, plan_id, status, current_period_start, auto_renew, created_at, updated_at)
                VALUES (?, ?, 'pending', NOW(), 0, NOW(), NOW())
            ")->execute([$clubId, $planId]);
        }

        // Створюємо рахунок зі статусом draft (+ промокод, якщо застосований)
        $pdo->prepare("
            INSERT INTO saas_invoices
                (club_id, subscription_id, plan_id, amount, promo_code, discount_amount,
                 period_start, period_end, status, due_date, payment_gateway)
            SELECT ?, s.id, ?, ?, ?, ?, ?, ?, 'draft', CURDATE(), 'wayforpay'
            FROM saas_subscriptions s WHERE s.club_id = ? LIMIT 1
        ")->execute([
            $clubId, $planId, $amount,
            $promo ? $promo['code'] : null,
            $promo ? $promoDiscount : null,
            $periodStart, $periodEnd, $clubId,
        ]);
        $invoiceId = (int)$pdo->lastInsertId();
        if (!$invoiceId) Response::error('Не вдалося створити рахунок');

        // Промокод одноразовий — "спалюємо" його одразу тут (не в callback), тому
        // WHERE is_used=0 як захист від подвійного застосування паралельними запитами.
        if ($promo) {
            $burned = $pdo->prepare("
                UPDATE saas_promo_codes
                SET is_used = 1, used_club_id = ?, used_invoice_id = ?, used_at = NOW()
                WHERE id = ? AND is_used = 0
            ");
            $burned->execute([$clubId, $invoiceId, $promo['id']]);
            if ($burned->rowCount() === 0) {
                $pdo->prepare("DELETE FROM saas_invoices WHERE id = ?")->execute([$invoiceId]);
                Response::error('Промокод щойно використали в іншому платежі');
            }
        }

        $wfpForm = self_wfpCreateForm($invoiceId, $amount, $clubId, $planId, $months);

        Response::ok([
            'invoice_id' => $invoiceId,
            'amount'     => $amount,
            'wfp_form'   => $wfpForm,
        ]);


    // ── WAYFORPAY CALLBACK (webhook) ─────────────────────────
    // WayForPay надсилає JSON POST після кожного платежу
    case 'wfp_callback':
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true) ?? [];

        // WFP іноді передає JSON як перший ключ POST
        if (empty($data) && !empty($_POST)) {
            $firstKey = array_key_first($_POST);
            $data = json_decode($firstKey, true) ?? [];
        }

        $orderRef = $data['orderReference']    ?? '';
        $status   = $data['transactionStatus'] ?? '';
        $amount   = (float)($data['amount']    ?? 0);

        // Обов'язкова відповідь WayForPay
        $respondWfp = function() use ($orderRef) {
            $time = time();
            $sig  = hash_hmac('md5', $orderRef . ';accept;' . $time, WFP_MERCHANT_SECRET);
            header('Content-Type: application/json');
            echo json_encode([
                'orderReference' => $orderRef ?: 'unknown',
                'status'         => 'accept',
                'time'           => $time,
                'signature'      => $sig,
            ]);
        };

        // Перевірка підпису WFP
        $sigFields = [
            $data['merchantAccount']   ?? '',
            $data['orderReference']    ?? '',
            $data['amount']            ?? '',
            $data['currency']          ?? '',
            $data['authCode']          ?? '',
            $data['cardPan']           ?? '',
            $data['transactionStatus'] ?? '',
            $data['reasonCode']        ?? '',
        ];
        $expectedSig = hash_hmac('md5', implode(';', $sigFields), WFP_MERCHANT_SECRET);
        if (!hash_equals($expectedSig, $data['merchantSignature'] ?? '')) {
            error_log('[WFP] Invalid signature orderRef=' . $orderRef);
            $respondWfp(); exit;
        }

        // Лише Approved
        if ($status !== 'Approved' || $amount <= 0) {
            // Telegram: сповіщення SuperAdmin про невдалу спробу оплати (не блокує відповідь WFP)
            try {
                if (preg_match('/^inv_(\d+)_(\d+)/', $orderRef, $failM)) {
                    Telegram::notifySuperAdmins(
                        "⚠️ Платіж не пройшов: клуб #{$failM[2]}, сума " . number_format($amount, 2) . " грн, статус {$status}"
                    );
                }
            } catch (Throwable $e) {
                error_log('[WFP] Telegram fail-notify error: ' . $e->getMessage());
            }
            $respondWfp(); exit;
        }

        // Парсимо orderReference: inv_{invoiceId}_{clubId}
        if (!preg_match('/^inv_(\d+)_(\d+)/', $orderRef, $m)) {
            $respondWfp(); exit;
        }
        $invoiceId = (int)$m[1];
        $clubId    = (int)$m[2];

        // Захист від дублів
        $dup = $pdo->prepare("SELECT status FROM saas_invoices WHERE id=? AND club_id=? LIMIT 1");
        $dup->execute([$invoiceId, $clubId]);
        $invoice = $dup->fetch();
        if (!$invoice || $invoice['status'] === 'paid') {
            $respondWfp(); exit;
        }

        // Зберігаємо транзакцію
        $txnId = $data['transactionStatus'] . '_' . ($data['authCode'] ?? time());
        $pdo->prepare("
            INSERT INTO saas_payments
                (invoice_id, club_id, amount, gateway, gateway_txn_id,
                 gateway_status, gateway_raw, status)
            VALUES (?, ?, ?, 'wayforpay', ?, ?, ?, 'success')
        ")->execute([
            $invoiceId, $clubId, $amount, $txnId, $status,
            json_encode($data),
        ]);
        Recalc::saasInvoiceStatus($pdo, $invoiceId);

        // Отримуємо plan_id і місяці з рахунку
        $inv = $pdo->prepare("SELECT plan_id, period_start, period_end FROM saas_invoices WHERE id=?");
        $inv->execute([$invoiceId]);
        $invRow = $inv->fetch();

        $months = max(1, (int)round(
            (strtotime($invRow['period_end']) - strtotime($invRow['period_start'])) / (30 * 86400)
        ));

        // Активуємо підписку
        Billing::activate($clubId, $invRow['plan_id'], $months);

        // Оновлюємо рахунок
        $pdo->prepare("UPDATE saas_invoices SET status='paid', paid_at=NOW() WHERE id=?")
            ->execute([$invoiceId]);

        // Telegram: сповіщення SuperAdmin про успішну оплату (не блокує основний потік)
        try {
            Telegram::notifySuperAdmins(
                "💰 Оплата отримана: клуб #{$clubId}, " . number_format($amount, 2) . " грн"
            );
        } catch (Throwable $e) {
            error_log('[WFP] Telegram notify error: ' . $e->getMessage());
        }

        // Email власнику
        try {
            $owner = $pdo->prepare("
                SELECT u.email, u.full_name, p.name AS plan_name
                FROM sys_clubs c
                JOIN sys_users u ON u.id = c.owner_id
                JOIN saas_subscriptions s ON s.club_id = c.id
                JOIN saas_plans p ON p.id = s.plan_id
                WHERE c.id = ?
            ");
            $owner->execute([$clubId]);
            $o = $owner->fetch();
            if ($o) {
                Mailer::sendTemplate('payment_success', [
                    'owner_name' => $o['full_name'],
                    'plan_name'  => $o['plan_name'],
                    'invoice_id' => $invoiceId,
                    'amount'     => number_format($amount, 2) . ' грн',
                    'period'     => $invRow['period_start'] . ' — ' . $invRow['period_end'],
                ], $o['email']);
            }
        } catch (Throwable $e) {
            error_log('[WFP] Email error: ' . $e->getMessage());
        }

        error_log("[WFP] Активовано: club={$clubId} invoice={$invoiceId} amount={$amount}");
        $respondWfp();
        exit;


    // ── РУЧНА ОПЛАТА (SuperAdmin) ────────────────────────────
    // ── АКТИВАЦІЯ ТРІАЛУ ─────────────────────────────────────
    case 'activate_trial':
        $clubId = (int)($sess['active_club_id'] ?? 0);
        if (!$clubId) Response::error('Не обрано клуб');
        if (!Auth::can($sess, $clubId, 'billing.manage')) Response::forbidden();

        // Перевірити чи вже є підписка
        $exists = $pdo->prepare("SELECT id FROM saas_subscriptions WHERE club_id=? LIMIT 1");
        $exists->execute([$clubId]);
        if ($exists->fetchColumn()) Response::error('Підписка вже існує');

        // Знайти бізнес план для тріалу (найдорожчий не-free)
        $trialPlan = $pdo->query("
            SELECT id FROM saas_plans
            WHERE is_free = 0 AND is_active = 1
            ORDER BY price_monthly DESC LIMIT 1
        ")->fetchColumn();
        if (!$trialPlan) Response::error('Тріал план не знайдено');

        $trialDays = 14;
        $trialEnds = date('Y-m-d', strtotime("+{$trialDays} days"));

        $pdo->prepare("
            INSERT INTO saas_subscriptions
                (club_id, plan_id, status, trial_ends_at, current_period_start, auto_renew, created_at, updated_at)
            VALUES (?, ?, 'trial', ?, NOW(), 0, NOW(), NOW())
        ")->execute([$clubId, $trialPlan, $trialEnds]);

        $pdo->prepare("UPDATE sys_clubs SET subscription_status='trial' WHERE id=?")
            ->execute([$clubId]);

        Response::ok(['message' => 'Тріал активовано', 'trial_ends' => $trialEnds]);


    // ── ПЕРЕЙТИ НА FREE ПЛАН ────────────────────────────────
    case 'switch_to_free':
        $clubId = (int)($sess['active_club_id'] ?? 0);
        if (!$clubId) Response::error('Не обрано клуб');
        if (!Auth::can($sess, $clubId, 'billing.manage')) Response::forbidden();

        // Якщо клієнт обрав конкретний план — дозволяємо лише якщо його реальна
        // (з урахуванням акції) ціна дорівнює 0, інакше це спроба обійти оплату.
        $freePlan = null;
        $requestedPlanId = (int)($input['plan_id'] ?? 0);
        if ($requestedPlanId) {
            $reqPlan = $pdo->prepare("SELECT * FROM saas_plans WHERE id=? AND is_active=1");
            $reqPlan->execute([$requestedPlanId]);
            $reqPlan = $reqPlan->fetch();
            if ($reqPlan && self_planEffectivePrice($reqPlan) <= 0) $freePlan = $requestedPlanId;
        }
        if (!$freePlan) {
            $freePlan = $pdo->query("
                SELECT id FROM saas_plans WHERE is_free = 1 AND is_active = 1 LIMIT 1
            ")->fetchColumn();
        }
        if (!$freePlan) Response::error('Free план не знайдено');

        // Перевірка лімітів тільки якщо підписка активна (не заблокована)
        $currentStatus = $pdo->prepare("SELECT status FROM saas_subscriptions WHERE club_id=? LIMIT 1");
        $currentStatus->execute([$clubId]);
        $currentStatus = $currentStatus->fetchColumn();
        $isBlocked = in_array($currentStatus, ['trial_expired', 'past_due', 'cancelled', 'deleted', null, false]);

        if (!$isBlocked) {
            $limitError = checkPlanLimits($pdo, $clubId, (int)$freePlan);
            if ($limitError) Response::error('Перевищено ліміти плану: ' . $limitError);
        }

        $pdo->prepare("
            INSERT INTO saas_subscriptions
                (club_id, plan_id, status, current_period_start, current_period_end, auto_renew, created_at, updated_at)
            VALUES
                (?, ?, 'active', NOW(), NULL, 0, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                plan_id              = VALUES(plan_id),
                status               = 'active',
                current_period_start = NOW(),
                current_period_end   = NULL,
                auto_renew           = 0,
                updated_at           = NOW()
        ")->execute([$clubId, $freePlan]);

        $pdo->prepare("UPDATE sys_clubs SET subscription_status = 'active' WHERE id = ?")->execute([$clubId]);

        // Захист від "дірки" вище (isBlocked-гілка пропускає checkPlanLimits) —
        // якщо команда все одно не влізла в новий ліміт, деактивує зайвих.
        Billing::enforceTeamLimit($clubId);

        Response::ok(['message' => 'Переведено на Free план']);


    case 'record_manual':
        if (($sess['global_level'] ?? 0) < 100) Response::forbidden();

        $clubId   = (int)($input['club_id']  ?? 0);
        $planId   = (int)($input['plan_id']  ?? 0);
        $months   = max(1, (int)($input['months'] ?? 1));
        $note     = trim($input['note'] ?? 'Ручна оплата');

        if (!$clubId || !$planId) Response::error('Вкажіть club_id і plan_id');

        $plan = $pdo->prepare("SELECT * FROM saas_plans WHERE id=?");
        $plan->execute([$planId]);
        $plan = $plan->fetch();
        if (!$plan) Response::error('План не знайдено');

        $amount = self_planEffectivePrice($plan) * $months;
        $periodEnd = date('Y-m-d', strtotime("+{$months} months"));

        $pdo->beginTransaction();
        try {
            // Рахунок
            $pdo->prepare("
                INSERT INTO saas_invoices
                    (club_id, subscription_id, plan_id, amount, period_start, period_end,
                     status, paid_at, due_date, payment_gateway, notes)
                SELECT ?, s.id, ?, ?, CURDATE(), ?, 'paid', NOW(), CURDATE(), 'manual', ?
                FROM saas_subscriptions s WHERE s.club_id = ?
            ")->execute([$clubId, $planId, $amount, $periodEnd, $note, $clubId]);
            $invoiceId = (int)$pdo->lastInsertId();

            // Транзакція
            $pdo->prepare("
                INSERT INTO saas_payments
                    (invoice_id, club_id, amount, gateway, status, recorded_by, recorded_note)
                VALUES (?, ?, ?, 'manual', 'success', ?, ?)
            ")->execute([$invoiceId, $clubId, $amount, $sess['user_id'], $note]);
            Recalc::saasInvoiceStatus($pdo, $invoiceId);

            // Активація
            Billing::activate($clubId, $planId, $months);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::serverError($e->getMessage());
        }

        Response::ok(['invoice_id' => $invoiceId], "Оплату записано. Клуб активовано на {$months} міс.");


    // ── СПИСОК КЛУБІВ ДЛЯ SUPERADMIN ────────────────────────
    case 'admin_list':
        if (($sess['global_level'] ?? 0) < 100) Response::forbidden();

        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 25;
        $offset  = ($page - 1) * $perPage;
        $search  = trim($_GET['search'] ?? '');

        $where  = '1=1';
        $params = [];
        if ($search) {
            $where    = 'c.name LIKE ? OR u.email LIKE ?';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $stmt = $pdo->prepare("
            SELECT
                c.id, c.name, c.city, c.subscription_status, c.trial_ends_at,
                u.email AS owner_email, u.full_name AS owner_name,
                s.status AS sub_status, s.current_period_end,
                p.name AS plan_name, p.price_monthly,
                (SELECT COUNT(*) FROM clients cl WHERE cl.club_id = c.id AND cl.status != 'banned') AS clients_count,
                GREATEST(0, DATEDIFF(s.trial_ends_at, NOW())) AS trial_days_left
            FROM sys_clubs c
            JOIN sys_users u ON u.id = c.owner_id
            LEFT JOIN saas_subscriptions s ON s.club_id = c.id
            LEFT JOIN saas_plans p ON p.id = s.plan_id
            WHERE {$where}
            ORDER BY c.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$perPage, $offset]));

        $total = (int)$pdo->query("SELECT COUNT(*) FROM sys_clubs c JOIN sys_users u ON u.id=c.owner_id WHERE {$where}")->fetchColumn();

        Response::ok([
            'clubs'      => $stmt->fetchAll(),
            'pagination' => [
                'total'   => $total,
                'page'    => $page,
                'pages'   => max(1, (int)ceil($total / $perPage)),
            ],
        ]);


    // ── КРОН (щоденна перевірка) ─────────────────────────────
    // Виклик: GET /api/billing_api.php?action=cron_check&key=...
    case 'cron_check':
        $key = $_GET['key'] ?? '';
        if (!defined('CRON_SECRET') || !hash_equals(CRON_SECRET, $key)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
        $results = Billing::runDailyCheck($pdo);
        echo json_encode(['ok' => true, 'actions' => $results]);
        exit;


    default:
        Response::error("Невідома дія: {$action}", 400);

}} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}

// ── Генерація WayForPay форми ────────────────────────────────
function self_wfpCreateForm(int $invoiceId, float $amount, int $clubId, int $planId, int $months): array
{
    $orderRef    = "inv_{$invoiceId}_{$clubId}_" . time();
    $orderDate   = time();
    $currency    = 'UAH';
    $productName = 'Підписка Sport CRM #' . $invoiceId;
    $returnUrl   = rtrim(APP_URL, '/') . '/billing?paid=1';
    $serviceUrl  = rtrim(APP_URL, '/') . '/api/billing_api.php?action=wfp_callback';
    $merchant    = WFP_MERCHANT_LOGIN;
    $domain      = parse_url(APP_URL, PHP_URL_HOST);

    // Підпис згідно документації WayForPay:
    // merchantAccount;merchantDomainName;orderReference;orderDate;amount;currency;productName;productCount;productPrice
    $sigString = implode(';', [
        $merchant,
        $domain,
        $orderRef,
        $orderDate,
        $amount,
        $currency,
        $productName,  // productName[]
        1,             // productCount[]
        $amount,       // productPrice[]
    ]);
    $signature = hash_hmac('md5', $sigString, WFP_MERCHANT_SECRET);

    // HTML-форма для автосабміту
    $html = '<form id="wfp-form" method="POST" action="https://secure.wayforpay.com/pay" accept-charset="utf-8">'
        . self_wfpInput('merchantAccount',    $merchant)
        . self_wfpInput('merchantDomainName', $domain)
        . self_wfpInput('orderReference',     $orderRef)
        . self_wfpInput('orderDate',          $orderDate)
        . self_wfpInput('amount',             $amount)
        . self_wfpInput('currency',           $currency)
        . self_wfpInput('orderLifetime',      600)
        . self_wfpInput('productName[]',      $productName)
        . self_wfpInput('productCount[]',     1)
        . self_wfpInput('productPrice[]',     $amount)
        . self_wfpInput('returnUrl',          $returnUrl)
        . self_wfpInput('serviceUrl',         $serviceUrl)
        . self_wfpInput('merchantSignature',  $signature)
        . '</form>';

    return ['html' => $html, 'order_ref' => $orderRef];
}

function self_wfpInput(string $name, $value): string
{
    return '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars((string)$value) . '">';
}
// ── Акція/знижка на план ─────────────────────────────────
function self_planDiscountActive(array $plan): bool
{
    $pct = (int)($plan['discount_percent'] ?? 0);
    if ($pct <= 0) return false;
    $until = $plan['discount_valid_until'] ?? null;
    return !$until || $until >= date('Y-m-d');
}

function self_planEffectivePrice(array $plan): float
{
    if (!self_planDiscountActive($plan)) return (float)$plan['price_monthly'];
    $pct = (int)$plan['discount_percent'];
    return round((float)$plan['price_monthly'] * (1 - $pct / 100), 2);
}

// ── Перевірка лімітів плану ──────────────────────────────
function checkPlanLimits(PDO $pdo, int $clubId, int $planId): ?string
{
    $stmt = $pdo->prepare("SELECT clients_limit, users_limit, invoices_limit FROM saas_plans WHERE id=?");
    $stmt->execute([$planId]);
    $plan = $stmt->fetch();
    if (!$plan) return null;

    $u = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM clients WHERE club_id=?) AS clients_cnt,
            (SELECT COUNT(*) FROM sys_user_clubs WHERE club_id=? AND is_active=1) AS users_cnt,
            (SELECT COUNT(*) FROM client_invoices WHERE club_id=?
             AND end_date >= CURDATE() AND visits_used < visits_total
             AND status NOT IN ('frozen','cancelled')) AS inv_cnt
    ");
    $u->execute([$clubId, $clubId, $clubId]);
    $usage = $u->fetch();

    $errors = [];
    if ($plan['clients_limit']  && (int)$usage['clients_cnt'] > (int)$plan['clients_limit'])
        $errors[] = "Клієнти: {$usage['clients_cnt']} (ліміт {$plan['clients_limit']})";
    if ($plan['users_limit']    && (int)$usage['users_cnt']   > (int)$plan['users_limit'])
        $errors[] = "Команда: {$usage['users_cnt']} (ліміт {$plan['users_limit']})";
    if ($plan['invoices_limit'] && (int)$usage['inv_cnt']     > (int)$plan['invoices_limit'])
        $errors[] = "Активні абонементи: {$usage['inv_cnt']} (ліміт {$plan['invoices_limit']})";

    return $errors ? implode('; ', $errors) : null;
}
