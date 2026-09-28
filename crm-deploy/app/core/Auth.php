<?php
/**
 * Auth.php — Авторизація та перевірка прав
 *
 * Використання в API:
 *   $sess   = Auth::requireAuth();
 *   $access = Auth::requireClubAccess($sess, $clubId);
 *   $can    = Auth::can($sess, $clubId, 'clients.create');
 *   $perms  = Auth::getPermissions($sess, $clubId);
 *   $menu   = Auth::getMenuSettings($clubId, $access['level']);
 */

class Auth
{
    // ── ПЕРЕВІРКА СЕСІЇ ─────────────────────────────────────

    public static function requireAuth(): array
    {
        $rawToken = $_COOKIE[SESSION_COOKIE] ?? '';
        if (!$rawToken) Response::unauthorized();

        $token = hash('sha256', $rawToken);
        $pdo   = Database::get();

        $stmt = $pdo->prepare("
            SELECT s.user_id, s.active_club_id, s.view_as_role_slug, s.expires_at,
                   u.email, u.full_name, u.is_active,
                   r.slug  AS global_role,
                   r.level AS global_level,
                   r.id    AS global_role_id
            FROM sys_sessions s
            JOIN sys_users u  ON u.id = s.user_id
            LEFT JOIN sys_roles r ON r.id = u.global_role_id
            WHERE s.session_token = ? AND s.expires_at > NOW()
        ");
        $stmt->execute([$token]);
        $sess = $stmt->fetch();

        if (!$sess || !$sess['is_active']) {
            self::clearCookie();
            Response::unauthorized('Сесія застаріла, увійдіть знову');
        }

        return $sess;
    }

    // ── ДОСТУП ДО КЛУБУ ─────────────────────────────────────

    /**
     * Перевіряє доступ до клубу.
     * Повертає ['role_id'=>..., 'slug'=>..., 'level'=>...]
     */
    public static function requireClubAccess(array $sess, int $clubId, int $minLevel = 30): array
    {
        if ((int)($sess['global_level'] ?? 0) >= 100) {
            return ['role_id' => 1, 'slug' => 'superadmin', 'level' => 100];
        }

        $pdo  = Database::get();
        $stmt = $pdo->prepare("
            SELECT r.id AS role_id, r.slug, r.level
            FROM sys_user_clubs uc
            JOIN sys_roles r ON r.id = uc.role_id
            WHERE uc.user_id = ? AND uc.club_id = ? AND uc.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$sess['user_id'], $clubId]);
        $access = $stmt->fetch();

        if (!$access || (int)$access['level'] < $minLevel) {
            Response::forbidden("Доступ до клубу #{$clubId} заборонено");
        }

        return $access;
    }

    // ── ХЕЛПЕРИ РІВНІВ ДОСТУПУ ──────────────────────────────

    /** SuperAdmin платформи (global_level >= 100) */
    public static function isSuperAdmin(array $sess): bool
    {
        return (int)($sess['global_level'] ?? 0) >= 100;
    }

    /** Менеджер і вище (level >= 50) — може писати */
    public static function canWrite(array $access): bool
    {
        return (int)$access['level'] >= 50;
    }

    /** Власник і вище (level >= 80) або SuperAdmin */
    public static function isOwner(array $sess, array $access): bool
    {
        return (int)$access['level'] >= 80 || self::isSuperAdmin($sess);
    }

    // ── ПЕРЕВІРКА ПРАВА ─────────────────────────────────────

