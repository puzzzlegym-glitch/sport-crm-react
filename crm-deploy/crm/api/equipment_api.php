<?php
/**
 * equipment_api.php — API модуля "Обладнання"
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$sess = Auth::requireAuth();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';

$pdo = Database::get();
$clubId = (int)($input['club_id'] ?? $_GET['club_id'] ?? $sess['active_club_id'] ?? 0);

if (!$clubId) {
    Response::error('Не обрано клуб', 400);
}

Auth::requireClubAccess($sess, $clubId, 30);

$allowedStatuses = ['working', 'maintenance', 'broken'];

try {
    switch ($action) {

        // ════ СПИСОК ОБЛАДНАННЯ ═══════════════════════════════════
        case 'get_list':
            if (!Auth::can($sess, $clubId, 'equipment.view')) Response::forbidden();

            $stmt = $pdo->prepare("
                SELECT id, name, category, location_note, status, notes, created_at, updated_at
                FROM club_equipment
                WHERE club_id = ?
                ORDER BY category, name
            ");
            $stmt->execute([$clubId]);
            Response::ok(['equipment' => $stmt->fetchAll()]);
            break;

        // ════ СТВОРИТИ ОДИНИЦЮ ОБЛАДНАННЯ ═══════════════════════════
        case 'create':
            if (!Auth::can($sess, $clubId, 'equipment.manage')) Response::forbidden();
            Billing::requireWriteAccess($clubId);

            $name = trim($input['name'] ?? '');
            if (strlen($name) < 1) Response::error('Введіть назву обладнання');

            $status = trim($input['status'] ?? 'working');
            if (!in_array($status, $allowedStatuses)) $status = 'working';

            $pdo->prepare("
                INSERT INTO club_equipment (club_id, name, category, location_note, status, notes)
                VALUES (?,?,?,?,?,?)
            ")->execute([
                $clubId,
                htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
                trim($input['category'] ?? '') ?: null,
                trim($input['location_note'] ?? '') ?: null,
                $status,
                trim($input['notes'] ?? '') ?: null,
            ]);

            Response::ok(['id' => (int)$pdo->lastInsertId()], 'Обладнання додано');
            break;

        // ════ РЕДАГУВАТИ ОДИНИЦЮ ОБЛАДНАННЯ ══════════════════════════
        case 'update':
            if (!Auth::can($sess, $clubId, 'equipment.manage')) Response::forbidden();

            $id = (int)($input['id'] ?? 0);
            $name = trim($input['name'] ?? '');

            if (!$id) Response::error('Не вказано id');
            if (!strlen($name)) Response::error('Введіть назву обладнання');

            $status = trim($input['status'] ?? 'working');
            if (!in_array($status, $allowedStatuses)) $status = 'working';

            $check = $pdo->prepare("SELECT 1 FROM club_equipment WHERE id=? AND club_id=?");
            $check->execute([$id, $clubId]);
            if (!$check->fetchColumn()) Response::error('Обладнання не знайдено', 404);

            $pdo->prepare("
                UPDATE club_equipment SET
                    name = ?, category = ?, location_note = ?, status = ?, notes = ?
                WHERE id = ? AND club_id = ?
            ")->execute([
                htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
                trim($input['category'] ?? '') ?: null,
                trim($input['location_note'] ?? '') ?: null,
                $status,
                trim($input['notes'] ?? '') ?: null,
                $id, $clubId,
            ]);

            Response::ok([], 'Збережено');
            break;

        // ════ ВИДАЛИТИ ОДИНИЦЮ ОБЛАДНАННЯ ════════════════════════════
        case 'delete':
            if (!Auth::can($sess, $clubId, 'equipment.manage')) Response::forbidden();

            $id = (int)($input['id'] ?? 0);
            if (!$id) Response::error('Не вказано id');

            $stmt = $pdo->prepare("DELETE FROM club_equipment WHERE id = ? AND club_id = ?");
            $stmt->execute([$id, $clubId]);

            if ($stmt->rowCount() === 0) Response::error('Обладнання не знайдено', 404);

            Response::ok([], 'Видалено');
            break;

        default:
            Response::error("Невідома дія: {$action}", 400);
    }
} catch (PDOException $e) {
    Response::serverError('DB Error: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}
