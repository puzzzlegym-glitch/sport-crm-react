<?php
/**
 * visits_api.php — API журналу відвідувань
 *
 * Дії:
 *   scan          — сканування штрих-коду або пошук по телефону → відмічає відвідування
 *   check_in      — ручна відмітка для конкретного клієнта
 *   get_list      — журнал відвідувань з фільтрами
 *   get_today     — відвідування сьогодні (для екрану адміністратора)
 *   get_client    — відвідування конкретного клієнта
 *   get_stats     — статистика відвідувань
 *   delete        — видалити відмітку (owner)
 *   get_barcode   — отримати штрих-код клієнта
 *   set_barcode   — встановити штрих-код клієнту
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

// Знімаємо заморозку з абонементів, у яких запланований термін вже минув
// (інакше клієнт лишається заблокованим для відвідувань назавжди).
Recalc::autoUnfreezeExpired($pdo, $clubId);

$access  = Auth::requireClubAccess($sess, $clubId, 30);
$isOwner = Auth::isOwner($sess, $access);

try { switch ($action) {

    // ════ СКАНУВАННЯ ══════════════════════════════════════════
    // Головна дія — отримує barcode або телефон, знаходить клієнта,
    // перевіряє активний абонемент, фіксує відвідування
    case 'scan':
        if (!Auth::can($sess, $clubId, 'visits.checkin')) Response::forbidden();

        $query = trim($input['query'] ?? ''); // штрих-код або номер телефону
        if (!$query) Response::error('Введіть штрих-код або телефон');

        // Шукаємо клієнта: спочатку по штрих-коду, потім по телефону
        $client = null;

        // По штрих-коду (точний збіг)
        $stmt = $pdo->prepare("
            SELECT id, full_name, phone, status, balance, barcode, photo_url
            FROM clients
            WHERE club_id = ? AND barcode = ?
            LIMIT 1
        ");
        $stmt->execute([$clubId, $query]);
        $client = $stmt->fetch();

        // По нормалізованому телефону (якщо штрих-код не знайдено)
        if (!$client) {
            $normalized = preg_replace('/\D/', '', $query);
            if (strlen($normalized) >= 9) {
                $stmt = $pdo->prepare("
                    SELECT id, full_name, phone, status, balance, barcode, photo_url
                    FROM clients
                    WHERE club_id = ? AND phone_normalized LIKE ?
                    LIMIT 1
                ");
                $stmt->execute([$clubId, '%' . substr($normalized, -9)]);
                $client = $stmt->fetch();
            }
        }

        if (!$client) {
            Response::error("Клієнта не знайдено. Перевірте штрих-код або номер.", 404);
        }

        // Перевіряємо статус клієнта
        if ($client['status'] === 'blocked') {
            Response::error("Клієнт заблокований.", 403);
        }

        // Знаходимо активний абонемент
        $invoice = null;
        $invStmt = $pdo->prepare("
            SELECT ci.id, ci.tariff_name, ci.end_date,
                   ci.visits_total, ci.visits_used,
                   ci.trainer_id, ci.trainer_name,
                   DATEDIFF(ci.end_date, CURDATE()) AS days_left
            FROM client_invoices ci
            WHERE ci.client_id = ?
              AND ci.club_id   = ?
              AND ci.status    = 'active'
              AND ci.start_date <= CURDATE()
              AND ci.end_date >= CURDATE()
              AND " . Attendance::PAID_ENOUGH_SQL . "
            ORDER BY ci.end_date ASC
            LIMIT 1
        ");
        $invStmt->execute([$client['id'], $clubId]);
        $invoice = $invStmt->fetch();

        // Попередження або блокування залежно від стану абонементу
        $warning = null;
        $force   = !empty($input['force']) && $isOwner;

        if (!$invoice) {
            $info = self_noInvoiceMessage($pdo, $clubId, $client['id']);
            if (!$force) Response::error($info['message'], 403, ['reason' => $info['reason'], 'invoice_id' => $info['invoice_id']]);
            $warning = $info['message'] . ' (обхід власником)';
        } elseif ($invoice['visits_total'] && $invoice['visits_used'] >= $invoice['visits_total']) {
            if (!$force) Response::error('Вичерпано всі відвідування абонементу.', 403);
            $warning = 'Ліміт відвідувань вичерпано (обхід власником)';
        } elseif ((int)$invoice['days_left'] <= 3) {
            $warning = "Абонемент закінчується через {$invoice['days_left']} дн.";
        }

        // Захист від подвійного сканування (5 хвилин)
        $recentStmt = $pdo->prepare("
            SELECT id FROM visits
            WHERE client_id = ? AND club_id = ?
              AND visited_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            LIMIT 1
        ");
        $recentStmt->execute([$client['id'], $clubId]);
        if ($recentStmt->fetchColumn()) {
            Response::ok([
                'already_checked_in' => true,
                'client'  => $client,
                'invoice' => $invoice,
                'warning' => 'Вже відмічено менше 5 хвилин тому',
            ], 'Вже відмічено');
        }

        // Фіксуємо відвідування
        $visitId = Attendance::recordVisit(
            $pdo, $clubId, $client['id'],
            $invoice['id']           ?? null,
            $invoice['trainer_id']   ?? null,
            $invoice['trainer_name'] ?? null,
            'barcode',
            $sess
        );

        Attendance::createTrainerEarning($pdo, $clubId, $visitId, $invoice['id'] ?? null, $invoice['trainer_id'] ?? null);

        Response::ok([
            'visit_id' => $visitId,
            'client'   => $client,
            'invoice'  => $invoice,
            'warning'  => $warning,
        ], 'Відвідування відмічено ✓');


    // ════ РУЧНА ВІДМІТКА ══════════════════════════════════════
    case 'check_in':
        if (!Auth::can($sess, $clubId, 'visits.checkin')) Response::forbidden();

        $clientId  = (int)($input['client_id'] ?? 0);
        $invoiceId = (int)($input['invoice_id'] ?? 0) ?: null;
        if (!$clientId) Response::error('Вкажіть client_id');

        // Перевіряємо клієнта
        $cStmt = $pdo->prepare("
            SELECT id, full_name, status FROM clients
            WHERE id=? AND club_id=? LIMIT 1
        ");
        $cStmt->execute([$clientId, $clubId]);
        $client = $cStmt->fetch();
        if (!$client) Response::error('Клієнта не знайдено', 404);
        if ($client['status'] === 'blocked') Response::error('Клієнт заблокований', 403);

        // Якщо invoice не передано — беремо активний
        if (!$invoiceId) {
            $invStmt = $pdo->prepare("
                SELECT ci.id, ci.trainer_id, ci.trainer_name, ci.visits_total, ci.visits_used FROM client_invoices ci
                WHERE ci.client_id=? AND ci.club_id=? AND ci.status='active'
                  AND ci.start_date<=CURDATE() AND ci.end_date>=CURDATE()
                  AND " . Attendance::PAID_ENOUGH_SQL . "
                ORDER BY end_date ASC LIMIT 1
            ");
            $invStmt->execute([$clientId, $clubId]);
            $inv = $invStmt->fetch();
            $invoiceId   = $inv['id']           ?? null;
            $trainerId   = $inv['trainer_id']   ?? null;
            $trainerName = $inv['trainer_name'] ?? null;
        } else {
            $invStmt = $pdo->prepare("
                SELECT status, start_date, end_date, trainer_id, trainer_name, visits_total, visits_used,
                       paid_amount, min_paid_to_activate
                FROM client_invoices WHERE id=? AND club_id=?
            ");
            $invStmt->execute([$invoiceId, $clubId]);
            $inv = $invStmt->fetch();
            $notPaidEnough = $inv && $inv['min_paid_to_activate'] !== null
                && (float)$inv['paid_amount'] < (float)$inv['min_paid_to_activate'];
            if ($inv && ($inv['status'] !== 'active' || $inv['start_date'] > date('Y-m-d') || $inv['end_date'] < date('Y-m-d') || $notPaidEnough)) {
                $invoiceId = null; // абонемент заморожений/ще не розпочався/завершений/скасований/не оплачений (група) — не використовуємо
            }
            $trainerId   = $inv['trainer_id']   ?? null;
            $trainerName = $inv['trainer_name'] ?? null;
        }

        // Тренер на конкретне відвідування можна змінити відносно
        // "рекомендованого" тренера абонемента (client_invoices.trainer_id) —
        // напр. клієнта тренував інший тренер цього разу.
        $overrideTrainerId = (int)($input['trainer_id'] ?? 0) ?: null;
        if ($overrideTrainerId) {
            $ovStmt = $pdo->prepare("
                SELECT u.full_name FROM club_trainers ct
                JOIN sys_users u ON u.id = ct.user_id
                WHERE ct.id=? AND ct.club_id=? AND ct.is_active=1 LIMIT 1
            ");
            $ovStmt->execute([$overrideTrainerId, $clubId]);
            $overrideName = $ovStmt->fetchColumn();
            if ($overrideName) {
                $trainerId   = $overrideTrainerId;
                $trainerName = $overrideName;
            }
        }

        $force = !empty($input['force']) && $isOwner;
        if (!$invoiceId) {
            $info = self_noInvoiceMessage($pdo, $clubId, $clientId);
            if (!$force) Response::error($info['message'], 403, ['reason' => $info['reason'], 'invoice_id' => $info['invoice_id']]);
        } elseif (!empty($inv['visits_total']) && $inv['visits_used'] >= $inv['visits_total']) {
            if (!$force) Response::error('Вичерпано всі відвідування абонементу.', 403);
        }

        $visitDate = trim($input['visit_date'] ?? '');
        $visitDate = ($visitDate && $visitDate <= date('Y-m-d')) ? $visitDate . ' 12:00:00' : null;
        $visitId = Attendance::recordVisit(
            $pdo, $clubId, $clientId,
            $invoiceId, $trainerId, $trainerName,
            'manual', $sess, null, $visitDate
        );

        Attendance::createTrainerEarning($pdo, $clubId, $visitId, $invoiceId, $trainerId);

        Response::ok(['visit_id' => $visitId], 'Відвідування відмічено');


    // ════ РЕКОМЕНДОВАНИЙ ТРЕНЕР КЛІЄНТА (для форми ручної відмітки) ═
    case 'get_active_invoice':
        $clientId = (int)($input['client_id'] ?? $_GET['client_id'] ?? 0);
        if (!$clientId) Response::error('Вкажіть client_id');

        $stmt = $pdo->prepare("
            SELECT ci.id, ci.tariff_name, ci.trainer_id, ci.trainer_name
            FROM client_invoices ci
            WHERE ci.client_id=? AND ci.club_id=? AND ci.status='active'
              AND ci.start_date<=CURDATE() AND ci.end_date>=CURDATE()
              AND " . Attendance::PAID_ENOUGH_SQL . "
            ORDER BY ci.end_date ASC LIMIT 1
        ");
        $stmt->execute([$clientId, $clubId]);
        Response::ok(['invoice' => $stmt->fetch() ?: null]);


    // ════ СПИСОК ТРЕНЕРІВ ДЛЯ ВИБОРУ ПРИ ВІДМІТЦІ ═══════════════
    // (не потребує trainers.manage — вибір "хто провів заняття" є
    // частиною самого чекіну, а не керування профілями тренерів)
    case 'get_checkin_trainers':
        if (!Auth::can($sess, $clubId, 'visits.checkin')) Response::forbidden();
        $stmt = $pdo->prepare("
            SELECT ct.id, u.full_name
            FROM club_trainers ct
            JOIN sys_users u ON u.id = ct.user_id
            WHERE ct.club_id=? AND ct.is_active=1
            ORDER BY u.full_name
        ");
        $stmt->execute([$clubId]);
        Response::ok(['trainers' => $stmt->fetchAll()]);


    // ════ ЖУРНАЛ ВІДВІДУВАНЬ ══════════════════════════════════
    case 'get_list':
        // invoice_id — перегляд відвідувань конкретного абонемента (картка абонемента):
        // без обов'язкового діапазону дат, показуємо всю історію по цьому invoice_id.
        $invoiceId = (int)($input['invoice_id'] ?? 0) ?: null;
        $dateFrom = trim($input['date_from'] ?? ($invoiceId ? '' : date('Y-m-d')));
        $dateTo   = trim($input['date_to']   ?? ($invoiceId ? '' : date('Y-m-d')));
        $search   = trim($input['search']    ?? '');
        $page     = max(1, (int)($input['page'] ?? 1));
        $perPage  = 30;
        $offset   = ($page - 1) * $perPage;

        $where  = ['v.club_id = ?'];
        $params = [$clubId];

        if ($invoiceId) {
            $where[]  = 'v.invoice_id = ?';
            $params[] = $invoiceId;
        }
        if ($dateFrom !== '' && $dateTo !== '') {
            $where[]  = 'DATE(v.visited_at) BETWEEN ? AND ?';
            $params[] = $dateFrom;
            $params[] = $dateTo;
        }

        if ($search) {
            $where[]  = '(c.full_name LIKE ? OR c.phone LIKE ?)';
            $like     = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $whereSQL = implode(' AND ', $where);

        $countStmt = $pdo->prepare("
            SELECT COUNT(*) FROM visits v
            JOIN clients c ON c.id = v.client_id
            WHERE {$whereSQL}
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT
                v.id, v.visited_at, v.method, v.notes,
                v.admin_name, v.trainer_name,
                c.id          AS client_id,
                c.full_name   AS client_name,
                c.phone       AS client_phone,
                c.photo_url   AS client_photo,
                ci.tariff_name
            FROM visits v
            JOIN clients c ON c.id = v.client_id
            LEFT JOIN client_invoices ci ON ci.id = v.invoice_id
            WHERE {$whereSQL}
            ORDER BY v.visited_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$perPage, $offset]));

        Response::ok([
            'visits'     => $stmt->fetchAll(),
            'total'      => $total,
            'pagination' => [
                'total'   => $total,
                'page'    => $page,
                'pages'   => max(1, (int)ceil($total / $perPage)),
                'per_page'=> $perPage,
            ],
        ]);


    // ════ ВІДВІДУВАННЯ СЬОГОДНІ ═══════════════════════════════
    case 'get_today':
        $stmt = $pdo->prepare("
            SELECT
                v.id, v.visited_at, v.method,
                v.admin_name, v.trainer_name,
                c.id        AS client_id,
                c.full_name AS client_name,
                c.phone     AS client_phone,
                c.photo_url AS client_photo,
                ci.tariff_name
            FROM visits v
            JOIN clients c ON c.id = v.client_id
            LEFT JOIN client_invoices ci ON ci.id = v.invoice_id
            WHERE v.club_id = ?
              AND DATE(v.visited_at) = CURDATE()
            ORDER BY v.visited_at DESC
            LIMIT 100
        ");
        $stmt->execute([$clubId]);
        $visits = $stmt->fetchAll();

        // Кількість унікальних клієнтів сьогодні
        $uniqStmt = $pdo->prepare("
            SELECT COUNT(DISTINCT client_id) FROM visits
            WHERE club_id=? AND DATE(visited_at)=CURDATE()
        ");
        $uniqStmt->execute([$clubId]);

        Response::ok([
            'visits'          => $visits,
            'count'           => count($visits),
            'unique_clients'  => (int)$uniqStmt->fetchColumn(),
        ]);


    // ════ ВІДВІДУВАННЯ КЛІЄНТА ════════════════════════════════
    case 'get_client':
        $clientId = (int)($input['client_id'] ?? $_GET['client_id'] ?? 0);
        if (!$clientId) Response::error('Вкажіть client_id');

        $stmt = $pdo->prepare("
            SELECT v.id, v.visited_at, v.method, v.trainer_name, v.notes,
                   ci.tariff_name
            FROM visits v
            LEFT JOIN client_invoices ci ON ci.id = v.invoice_id
            WHERE v.club_id = ? AND v.client_id = ?
            ORDER BY v.visited_at DESC
            LIMIT 50
        ");
        $stmt->execute([$clubId, $clientId]);

        $total = (int)$pdo->prepare("
            SELECT COUNT(*) FROM visits WHERE club_id=? AND client_id=?
        ")->execute([$clubId, $clientId]) ?
        $pdo->prepare("SELECT COUNT(*) FROM visits WHERE club_id=? AND client_id=?")
            ->execute([$clubId, $clientId]) : 0;

        Response::ok([
            'visits' => $stmt->fetchAll(),
            'total'  => $pdo->query(
                "SELECT COUNT(*) FROM visits WHERE club_id={$clubId} AND client_id={$clientId}"
            )->fetchColumn(),
        ]);


    // ════ СТАТИСТИКА ══════════════════════════════════════════
    case 'get_stats':
        // Сьогодні
        $today = $pdo->prepare("
            SELECT
                COUNT(*)                    AS total_today,
                COUNT(DISTINCT client_id)   AS unique_today
            FROM visits
            WHERE club_id=? AND DATE(visited_at)=CURDATE()
        ");
        $today->execute([$clubId]);
        $t = $today->fetch();

        // Тиждень
        $week = $pdo->prepare("
            SELECT COUNT(*) AS total_week
            FROM visits
            WHERE club_id=?
              AND visited_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $week->execute([$clubId]);

        // Місяць
        $month = $pdo->prepare("
            SELECT COUNT(*) AS total_month
            FROM visits
            WHERE club_id=?
              AND MONTH(visited_at)=MONTH(NOW())
              AND YEAR(visited_at)=YEAR(NOW())
        ");
        $month->execute([$clubId]);

        // По днях — останні 14 днів (для графіку)
        $chart = $pdo->prepare("
            SELECT
                DATE(visited_at)    AS day,
                COUNT(*)            AS cnt,
                COUNT(DISTINCT client_id) AS unique_cnt
            FROM visits
            WHERE club_id=?
              AND visited_at >= DATE_SUB(NOW(), INTERVAL 13 DAY)
            GROUP BY DATE(visited_at)
            ORDER BY day ASC
        ");
        $chart->execute([$clubId]);

        // Пікові години (сьогодні)
        $hours = $pdo->prepare("
            SELECT HOUR(visited_at) AS hr, COUNT(*) AS cnt
            FROM visits
            WHERE club_id=? AND DATE(visited_at)=CURDATE()
            GROUP BY HOUR(visited_at)
            ORDER BY hr
        ");
        $hours->execute([$clubId]);

        Response::ok([
            'stats' => [
                'total_today'  => (int)$t['total_today'],
                'unique_today' => (int)$t['unique_today'],
                'total_week'   => (int)$week->fetchColumn(),
                'total_month'  => (int)$month->fetchColumn(),
            ],
            'chart' => $chart->fetchAll(),
            'hours' => $hours->fetchAll(),
        ]);


    // ════ ВИДАЛИТИ ВІДМІТКУ ═══════════════════════════════════
    case 'delete':
        if (!Auth::can($sess, $clubId, 'visits.delete')) Response::forbidden('Видалення — лише власник');

        $id = (int)($input['id'] ?? 0);
        if (!$id) Response::error('Вкажіть id');

        $confirmReversal = !empty($input['confirm_reversal']);

        // Якщо за це відвідування тренеру вже щось реально виплачено —
        // видалення саме по собі не блокується назавжди, а вимагає явного
        // підтвердження менеджера/власника: це "сторно" помилково закріпленого
        // тренера чи виплати — типовий сценарій виправлення, коли адмін
        // призначив не того тренера і встиг йому заплатити. Після підтвердження
        // видаляємо й пов'язані захищені записи витрат (club_expenses,
        // source='trainer_payout') — кошти автоматично повертаються в касу/облік.
        $earnStmt = $pdo->prepare("
            SELECT te.id, te.paid_amount, u.full_name AS trainer_name
            FROM trainer_earnings te
            JOIN club_trainers ct ON ct.id = te.trainer_id
            JOIN sys_users u ON u.id = ct.user_id
            WHERE te.source='visit' AND te.source_id=? LIMIT 1
        ");
        $earnStmt->execute([$id]);
        $earning = $earnStmt->fetch();
        if ($earning && (float)$earning['paid_amount'] > 0 && !$confirmReversal) {
            Response::error(
                "Тренеру {$earning['trainer_name']} вже виплачено " . number_format((float)$earning['paid_amount'], 2) . " грн за це відвідування. Видалення поверне ці кошти в касу/облік і скасує нарахування — виплату правильному тренеру потрібно буде зробити заново. Підтвердіть видалення.",
                409,
                ['requires_confirmation' => true, 'paid_amount' => (float)$earning['paid_amount'], 'trainer_name' => $earning['trainer_name']]
            );
        }

        $pdo->beginTransaction();
        try {
            if ($earning) {
                // Сторно вже виплачених коштів: видаляємо захищені системні
                // витрати, породжені цим нарахуванням — гроші "повертаються".
                // Часткових виплат може бути кілька — прибираємо всі разом з рядками каси
                $expStmt = $pdo->prepare("SELECT id FROM club_expenses WHERE source='trainer_payout' AND source_id=?");
                $expStmt->execute([$earning['id']]);
                $expIdsToRemove = $expStmt->fetchAll(PDO::FETCH_COLUMN);

                $pdo->prepare("DELETE FROM club_expenses WHERE source='trainer_payout' AND source_id=?")
                    ->execute([$earning['id']]);
                foreach ($expIdsToRemove as $expId) Recalc::cashflowSyncExpense($pdo, (int)$expId);
                $pdo->prepare("DELETE FROM trainer_earnings WHERE id=?")->execute([$earning['id']]);
            }
            $invIdStmt = $pdo->prepare("SELECT invoice_id FROM visits WHERE id=? AND club_id=?");
            $invIdStmt->execute([$id, $clubId]);
            $visitInvoiceId = $invIdStmt->fetchColumn();

            $stmt = $pdo->prepare("DELETE FROM visits WHERE id=? AND club_id=?");
            $stmt->execute([$id, $clubId]);
            if (!$stmt->rowCount()) {
                $pdo->rollBack();
                Response::error('Відвідування не знайдено', 404);
            }
            if ($visitInvoiceId) Recalc::invoiceVisitsUsed($pdo, (int)$visitInvoiceId);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::serverError($e->getMessage());
        }

        Response::ok([], 'Відмітку видалено');


    // ════ ШТРИХ-КОД КЛІЄНТА ═══════════════════════════════════
    case 'set_barcode':
        $clientId = (int)($input['client_id'] ?? 0);
        $barcode  = trim($input['barcode']    ?? '');
        if (!$clientId) Response::error('Вкажіть client_id');
        if (!$barcode)  Response::error('Введіть штрих-код');

        // Унікальність в межах клубу
        $dupStmt = $pdo->prepare("
            SELECT id FROM clients
            WHERE club_id=? AND barcode=? AND id!=?
            LIMIT 1
        ");
        $dupStmt->execute([$clubId, $barcode, $clientId]);
        if ($dupStmt->fetchColumn()) {
            Response::error('Цей штрих-код вже призначено іншому клієнту', 409);
        }

        $pdo->prepare("UPDATE clients SET barcode=? WHERE id=? AND club_id=?")
            ->execute([$barcode, $clientId, $clubId]);

        Response::ok(['barcode' => $barcode], 'Штрих-код збережено');


    default:
        Response::error("Невідома дія: {$action}", 400);

}} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}

// ── Внутрішня функція: чому немає активного абонементу ───────
// Розрізняє "заморожений" / "ще не розпочався" / "немає взагалі",
// щоб адміністратор бачив точну причину блокування.
// Повертає ['message'=>..., 'reason'=>'frozen'|'future'|'none', 'invoice_id'=>?int] —
// reason='frozen' дозволяє фронтенду запропонувати дострокову розморозку просто з екрана відмітки.
function self_noInvoiceMessage(PDO $pdo, int $clubId, int $clientId): array {
    $frozenStmt = $pdo->prepare("
        SELECT id FROM client_invoices
        WHERE client_id = ? AND club_id = ? AND status = 'frozen'
        LIMIT 1
    ");
    $frozenStmt->execute([$clientId, $clubId]);
    $frozenId = $frozenStmt->fetchColumn();
    if ($frozenId) {
        return ['message' => 'Абонемент заморожений. Відвідування заборонено.', 'reason' => 'frozen', 'invoice_id' => (int)$frozenId];
    }

    // Абонемент учасника групи, який ще не вніс мінімальну оплату
    $pendingStmt = $pdo->prepare("
        SELECT id, min_paid_to_activate, paid_amount FROM client_invoices
        WHERE client_id = ? AND club_id = ? AND status = 'active'
          AND min_paid_to_activate IS NOT NULL AND paid_amount < min_paid_to_activate
          AND end_date >= CURDATE()
        ORDER BY start_date ASC LIMIT 1
    ");
    $pendingStmt->execute([$clientId, $clubId]);
    $pending = $pendingStmt->fetch();
    if ($pending) {
        $left = (float)$pending['min_paid_to_activate'] - (float)$pending['paid_amount'];
        return ['message' => 'Груповий абонемент не активовано: потрібно внести ще ' . number_format($left, 0, '.', ' ') . ' грн. Відвідування заборонено.', 'reason' => 'pending', 'invoice_id' => (int)$pending['id']];
    }

    $futureStmt = $pdo->prepare("
        SELECT start_date FROM client_invoices
        WHERE client_id = ? AND club_id = ? AND status = 'active' AND start_date > CURDATE()
        ORDER BY start_date ASC LIMIT 1
    ");
    $futureStmt->execute([$clientId, $clubId]);
    $futureStart = $futureStmt->fetchColumn();
    if ($futureStart) {
        return ['message' => 'Абонемент ще не розпочався (діє з ' . date('d.m.Y', strtotime($futureStart)) . '). Відвідування заборонено.', 'reason' => 'future', 'invoice_id' => null];
    }

    return ['message' => 'Немає активного абонементу. Відвідування заборонено.', 'reason' => 'none', 'invoice_id' => null];
}
