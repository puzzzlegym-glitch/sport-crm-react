<?php
/**
 * clubs_api.php — Управління клубами (SuperAdmin)
 *
 * Дії:
 *   get_list        — всі клуби з підписками, пошук, пагінація
 *   get_one         — деталі одного клубу
 *   update          — редагувати клуб (назва, місто, контакти)
 *   toggle_active   — заблокувати / розблокувати клуб
 *   extend_trial    — продовжити тріал на N днів (або надати новий, якщо тріалу немає/він неактивний)
 *   cancel_trial    — достроково скасувати активний тріал
 *   record_payment  — записати ручний платіж і активувати підписку
 *   get_payments    — історія платежів клубу
 *   get_stats       — загальна статистика платформи для дашборду
 *   request_delete  — крок 1 видалення власника+клубу: перевірка захисту, код у Telegram
 *   confirm_delete  — крок 2: перевірка коду, каскадне видалення клубу і власника
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$sess = Auth::requireAuth();

// Всі дії тільки для SuperAdmin
if (($sess['global_level'] ?? 0) < 100) {
    Response::forbidden('Розділ доступний тільки для SuperAdmin');
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';
$pdo    = Database::get();

try { switch ($action) {

    // ════ УВІЙТИ В КЛУБ ЯК OWNER (SuperAdmin) ════════════════
    // SuperAdmin отримує active_club_id і бачить інтерфейс owner
    case 'enter_club':
        $clubId = (int)($input['club_id'] ?? 0);
        if (!$clubId) Response::error('Вкажіть club_id');

        // SuperAdmin може переглядати будь-який клуб (навіть неактивний)
        $isSuperAdmin = ($sess['global_level'] ?? 0) >= 100;
        $checkSql = $isSuperAdmin
            ? "SELECT id, name FROM sys_clubs WHERE id=?"
            : "SELECT id, name FROM sys_clubs WHERE id=? AND is_active=1";

        $check = $pdo->prepare($checkSql);
        $check->execute([$clubId]);
        $club = $check->fetch();
        if (!$club) Response::error('Клуб не знайдено або заблокований');

        // Записуємо active_club_id в сесію
        $token = hash('sha256', $_COOKIE[SESSION_COOKIE] ?? '');
        $pdo->prepare("
            UPDATE sys_sessions SET active_club_id = ? WHERE session_token = ?
        ")->execute([$clubId, $token]);

        Response::ok([
            'club_id'   => $clubId,
            'club_name' => $club['name'],
            'redirect'  => '/dashboard',
        ], "Ви увійшли в клуб «{$club['name']}»");


    // ════ СПИСОК КЛУБІВ ═══════════════════════════════════════
    case 'get_list':
        $search  = trim($input['search']  ?? $_GET['search']  ?? '');
        $status  = trim($input['status']  ?? $_GET['status']  ?? '');
        $page    = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;

        $where  = ['1=1'];
        $params = [];

        if ($search) {
            $where[]  = '(c.name LIKE ? OR u.email LIKE ? OR u.full_name LIKE ?)';
            $like     = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($status) {
            $where[]  = 'c.subscription_status = ?';
            $params[] = $status;
        }

        $whereSQL = implode(' AND ', $where);

        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM sys_clubs c
            JOIN sys_users u ON u.id = c.owner_id
            WHERE {$whereSQL}
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT
                c.id,
                c.name,
                c.slug,
                c.city,
                c.is_active,
                c.subscription_status,
                c.trial_ends_at,
                c.created_at,
                u.id            AS owner_id,
                u.full_name     AS owner_name,
                u.email         AS owner_email,
                u.phone         AS owner_phone,
                u.last_login_at AS owner_last_login,
                s.status        AS sub_status,
                s.current_period_end,
                s.trial_ends_at AS sub_trial_ends,
                p.slug          AS plan_slug,
                p.name          AS plan_name,
                p.price_monthly,
                GREATEST(0, DATEDIFF(s.trial_ends_at, NOW())) AS trial_days_left,
                (SELECT COUNT(*) FROM clients cl
                 WHERE cl.club_id = c.id AND cl.status != 'banned') AS clients_count,
                (SELECT COUNT(*) FROM sys_user_clubs uc
                 WHERE uc.club_id = c.id AND uc.is_active = 1)       AS staff_count,
                (SELECT SUM(sp.amount)
                 FROM saas_payments sp
                 WHERE sp.club_id = c.id AND sp.status = 'success')  AS total_paid
            FROM sys_clubs c
            JOIN sys_users u ON u.id = c.owner_id
            LEFT JOIN saas_subscriptions s ON s.club_id = c.id
            LEFT JOIN saas_plans p ON p.id = s.plan_id
            WHERE {$whereSQL}
            ORDER BY c.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$perPage, $offset]));

        Response::ok([
            'clubs'      => $stmt->fetchAll(),
            'pagination' => [
                'total'   => $total,
                'page'    => $page,
                'pages'   => max(1, (int)ceil($total / $perPage)),
                'per_page'=> $perPage,
            ],
        ]);


    // ════ ОДИН КЛУБ ═══════════════════════════════════════════
    case 'get_one':
        $clubId = (int)($input['club_id'] ?? $_GET['club_id'] ?? 0);
        if (!$clubId) Response::error('Не вказано club_id');

        $stmt = $pdo->prepare("
            SELECT
                c.*,
                u.full_name  AS owner_name,
                u.email      AS owner_email,
                u.phone      AS owner_phone,
                u.last_login_at AS owner_last_login,
                s.status     AS sub_status,
                s.trial_ends_at AS sub_trial_ends,
                s.current_period_start,
                s.current_period_end,
                s.auto_renew,
                s.admin_notes,
                p.id         AS plan_id,
                p.slug       AS plan_slug,
                p.name       AS plan_name,
                p.price_monthly,
                (SELECT SUM(sp.amount) FROM saas_payments sp
                 WHERE sp.club_id = c.id AND sp.status = 'success') AS total_paid
            FROM sys_clubs c
            JOIN sys_users u ON u.id = c.owner_id
            LEFT JOIN saas_subscriptions s ON s.club_id = c.id
            LEFT JOIN saas_plans p ON p.id = s.plan_id
            WHERE c.id = ?
        ");
        $stmt->execute([$clubId]);
        $club = $stmt->fetch();
        if (!$club) Response::error('Клуб не знайдено', 404);

        Response::ok(['club' => $club]);


    // ════ РЕДАГУВАТИ КЛУБ ═════════════════════════════════════
    case 'update':
        $clubId = (int)($input['club_id'] ?? 0);
        if (!$clubId) Response::error('Не вказано club_id');

        $name = trim($input['name'] ?? '');
        if (strlen($name) < 2) Response::error('Введіть назву клубу');

        $pdo->prepare("
            UPDATE sys_clubs SET
                name    = ?,
                city    = ?,
                address = ?,
                phone   = ?,
                email   = ?
            WHERE id = ?
        ")->execute([
            htmlspecialchars($name, ENT_NOQUOTES, 'UTF-8'),
            trim($input['city']    ?? '') ?: null,
            trim($input['address'] ?? '') ?: null,
            trim($input['phone']   ?? '') ?: null,
            trim($input['email']   ?? '') ?: null,
            $clubId,
        ]);

        Response::ok([], 'Клуб оновлено');


    // ════ ЗАБЛОКУВАТИ / РОЗБЛОКУВАТИ ══════════════════════════
    case 'toggle_active':
        $clubId = (int)($input['club_id'] ?? 0);
        if (!$clubId) Response::error('Не вказано club_id');

        $stmt = $pdo->prepare("SELECT is_active, name FROM sys_clubs WHERE id = ?");
        $stmt->execute([$clubId]);
        $club = $stmt->fetch();
        if (!$club) Response::error('Клуб не знайдено', 404);

        $newStatus = $club['is_active'] ? 0 : 1;
        $pdo->prepare("UPDATE sys_clubs SET is_active = ? WHERE id = ?")
            ->execute([$newStatus, $clubId]);

        Response::ok(
            ['is_active' => $newStatus],
            $newStatus
                ? "Клуб «{$club['name']}» розблоковано"
                : "Клуб «{$club['name']}» заблоковано"
        );


    // ════ ПРОДОВЖИТИ / НАДАТИ ТРІАЛ ═══════════════════════════
    // Якщо тріал зараз активний і не минув — дні додаються до дати його закінчення.
    // Інакше (Free/active/trial_expired/cancelled) — тріал стартує заново від сьогодні.
    case 'extend_trial':
        $clubId = (int)($input['club_id'] ?? 0);
        $days   = max(1, min(90, (int)($input['days'] ?? 14)));
        if (!$clubId) Response::error('Не вказано club_id');

        // Знаходимо підписку
        $subStmt = $pdo->prepare("
            SELECT id, status, trial_ends_at FROM saas_subscriptions WHERE club_id = ?
        ");
        $subStmt->execute([$clubId]);
        $sub = $subStmt->fetch();
        if (!$sub) Response::error('Підписку не знайдено');

        // Рахуємо від поточної дати або від кінця тріалу (якщо той ще активний)
        $baseTime = ($sub['status'] === 'trial' && $sub['trial_ends_at'] && strtotime($sub['trial_ends_at']) > time())
            ? strtotime($sub['trial_ends_at'])
            : time();
        $newEnd = date('Y-m-d H:i:s', $baseTime + $days * 86400);

        // Опціональна зміна плану (напр. "надати Pro на тріал" замість поточного)
        $planId = (int)($input['plan_id'] ?? 0);
        if ($planId) {
            $planCheck = $pdo->prepare("SELECT id FROM saas_plans WHERE id = ? AND is_active = 1");
            $planCheck->execute([$planId]);
            if (!$planCheck->fetchColumn()) Response::error('Оберіть коректний план');
        }

        $pdo->prepare("
            UPDATE saas_subscriptions
            SET trial_ends_at = ?, status = 'trial', updated_at = NOW()" . ($planId ? ", plan_id = ?" : "") . "
            WHERE id = ?
        ")->execute($planId ? [$newEnd, $planId, $sub['id']] : [$newEnd, $sub['id']]);

        $pdo->prepare("
            UPDATE sys_clubs
            SET subscription_status = 'trial', trial_ends_at = ?
            WHERE id = ?
        ")->execute([$newEnd, $clubId]);

        // Опціональна нотатка
        $note = trim($input['note'] ?? '');
        $logLine = "\n[" . date('d.m.Y') . "] Тріал " . ($sub['status'] === 'trial' ? 'продовжено' : 'надано') . " на {$days} дн. до " . date('d.m.Y', strtotime($newEnd)) . " SuperAdmin'ом" . ($note ? ": {$note}" : '.');
        $pdo->prepare("
            UPDATE saas_subscriptions SET admin_notes = CONCAT(IFNULL(admin_notes,''), ?)
            WHERE id = ?
        ")->execute([$logLine, $sub['id']]);

        Response::ok(
            ['new_trial_end' => $newEnd],
            "Тріал діє до " . date('d.m.Y', strtotime($newEnd))
        );


    // ════ СКАСУВАТИ ТРІАЛ ═════════════════════════════════════
    // Дострокове завершення активного тріалу адміністратором (напр. відкликати помилково надані дні).
    case 'cancel_trial':
        $clubId = (int)($input['club_id'] ?? 0);
        if (!$clubId) Response::error('Не вказано club_id');

        $subStmt = $pdo->prepare("SELECT id, status FROM saas_subscriptions WHERE club_id = ?");
        $subStmt->execute([$clubId]);
        $sub = $subStmt->fetch();
        if (!$sub) Response::error('Підписку не знайдено');
        if ($sub['status'] !== 'trial') Response::error('Тріал зараз не активний');

        $pdo->prepare("
            UPDATE saas_subscriptions
            SET status = 'cancelled', trial_ends_at = NOW(), updated_at = NOW(),
                admin_notes = CONCAT(IFNULL(admin_notes,''), ?)
            WHERE id = ?
        ")->execute(["\n[" . date('d.m.Y') . "] Тріал скасовано SuperAdmin'ом.", $sub['id']]);

        $pdo->prepare("UPDATE sys_clubs SET subscription_status = 'cancelled' WHERE id = ?")
            ->execute([$clubId]);

        Response::ok([], 'Тріал скасовано');


    // ════ РУЧНИЙ ПЛАТІЖ ═══════════════════════════════════════
    case 'record_payment':
        $clubId  = (int)($input['club_id']  ?? 0);
        $planId  = (int)($input['plan_id']  ?? 0);
        $months  = max(1, min(12, (int)($input['months'] ?? 1)));
        $note    = trim($input['note'] ?? 'Ручна оплата');
        $amount  = (float)($input['amount'] ?? 0);

        if (!$clubId || !$planId) Response::error('Вкажіть club_id і plan_id');

        // Якщо сума не передана — рахуємо з плану
        if ($amount <= 0) {
            $planStmt = $pdo->prepare("SELECT price_monthly FROM saas_plans WHERE id = ?");
            $planStmt->execute([$planId]);
            $plan = $planStmt->fetch();
            if (!$plan) Response::error('План не знайдено');
            $amount = $plan['price_monthly'] * $months;
        }

        $periodEnd = date('Y-m-d', strtotime("+{$months} months"));

        $pdo->beginTransaction();
        try {
            // Рахунок
            $pdo->prepare("
                INSERT INTO saas_invoices
                    (club_id, subscription_id, plan_id, amount,
                     period_start, period_end, status, paid_at,
                     due_date, payment_gateway, notes)
                SELECT ?, s.id, ?, ?, CURDATE(), ?, 'paid', NOW(), CURDATE(), 'manual', ?
                FROM saas_subscriptions s WHERE s.club_id = ?
            ")->execute([$clubId, $planId, $amount, $periodEnd, $note, $clubId]);
            $invoiceId = (int)$pdo->lastInsertId();

            // Транзакція
            $pdo->prepare("
                INSERT INTO saas_payments
                    (invoice_id, club_id, amount, gateway,
                     status, recorded_by, recorded_note)
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

        // Email власнику
        try {
            $ownerStmt = $pdo->prepare("
                SELECT u.email, u.full_name, c.name AS club_name, p.name AS plan_name
                FROM sys_clubs c
                JOIN sys_users u ON u.id = c.owner_id
                JOIN saas_plans p ON p.id = ?
                WHERE c.id = ?
            ");
            $ownerStmt->execute([$planId, $clubId]);
            $o = $ownerStmt->fetch();
            if ($o) {
                Mailer::sendTemplate('payment_success', [
                    'owner_name'   => $o['full_name'],
                    'plan_name'    => $o['plan_name'],
                    'invoice_id'   => $invoiceId,
                    'amount'       => number_format($amount, 2) . ' грн',
                    'period'       => date('d.m.Y') . ' — ' . date('d.m.Y', strtotime($periodEnd)),
                    'next_billing' => date('d.m.Y', strtotime("+{$months} months")),
                ], $o['email']);
            }
        } catch (Throwable $e) {
            error_log('[Clubs] Payment email error: ' . $e->getMessage());
        }

        Response::ok(
            ['invoice_id' => $invoiceId, 'amount' => $amount],
            "Платіж записано. Підписку активовано на {$months} міс."
        );


    // ════ ІСТОРІЯ ПЛАТЕЖІВ КЛУБУ ══════════════════════════════
    case 'get_payments':
        $clubId = (int)($input['club_id'] ?? $_GET['club_id'] ?? 0);
        if (!$clubId) Response::error('Не вказано club_id');

        $stmt = $pdo->prepare("
            SELECT
                i.id         AS invoice_id,
                i.amount,
                i.status     AS invoice_status,
                i.period_start,
                i.period_end,
                i.paid_at,
                i.payment_gateway,
                i.notes,
                p.name       AS plan_name,
                py.status    AS payment_status,
                py.gateway,
                py.created_at,
                u.full_name  AS recorded_by_name
            FROM saas_invoices i
            JOIN saas_plans p ON p.id = i.plan_id
            LEFT JOIN saas_payments py ON py.invoice_id = i.id
            LEFT JOIN sys_users u ON u.id = py.recorded_by
            WHERE i.club_id = ?
            ORDER BY i.created_at DESC
            LIMIT 24
        ");
        $stmt->execute([$clubId]);

        Response::ok(['payments' => $stmt->fetchAll()]);


    // ════ ЗАГАЛЬНА СТАТИСТИКА ПЛАТФОРМИ ═══════════════════════
    case 'get_stats':
        $row = $pdo->query("
            SELECT
                COUNT(*)                                              AS total_clubs,
                SUM(c.is_active = 1)                                 AS active_clubs,
                SUM(c.subscription_status = 'trial')                 AS trial_clubs,
                SUM(c.subscription_status = 'active')                AS paid_clubs,
                SUM(c.subscription_status = 'trial_expired')         AS expired_clubs,
                SUM(DATEDIFF(s.trial_ends_at, NOW()) BETWEEN 0 AND 7
                    AND c.subscription_status = 'trial')             AS expiring_soon
            FROM sys_clubs c
            LEFT JOIN saas_subscriptions s ON s.club_id = c.id
        ")->fetch();

        $revenue = $pdo->query("
            SELECT
                SUM(CASE WHEN MONTH(created_at) = MONTH(NOW())
                         AND YEAR(created_at) = YEAR(NOW())
                    THEN amount ELSE 0 END) AS revenue_month,
                SUM(amount)                 AS revenue_total
            FROM saas_payments
            WHERE status = 'success'
        ")->fetch();

        Response::ok([
            'stats' => array_merge(
                array_map('intval', $row),
                [
                    'revenue_month' => (float)($revenue['revenue_month'] ?? 0),
                    'revenue_total' => (float)($revenue['revenue_total'] ?? 0),
                ]
            ),
        ]);


    // ════ ВИДАЛЕННЯ ВЛАСНИКА+КЛУБУ — КРОК 1: код у Telegram ═══
    case 'request_delete':
        $clubId = (int)($input['club_id'] ?? 0);
        $reason = trim($input['reason'] ?? '');
        if (!$clubId) Response::error('Не вказано club_id');
        if (mb_strlen($reason) < 5) Response::error('Опишіть причину видалення (мінімум 5 символів)');

        $stmt = $pdo->prepare("
            SELECT c.name, u.id AS owner_id, u.full_name AS owner_name, u.email AS owner_email
            FROM sys_clubs c JOIN sys_users u ON u.id = c.owner_id
            WHERE c.id = ?
        ");
        $stmt->execute([$clubId]);
        $club = $stmt->fetch();
        if (!$club) Response::error('Клуб не знайдено', 404);

        $paidStmt = $pdo->prepare("SELECT COUNT(*) FROM saas_payments WHERE club_id=? AND status='success'");
        $paidStmt->execute([$clubId]);
        if ((int)$paidStmt->fetchColumn() > 0) {
            Response::error('Неможливо видалити: власник має успішні оплати білінгу. Видалення заборонено.', 403);
        }

        $adminId = (int)$sess['user_id'];
        $adminStmt = $pdo->prepare("SELECT telegram_id, full_name FROM sys_users WHERE id=?");
        $adminStmt->execute([$adminId]);
        $admin = $adminStmt->fetch();
        if (!$admin || !$admin['telegram_id']) {
            Response::error('Спочатку прив\'яжіть свій Telegram у Налаштуваннях — код підтвердження надсилається туди.', 400);
        }

        // Інвалідуємо попередні невикористані запити на цей клуб від цього адміна
        $pdo->prepare("DELETE FROM club_deletion_requests WHERE club_id=? AND requested_by=? AND used_at IS NULL")
            ->execute([$clubId, $adminId]);

        $code     = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = hash('sha256', $code);

        $pdo->prepare("
            INSERT INTO club_deletion_requests (club_id, requested_by, reason, code_hash, expires_at, created_at)
            VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())
        ")->execute([$clubId, $adminId, $reason, $codeHash]);

        Telegram::sendMessage($admin['telegram_id'],
            "⚠️ <b>Підтвердження видалення клубу</b>\n\n" .
            "Клуб: <b>{$club['name']}</b>\n" .
            "Власник: {$club['owner_name']} ({$club['owner_email']})\n" .
            "Причина: {$reason}\n\n" .
            "Код підтвердження: <b>{$code}</b>\n" .
            "Дійсний 10 хвилин. Якщо це не ви — проігноруйте це повідомлення."
        );

        Response::ok(['expires_in' => 600], 'Код надіслано у ваш Telegram');


    // ════ ВИДАЛЕННЯ ВЛАСНИКА+КЛУБУ — КРОК 2: підтвердження ════
    case 'confirm_delete':
        $clubId = (int)($input['club_id'] ?? 0);
        $code   = trim($input['code'] ?? '');
        if (!$clubId) Response::error('Не вказано club_id');
        if (!$code) Response::error('Введіть код підтвердження');

        $adminId = (int)$sess['user_id'];

        $reqStmt = $pdo->prepare("
            SELECT * FROM club_deletion_requests
            WHERE club_id=? AND requested_by=? AND used_at IS NULL
            ORDER BY id DESC LIMIT 1
        ");
        $reqStmt->execute([$clubId, $adminId]);
        $delReq = $reqStmt->fetch();
        if (!$delReq) Response::error('Запит на видалення не знайдено. Натисніть "Видалити" ще раз.', 404);

        if (strtotime($delReq['expires_at']) < time()) {
            $pdo->prepare("DELETE FROM club_deletion_requests WHERE id=?")->execute([$delReq['id']]);
            Response::error('Код прострочено. Запросіть новий.', 400);
        }
        if ((int)$delReq['attempts'] >= 5) {
            $pdo->prepare("DELETE FROM club_deletion_requests WHERE id=?")->execute([$delReq['id']]);
            Response::error('Забагато невірних спроб. Запросіть новий код.', 400);
        }

        if (!hash_equals($delReq['code_hash'], hash('sha256', $code))) {
            $pdo->prepare("UPDATE club_deletion_requests SET attempts = attempts + 1 WHERE id=?")->execute([$delReq['id']]);
            Response::error('Невірний код. Спроб залишилось: ' . (4 - (int)$delReq['attempts']), 400);
        }

        // Повторна перевірка захисту від реальних оплат (захист від "гонки")
        $paidStmt = $pdo->prepare("SELECT COUNT(*) FROM saas_payments WHERE club_id=? AND status='success'");
        $paidStmt->execute([$clubId]);
        if ((int)$paidStmt->fetchColumn() > 0) {
            Response::error('Неможливо видалити: власник має успішні оплати білінгу. Видалення заборонено.', 403);
        }

        $clubStmt = $pdo->prepare("
            SELECT c.name AS club_name, u.id AS owner_id, u.full_name AS owner_name, u.email AS owner_email
            FROM sys_clubs c JOIN sys_users u ON u.id = c.owner_id
            WHERE c.id = ?
        ");
        $clubStmt->execute([$clubId]);
        $club = $clubStmt->fetch();
        if (!$club) Response::error('Клуб не знайдено', 404);

        $totalPaidStmt = $pdo->prepare("SELECT IFNULL(SUM(amount),0) FROM saas_payments WHERE club_id=?");
        $totalPaidStmt->execute([$clubId]);
        $totalPaid = (float)$totalPaidStmt->fetchColumn();

        $adminStmt = $pdo->prepare("SELECT full_name FROM sys_users WHERE id=?");
        $adminStmt->execute([$adminId]);
        $adminName = $adminStmt->fetchColumn() ?: '—';

        $ownerDeleted = false;

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE club_deletion_requests SET used_at = NOW() WHERE id=?")->execute([$delReq['id']]);

            // Спецвидалення trainer_earnings — не має власної колонки club_id,
            // прив'язана через club_trainers.id
            $pdo->prepare("
                DELETE te FROM trainer_earnings te
                JOIN club_trainers ct ON ct.id = te.trainer_id
                WHERE ct.club_id = ?
            ")->execute([$clubId]);

            // Динамічний якір: усі таблиці з колонкою club_id в поточній схемі —
            // підхоплює автоматично будь-яку нову club-таблицю без правок цього коду.
            $tablesStmt = $pdo->query("
                SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'club_id'
            ");
            $clubTables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($clubTables as $table) {
                // TABLE_NAME приходить з INFORMATION_SCHEMA, не від користувача — безпечно для backticks
                $pdo->prepare("DELETE FROM `{$table}` WHERE club_id = ?")->execute([$clubId]);
            }

            // Сам клуб — каскадом забере support_tickets(+messages), client_telegram_links,
            // club_role_permissions (FK ON DELETE CASCADE вже задекларовано в попередніх міграціях)
            $pdo->prepare("DELETE FROM sys_clubs WHERE id = ?")->execute([$clubId]);

            // Власника видаляємо, лише якщо він більше ніде не власник і не персонал іншого клубу
            $otherClubsStmt = $pdo->prepare("SELECT COUNT(*) FROM sys_clubs WHERE owner_id = ?");
            $otherClubsStmt->execute([$club['owner_id']]);
            $otherMembershipStmt = $pdo->prepare("SELECT COUNT(*) FROM sys_user_clubs WHERE user_id = ?");
            $otherMembershipStmt->execute([$club['owner_id']]);

            if ((int)$otherClubsStmt->fetchColumn() === 0 && (int)$otherMembershipStmt->fetchColumn() === 0) {
                $pdo->prepare("DELETE FROM sys_users WHERE id = ?")->execute([$club['owner_id']]);
                $ownerDeleted = true;
            }

            $pdo->prepare("
                INSERT INTO deleted_clubs_audit
                    (club_id, club_name, owner_name, owner_email, reason, deleted_by, deleted_by_name, total_paid_before_delete, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $clubId, $club['club_name'], $club['owner_name'], $club['owner_email'],
                $delReq['reason'], $adminId, $adminName, $totalPaid,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::serverError('Помилка видалення: ' . $e->getMessage());
        }

        Response::ok(
            ['owner_deleted' => $ownerDeleted],
            "Клуб «{$club['club_name']}» та всі його дані видалено" . ($ownerDeleted ? ' разом з обліковим записом власника' : '')
        );


    default:
        Response::error("Невідома дія: {$action}", 400);

}} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}