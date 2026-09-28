<?php
/**
 * permissions_api.php — Матриця прав доступу (золотий стандарт + override клубу)
 *
 * Дії:
 *   get_catalog          — довідник усіх дій (sys_permissions) + список ролей (sys_roles)
 *   get_club_matrix      — ефективні права поточного клубу (стандарт + override)
 *   save_club_matrix     — зберегти override для поточного клубу
 *   get_system_defaults  — золотий стандарт (без контексту клубу)
 *   save_system_defaults — зберегти золотий стандарт
 *
 * Права:
 *   Перегляд club-матриці   → будь-яка роль клубу (30+)
 *   Редагування club-матриці → access.manage (за замовчуванням лише owner) або SuperAdmin
 *   Перегляд/редагування golden standard → лише SuperAdmin
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$sess   = Auth::requireAuth();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';
$pdo    = Database::get();

try { switch ($action) {

    // ════ ДОВІДНИК ДІЙ І РОЛЕЙ ════════════════════════════════
    case 'get_catalog':
        $perms = $pdo->query("
            SELECT slug, label, category, sort_order
            FROM sys_permissions
            ORDER BY category, sort_order
        ")->fetchAll();

        $roles = $pdo->query("
            SELECT id, slug, name_ua, level
            FROM sys_roles
            WHERE slug IN ('trainer','manager','owner')
            ORDER BY level
        ")->fetchAll();

        Response::ok(['permissions' => $perms, 'roles' => $roles]);


    // ════ МАТРИЦЯ КЛУБУ (стандарт + override) ═════════════════
    case 'get_club_matrix':
        $clubId = (int)($input['club_id'] ?? $_GET['club_id'] ?? $sess['active_club_id'] ?? 0);
        if (!$clubId) Response::error('Не обрано клуб', 400);
        Auth::requireClubAccess($sess, $clubId, 30);

        $defaults = $pdo->query("
            SELECT role_id, permission_slug FROM sys_role_permissions
        ")->fetchAll();

        $overrideStmt = $pdo->prepare("
            SELECT role_id, permission_slug, is_allowed
            FROM club_role_permissions WHERE club_id = ?
        ");
        $overrideStmt->execute([$clubId]);

        $canEdit = Auth::isSuperAdmin($sess) || Auth::can($sess, $clubId, 'access.manage');

        Response::ok([
            'defaults'  => array_map(fn($r) => ['role_id' => (int)$r['role_id'], 'permission_slug' => $r['permission_slug']], $defaults),
            'overrides' => array_map(fn($r) => ['role_id' => (int)$r['role_id'], 'permission_slug' => $r['permission_slug'], 'is_allowed' => (bool)$r['is_allowed']], $overrideStmt->fetchAll()),
            'can_edit'  => $canEdit,
        ]);


    // ════ ЗБЕРЕГТИ МАТРИЦЮ КЛУБУ ══════════════════════════════
    case 'save_club_matrix':
        $clubId = (int)($input['club_id'] ?? $_GET['club_id'] ?? $sess['active_club_id'] ?? 0);
        if (!$clubId) Response::error('Не обрано клуб', 400);
        Auth::requireClubAccess($sess, $clubId, 30);

        $canEdit = Auth::isSuperAdmin($sess) || Auth::can($sess, $clubId, 'access.manage');
        if (!$canEdit) Response::forbidden('Редагування прав доступу — лише власник');

        $rows = $input['rows'] ?? [];
        if (!is_array($rows)) Response::error('Невірний формат даних');

        // Повний перезапис override-таблиці клубу — фронтенд надсилає лише
        // клітинки, що відрізняються від золотого стандарту (сама таблиця — sparse override).
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM club_role_permissions WHERE club_id = ?")->execute([$clubId]);

        $ins = $pdo->prepare("
            INSERT INTO club_role_permissions (club_id, role_id, permission_slug, is_allowed)
            VALUES (?, ?, ?, ?)
        ");
        foreach ($rows as $r) {
            $roleId = (int)($r['role_id'] ?? 0);
            $slug   = trim($r['permission_slug'] ?? '');
            if (!$roleId || !$slug) continue;
            $ins->execute([$clubId, $roleId, $slug, !empty($r['is_allowed']) ? 1 : 0]);
        }
        $pdo->commit();

        Response::ok([], 'Права доступу клубу збережено');


    // ════ ЗОЛОТИЙ СТАНДАРТ (SuperAdmin) ═══════════════════════
    case 'get_system_defaults':
        if (!Auth::isSuperAdmin($sess)) Response::forbidden('Тільки SuperAdmin');

        $rows = $pdo->query("SELECT role_id, permission_slug FROM sys_role_permissions")->fetchAll();
        Response::ok([
            'defaults' => array_map(fn($r) => ['role_id' => (int)$r['role_id'], 'permission_slug' => $r['permission_slug']], $rows),
        ]);


    case 'save_system_defaults':
        if (!Auth::isSuperAdmin($sess)) Response::forbidden('Тільки SuperAdmin');

        $rows = $input['rows'] ?? [];
        if (!is_array($rows)) Response::error('Невірний формат даних');

        // Повний перезапис sys_role_permissions — це живий fallback для УСІХ клубів
        // без власного override (підтверджено користувачем як бажана поведінка).
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM sys_role_permissions");

        $ins = $pdo->prepare("INSERT INTO sys_role_permissions (role_id, permission_slug) VALUES (?, ?)");
        foreach ($rows as $r) {
            $roleId = (int)($r['role_id'] ?? 0);
            $slug   = trim($r['permission_slug'] ?? '');
            if (!$roleId || !$slug) continue;
            $ins->execute([$roleId, $slug]);
        }
        $pdo->commit();

        Response::ok([], 'Золотий стандарт збережено');


    default:
        Response::error("Невідома дія: {$action}", 400);

}} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    Response::serverError($e->getMessage());
}
