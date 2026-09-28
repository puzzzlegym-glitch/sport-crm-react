<?php
/**
 * tariffs_api.php — API управління тарифами клубу
 *
 * Дії:
 *   get_list   — список тарифів (активні + архівні)
 *   get_one    — один тариф + статистика використання
 *   create     — створити тариф
 *   update     — редагувати тариф
 *   archive    — архівувати / відновити (is_active toggle)
 *
 * Права:
 *   Читати    → manager (50+)
 *   Керувати  → owner (80+)
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

Auth::requireClubAccess($sess, $clubId, 50);

try { switch ($action) {

    // ════ СПИСОК ТАРИФІВ ═════════════════════════════════════
    case 'get_list':
        $showArchived = !empty($input['archived'] ?? $_GET['archived'] ?? '');

        $stmt = $pdo->prepare("
            SELECT
                t.id, t.name, t.category, t.duration_days,
                t.visits_limit, t.price, t.description,
                t.color, t.is_active, t.sort_order,
                t.freeze_days_max, t.freeze_days_min, t.prolong_sum,
                t.has_trainer, t.earn_release_trigger,
                COALESCE(t.coverage, 'all') AS coverage,
                t.created_at,
                COUNT(ci.id)          AS usage_total,
                SUM(ci.status='active') AS usage_active
            FROM tariffs t
            LEFT JOIN client_invoices ci ON ci.tariff_id = t.id
            WHERE t.club_id = ?
              AND t.is_active = ?
            GROUP BY t.id
            ORDER BY t.sort_order, t.name
        ");
        $stmt->execute([$clubId, $showArchived ? 0 : 1]);

        Response::ok(['tariffs' => $stmt->fetchAll()]);


    // ════ ОДИН ТАРИФ ═════════════════════════════════════════
    case 'get_one':
        $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
        if (!$id) Response::error('Не вказано id');

        $stmt = $pdo->prepare("
            SELECT t.*,
                COUNT(ci.id)                                    AS usage_total,
                SUM(ci.status = 'active')                       AS usage_active,
                SUM(ci.price)                                   AS revenue_total,
                SUM(CASE WHEN MONTH(ci.created_at) = MONTH(NOW())
                          AND YEAR(ci.created_at)  = YEAR(NOW())
                     THEN 1 ELSE 0 END)                         AS sold_this_month
            FROM tariffs t
            LEFT JOIN client_invoices ci ON ci.tariff_id = t.id
            WHERE t.id = ? AND t.club_id = ?
            GROUP BY t.id
            LIMIT 1
        ");
        $stmt->execute([$id, $clubId]);
        $tariff = $stmt->fetch();
        if (!$tariff) Response::error('Тариф не знайдено', 404);

        Response::ok(['tariff' => $tariff]);


    // ════ СТВОРИТИ ТАРИФ ══════════════════════════════════════
    case 'create':
        if (!Auth::can($sess, $clubId, 'tariffs.create')) Response::forbidden('Керування тарифами — лише власник');

        $name = trim($input['name'] ?? '');
        if (!$name) Response::error("Назва обов'язкова");

        $price = (float)($input['price'] ?? 0);
        if ($price < 0) Response::error("Ціна не може бути від'ємною");

        $earnTrigger = in_array($input['earn_release_trigger'] ?? '', ['on_sale','on_each_visit','on_visits_done','on_end_date'])
            ? $input['earn_release_trigger'] : 'on_each_visit';

        // Що покриває абонемент: all=зал+групові, gym, group, personal (див. Attendance::SERVICE_COVERAGE)
        $coverage = in_array($input['coverage'] ?? '', ['all','gym','group','personal'], true)
            ? $input['coverage'] : (!empty($input['has_trainer']) ? 'personal' : 'all');

        $freezeMax = max(0, (int)($input['freeze_days_max'] ?? 0));
        $freezeMin = max(0, (int)($input['freeze_days_min'] ?? 0));
        if ($freezeMax > 0 && $freezeMin > $freezeMax) {
            Response::error('Мінімум заморозки не може перевищувати максимум');
        }

        $pdo->prepare("
            INSERT INTO tariffs
                (club_id, name, category, duration_days, visits_limit,
                 price, description, color, sort_order,
                 freeze_days_max, freeze_days_min, prolong_sum,
                 has_trainer, earn_release_trigger, coverage)
            VALUES (?,?,?,?,?, ?,?,?,?, ?,?,?, ?,?,?)
        ")->execute([
            $clubId,
            $name,
            trim($input['category']    ?? '') ?: null,
            max(1, (int)($input['duration_days'] ?? 30)),
            ($input['visits_limit'] !== '' && $input['visits_limit'] !== null)
                ? (int)$input['visits_limit'] : null,
            $price,
            trim($input['description'] ?? '') ?: null,
            preg_match('/^#[0-9a-fA-F]{6}$/', $input['color'] ?? '')
                ? $input['color'] : '#4f9cf9',
            (int)($input['sort_order'] ?? 0),
            $freezeMax,
            $freezeMin,
            max(0, (float)($input['prolong_sum']   ?? 0)),
            (int)(bool)($input['has_trainer'] ?? 0),
            $earnTrigger,
            $coverage,
        ]);

        Response::ok(['id' => (int)$pdo->lastInsertId()], 'Тариф створено');


    // ════ РЕДАГУВАТИ ТАРИФ ════════════════════════════════════
    case 'update':
        if (!Auth::can($sess, $clubId, 'tariffs.edit')) Response::forbidden('Керування тарифами — лише власник');

        $id   = (int)($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        if (!$id)   Response::error('Не вказано id');
        if (!$name) Response::error("Назва обов'язкова");

        $check = $pdo->prepare("SELECT 1 FROM tariffs WHERE id=? AND club_id=?");
        $check->execute([$id, $clubId]);
        if (!$check->fetchColumn()) Response::error('Тариф не знайдено', 404);

        $earnTrigger = in_array($input['earn_release_trigger'] ?? '', ['on_sale','on_each_visit','on_visits_done','on_end_date'])
            ? $input['earn_release_trigger'] : 'on_each_visit';

        // Що покриває абонемент: all=зал+групові, gym, group, personal (див. Attendance::SERVICE_COVERAGE)
        $coverage = in_array($input['coverage'] ?? '', ['all','gym','group','personal'], true)
            ? $input['coverage'] : (!empty($input['has_trainer']) ? 'personal' : 'all');

        $freezeMax = max(0, (int)($input['freeze_days_max'] ?? 0));
        $freezeMin = max(0, (int)($input['freeze_days_min'] ?? 0));
        if ($freezeMax > 0 && $freezeMin > $freezeMax) {
            Response::error('Мінімум заморозки не може перевищувати максимум');
        }

        $pdo->prepare("
            UPDATE tariffs SET
                name             = ?,
                category         = ?,
                duration_days    = ?,
                visits_limit     = ?,
                price            = ?,
                description      = ?,
                color            = ?,
                sort_order       = ?,
                freeze_days_max  = ?,
                freeze_days_min  = ?,
                prolong_sum      = ?,
                has_trainer      = ?,
                earn_release_trigger = ?,
                coverage         = ?
            WHERE id = ? AND club_id = ?
        ")->execute([
            $name,
            trim($input['category']    ?? '') ?: null,
            max(1, (int)($input['duration_days'] ?? 30)),
            ($input['visits_limit'] !== '' && $input['visits_limit'] !== null)
                ? (int)$input['visits_limit'] : null,
            max(0, (float)($input['price'] ?? 0)),
            trim($input['description'] ?? '') ?: null,
            preg_match('/^#[0-9a-fA-F]{6}$/', $input['color'] ?? '')
                ? $input['color'] : '#4f9cf9',
            (int)($input['sort_order'] ?? 0),
            $freezeMax,
            $freezeMin,
            max(0, (float)($input['prolong_sum']   ?? 0)),
            (int)(bool)($input['has_trainer'] ?? 0),
            $earnTrigger,
            $coverage,
            $id, $clubId,
        ]);

        Response::ok([], 'Збережено');


    // ════ АРХІВУВАТИ / ВІДНОВИТИ ══════════════════════════════
    case 'archive':
        if (!Auth::can($sess, $clubId, 'tariffs.delete')) Response::forbidden('Керування тарифами — лише власник');

        $id = (int)($input['id'] ?? 0);
        if (!$id) Response::error('Не вказано id');

        $row = $pdo->prepare("SELECT is_active FROM tariffs WHERE id=? AND club_id=?");
        $row->execute([$id, $clubId]);
        $cur = $row->fetchColumn();
        if ($cur === false) Response::error('Тариф не знайдено', 404);

        $newVal = $cur ? 0 : 1;
        $pdo->prepare("UPDATE tariffs SET is_active=? WHERE id=? AND club_id=?")
            ->execute([$newVal, $id, $clubId]);

        $msg = $newVal ? 'Тариф відновлено' : 'Тариф архівовано';
        Response::ok(['is_active' => $newVal], $msg);


    default:
        Response::error("Невідома дія: {$action}", 400);

}} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}
