<?php
/**
 * clients_api.php — API модуля "Клієнти"
 *
 * Дії:
 *   get_list      — список з пошуком, фільтрами, пагінацією
 *   get_one       — один клієнт + його абонементи
 *   get_activity  — об'єднана стрічка подій (відвідування/оплати/продажі) для картки клієнта
 *   create        — додати клієнта
 *   update        — редагувати (включно зі зміною статусу: regular/premium/blocked)
 *   import        — масовий імпорт клієнтів з Excel (лише тарифи без ліміту клієнтів)
 *   get_stats     — статистика клуба (кількості для дашборду)
 *   search        — швидкий пошук для autocomplete
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$sess   = Auth::requireAuth();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';

// Клуб береться з сесії (активний), або з параметра
$clubId = (int)($input['club_id'] ?? $_GET['club_id'] ?? $sess['active_club_id'] ?? 0);
if (!$clubId) Response::error('Не обрано клуб. Оберіть клуб у шапці.', 400);

// Перевіряємо доступ до клубу (мінімум — тренер)
$access = Auth::requireClubAccess($sess, $clubId, 30);

$pdo = Database::get();

// Знімаємо заморозку з абонементів, у яких запланований термін вже минув
// (інакше борг/бейдж "заморожено" на картці клієнта зависають назавжди).
Recalc::autoUnfreezeExpired($pdo, $clubId);

// Реальний статус абонементу — рахується з дат/відвідувань, а не зі збереженої
// колонки status (та лишається 'active', поки її ніхто не змінить вручну).
// Дублює логіку з invoices_api.php::$EFFECTIVE_STATUS_SQL — рівно 5 статусів:
// future / active / frozen / finished / cancelled.
$EFFECTIVE_STATUS_SQL = "(CASE
    WHEN ci.status = 'cancelled' THEN 'cancelled'
    WHEN ci.status = 'frozen' THEN 'frozen'
    WHEN ci.start_date > CURDATE() THEN 'future'
    WHEN ci.end_date < CURDATE() THEN 'finished'
    WHEN ci.visits_total IS NOT NULL AND ci.visits_used >= ci.visits_total THEN 'finished'
    ELSE 'active'
END)";

try {
    switch ($action) {

        // ════ СПИСОК КЛІЄНТІВ ════════════════════════════════
        case 'get_list':
            $search   = trim($input['search']   ?? $_GET['search']   ?? '');
            $status   = trim($input['status']   ?? $_GET['status']   ?? '');
            $page     = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
            $perPage  = min(100, max(10, (int)($input['per_page'] ?? 25)));
            $offset   = ($page - 1) * $perPage;
            $orderBy  = in_array($input['order'] ?? '', ['full_name','created_at','status'])
                        ? $input['order'] : 'created_at';
            $orderDir = ($input['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

            $where  = ['c.club_id = ?'];
            $params = [$clubId];

            if ($search) {
                // Пошук по ПІБ / email завжди; по телефону — лише якщо в запиті є цифри
                // (інакше preg_replace дає '' і LIKE '%%' збігається з усіма клієнтами);
                // по штрих-коду/картці — точний збіг, як при скануванні у Відвідуваннях.
                $digits = preg_replace('/[^0-9]/', '', $search);
                $like   = '%' . $search . '%';

                $searchConditions = ['c.full_name LIKE ?', 'c.email LIKE ?'];
                $searchParams     = [$like, $like];

                if ($digits !== '') {
                    $searchConditions[] = 'c.phone_normalized LIKE ?';
                    $searchParams[]     = '%' . $digits . '%';
                }

                $searchConditions[] = 'c.barcode = ?';
                $searchParams[]     = $search;

                $where[] = '(' . implode(' OR ', $searchConditions) . ')';
                $params  = array_merge($params, $searchParams);
            }

            if ($status && in_array($status, ['regular','premium','blocked'])) {
                $where[]  = 'c.status = ?';
                $params[] = $status;
            }

            $whereSQL = implode(' AND ', $where);

            // Загальна кількість (для пагінації)
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM clients c WHERE {$whereSQL}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            // Основний запит
            $stmt = $pdo->prepare("
                SELECT
                    c.id, c.full_name, c.phone, c.email,
                    c.birthday, c.gender, c.status, c.status_reason, c.status_changed_at, c.balance,
                    c.created_at, c.photo_url, c.telegram_id,
                    -- Активний абонемент
                    (
                        SELECT ci.tariff_name
                        FROM client_invoices ci
                        WHERE ci.client_id = c.id
                          AND ci.status = 'active'
                          AND ci.start_date <= CURDATE()
                          AND ci.end_date  >= CURDATE()
                          AND (ci.visits_total IS NULL OR ci.visits_used < ci.visits_total)
                        ORDER BY ci.end_date DESC
                        LIMIT 1
                    ) AS active_tariff,
                    (
                        SELECT ci.end_date
                        FROM client_invoices ci
                        WHERE ci.client_id = c.id
                          AND ci.status = 'active'
                          AND ci.start_date <= CURDATE()
                          AND ci.end_date  >= CURDATE()
                          AND (ci.visits_total IS NULL OR ci.visits_used < ci.visits_total)
                        ORDER BY ci.end_date DESC
                        LIMIT 1
                    ) AS tariff_end_date,
                    -- Дата останнього відвідування
                    (
                        SELECT DATE(v.visited_at)
                        FROM visits v
                        WHERE v.client_id = c.id
                        ORDER BY v.visited_at DESC
                        LIMIT 1
                    ) AS last_visit
                FROM clients c
                WHERE {$whereSQL}
                ORDER BY c.{$orderBy} {$orderDir}
                LIMIT ? OFFSET ?
            ");
            $stmt->execute(array_merge($params, [$perPage, $offset]));
            $clients = $stmt->fetchAll();

            Response::ok([
                'clients'    => $clients,
                'pagination' => [
                    'total'    => $total,
                    'page'     => $page,
                    'per_page' => $perPage,
                    'pages'    => max(1, (int)ceil($total / $perPage)),
                ],
            ]);


        // ════ ОДИН КЛІЄНТ ════════════════════════════════════
        case 'get_one':
            $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
            if (!$id) Response::error('Не вказано id', 400);

            $stmt = $pdo->prepare("
                SELECT c.*,
                       u.full_name AS created_by_name
                FROM clients c
                LEFT JOIN sys_users u ON u.id = c.created_by
                WHERE c.id = ? AND c.club_id = ?
                LIMIT 1
            ");
            $stmt->execute([$id, $clubId]);
            $client = $stmt->fetch();
            if (!$client) Response::error('Клієнта не знайдено', 404);

            // Абонементи
            $invStmt = $pdo->prepare("
                SELECT ci.*,
                       {$EFFECTIVE_STATUS_SQL} AS status,
                       (ci.price - ci.paid_amount) AS debt
                FROM client_invoices ci
                WHERE ci.client_id = ? AND ci.club_id = ?
                ORDER BY ci.created_at DESC
                LIMIT 20
            ");
            $invStmt->execute([$id, $clubId]);

            // Останні відвідування
            $visStmt = $pdo->prepare("
                SELECT v.visited_at, v.notes
                FROM visits v
                WHERE v.client_id = ? AND v.club_id = ?
                ORDER BY v.visited_at DESC
                LIMIT 10
            ");
            $visStmt->execute([$id, $clubId]);

            // Загальний борг (price - paid_amount по активних/заморожених)
            $debtRow = $pdo->prepare("
                SELECT COALESCE(SUM(GREATEST(price - paid_amount, 0)), 0) AS total_debt
                FROM client_invoices
                WHERE client_id = ? AND club_id = ?
                  AND status IN ('active','frozen')
            ");
            $debtRow->execute([$id, $clubId]);
            $totalDebt = (float)$debtRow->fetchColumn();

            $invoices = $invStmt->fetchAll();
            Response::ok([
                'client'     => $client,
                'total_debt' => $totalDebt,
                'invoices'   => $invoices,
                'visits'     => $visStmt->fetchAll(),
            ]);


        // ════ СТРІЧКА ОСТАННІХ ОПЕРАЦІЙ (картка клієнта) ═════
        // Об'єднує відвідування + оплати абонементів + продажі товарів в один
        // хронологічний фід для вкладки "Основне" картки клієнта.
        case 'get_activity':
            $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
            if (!$id) Response::error('Не вказано id', 400);

            $chk = $pdo->prepare("SELECT id FROM clients WHERE id = ? AND club_id = ? LIMIT 1");
            $chk->execute([$id, $clubId]);
            if (!$chk->fetchColumn()) Response::error('Клієнта не знайдено', 404);

            $actStmt = $pdo->prepare("
                (SELECT 'visit' AS type, v.visited_at AS event_at, NULL AS amount,
                        NULL AS payment_method, NULL AS tariff_name, v.notes AS notes
                 FROM visits v
                 WHERE v.client_id = ? AND v.club_id = ?)
                UNION ALL
                (SELECT 'payment' AS type, cp.created_at AS event_at, cp.amount,
                        cp.payment_method, ci.tariff_name AS tariff_name, cp.notes AS notes
                 FROM club_payments cp
                 LEFT JOIN client_invoices ci ON ci.id = cp.invoice_id
                 WHERE cp.client_id = ? AND cp.club_id = ?)
                UNION ALL
                (SELECT 'sale' AS type, so.created_at AS event_at, so.total_amount AS amount,
                        so.payment_method, NULL AS tariff_name,
                        CONCAT(so.items_count, 'x ', COALESCE((
                            SELECT ps.product_name FROM product_sales ps
                            WHERE ps.order_id = so.id ORDER BY ps.id LIMIT 1
                        ), '')) AS notes
                 FROM sale_orders so
                 WHERE so.client_id = ? AND so.club_id = ? AND so.status = 'completed')
                ORDER BY event_at DESC
                LIMIT 10
            ");
            $actStmt->execute([$id, $clubId, $id, $clubId, $id, $clubId]);
            Response::ok(['activity' => $actStmt->fetchAll()]);


        // ════ СТВОРИТИ КЛІЄНТА ═══════════════════════════════
        case 'create':
            if (!Auth::can($sess, $clubId, 'clients.create')) Response::forbidden();
            Billing::requireWriteAccess($clubId);
            Billing::checkClientLimit($clubId);

            $name = trim($input['full_name'] ?? '');
            if (strlen($name) < 2) Response::error('Введіть ПІБ (мінімум 2 символи)');

            // Телефон: формат +380XXXXXXXXX і перевірка дубліката в межах клубу
            $phone = trim($input['phone'] ?? '');
            if ($phone !== '') {
                if (!preg_match('/^\+380\d{9}$/', $phone)) {
                    Response::error('Некоректний формат телефону. Приклад: +380671234567');
                }
                $phoneNorm = preg_replace('/[^0-9]/', '', $phone);
                $dup = $pdo->prepare("
                    SELECT id FROM clients
                    WHERE club_id = ? AND phone_normalized = ?
                    LIMIT 1
                ");
                $dup->execute([$clubId, $phoneNorm]);
                if ($dup->fetchColumn()) {
                    Response::error("Клієнт з телефоном {$phone} вже існує в цьому клубі", 409);
                }
            }

            // Email: формат і перевірка дубліката в межах клубу
            $email = trim($input['email'] ?? '');
            if ($email !== '') {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    Response::error('Некоректний email');
                }
                $dupEmail = $pdo->prepare("
                    SELECT id FROM clients
                    WHERE club_id = ? AND LOWER(email) = LOWER(?)
                    LIMIT 1
                ");
                $dupEmail->execute([$clubId, $email]);
                if ($dupEmail->fetchColumn()) {
                    Response::error("Клієнт з email {$email} вже існує в цьому клубі", 409);
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO clients
                    (club_id, full_name, phone, phone_normalized, email, birthday, gender,
                     address, status, source, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $clubId,
                htmlspecialchars($name, ENT_NOQUOTES, 'UTF-8'),
                $phone ?: null,
                Recalc::normalizePhone($phone),
                $email ?: null,
                preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['birthday'] ?? '') ? $input['birthday'] : null,
                in_array($input['gender'] ?? '', ['M','F']) ? $input['gender'] : '',
                trim($input['address'] ?? '') ?: null,
                in_array($input['status'] ?? '', ['regular','premium']) ? $input['status'] : 'regular',
                trim($input['source'] ?? '') ?: null,
                trim($input['notes'] ?? '') ?: null,
                $sess['user_id'],
            ]);

            $newId = (int)$pdo->lastInsertId();
            Response::ok(['id' => $newId], 'Клієнта додано');


        // ════ МАСОВИЙ ІМПОРТ З EXCEL (лише тарифи без ліміту клієнтів) ═
        case 'import':
            if (!Auth::can($sess, $clubId, 'clients.create')) Response::forbidden();

            // Фіча доступна лише клубам, чий тариф не обмежує кількість клієнтів
            // (clients_limit = NULL — безліміт, а не конкретна назва/slug плану).
            $planStmt = $pdo->prepare("
                SELECT p.clients_limit FROM saas_subscriptions s
                JOIN saas_plans p ON p.id = s.plan_id
                WHERE s.club_id = ? LIMIT 1
            ");
            $planStmt->execute([$clubId]);
            $planRow = $planStmt->fetch();
            if (!$planRow || $planRow['clients_limit'] !== null) {
                Response::forbidden('Імпорт клієнтів з Excel доступний лише на тарифі без обмеження кількості клієнтів');
            }

            $rows = is_array($input['rows'] ?? null) ? $input['rows'] : [];
            if (!$rows) Response::error('Немає рядків для імпорту');
            if (count($rows) > 500) Response::error('Максимум 500 клієнтів за один імпорт');

            // Існуючі телефони/email клубу — для перевірки дублікатів одним запитом,
            // а не по одному SELECT на кожен рядок (як у create).
            $existingStmt = $pdo->prepare("SELECT phone_normalized, LOWER(email) AS email_lc FROM clients WHERE club_id = ?");
            $existingStmt->execute([$clubId]);
            $existingPhones = [];
            $existingEmails = [];
            foreach ($existingStmt->fetchAll() as $r) {
                if ($r['phone_normalized']) $existingPhones[$r['phone_normalized']] = true;
                if ($r['email_lc'])         $existingEmails[$r['email_lc']]         = true;
            }

            $insertStmt = $pdo->prepare("
                INSERT INTO clients
                    (club_id, full_name, phone, email, birthday, gender,
                     address, status, source, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $added = 0;
            $skipped = [];
            $rowNum = 1;
            foreach ($rows as $row) {
                $rowNum++; // рядок 1 — заголовки файлу, дані починаються з 2

                $name = trim($row['full_name'] ?? '');
                if (strlen($name) < 2) {
                    $skipped[] = ['row' => $rowNum, 'name' => $name, 'reason' => 'Немає ПІБ (мінімум 2 символи)'];
                    continue;
                }

                $phone = trim($row['phone'] ?? '');
                $phoneNorm = null;
                if ($phone !== '') {
                    if (!preg_match('/^\+380\d{9}$/', $phone)) {
                        $skipped[] = ['row' => $rowNum, 'name' => $name, 'reason' => 'Некоректний формат телефону'];
                        continue;
                    }
                    $phoneNorm = preg_replace('/[^0-9]/', '', $phone);
                    if (isset($existingPhones[$phoneNorm])) {
                        $skipped[] = ['row' => $rowNum, 'name' => $name, 'reason' => "Клієнт з телефоном {$phone} вже є в клубі"];
                        continue;
                    }
                }

                $email = trim($row['email'] ?? '');
                if ($email !== '') {
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $skipped[] = ['row' => $rowNum, 'name' => $name, 'reason' => 'Некоректний email'];
                        continue;
                    }
                    if (isset($existingEmails[strtolower($email)])) {
                        $skipped[] = ['row' => $rowNum, 'name' => $name, 'reason' => "Клієнт з email {$email} вже є в клубі"];
                        continue;
                    }
                }

                $insertStmt->execute([
                    $clubId,
                    htmlspecialchars($name, ENT_NOQUOTES, 'UTF-8'),
                    $phone ?: null,
                    $email ?: null,
                    preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['birthday'] ?? '') ? $row['birthday'] : null,
                    in_array($row['gender'] ?? '', ['M', 'F']) ? $row['gender'] : '',
                    trim($row['address'] ?? '') ?: null,
                    in_array($row['status'] ?? '', ['regular', 'premium']) ? $row['status'] : 'regular',
                    trim($row['source'] ?? '') ?: null,
                    trim($row['notes'] ?? '') ?: null,
                    $sess['user_id'],
                ]);
                $added++;

                // Щоб дублікати всередині того самого файлу теж ловились
                if ($phoneNorm) $existingPhones[$phoneNorm] = true;
                if ($email)     $existingEmails[strtolower($email)] = true;
            }

            Response::ok(['added' => $added, 'skipped' => $skipped], "Додано клієнтів: {$added}");


        // ════ ОНОВИТИ КЛІЄНТА ════════════════════════════════
        case 'update':
            if (!Auth::can($sess, $clubId, 'clients.edit')) Response::forbidden();

            $id = (int)($input['id'] ?? 0);
            if (!$id) Response::error('Не вказано id');

            // Перевіряємо що клієнт належить цьому клубу
            $exists = $pdo->prepare("SELECT status, status_reason, status_changed_at, status_changed_by FROM clients WHERE id=? AND club_id=?");
            $exists->execute([$id, $clubId]);
            $existingRow = $exists->fetch();
            if (!$existingRow) Response::error('Клієнта не знайдено', 404);
            $currentStatus = $existingRow['status'];

            // Статус: regular/premium — будь-хто з правом редагування; blocked — лише власник,
            // і лише з обов'язковою причиною (ручний режим, без автоматики)
            $newStatus = in_array($input['status'] ?? '', ['regular','premium','blocked'])
                ? $input['status'] : 'regular';
            $statusReason = trim($input['status_reason'] ?? '');

            if ($newStatus === 'blocked' || $currentStatus === 'blocked') {
                if (!Auth::isOwner($sess, $access)) Response::forbidden('Блокування/розблокування клієнта — лише власник і вище');
            }
            if ($newStatus === 'blocked' && $statusReason === '') {
                Response::error('Вкажіть причину блокування');
            }

            $name = trim($input['full_name'] ?? '');
            if (strlen($name) < 2) Response::error('Введіть ПІБ');

            // Телефон: формат +380XXXXXXXXX і перевірка дубліката в межах клубу (крім себе)
            $phone = trim($input['phone'] ?? '');
            if ($phone !== '') {
                if (!preg_match('/^\+380\d{9}$/', $phone)) {
                    Response::error('Некоректний формат телефону. Приклад: +380671234567');
                }
                $phoneNorm = preg_replace('/[^0-9]/', '', $phone);
                $dup = $pdo->prepare("
                    SELECT id FROM clients
                    WHERE club_id = ? AND phone_normalized = ? AND id != ?
                    LIMIT 1
                ");
                $dup->execute([$clubId, $phoneNorm, $id]);
                if ($dup->fetchColumn()) {
                    Response::error("Клієнт з телефоном {$phone} вже існує в цьому клубі", 409);
                }
            }

            // Email: формат і перевірка дубліката в межах клубу (крім себе)
            $email = trim($input['email'] ?? '');
            if ($email !== '') {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    Response::error('Некоректний email');
                }
                $dupEmail = $pdo->prepare("
                    SELECT id FROM clients
                    WHERE club_id = ? AND LOWER(email) = LOWER(?) AND id != ?
                    LIMIT 1
                ");
                $dupEmail->execute([$clubId, $email, $id]);
                if ($dupEmail->fetchColumn()) {
                    Response::error("Клієнт з email {$email} вже існує в цьому клубі", 409);
                }
            }

            // Статус змінився — фіксуємо причину/час/автора; інакше лишаємо як було
            $statusChanged = $newStatus !== $currentStatus;

            $stmt = $pdo->prepare("
                UPDATE clients SET
                    full_name = ?, phone = ?, phone_normalized = ?, email = ?, birthday = ?,
                    gender = ?, address = ?, status = ?,
                    status_reason = ?, status_changed_at = ?, status_changed_by = ?,
                    source = ?, notes = ?
                WHERE id = ? AND club_id = ?
            ");
            $stmt->execute([
                htmlspecialchars($name, ENT_NOQUOTES, 'UTF-8'),
                $phone ?: null,
                Recalc::normalizePhone($phone),
                $email ?: null,
                preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['birthday'] ?? '') ? $input['birthday'] : null,
                in_array($input['gender'] ?? '', ['M','F']) ? $input['gender'] : '',
                trim($input['address'] ?? '') ?: null,
                $newStatus,
                $statusChanged ? ($newStatus === 'blocked' ? $statusReason : null) : $existingRow['status_reason'],
                $statusChanged ? date('Y-m-d H:i:s') : $existingRow['status_changed_at'],
                $statusChanged ? $sess['user_id'] : $existingRow['status_changed_by'],
                trim($input['source'] ?? '') ?: null,
                trim($input['notes'] ?? '') ?: null,
                $id,
                $clubId,
            ]);
            Response::ok([], 'Збережено');


        // ════ СТАТИСТИКА ДЛЯ ДАШБОРДУ ═══════════════════════
        case 'get_stats':
            $stats = $pdo->prepare("
                SELECT
                    SUM(status = 'regular')  AS total_regular,
                    SUM(status = 'premium')  AS total_premium,
                    SUM(status = 'blocked')  AS total_blocked,
                    SUM(status != 'blocked' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS new_this_month
                FROM clients
                WHERE club_id = ?
            ");
            $stats->execute([$clubId]);

            // Абонементи, що закінчуються за 7 днів
            $expiring = $pdo->prepare("
                SELECT COUNT(*) FROM client_invoices
                WHERE club_id = ?
                  AND status = 'active'
                  AND start_date <= CURDATE()
                  AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                  AND (visits_total IS NULL OR visits_used < visits_total)
            ");
            $expiring->execute([$clubId]);

            $row = $stats->fetch();
            Response::ok([
                'stats' => [
                    'total_regular'   => (int)$row['total_regular'],
                    'total_premium'   => (int)$row['total_premium'],
                    'total_blocked'   => (int)$row['total_blocked'],
                    'new_this_month'  => (int)$row['new_this_month'],
                    'expiring_soon'   => (int)$expiring->fetchColumn(),
                ],
            ]);


        // ════ ШВИДКИЙ ПОШУК (для autocomplete) ══════════════
        case 'search':
            $q = trim($input['q'] ?? $_GET['q'] ?? '');
            if (strlen($q) < 2) Response::ok(['results' => []]);

            // ПІБ — завжди; телефон — лише якщо є цифри (інакше LIKE '%%' збігається з усіма);
            // штрих-код/картка — точний збіг.
            $digits = preg_replace('/[^0-9]/', '', $q);

            $searchConditions = ['full_name LIKE ?'];
            $searchParams     = ['%' . $q . '%'];

            if ($digits !== '') {
                $searchConditions[] = 'phone_normalized LIKE ?';
                $searchParams[]     = '%' . $digits . '%';
            }

            $searchConditions[] = 'barcode = ?';
            $searchParams[]     = $q;

            $stmt = $pdo->prepare("
                SELECT id, full_name, phone, status, barcode
                FROM clients
                WHERE club_id = ?
                  AND status != 'blocked'
                  AND (" . implode(' OR ', $searchConditions) . ")
                ORDER BY full_name
                LIMIT 10
            ");
            $stmt->execute(array_merge([$clubId], $searchParams));
            Response::ok(['results' => $stmt->fetchAll()]);


        default:
            Response::error("Невідома дія: {$action}", 400);
    }

} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}