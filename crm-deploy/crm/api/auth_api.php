<?php
/**
 * auth_api.php — API авторизації
 *
 * Дії:
 *   login        — вхід (email + password)
 *   logout       — вихід
 *   check        — перевірка поточної сесії
 *   switch_club  — перемикання активного клубу
 *   exit_club    — SuperAdmin виходить з режиму клубу → повертається на системну панель
 *   my_clubs     — список клубів поточного юзера
 *   set_view_role — SuperAdmin: перемкнути роль перегляду клубу (owner/manager/trainer)
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';

try { switch ($action) {

    // ── ВХІД ────────────────────────────────────────────────
    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') Response::error('Метод не підтримується', 405);

        $email    = trim($input['email']    ?? '');
        $password = trim($input['password'] ?? '');
        if (!$email || !$password) Response::error('Введіть email і пароль');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Response::error('Невірний формат email');

        $result = Auth::login($email, $password);
        Response::ok([
            'user'    => ['id' => $result['user_id'], 'full_name' => $result['full_name'], 'email' => $result['email']],
            'club_id' => $result['active_club_id'],
            'redirect'=> '/dashboard',
        ], 'Ласкаво просимо!');


    // ── ВИХІД ───────────────────────────────────────────────
    case 'logout':
        Auth::logout();
        Response::ok([], 'До побачення!');


    // ── ВІДНОВЛЕННЯ ПАРОЛЯ: запит листа ──────────────────────
    case 'forgot_password':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') Response::error('Метод не підтримується', 405);

        $email = trim($input['email'] ?? '');
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Введіть коректний email');
        }

        $pdo = Database::get();

        $stmt = $pdo->prepare("
            SELECT id, full_name, email, is_active, password_reset_token_expires
            FROM sys_users WHERE email = ? LIMIT 1
        ");
        $stmt->execute([strtolower($email)]);
        $user = $stmt->fetch();

        // Відповідь однакова незалежно від того, чи є такий email —
        // щоб не дати змогу перевіряти список зареєстрованих пошт.
        // Токен видається раз на 5 хв на одну адресу — захист від спаму на чужу пошту
        // (термін токена — 1 год, тож "видано < 5 хв тому" ⇔ expires_at > now + 55 хв).
        $recentlyIssued = $user['password_reset_token_expires']
            && strtotime($user['password_reset_token_expires']) > time() + 3300;

        if ($user && $user['is_active'] && !$recentlyIssued) {
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600); // 1 година
            $pdo->prepare("
                UPDATE sys_users SET password_reset_token=?, password_reset_token_expires=?
                WHERE id=?
            ")->execute([$token, $expires, $user['id']]);

            try {
                $resetUrl = rtrim(APP_URL, '/') . '/reset-password?token=' . $token;
                Mailer::sendTemplate('password_reset', [
                    'full_name' => $user['full_name'],
                    'reset_url' => $resetUrl,
                ], $user['email'], $user['full_name']);
            } catch (Throwable $e) {
                error_log('[Auth] Password reset email failed: ' . $e->getMessage());
            }
        }

        Response::ok([], 'Якщо такий email зареєстровано — на нього надіслано лист з інструкціями');


    // ── ВІДНОВЛЕННЯ ПАРОЛЯ: встановлення нового ──────────────
    case 'reset_password':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') Response::error('Метод не підтримується', 405);

        $token    = trim($input['token'] ?? '');
        $password = trim($input['password'] ?? '');
        if (!$token) Response::error('Невірне посилання для відновлення');
        if (strlen($password) < 8) Response::error('Пароль має містити мінімум 8 символів');

        $pdo  = Database::get();
        $stmt = $pdo->prepare("
            SELECT id, email FROM sys_users
            WHERE password_reset_token = ? AND password_reset_token_expires > NOW()
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) Response::error('Посилання недійсне або застаріло. Запросіть нове.', 400);

        $pdo->prepare("
            UPDATE sys_users
            SET password_hash = ?, password_reset_token = NULL, password_reset_token_expires = NULL
            WHERE id = ?
        ")->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);

        // Виходимо з усіх активних сесій цього користувача — новий пароль, нові сесії
        $pdo->prepare("DELETE FROM sys_sessions WHERE user_id = ?")->execute([$user['id']]);
        // Знімаємо блокування акаунта за невірні паролі (Auth::login, ліміт на акаунт) —
        // власник довів доступ до пошти, тож може входити одразу.
        $pdo->prepare("DELETE FROM sys_login_log WHERE email = ? AND fail_reason = 'wrong_password'")
            ->execute([strtolower($user['email'])]);

        Response::ok([], 'Пароль змінено. Тепер увійдіть з новим паролем.');


    // ── ПЕРЕВІРКА СЕСІЇ ─────────────────────────────────────
    case 'check':
        $sess = Auth::requireAuth();
        $pdo  = Database::get();

        $isSuperAdmin   = Auth::isSuperAdmin($sess);
        $club           = null;
        $clubRole       = null;
        $permissions    = [];
        $menu           = [];
        $allowedPages   = null;
        $planIsFree     = false;
        $limitsExceeded = false;

        if ($sess['active_club_id']) {
            // Завантажуємо клуб (навіть заблокований)
            $stmt = $pdo->prepare("
                SELECT id, name, slug, city, logo_url, subscription_status, is_active
                FROM sys_clubs WHERE id = ? LIMIT 1
            ");
            $stmt->execute([$sess['active_club_id']]);
            $club = $stmt->fetch() ?: null;

            // Авто-виправлення is_active
            if ($club && !$club['is_active'] &&
                in_array($club['subscription_status'], ['active', 'trial'])) {
                $pdo->prepare("UPDATE sys_clubs SET is_active=1 WHERE id=?")
                    ->execute([$club['id']]);
                $club['is_active'] = 1;
            }

            if ($club) {
                // Роль у клубі. SuperAdmin у режимі клубу отримує роль "owner" за замовчуванням
                // (не фейковий universal-доступ — щоб бачити рівно те саме, що й реальний власник
                // клубу), АЛЕ може перемкнутись на manager/trainer через set_view_role, щоб швидко
                // перевірити, як застосунок виглядає й поводиться під іншою роллю (пошук багів).
                if ($isSuperAdmin) {
                    $viewRoleSlug = in_array($sess['view_as_role_slug'] ?? null, ['owner', 'manager', 'trainer'], true)
                        ? $sess['view_as_role_slug'] : 'owner';
                    $viewRoleStmt = $pdo->prepare("SELECT id AS role_id, slug, level FROM sys_roles WHERE slug = ? LIMIT 1");
                    $viewRoleStmt->execute([$viewRoleSlug]);
                    $clubRole = $viewRoleStmt->fetch() ?: ['role_id' => 2, 'slug' => 'owner', 'level' => 80];
                } else {
                    $roleStmt = $pdo->prepare("
                        SELECT r.id AS role_id, r.slug, r.level
                        FROM sys_user_clubs uc
                        JOIN sys_roles r ON r.id = uc.role_id
                        WHERE uc.user_id = ? AND uc.club_id = ? AND uc.is_active = 1
                        LIMIT 1
                    ");
                    $roleStmt->execute([$sess['user_id'], $sess['active_club_id']]);
                    $clubRole = $roleStmt->fetch() ?: ['role_id' => 2, 'slug' => 'owner', 'level' => 80];
                }

                $roleLevel   = (int)$clubRole['level'];
                $permissions = Auth::getPermissions($sess, (int)$club['id'], $isSuperAdmin ? (int)$clubRole['role_id'] : null);
                $menu        = Auth::getMenuSettings((int)$club['id'], $roleLevel);

                // ── Дозволені сторінки плану ──────────────────────────
                $planStmt = $pdo->prepare("
                    SELECT s.status, s.trial_ends_at,
                           p.allowed_pages, p.is_free,
                           p.clients_limit, p.users_limit, p.invoices_limit
                    FROM saas_subscriptions s
                    JOIN saas_plans p ON p.id = s.plan_id
                    WHERE s.club_id = ? LIMIT 1
                ");
                $planStmt->execute([$club['id']]);
                $planRow = $planStmt->fetch();

                // Лінивий авто-перехід тріалу, що минув — окремого cron на хостингу
                // немає, тож переводимо статус прямо тут, при кожному вході в клуб.
                if ($planRow && $planRow['status'] === 'trial' && $planRow['trial_ends_at']
                    && strtotime($planRow['trial_ends_at']) < strtotime('today')) {
                    $pdo->prepare("UPDATE saas_subscriptions SET status='trial_expired', updated_at=NOW() WHERE club_id=?")
                        ->execute([$club['id']]);
                    $pdo->prepare("UPDATE sys_clubs SET subscription_status='trial_expired' WHERE id=?")
                        ->execute([$club['id']]);
                    $planRow['status'] = 'trial_expired';
                    $club['subscription_status'] = 'trial_expired';
                }

                // Заблокована підписка (тріал минув / не оплачено / скасовано) —
                // доступ лише до сторінок безкоштовного плану, а не плану, що лишився в saas_subscriptions.
                $isBlocked = $planRow && in_array($planRow['status'], ['trial_expired', 'past_due', 'cancelled', 'deleted']);
                if ($isBlocked) {
                    $freePlan = $pdo->query("
                        SELECT allowed_pages, is_free, clients_limit, users_limit, invoices_limit
                        FROM saas_plans WHERE is_free = 1 AND is_active = 1 LIMIT 1
                    ")->fetch();
                    $planRow = $freePlan ?: $planRow;
                }

                $allowedPages  = $planRow ? json_decode($planRow['allowed_pages'] ?? 'null', true) : null;
                $planIsFree    = $planRow ? (bool)$planRow['is_free'] : false;
                // "Немає обмежень по клієнтах" — єдиний критерій для фіч, які масово додають
                // клієнтів (напр. імпорт з Excel), а не назва/slug тарифу (який може змінитись).
                $clientsUnlimited = $planRow ? ($planRow['clients_limit'] === null) : false;

                // Якщо підписки немає — статус "no_subscription" (не блокуємо)
                if (!$planRow && !$isSuperAdmin) {
                    $club['subscription_status'] = 'no_subscription';
                }

                // Перевірка чи перевищено ліміти (для Free-плану)
                $limitsExceeded = false;
                if ($planIsFree && $planRow) {
                    $usageStmt = $pdo->prepare("
                        SELECT
                            (SELECT COUNT(*) FROM clients WHERE club_id=?) AS clients_cnt,
                            (SELECT COUNT(*) FROM sys_user_clubs WHERE club_id=? AND is_active=1) AS users_cnt,
                            (SELECT COUNT(*) FROM client_invoices WHERE club_id=?
                             AND (end_date >= CURDATE() AND (visits_total IS NULL OR visits_used < visits_total))
                             AND status NOT IN ('frozen','cancelled')) AS inv_cnt
                    ");
                    $usageStmt->execute([$club['id'], $club['id'], $club['id']]);
                    $usage = $usageStmt->fetch();

                    $limitsExceeded =
                        ($planRow['clients_limit']  && (int)$usage['clients_cnt'] > (int)$planRow['clients_limit'])  ||
                        ($planRow['users_limit']    && (int)$usage['users_cnt']   > (int)$planRow['users_limit'])    ||
                        ($planRow['invoices_limit'] && (int)$usage['inv_cnt']     > (int)$planRow['invoices_limit']);
                }
            }
        }

        // ── Активна зміна каси ──────────────────────────────
        $activeShift = null;
        if ($sess['active_club_id']) {
            $shiftStmt = $pdo->prepare("
                SELECT id FROM cash_shifts
                WHERE club_id = ? AND closed_at IS NULL
                LIMIT 1
            ");
            $shiftStmt->execute([$sess['active_club_id']]);
            $shiftRow = $shiftStmt->fetch();
            $activeShift = $shiftRow ? (int)$shiftRow['id'] : null;
        }

        Response::ok([
            'authenticated' => true,
            'user' => [
                'id'           => $sess['user_id'],
                'full_name'    => $sess['full_name'],
                'email'        => $sess['email'],
                'global_role'  => $sess['global_role']  ?? null,
                'global_level' => (int)($sess['global_level'] ?? 0),
                'is_superadmin'=> $isSuperAdmin,
            ],
            'active_club'     => $club,
            'club_role'       => $clubRole,
            'in_club_mode'    => $isSuperAdmin && $club !== null,
            'permissions'     => $permissions,
            'menu'            => $menu,
            'allowed_pages'   => $allowedPages   ?? null,
            'plan_is_free'    => $planIsFree      ?? false,
            'clients_unlimited' => $clientsUnlimited ?? false,
            'limits_exceeded' => $limitsExceeded  ?? false,
            'active_shift_id' => $activeShift,
        ]);


    // ── МОЇ КЛУБИ ───────────────────────────────────────────
    case 'my_clubs':
        $sess = Auth::requireAuth();
        $pdo  = Database::get();

        if (Auth::isSuperAdmin($sess)) {
            $stmt = $pdo->query("
                SELECT c.id, c.name, c.slug, c.city, c.logo_url,
                       'superadmin' AS role_slug, 100 AS role_level
                FROM sys_clubs c
                WHERE c.is_active = 1
                ORDER BY c.name
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT c.id, c.name, c.slug, c.city, c.logo_url,
                       r.slug AS role_slug, r.level AS role_level
                FROM sys_user_clubs uc
                JOIN sys_clubs c ON c.id  = uc.club_id
                JOIN sys_roles r ON r.id  = uc.role_id
                WHERE uc.user_id = ? AND uc.is_active = 1 AND c.is_active = 1
                ORDER BY c.name
            ");
            $stmt->execute([$sess['user_id']]);
        }

        Response::ok(['clubs' => $stmt->fetchAll()]);


    // ── ПЕРЕМИКАННЯ КЛУБУ ────────────────────────────────────
    case 'switch_club':
        $sess   = Auth::requireAuth();
        $clubId = (int)($input['club_id'] ?? 0);
        if (!$clubId) Response::error('Вкажіть club_id');

        Auth::requireClubAccess($sess, $clubId, 30);

        // Скидаємо view_as_role_slug при переході в інший клуб — щоб SuperAdmin не переглядав
        // новий клуб "як тренер" за інерцією від попереднього і не сплутав це з реальним доступом.
        $token = hash('sha256', $_COOKIE[SESSION_COOKIE] ?? '');
        Database::get()->prepare("
            UPDATE sys_sessions SET active_club_id = ?, view_as_role_slug = NULL WHERE session_token = ?
        ")->execute([$clubId, $token]);

        Response::ok(['active_club_id' => $clubId], 'Клуб змінено');


    // ── ВИХІД З РЕЖИМУ КЛУБУ (тільки SuperAdmin) ─────────────
    // SuperAdmin повертається до системної панелі без клубу
    case 'exit_club':
        $sess = Auth::requireAuth();
        if (($sess['global_level'] ?? 0) < 100) {
            Response::forbidden('Тільки для SuperAdmin');
        }

        $token = hash('sha256', $_COOKIE[SESSION_COOKIE] ?? '');
        Database::get()->prepare("
            UPDATE sys_sessions SET active_club_id = NULL, view_as_role_slug = NULL WHERE session_token = ?
        ")->execute([$token]);

        Response::ok(['redirect' => '/clubs'], 'Повернуто до системної панелі');


    // ── ПЕРЕМИКАННЯ РОЛІ ПЕРЕГЛЯДУ (тільки SuperAdmin, у режимі клубу) ──
    // Дає SuperAdmin переглядати активний клуб під правами owner/manager/trainer
    // без створення тестового юзера — для швидкого пошуку багів у розмежуванні прав.
    case 'set_view_role':
        $sess = Auth::requireAuth();
        if (!Auth::isSuperAdmin($sess)) {
            Response::forbidden('Тільки для SuperAdmin');
        }
        if (!$sess['active_club_id']) {
            Response::error('Спершу увійдіть у клуб');
        }

        $roleSlug = trim($input['role_slug'] ?? '');
        if (!in_array($roleSlug, ['owner', 'manager', 'trainer'], true)) {
            Response::error('Невірна роль перегляду');
        }

        $token = hash('sha256', $_COOKIE[SESSION_COOKIE] ?? '');
        Database::get()->prepare("
            UPDATE sys_sessions SET view_as_role_slug = ? WHERE session_token = ?
        ")->execute([$roleSlug, $token]);

        Response::ok(['view_as_role' => $roleSlug], 'Режим перегляду змінено');


    default:
        Response::error("Невідома дія: {$action}", 400);

}} catch (RuntimeException $e) {
    // Помилки входу (невірний пароль, блокування, непідтверджений email) — НЕ 401:
    // на 401 фронтенд (api/client.js) перезавантажує /login і текст помилки губиться.
    $extra = $e->getCode() === Auth::ERR_EMAIL_NOT_VERIFIED ? ['reason' => 'email_not_verified'] : [];
    Response::error($e->getMessage(), 400, $extra);
} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}


header('Content-Type: application/json; charset=utf-8');

// Читаємо тіло запиту (JSON)
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';

// OPTIONS-preflight для CORS (браузер надсилає перед POST)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {

    switch ($action) {

        // ── ВХІД ────────────────────────────────────────────
        case 'login':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                Response::error('Метод не підтримується', 405);
            }

            $email    = trim($input['email']    ?? '');
            $password = trim($input['password'] ?? '');

            if (!$email || !$password) {
                Response::error('Введіть email і пароль');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Response::error('Невірний формат email');
            }

            // Auth::login кидає RuntimeException при помилці
            $result = Auth::login($email, $password);

            Response::ok([
                'user'     => [
                    'id'        => $result['user_id'],
                    'full_name' => $result['full_name'],
                    'email'     => $result['email'],
                ],
                'club_id'  => $result['active_club_id'],
                'redirect' => '/dashboard',
            ], 'Ласкаво просимо!');


        // ── ВИХІД ───────────────────────────────────────────
        case 'logout':
            Auth::logout();
            Response::ok([], 'До побачення!');


        // ── ПЕРЕВІРКА СЕСІЇ ─────────────────────────────────
        // Фронтенд викликає при кожному завантаженні сторінки
        // щоб перевірити — авторизований чи ні
        case 'check':
            $sess = Auth::requireAuth();

            $isSA = Auth::isSuperAdmin($sess);
            $club = null;
            if ($sess['active_club_id']) {
                $clubWhere2 = $isSA ? "WHERE id = ? LIMIT 1" : "WHERE id = ? AND is_active = 1 LIMIT 1";
                $stmt = Database::get()->prepare("
                    SELECT id, name, slug, city, logo_url
                    FROM sys_clubs
                    {$clubWhere2}
                ");
                $stmt->execute([$sess['active_club_id']]);
                $club = $stmt->fetch() ?: null;
            }

            Response::ok([
                'authenticated' => true,
                'user' => [
                    'id'          => $sess['user_id'],
                    'full_name'   => $sess['full_name'],
                    'email'       => $sess['email'],
                    'global_role' => $sess['global_role'] ?? null,
                    'global_level'=> (int)($sess['global_level'] ?? 0),
                ],
                'active_club' => $club,
            ]);


        // ── МОЇ КЛУБИ ───────────────────────────────────────
        case 'my_clubs':
            $sess = Auth::requireAuth();
            $pdo  = Database::get();

            // SuperAdmin бачить всі клуби
            if (Auth::isSuperAdmin($sess)) {
                $stmt = $pdo->query("
                    SELECT c.id, c.name, c.slug, c.city, c.logo_url,
                           'superadmin' AS role_slug, 100 AS role_level
                    FROM sys_clubs c
                    WHERE c.is_active = 1
                    ORDER BY c.name
                ");
            } else {
                $stmt = $pdo->prepare("
                    SELECT c.id, c.name, c.slug, c.city, c.logo_url,
                           r.slug AS role_slug, r.level AS role_level
                    FROM sys_user_clubs uc
                    JOIN sys_clubs c ON c.id = uc.club_id
                    JOIN sys_roles r ON r.id = uc.role_id
                    WHERE uc.user_id = ?
                      AND uc.is_active = 1
                      AND c.is_active  = 1
                    ORDER BY c.name
                ");
                $stmt->execute([$sess['user_id']]);
            }

            Response::ok(['clubs' => $stmt->fetchAll()]);


        // ── ПЕРЕМИКАННЯ АКТИВНОГО КЛУБУ ─────────────────────
        case 'switch_club':
            $sess   = Auth::requireAuth();
            $clubId = (int)($input['club_id'] ?? 0);
            if (!$clubId) Response::error('Вкажіть club_id');

            // Перевіряємо доступ до клубу
            Auth::requireClubAccess($sess, $clubId, 30);

            // Оновлюємо active_club_id у сесії
            $token = hash('sha256', $_COOKIE[SESSION_COOKIE] ?? '');
            Database::get()->prepare("
                UPDATE sys_sessions SET active_club_id = ?
                WHERE session_token = ?
            ")->execute([$clubId, $token]);

            Response::ok(['active_club_id' => $clubId], 'Клуб змінено');


        default:
            Response::error("Невідома дія: {$action}", 400);
    }

} catch (RuntimeException $e) {
    // Очікувані помилки (невірний пароль, брутфорс тощо)
    Response::error($e->getMessage(), 401);

} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());

} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}
