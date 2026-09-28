<?php
/**
 * certificates_api.php — API модуля "Сертифікати"
 *
 * Подарункові сертифікати з кодами, що переоформлюються циклічно: продаж →
 * гроші "лежать на сертифікаті" (клієнт ще не прив'язаний) → активація
 * (прив'язка до клієнта, сума падає йому на депозит через client_deposits) →
 * код повертається в пул available і знову доступний до продажу — НЕ
 * одноразовий ваучер.
 *
 * Дії:
 *   get_certificates        — список кодів + їх поточний/останній цикл
 *                             (з client_id — натомість історія активацій ЦЬОГО клієнта)
 *   sell_certificate         — продати (код+сума), клієнт ще не прив'язаний
 *   redeem_certificate       — активувати за кодом: прив'язати клієнта, зарахувати депозит
 *   cancel_certificate_sale  — скасувати продаж (до активації)
 *   cancel_redemption        — скасувати ВЖЕ активований сертифікат клієнта
 *                              (видаляє нарахований депозит, код повертається в available)
 *   update_certificate       — редагувати код (якщо available) і/або дані поточного
 *                              непогашеного продажу — сума/спосіб/покупець/примітка (якщо sold)
 *   delete_certificate       — видалити код повністю (лише якщо available — без активного продажу)
 *
 * Права:
 *   Читати            → certificates.view   (за замовчуванням manager+owner)
 *   Продати/активувати/редагувати → certificates.manage (за замовчуванням manager+owner)
 *   Скасувати продаж/активацію/видалити код → certificates.cancel (за замовчуванням owner)
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

// Базовий рівень навмисно низький (30) — саме Auth::can()-перевірки нижче є
// реальним гейтом, щоб клуб міг per-role/per-club дозволити доступ і
// тренеру, якщо захоче (той самий патерн, що й у cash_api.php).
Auth::requireClubAccess($sess, $clubId, 30);

try { switch ($action) {

    // ════ СПИСОК ═══════════════════════════════════════════════
    case 'get_certificates':
        if (!Auth::can($sess, $clubId, 'certificates.view')) Response::forbidden();

        // З client_id — це не список кодів, а історія активацій ЦЬОГО клієнта
        // (картка клієнта): один код може бути активований різними клієнтами
        // за різні цикли, тож тут читаємо весь certificate_sales, а не лише
        // "останній цикл на код", як у гілці нижче.
        $certClientId = (int)($input['client_id'] ?? 0);
        if ($certClientId) {
            $stmt = $pdo->prepare("
                SELECT cs.id AS sale_id, cs.certificate_id, c.code, cs.amount, cs.payment_method,
                       cs.status AS sale_status, cs.deposit_id,
                       cs.redeemed_admin_name, cs.redeemed_at,
                       cs.cancel_reason, cs.cancelled_at
                FROM certificate_sales cs
                JOIN certificates c ON c.id = cs.certificate_id
                WHERE cs.club_id = ? AND cs.redeemed_client_id = ?
                ORDER BY cs.redeemed_at DESC
                LIMIT 200
            ");
            $stmt->execute([$clubId, $certClientId]);
            Response::ok(['certificates' => $stmt->fetchAll()]);
        }

        $status = trim($input['status'] ?? '');
        $search = trim($input['search'] ?? '');

        $where  = ['c.club_id = ?'];
        $params = [$clubId];
        if ($status !== '') { $where[] = 'c.status = ?'; $params[] = $status; }
        if ($search !== '') {
            $where[]  = '(c.code LIKE ? OR cs.buyer_name LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        $whereSQL = implode(' AND ', $where);

        // Для кожного коду — його поточний/останній цикл продажу (по sold_at DESC)
        $stmt = $pdo->prepare("
            SELECT c.id, c.code, c.status, c.created_at,
                   cs.id AS sale_id, cs.amount, cs.payment_method, cs.buyer_name, cs.notes,
                   cs.status AS sale_status, cs.sold_admin_name, cs.sold_at,
                   cs.redeemed_client_id, cl.full_name AS redeemed_client_name,
                   cs.redeemed_admin_name, cs.redeemed_at
            FROM certificates c
            LEFT JOIN certificate_sales cs ON cs.id = (
                SELECT id FROM certificate_sales
                WHERE certificate_id = c.id
                ORDER BY sold_at DESC LIMIT 1
            )
            LEFT JOIN clients cl ON cl.id = cs.redeemed_client_id
            WHERE {$whereSQL}
            ORDER BY c.created_at DESC
            LIMIT 300
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Зведення — без прив'язки до періоду: available/sold — поточний стан,
        // redeemed — за весь час (це не звіт по датах, а робочий список кодів).
        $sumStmt = $pdo->prepare("
            SELECT
                SUM(c.status = 'available') AS available_count,
                SUM(c.status = 'sold')      AS sold_count,
                COALESCE(SUM(CASE WHEN c.status = 'sold' THEN cs.amount ELSE 0 END), 0) AS sold_amount,
                (SELECT COUNT(*) FROM certificate_sales WHERE club_id = ? AND status = 'redeemed') AS redeemed_count,
                (SELECT COALESCE(SUM(amount), 0) FROM certificate_sales WHERE club_id = ? AND status = 'redeemed') AS redeemed_amount
            FROM certificates c
            LEFT JOIN certificate_sales cs ON cs.id = (
                SELECT id FROM certificate_sales
                WHERE certificate_id = c.id
                ORDER BY sold_at DESC LIMIT 1
            )
            WHERE c.club_id = ?
        ");
        $sumStmt->execute([$clubId, $clubId, $clubId]);

        Response::ok([
            'certificates' => $rows,
            'summary'      => $sumStmt->fetch(),
        ]);


    // ════ ПРОДАТИ ═════════════════════════════════════════════
    case 'sell_certificate':
        if (!Auth::can($sess, $clubId, 'certificates.manage')) Response::forbidden();

        $code   = trim($input['code'] ?? '');
        $amount = (float)($input['amount'] ?? 0);
        $method = trim($input['payment_method'] ?? 'cash');
        if (!in_array($method, ['cash','card','terminal','transfer','other'], true)) $method = 'cash';

        if ($code === '') Response::error("Введіть код сертифіката");
        if ($amount <= 0) Response::error('Сума має бути більше 0');

        $certStmt = $pdo->prepare("SELECT id, status FROM certificates WHERE club_id=? AND code=? LIMIT 1");
        $certStmt->execute([$clubId, $code]);
        $cert = $certStmt->fetch();
        if ($cert && $cert['status'] !== 'available') {
            Response::error('Сертифікат вже продано і очікує активації');
        }

        $pdo->beginTransaction();
        try {
            if ($cert) {
                $certId = (int)$cert['id'];
            } else {
                $pdo->prepare("
                    INSERT INTO certificates (club_id, code, status, created_admin_id, created_admin_name)
                    VALUES (?, ?, 'available', ?, ?)
                ")->execute([$clubId, $code, $sess['user_id'], $sess['full_name'] ?? null]);
                $certId = (int)$pdo->lastInsertId();
            }

            $pdo->prepare("
                INSERT INTO certificate_sales
                    (certificate_id, club_id, amount, payment_method, buyer_name, notes,
                     status, sold_admin_id, sold_admin_name)
                VALUES (?,?, ?,?,?,?, 'sold', ?,?)
            ")->execute([
                $certId, $clubId, $amount, $method,
                trim($input['buyer_name'] ?? '') ?: null,
                trim($input['notes'] ?? '') ?: null,
                $sess['user_id'], $sess['full_name'] ?? null,
            ]);

            Recalc::cashflowSyncCertificateSale($pdo, (int)$pdo->lastInsertId());
            $pdo->prepare("UPDATE certificates SET status='sold' WHERE id=?")->execute([$certId]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::serverError($e->getMessage());
        }

        Response::ok([], "Сертифікат «{$code}» продано на " . number_format($amount, 2) . " грн");


    // ════ РЕДАГУВАТИ ═════════════════════════════════════════
    // available — можна перейменувати код; sold — можна виправити дані
    // поточного непогашеного продажу (суму/спосіб/покупця/примітку),
    // напр. якщо адміністратор помилився при внесенні.
    case 'update_certificate':
        if (!Auth::can($sess, $clubId, 'certificates.manage')) Response::forbidden();

        $id = (int)($input['id'] ?? 0);
        if (!$id) Response::error('Не вказано id');

        $certStmt = $pdo->prepare("SELECT * FROM certificates WHERE id=? AND club_id=? LIMIT 1");
        $certStmt->execute([$id, $clubId]);
        $cert = $certStmt->fetch();
        if (!$cert) Response::error('Сертифікат не знайдено', 404);

        $newCode = trim($input['code'] ?? '');
        if ($newCode === '') Response::error("Введіть код сертифіката");

        if ($newCode !== $cert['code']) {
            $dupStmt = $pdo->prepare("SELECT 1 FROM certificates WHERE club_id=? AND code=? AND id != ? LIMIT 1");
            $dupStmt->execute([$clubId, $newCode, $id]);
            if ($dupStmt->fetchColumn()) Response::error('Такий код вже існує');
        }

        $saleId = null;
        $amount = 0.0;
        $method = 'cash';
        if ($cert['status'] === 'sold') {
            $amount = (float)($input['amount'] ?? 0);
            if ($amount <= 0) Response::error('Сума має бути більше 0');
            $method = trim($input['payment_method'] ?? 'cash');
            if (!in_array($method, ['cash','card','terminal','transfer','other'], true)) $method = 'cash';

            $saleStmt = $pdo->prepare("
                SELECT id FROM certificate_sales
                WHERE certificate_id=? AND status='sold' ORDER BY sold_at DESC LIMIT 1
            ");
            $saleStmt->execute([$id]);
            $saleId = $saleStmt->fetchColumn();
            if (!$saleId) Response::error('Не знайдено активний продаж цього сертифіката');
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE certificates SET code=? WHERE id=?")->execute([$newCode, $id]);

            if ($saleId) {
                $pdo->prepare("
                    UPDATE certificate_sales SET amount=?, payment_method=?, buyer_name=?, notes=?
                    WHERE id=?
                ")->execute([
                    $amount, $method,
                    trim($input['buyer_name'] ?? '') ?: null,
                    trim($input['notes'] ?? '') ?: null,
                    $saleId,
                ]);
                Recalc::cashflowSyncCertificateSale($pdo, (int)$saleId);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::serverError($e->getMessage());
        }

        Response::ok([], 'Збережено');


    // ════ АКТИВУВАТИ ══════════════════════════════════════════
    case 'redeem_certificate':
        if (!Auth::can($sess, $clubId, 'certificates.manage')) Response::forbidden();

        $code     = trim($input['code'] ?? '');
        $clientId = (int)($input['client_id'] ?? 0);
        if ($code === '') Response::error("Введіть код сертифіката");
        if (!$clientId) Response::error('Оберіть клієнта-отримувача');

        $certStmt = $pdo->prepare("SELECT id, status FROM certificates WHERE club_id=? AND code=? LIMIT 1");
        $certStmt->execute([$clubId, $code]);
        $cert = $certStmt->fetch();
        if (!$cert) Response::error('Сертифікат не знайдено');
        if ($cert['status'] !== 'sold') Response::error('Цей сертифікат зараз не очікує активації');
        $certId = (int)$cert['id'];

        $saleStmt = $pdo->prepare("
            SELECT id, amount, payment_method FROM certificate_sales
            WHERE certificate_id=? AND status='sold'
            ORDER BY sold_at DESC LIMIT 1
        ");
        $saleStmt->execute([$certId]);
        $sale = $saleStmt->fetch();
        if (!$sale) Response::error('Не знайдено активний продаж цього сертифіката');

        $clientStmt = $pdo->prepare("SELECT id, full_name FROM clients WHERE id=? AND club_id=? LIMIT 1");
        $clientStmt->execute([$clientId, $clubId]);
        $client = $clientStmt->fetch();
        if (!$client) Response::error('Клієнта не знайдено');

        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                INSERT INTO client_deposits
                    (club_id, client_id, amount, operation, payment_method, admin_id, admin_name, notes)
                VALUES (?,?, ?, 'certificate', ?,?,?,?)
            ")->execute([
                $clubId, $clientId, $sale['amount'], $sale['payment_method'],
                $sess['user_id'], $sess['full_name'] ?? null,
                "Сертифікат «{$code}»",
            ]);
            $depositId = (int)$pdo->lastInsertId();
            Recalc::clientBalance($pdo, $clientId);

            $pdo->prepare("
                UPDATE certificate_sales
                SET status='redeemed', redeemed_client_id=?, redeemed_admin_id=?, redeemed_admin_name=?, redeemed_at=NOW(), deposit_id=?
                WHERE id=?
            ")->execute([$clientId, $sess['user_id'], $sess['full_name'] ?? null, $depositId, $sale['id']]);

            $pdo->prepare("UPDATE certificates SET status='available' WHERE id=?")->execute([$certId]);

            $newBalance = (float)$pdo->query("SELECT balance FROM clients WHERE id={$clientId}")->fetchColumn();

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::serverError($e->getMessage());
        }

        Response::ok(['new_balance' => $newBalance],
            "Сертифікат активовано. {$client['full_name']}: +" . number_format($sale['amount'], 2) . " грн на депозит");


    // ════ СКАСУВАТИ ПРОДАЖ ════════════════════════════════════
    case 'cancel_certificate_sale':
        if (!Auth::can($sess, $clubId, 'certificates.cancel')) Response::forbidden();

        $code = trim($input['code'] ?? '');
        if ($code === '') Response::error("Введіть код сертифіката");

        $certStmt = $pdo->prepare("SELECT id, status FROM certificates WHERE club_id=? AND code=? LIMIT 1");
        $certStmt->execute([$clubId, $code]);
        $cert = $certStmt->fetch();
        if (!$cert) Response::error('Сертифікат не знайдено');
        if ($cert['status'] !== 'sold') Response::error('Скасувати можна лише сертифікат, що очікує активації');

        $saleStmt = $pdo->prepare("SELECT id FROM certificate_sales WHERE certificate_id=? AND status='sold' ORDER BY sold_at DESC LIMIT 1");
        $saleStmt->execute([$cert['id']]);
        $saleId = $saleStmt->fetchColumn();
        if (!$saleId) Response::error('Не знайдено активний продаж цього сертифіката');

        $pdo->prepare("
            UPDATE certificate_sales SET status='cancelled', cancel_reason=?, cancelled_at=NOW() WHERE id=?
        ")->execute([trim($input['reason'] ?? '') ?: null, $saleId]);
        Recalc::cashflowSyncCertificateSale($pdo, (int)$saleId);
        $pdo->prepare("UPDATE certificates SET status='available' WHERE id=?")->execute([$cert['id']]);

        Response::ok([], 'Продаж сертифіката скасовано');


    // ════ СКАСУВАТИ АКТИВАЦІЮ (клієнт вже отримав депозит) ═════
    // На відміну від cancel_certificate_sale (тільки поки status='sold'),
    // ця дія відкатує ВЖЕ активований сертифікат: знімає нарахований
    // депозит клієнта і повертає код у пул available — власник видаляє
    // цей "бізнес-запис клієнта" з картки клієнта.
    case 'cancel_redemption':
        if (!Auth::can($sess, $clubId, 'certificates.cancel')) Response::forbidden('Скасування активації — лише власник');

        $saleId = (int)($input['sale_id'] ?? 0);
        if (!$saleId) Response::error('Не вказано sale_id');

        $saleStmt = $pdo->prepare("
            SELECT cs.*, c.code FROM certificate_sales cs
            JOIN certificates c ON c.id = cs.certificate_id
            WHERE cs.id=? AND cs.club_id=? LIMIT 1
        ");
        $saleStmt->execute([$saleId, $clubId]);
        $sale = $saleStmt->fetch();
        if (!$sale) Response::error('Запис не знайдено', 404);
        if ($sale['status'] !== 'redeemed') Response::error('Скасувати можна лише активований сертифікат');

        $pdo->beginTransaction();
        try {
            if ($sale['deposit_id']) {
                $pdo->prepare("DELETE FROM client_deposits WHERE id=? AND club_id=?")
                    ->execute([$sale['deposit_id'], $clubId]);
                Recalc::clientBalance($pdo, (int)$sale['redeemed_client_id']);
            }

            $pdo->prepare("
                UPDATE certificate_sales
                SET status='cancelled', cancel_reason=?, cancelled_at=NOW(), deposit_id=NULL
                WHERE id=?
            ")->execute([trim($input['reason'] ?? '') ?: 'Скасовано власником після активації', $saleId]);

            $pdo->prepare("UPDATE certificates SET status='available' WHERE id=?")->execute([$sale['certificate_id']]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::serverError($e->getMessage());
        }

        Response::ok([], "Активацію сертифіката «{$sale['code']}» скасовано, депозит знято");


    // ════ ВИДАЛИТИ КОД ═══════════════════════════════════════
    // Лише якщо код зараз ВІЛЬНИЙ (available) — без продажу в процесі.
    // Каскадно видаляє й всю історію циклів цього коду (certificate_sales,
    // FK ON DELETE CASCADE) — тому лише власник.
    case 'delete_certificate':
        if (!Auth::can($sess, $clubId, 'certificates.cancel')) Response::forbidden('Видалення — лише власник');

        $id = (int)($input['id'] ?? 0);
        if (!$id) Response::error('Не вказано id');

        $certStmt = $pdo->prepare("SELECT status FROM certificates WHERE id=? AND club_id=? LIMIT 1");
        $certStmt->execute([$id, $clubId]);
        $cert = $certStmt->fetch();
        if (!$cert) Response::error('Сертифікат не знайдено', 404);
        if ($cert['status'] !== 'available') {
            Response::error('Видалити можна лише вільний код — спочатку скасуйте поточний продаж', 409);
        }

        $pdo->prepare("DELETE FROM certificates WHERE id=? AND club_id=?")->execute([$id, $clubId]);

        Response::ok([], 'Сертифікат видалено');


    default:
        Response::error("Невідома дія: {$action}", 400);

}} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}
