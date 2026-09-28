<?php
/**
 * settings_api.php — Налаштування клубу і профілю
 *
 * Дії:
 *   get_club       — отримати налаштування клубу
 *   update_club    — зберегти налаштування клубу
 *   get_profile    — отримати профіль поточного юзера
 *   update_profile — зберегти профіль (ім'я, телефон)
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$sess   = Auth::requireAuth();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';
$pdo    = Database::get();

$clubId = (int)($input['club_id'] ?? $_GET['club_id'] ?? $sess['active_club_id'] ?? 0);

try { switch ($action) {

    // ════ ОТРИМАТИ НАЛАШТУВАННЯ КЛУБУ ═════════════════════════
    case 'get_club':
        if (!$clubId) Response::error('Не обрано клуб', 400);
        if (!Auth::can($sess, $clubId, 'settings.manage')) Response::forbidden();

        $stmt = $pdo->prepare("
            SELECT
                c.id, c.name, c.slug, c.city, c.address,
                c.phone, c.email, c.logo_url, c.timezone,
                c.currency, c.is_active, c.created_at,
                c.subscription_status, c.trial_ends_at,
                c.cash_shift_auto_close_enabled, c.cash_shift_auto_close_time,
                u.full_name  AS owner_name,
                u.email      AS owner_email
            FROM sys_clubs c
            JOIN sys_users u ON u.id = c.owner_id
            WHERE c.id = ?
            LIMIT 1
        ");
        $stmt->execute([$clubId]);
        $club = $stmt->fetch();
        if (!$club) Response::error('Клуб не знайдено', 404);

        Response::ok(['club' => $club]);


    // ════ ЗБЕРЕГТИ НАЛАШТУВАННЯ КЛУБУ ═════════════════════════
    case 'update_club':
        if (!$clubId) Response::error('Не обрано клуб', 400);
        if (!Auth::can($sess, $clubId, 'settings.manage')) Response::forbidden();

        $name = trim($input['name'] ?? '');
        if (strlen($name) < 2) Response::error('Введіть назву клубу (мінімум 2 символи)');

        // Валідація email
        $email = trim($input['email'] ?? '');
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Невірний формат email');
        }

        // Валідація timezone
        $tz = trim($input['timezone'] ?? 'Europe/Kyiv');
        if (!in_array($tz, timezone_identifiers_list())) {
            $tz = 'Europe/Kyiv';
        }

        // Валідація currency
        $currency = strtoupper(trim($input['currency'] ?? 'UAH'));
        if (!in_array($currency, ['UAH', 'USD', 'EUR'])) {
            $currency = 'UAH';
        }

        // Автозакриття зміни каси (owner-only, settings.manage вже перевірено вище)
        $autoCloseEnabled = (int)(bool)($input['cash_shift_auto_close_enabled'] ?? 0);
        $autoCloseTime    = trim($input['cash_shift_auto_close_time'] ?? '');
        if ($autoCloseTime && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $autoCloseTime)) {
            Response::error('Невірний формат часу автозакриття (ГГ:ХХ)');
        }
        if ($autoCloseEnabled && !$autoCloseTime) {
            Response::error('Вкажіть час автозакриття зміни');
        }

        $pdo->prepare("
            UPDATE sys_clubs SET
                name      = ?,
                city      = ?,
                address   = ?,
                phone     = ?,
                email     = ?,
                logo_url  = ?,
                timezone  = ?,
                currency  = ?,
                cash_shift_auto_close_enabled = ?,
                cash_shift_auto_close_time    = ?
            WHERE id = ?
        ")->execute([
            htmlspecialchars($name, ENT_NOQUOTES, 'UTF-8'),
            trim($input['city']     ?? '') ?: null,
            trim($input['address']  ?? '') ?: null,
            trim($input['phone']    ?? '') ?: null,
            $email ?: null,
            trim($input['logo_url'] ?? '') ?: null,
            $tz,
            $currency,
            $autoCloseEnabled,
            $autoCloseTime ?: null,
            $clubId,
        ]);

        Response::ok([], 'Налаштування збережено');


    // ════ ОТРИМАТИ ПРОФІЛЬ ════════════════════════════════════
    case 'get_profile':
        $stmt = $pdo->prepare("
            SELECT id, full_name, email, phone, created_at, last_login_at
            FROM sys_users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$sess['user_id']]);
        $profile = $stmt->fetch();
        if (!$profile) Response::error('Профіль не знайдено', 404);

        // Клуби юзера
        $clubsStmt = $pdo->prepare("
            SELECT c.id, c.name, c.city, r.slug AS role_slug, r.name_ua AS role_name
            FROM sys_user_clubs uc
            JOIN sys_clubs c ON c.id = uc.club_id
            JOIN sys_roles r ON r.id = uc.role_id
            WHERE uc.user_id = ? AND uc.is_active = 1 AND c.is_active = 1
            ORDER BY c.name
        ");
        $clubsStmt->execute([$sess['user_id']]);

        Response::ok([
            'profile' => $profile,
            'clubs'   => $clubsStmt->fetchAll(),
            'role'    => $sess['global_role'] ?? null,
        ]);


    // ════ ЗБЕРЕГТИ ПРОФІЛЬ ════════════════════════════════════
    case 'update_profile':
        $fullName = trim($input['full_name'] ?? '');
        $phone    = trim($input['phone']     ?? '');

        if (strlen($fullName) < 2) Response::error('Введіть ім\'я (мінімум 2 символи)');

        $pdo->prepare("
            UPDATE sys_users SET full_name = ?, phone = ? WHERE id = ?
        ")->execute([
            htmlspecialchars($fullName, ENT_NOQUOTES, 'UTF-8'),
            $phone ?: null,
            $sess['user_id'],
        ]);

        Response::ok([], 'Профіль збережено');


    // ════ SYS_ROLES — список і редагування ════════════════════
    case 'get_sys_roles':
        if (($sess['global_level'] ?? 0) < 100) Response::forbidden();
        $stmt = $pdo->query("SELECT id, slug, name_ua, level FROM sys_roles ORDER BY level DESC");
        Response::ok(['roles' => $stmt->fetchAll()]);

    case 'update_sys_role':
        if (($sess['global_level'] ?? 0) < 100) Response::forbidden();
        $id     = (int)($input['id'] ?? 0);
        $nameUa = trim($input['name_ua'] ?? '');
        if (!$id || !$nameUa) Response::error('Вкажіть id і name_ua');
        $pdo->prepare("UPDATE sys_roles SET name_ua=? WHERE id=?")
            ->execute([htmlspecialchars($nameUa, ENT_NOQUOTES, 'UTF-8'), $id]);
        Response::ok([], 'Збережено');


    // ════ SYS_USERS — список всіх юзерів ══════════════════════
    case 'get_sys_users':
        if (($sess['global_level'] ?? 0) < 100) Response::forbidden();
        $search  = trim($input['search'] ?? $_GET['search'] ?? '');
        $page    = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
        $perPage = 30;
        $offset  = ($page - 1) * $perPage;

        $where  = ['1=1'];
        $params = [];
        if ($search) {
            $where[]  = '(u.full_name LIKE ? OR u.email LIKE ?)';
            $like     = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $whereSQL = implode(' AND ', $where);

        // Загальна кількість
        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM sys_users u WHERE {$whereSQL}"
        );
        $countStmt->execute($params);
        $totalCount = (int)$countStmt->fetchColumn();

        // Список
        $stmt = $pdo->prepare("
            SELECT
                u.id, u.full_name, u.email, u.phone,
                u.is_active, u.last_login_at, u.created_at,
                r.slug    AS global_role,
                r.name_ua AS global_role_name,
                (SELECT COUNT(*) FROM sys_user_clubs uc
                 WHERE uc.user_id = u.id) AS clubs_count
            FROM sys_users u
            LEFT JOIN sys_roles r ON r.id = u.global_role_id
            WHERE {$whereSQL}
            ORDER BY u.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$perPage, $offset]));

        Response::ok([
            'users'      => $stmt->fetchAll(),
            'pagination' => [
                'total'    => $totalCount,
                'page'     => $page,
                'pages'    => max(1, (int)ceil($totalCount / $perPage)),
                'per_page' => $perPage,
            ],
        ]);

    case 'toggle_sys_user':
        if (($sess['global_level'] ?? 0) < 100) Response::forbidden();
        $id = (int)($input['id'] ?? 0);
        if (!$id) Response::error('Вкажіть id');
        if ($id === (int)$sess['user_id']) Response::error('Не можна деактивувати себе');
        $stmt = $pdo->prepare("SELECT is_active FROM sys_users WHERE id=?");
        $stmt->execute([$id]);
        $cur = $stmt->fetchColumn();
        $new = $cur ? 0 : 1;
        $pdo->prepare("UPDATE sys_users SET is_active=? WHERE id=?")->execute([$new, $id]);
        Response::ok(['is_active' => $new], $new ? 'Активовано' : 'Деактивовано');


    // ════ ОНОВИТИ ПРОФІЛЬ КОРИСТУВАЧА (SuperAdmin) ════════════
    case 'update_sys_user_profile':
        if (($sess['global_level'] ?? 0) < 100) Response::forbidden();
        $id       = (int)($input['id'] ?? 0);
        $fullName = trim($input['full_name'] ?? '');
        if (!$id || !$fullName) Response::error('Вкажіть id і full_name');

        $pdo->prepare("
            UPDATE sys_users SET
                full_name      = ?,
                phone          = ?,
                global_role_id = ?,
                is_active      = ?
            WHERE id = ?
        ")->execute([
            htmlspecialchars($fullName, ENT_NOQUOTES, 'UTF-8'),
            trim($input['phone'] ?? '') ?: null,
            ($input['global_role_id'] !== '' && $input['global_role_id'] !== null)
                ? (int)$input['global_role_id'] : null,
            (int)(bool)($input['is_active'] ?? 1),
            $id,
        ]);
        Response::ok([], 'Збережено');


    // ════ СТВОРИТИ НОВОГО КОРИСТУВАЧА (SuperAdmin) ════════════
    case 'create_sys_user':
        if (($sess['global_level'] ?? 0) < 100) Response::forbidden();

        $fullName = trim($input['full_name'] ?? '');
        $email    = strtolower(trim($input['email'] ?? ''));
        $password = $input['password'] ?? '';

        if (!$fullName) Response::error('Введіть ім\'я');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Response::error('Невірний email');
        if (strlen($password) < 8) Response::error('Пароль мінімум 8 символів');

        // Перевірка унікальності email
        $dupStmt = $pdo->prepare("SELECT 1 FROM sys_users WHERE email=? LIMIT 1");
        $dupStmt->execute([$email]);
        if ($dupStmt->fetchColumn()) Response::error('Email вже використовується', 409);

        $pwdHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare("
            INSERT INTO sys_users
                (email, password_hash, full_name, phone, global_role_id, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
        ")->execute([
            $email,
            $pwdHash,
            htmlspecialchars($fullName, ENT_NOQUOTES, 'UTF-8'),
            trim($input['phone'] ?? '') ?: null,
            ($input['global_role_id'] !== '' && $input['global_role_id'] !== null)
                ? (int)$input['global_role_id'] : null,
        ]);

        Response::ok(['id' => (int)$pdo->lastInsertId()], 'Користувача створено');


    // ════ ДОДАТИ ПРИВ'ЯЗКУ USER → CLUB ════════════════════════
    case 'add_sys_user_club':
        if (($sess['global_level'] ?? 0) < 100) Response::forbidden();

        $userId = (int)($input['user_id'] ?? 0);
        $clubId = (int)($input['club_id'] ?? 0);
        $roleId = (int)($input['role_id'] ?? 0);
        if (!$userId || !$clubId || !$roleId) Response::error('Вкажіть user_id, club_id, role_id');

        // Перевірка дублікату
        $dupStmt = $pdo->prepare("SELECT id FROM sys_user_clubs WHERE user_id=? AND club_id=? LIMIT 1");
        $dupStmt->execute([$userId, $clubId]);
        if ($dupStmt->fetchColumn()) {
            Response::error('Такий зв\'язок вже існує. Відредагуйте існуючий.', 409);
        }

        $pdo->prepare("
            INSERT INTO sys_user_clubs (user_id, club_id, role_id, is_active, granted_by)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$userId, $clubId, $roleId,
            (int)(bool)($input['is_active'] ?? 1), $sess['user_id']]);

        Response::ok(['id' => (int)$pdo->lastInsertId()], 'Прив\'язку додано');


    // ════ ОНОВИТИ ПРИВ'ЯЗКУ USER → CLUB ═══════════════════════
    case 'update_sys_user_club':
        if (($sess['global_level'] ?? 0) < 100) Response::forbidden();

        $id     = (int)($input['id']      ?? 0);
        $roleId = (int)($input['role_id'] ?? 0);
        if (!$id || !$roleId) Response::error('Вкажіть id і role_id');

        $pdo->prepare("
            UPDATE sys_user_clubs SET
                role_id   = ?,
                is_active = ?
            WHERE id = ?
        ")->execute([
            $roleId,
            (int)(bool)($input['is_active'] ?? 1),
            $id,
        ]);

        Response::ok([], 'Збережено');


    // ════ SYS_USER_CLUBS — прив'язки юзерів до клубів ════════
    case 'get_sys_user_clubs':
        if (($sess['global_level'] ?? 0) < 100) Response::forbidden();
        $filterClub = (int)($_GET['club_id'] ?? $input['club_id'] ?? 0);
        $filterUser = (int)($_GET['user_id'] ?? $input['user_id'] ?? 0);

        $where  = ['1=1'];
        $params = [];
        if ($filterClub) { $where[] = 'uc.club_id = ?'; $params[] = $filterClub; }
        if ($filterUser) { $where[] = 'uc.user_id = ?'; $params[] = $filterUser; }
        $whereSQL = implode(' AND ', $where);

        $stmt = $pdo->prepare("
            SELECT
                uc.id, uc.is_active, uc.granted_at,
                u.id AS user_id, u.full_name, u.email,
                c.id AS club_id, c.name AS club_name,
                r.slug AS role_slug, r.name_ua AS role_name, r.level AS role_level
            FROM sys_user_clubs uc
            JOIN sys_users u ON u.id = uc.user_id
            JOIN sys_clubs c ON c.id = uc.club_id
            JOIN sys_roles r ON r.id = uc.role_id
            WHERE {$whereSQL}
            ORDER BY c.name, r.level DESC
            LIMIT 100
        ");
        $stmt->execute($params);
        Response::ok(['links' => $stmt->fetchAll()]);

    case 'remove_sys_user_club':        if (($sess['global_level'] ?? 0) < 100) Response::forbidden();
        $id = (int)($input['id'] ?? 0);
        if (!$id) Response::error('Вкажіть id');
        $pdo->prepare("DELETE FROM sys_user_clubs WHERE id=?")->execute([$id]);
        Response::ok([], 'Прив\'язку видалено');

    // ── Налаштування відображення колонок (заглушка) ─────────
    // Функціонал ще не реалізований — повертаємо порожній масив
    // щоб сторінки використовували DEFAULT_FIELDS
    case 'get_display_settings':
        Response::ok(['fields' => []]);

    case 'save_display_settings':
        Response::ok([], 'Збережено');


    default:
        Response::error("Невідома дія: {$action}", 400);

}} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}