    /**
     * Перевіряє чи має юзер конкретне право у клубі.
     *
     * Алгоритм:
     *   1. SuperAdmin → завжди true
     *   2. Шукаємо override у club_role_permissions
     *   3. Якщо override немає → беремо default з sys_role_permissions
     */
    public static function can(array $sess, int $clubId, string $permission): bool
    {
        if ((int)($sess['global_level'] ?? 0) >= 100) return true;

        $pdo    = Database::get();
        $access = self::requireClubAccess($sess, $clubId, 0);
        $roleId = (int)$access['role_id'];

        $override = $pdo->prepare("
            SELECT is_allowed FROM club_role_permissions
            WHERE club_id = ? AND role_id = ? AND permission_slug = ?
            LIMIT 1
        ");
        $override->execute([$clubId, $roleId, $permission]);
        $row = $override->fetch();
        if ($row !== false) return (bool)$row['is_allowed'];

        $default = $pdo->prepare("
            SELECT 1 FROM sys_role_permissions
            WHERE role_id = ? AND permission_slug = ? LIMIT 1
        ");
        $default->execute([$roleId, $permission]);

        return (bool)$default->fetchColumn();
    }

    /**
     * Повертає всі права юзера у клубі (slug => bool).
     * Передається на фронтенд у auth_api check.
     *
     * $forceRoleId — коли задано, права рахуються для ЦІЄЇ ролі, а не для сесії
     * (обходить global_level>=100 shortcut). Використовується для SuperAdmin у режимі
     * клубу: він має бачити рівно ті самі права, що й реальний власник клубу
     * (включно з club-специфічними overrides), а не universal-true.
     */
    public static function getPermissions(array $sess, int $clubId, ?int $forceRoleId = null): array
    {
        $pdo = Database::get();

        if ($forceRoleId === null && (int)($sess['global_level'] ?? 0) >= 100) {
            $rows = $pdo->query("SELECT slug FROM sys_permissions")->fetchAll();
            return array_fill_keys(array_column($rows, 'slug'), true);
        }

        $roleId = $forceRoleId ?? (int)self::requireClubAccess($sess, $clubId, 0)['role_id'];

        // Дефолтні права ролі
        $defaults = $pdo->prepare("
            SELECT permission_slug FROM sys_role_permissions WHERE role_id = ?
        ");
        $defaults->execute([$roleId]);
        $perms = array_fill_keys($defaults->fetchAll(PDO::FETCH_COLUMN), true);

        // Overrides клубу
        $overrides = $pdo->prepare("
            SELECT permission_slug, is_allowed FROM club_role_permissions
            WHERE club_id = ? AND role_id = ?
        ");
        $overrides->execute([$clubId, $roleId]);
        foreach ($overrides->fetchAll() as $row) {
            $perms[$row['permission_slug']] = (bool)$row['is_allowed'];
        }

        return $perms;
    }

    /**
     * Повертає видимість пунктів меню для юзера.
     * Дефолт — з рівня ролі. Override — з club_menu_settings.
     */
    public static function getMenuSettings(int $clubId, int $roleLevel): array
    {
        // Дефолтний мінімальний рівень для кожного пункту меню
        $defaults = [
            'dashboard' => 30,
            'clients'   => 30,
            'invoices'  => 30,
            'payments'  => 50,
            'tariffs'   => 50,
            'visits'    => 30,
            'products'  => 50,
            'sales'     => 50,
            'arrivals'  => 50,
            'finance'   => 50,
            'cash'      => 50,
            'trainers'  => 30,
            'users'     => 50,
            'billing'   => 80,
            'settings'  => 50,
        ];

        $pdo  = Database::get();
        $rows = $pdo->prepare("
            SELECT page_slug, min_role_level, is_visible
            FROM club_menu_settings WHERE club_id = ?
        ");
        $rows->execute([$clubId]);

        $overrides = [];
        foreach ($rows->fetchAll() as $r) {
            $overrides[$r['page_slug']] = [
                'min_level'  => (int)$r['min_role_level'],
                'is_visible' => (bool)$r['is_visible'],
            ];
        }

        $result = [];
        foreach ($defaults as $slug => $minLevel) {
            $ov              = $overrides[$slug] ?? null;
            $effectiveLevel  = $ov ? $ov['min_level']  : $minLevel;
            $isVisible       = $ov ? $ov['is_visible'] : true;
            $result[$slug]   = $isVisible && $roleLevel >= $effectiveLevel;
        }

        return $result;
    }

    // ── ВХІД ────────────────────────────────────────────────

    public static function login(string $email, string $password): array
    {
        $ip  = self::getIp();
        $pdo = Database::get();

        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS cnt FROM sys_login_log
            WHERE ip_address = ? AND success = 0
              AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$ip, LOGIN_BLOCK_SECS]);
        if ((int)$stmt->fetchColumn() >= MAX_LOGIN_FAILS) {
            self::logAttempt($email, $ip, false, 'blocked');
            throw new RuntimeException('Забагато спроб входу. Спробуйте через 15 хвилин.');
        }

        $stmt = $pdo->prepare("
            SELECT id, email, password_hash, full_name, global_role_id, is_active
            FROM sys_users WHERE email = ? LIMIT 1
        ");
        $stmt->execute([strtolower(trim($email))]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            self::logAttempt($email, $ip, false, 'wrong_password');
            throw new RuntimeException('Невірний email або пароль');
        }

        if (!$user['is_active']) {
            self::logAttempt($email, $ip, false, 'inactive');
            throw new RuntimeException('Акаунт заблоковано. Зверніться до адміністратора.');
        }

        $pdo->prepare("DELETE FROM sys_sessions WHERE user_id = ? AND expires_at < NOW()")
            ->execute([$user['id']]);

        $rawToken  = bin2hex(random_bytes(32));
        $hashToken = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
        $firstClub = self::getFirstClubId($pdo, $user['id'], $user['global_role_id']);

        $pdo->prepare("
            INSERT INTO sys_sessions
                (session_token, user_id, active_club_id, ip_address, user_agent, expires_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([
            $hashToken, $user['id'], $firstClub, $ip,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), $expiresAt,
        ]);

        setcookie(SESSION_COOKIE, $rawToken, [
            'expires'  => time() + SESSION_LIFETIME,
            'path'     => '/',
            'domain'   => COOKIE_DOMAIN,
            // Secure-кукі браузер узагалі не збереже на звичайному http (crm.test
            // локально) — тому вимикаємо лише там; на проді (https) лишається true.
            'secure'   => !IS_LOCAL_TEST,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $pdo->prepare("UPDATE sys_users SET last_login_at = NOW() WHERE id = ?")
            ->execute([$user['id']]);

        self::logAttempt($email, $ip, true, null);

        return [
            'user_id'        => $user['id'],
            'full_name'      => $user['full_name'],
            'email'          => $user['email'],
            'active_club_id' => $firstClub,
        ];
    }

    // ── ВИХІД ───────────────────────────────────────────────

    public static function logout(): void
    {
        $rawToken = $_COOKIE[SESSION_COOKIE] ?? '';
        if ($rawToken) {
            Database::get()
                ->prepare("DELETE FROM sys_sessions WHERE session_token = ?")
                ->execute([hash('sha256', $rawToken)]);
        }
        self::clearCookie();
    }

    // ── ПРИВАТНІ ────────────────────────────────────────────

    private static function getFirstClubId(PDO $pdo, int $userId, ?int $globalRoleId): ?int
    {
        if ($globalRoleId === 1) return null;

        $stmt = $pdo->prepare("
            SELECT club_id FROM sys_user_clubs
            WHERE user_id = ? AND is_active = 1 ORDER BY id LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ? (int)$row['club_id'] : null;
    }

    private static function logAttempt(string $email, string $ip, bool $success, ?string $reason): void
    {
        try {
            Database::get()->prepare("
                INSERT INTO sys_login_log (email, ip_address, success, fail_reason)
                VALUES (?, ?, ?, ?)
            ")->execute([$email, $ip, $success ? 1 : 0, $reason]);
        } catch (Throwable) {}
    }

    private static function clearCookie(): void
    {
        setcookie(SESSION_COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => COOKIE_DOMAIN,
            'secure'   => !IS_LOCAL_TEST,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function getIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) return trim(explode(',', $_SERVER[$key])[0]);
        }
        return '0.0.0.0';
    }
}
