<?php
/**
 * users_api.php — Управління співробітниками клубу
 *
 * Дії:
 *   get_list        — список співробітників клубу
 *   get_one         — один співробітник
 *   invite          — запросити нового (створити акаунт + прив'язати до клубу)
 *   update_role     — змінити роль
 *   toggle_access   — увімкнути / вимкнути доступ
 *   remove          — видалити з клубу
 *   get_roles       — список доступних ролей
 *   update_profile  — редагувати свій профіль
 *   change_password — змінити свій пароль
 *   check_email     — перевірити email (для live-валідації)
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$sess   = Auth::requireAuth();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';
$pdo    = Database::get();

$clubId = (int)($input['club_id'] ?? $_GET['club_id'] ?? $sess['active_club_id'] ?? 0);

// Особисті дії — не потребують club_id
$personalActions = ['update_profile', 'change_password', 'get_roles'];

if (!in_array($action, $personalActions)) {
    if (!$clubId) Response::error('Не обрано клуб', 400);
    $access = Auth::requireClubAccess($sess, $clubId, 30);
}

try { switch ($action) {

    // ════ СПИСОК СПІВРОБІТНИКІВ ══════════════════════════════
    case 'get_list':
        $showHidden = Auth::can($sess, $clubId, 'users.manage')
            && !empty($input['show_hidden'] ?? $_GET['show_hidden'] ?? false);

        $stmt = $pdo->prepare("
            SELECT
                u.id, u.full_name, u.email, u.phone,
                u.last_login_at, u.is_active, u.created_at,
                r.id      AS role_id,
                r.slug    AS role_slug,
                r.name_ua AS role_name,
                r.level   AS role_level,
                uc.is_active AS club_access,
                uc.granted_at,
                g.full_name  AS granted_by_name
            FROM sys_user_clubs uc
            JOIN sys_users u ON u.id  = uc.user_id
            JOIN sys_roles r ON r.id  = uc.role_id
            LEFT JOIN sys_users g ON g.id = uc.granted_by
            WHERE uc.club_id = ?
              AND r.slug != 'superadmin'
              " . ($showHidden ? '' : 'AND uc.is_active = 1') . "
            ORDER BY r.level DESC, u.full_name ASC
        ");
        $stmt->execute([$clubId]);

        // Ліміт плану
        $limStmt = $pdo->prepare("
            SELECT p.users_limit FROM saas_subscriptions s
            JOIN saas_plans p ON p.id = s.plan_id WHERE s.club_id = ?
        ");
        $limStmt->execute([$clubId]);
        $limRow = $limStmt->fetch();

        Response::ok([
            'users'      => $stmt->fetchAll(),
            'plan_limit' => $limRow ? $limRow['users_limit'] : null,
        ]);


    // ════ ОДИН СПІВРОБІТНИК ══════════════════════════════════
    case 'get_one':
        $userId = (int)($input['user_id'] ?? $_GET['user_id'] ?? 0);
        if (!$userId) Response::error('Не вказано user_id');

        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name, u.email, u.phone,
                   u.last_login_at, u.is_active, u.created_at,
                   r.slug AS role_slug, r.name_ua AS role_name, r.level AS role_level,
                   uc.is_active AS club_access, uc.granted_at
            FROM sys_user_clubs uc
            JOIN sys_users u ON u.id = uc.user_id
            JOIN sys_roles r ON r.id = uc.role_id
            WHERE uc.club_id = ? AND uc.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$clubId, $userId]);
        $user = $stmt->fetch();
        if (!$user) Response::error('Співробітника не знайдено', 404);

        Response::ok(['user' => $user]);


    // ════ ЗАПРОСИТИ СПІВРОБІТНИКА ════════════════════════════
    case 'invite':
        $myLevel = ($sess['global_level'] ?? 0) >= 100 ? 100 : ($access['level'] ?? 0);
        if (!Auth::can($sess, $clubId, 'users.manage')) Response::forbidden('Запрошувати можуть лише власники клубу');
        Billing::requireWriteAccess($clubId);

        $email    = strtolower(trim($input['email']     ?? ''));
        $fullName = trim($input['full_name'] ?? '');
        $roleSlug = trim($input['role_slug'] ?? 'manager');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Response::error('Невірний формат email');
        if (strlen($fullName) < 2) Response::error('Введіть ім\'я співробітника');
        if (!in_array($roleSlug, ['owner', 'manager', 'trainer'])) {
            Response::error('Роль може бути: owner, manager або trainer');
        }

        $roleRow = $pdo->prepare("SELECT id, level FROM sys_roles WHERE slug = ?");
        $roleRow->execute([$roleSlug]);
        $role = $roleRow->fetch();
        if (!$role) Response::error('Роль не знайдена');

        if ((int)$role['level'] >= $myLevel) {
            Response::forbidden('Не можна призначити роль вищу або рівну своїй');
        }

        $pdo->beginTransaction();
        try {
            $existStmt = $pdo->prepare("SELECT id FROM sys_users WHERE email = ? LIMIT 1");
            $existStmt->execute([$email]);
            $existingId = $existStmt->fetchColumn();

            if ($existingId) {
                // Перевіряємо чи є запис у клубі (активний або деактивований)
                $ucStmt = $pdo->prepare("
                    SELECT id, is_active, role_id FROM sys_user_clubs
                    WHERE user_id = ? AND club_id = ? LIMIT 1
                ");
                $ucStmt->execute([$existingId, $clubId]);
                $ucRow = $ucStmt->fetch();

                if ($ucRow) {
                    if ($ucRow['is_active']) {
                        // Активний запис — справжній дублікат
                        $pdo->rollBack();
                        Response::error('Цей співробітник вже активний у клубі', 409);
                    }
                    // Деактивований — реактивуємо з новою роллю (нова активна людина в клубі)
                    Billing::checkTeamLimit($clubId);
                    $pdo->prepare("
                        UPDATE sys_user_clubs SET is_active=1, role_id=?, granted_by=?
                        WHERE id=?
                    ")->execute([$role['id'], $sess['user_id'], $ucRow['id']]);
                } else {
                    // Юзер є в системі але не в цьому клубі — додаємо
                    Billing::checkTeamLimit($clubId);
                    $pdo->prepare("
                        INSERT INTO sys_user_clubs (user_id, club_id, role_id, is_active, granted_by)
                        VALUES (?, ?, ?, 1, ?)
                    ")->execute([$existingId, $clubId, $role['id'], $sess['user_id']]);
                }
                $userId = (int)$existingId;
                $isNew  = false;
                $tmpPwd = null;
            } else {
                // Новий юзер у системі — теж нова активна людина в клубі
                Billing::checkTeamLimit($clubId);
                $tmpPwd = implode('-', [
                    substr(str_shuffle('abcdefghjkmnpqrstuvwxyz'), 0, 4),
                    substr(str_shuffle('23456789'), 0, 4),
                    substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ'), 0, 4),
                ]);
                $pdo->prepare("
                    INSERT INTO sys_users (email, password_hash, full_name, is_active)
                    VALUES (?, ?, ?, 1)
                ")->execute([
                    $email,
                    password_hash($tmpPwd, PASSWORD_BCRYPT, ['cost' => 12]),
                    htmlspecialchars($fullName, ENT_NOQUOTES, 'UTF-8'),
                ]);
                $userId = (int)$pdo->lastInsertId();
                $isNew  = true;

                $pdo->prepare("
                    INSERT INTO sys_user_clubs (user_id, club_id, role_id, is_active, granted_by)
                    VALUES (?, ?, ?, 1, ?)
                ")->execute([$userId, $clubId, $role['id'], $sess['user_id']]);
            }

            // Синхронізація club_trainers: профіль тренера незалежний від головної
            // ролі членства (щоб власник/менеджер міг одночасно бути тренером —
            // вимикається вручну через паузу на сторінці "Тренери", не тут).
            if ($roleSlug === 'trainer') {
                $ctStmt = $pdo->prepare("SELECT id FROM club_trainers WHERE club_id=? AND user_id=? LIMIT 1");
                $ctStmt->execute([$clubId, $userId]);
                $ctId = $ctStmt->fetchColumn();
                if ($ctId) {
                    $pdo->prepare("UPDATE club_trainers SET is_active=1 WHERE id=?")
                        ->execute([$ctId]);
                } else {
                    $pdo->prepare("
                        INSERT INTO club_trainers (club_id, user_id, work_type, personal_earn_type, personal_earn_value)
                        VALUES (?, ?, 'employee', 'percent', 50)
                    ")->execute([$clubId, $userId]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::serverError($e->getMessage());
        }

        // Email запрошення
        try {
            $clubNameStmt = $pdo->prepare("SELECT name FROM sys_clubs WHERE id = ? LIMIT 1");
            $clubNameStmt->execute([$clubId]);
            $clubName = $clubNameStmt->fetchColumn() ?: 'Sport CRM';

            $roleLabel = match($roleSlug) {
                'owner'   => 'Власник',
                'trainer' => 'Тренер',
                default   => 'Менеджер',
            };

            if ($isNew && $tmpPwd) {
                // Новий юзер — відправляємо логін+пароль
                Mailer::send(
                    $email,
                    "Запрошення до {$clubName} — Sport CRM",
                    self_buildInviteEmail($fullName, $clubName, $roleLabel, $email, $tmpPwd),
                    $fullName
                );
            } else {
                // Існуючий юзер — повідомляємо про додавання до клубу
                Mailer::sendTemplate('club_invite', [
                    'full_name'  => $fullName,
                    'club_name'  => $clubName,
                    'role_label' => $roleLabel,
                ], $email, $fullName);
            }
        } catch (Throwable $e) {
            error_log('[Users] Invite email error: ' . $e->getMessage());
        }

        Response::ok(
            ['user_id' => $userId, 'is_new' => $isNew, 'tmp_pwd' => $tmpPwd],
            $isNew
                ? "Акаунт створено. Дані входу надіслано на {$email}"
                : "Співробітника додано до клубу"
        );


    // ════ ЗМІНИТИ РОЛЬ ══════════════════════════════════════
    case 'update_role':
        $myLevel = ($sess['global_level'] ?? 0) >= 100 ? 100 : ($access['level'] ?? 0);
        if (!Auth::can($sess, $clubId, 'users.manage')) Response::forbidden('Змінювати ролі можуть лише власники');

        $userId   = (int)($input['user_id']  ?? 0);
        $roleSlug = trim($input['role_slug'] ?? '');

        if (!$userId || !$roleSlug) Response::error('Вкажіть user_id і role_slug');
        if ($userId === (int)$sess['user_id']) Response::error('Не можна змінити власну роль');

        $roleRow = $pdo->prepare("SELECT id, level FROM sys_roles WHERE slug = ?");
        $roleRow->execute([$roleSlug]);
        $role = $roleRow->fetch();
        if (!$role) Response::error('Роль не знайдена');

        if ((int)$role['level'] >= $myLevel) {
            Response::forbidden('Не можна призначити роль вищу або рівну своїй');
        }

        Billing::requireWriteAccess($clubId);
        // Ліміт команди тут НЕ перевіряємо: юзер уже активний член клубу,
        // зміна головної ролі не додає нову людину — headcount не змінюється.

        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE sys_user_clubs SET role_id = ?
                WHERE user_id = ? AND club_id = ?
            ")->execute([$role['id'], $userId, $clubId]);

            // Синхронізація club_trainers: профіль тренера незалежний від
            // головної ролі членства (щоб власник/менеджер міг одночасно бути
            // тренером — вимикається вручну через паузу на сторінці "Тренери").
            if ($roleSlug === 'trainer') {
                $ctStmt = $pdo->prepare("SELECT id FROM club_trainers WHERE club_id=? AND user_id=? LIMIT 1");
                $ctStmt->execute([$clubId, $userId]);
                $ctId = $ctStmt->fetchColumn();
                if ($ctId) {
                    $pdo->prepare("UPDATE club_trainers SET is_active=1 WHERE id=?")
                        ->execute([$ctId]);
                } else {
                    $pdo->prepare("
                        INSERT INTO club_trainers (club_id, user_id, work_type, personal_earn_type, personal_earn_value)
                        VALUES (?, ?, 'employee', 'percent', 50)
                    ")->execute([$clubId, $userId]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::serverError($e->getMessage());
        }

        Response::ok([], 'Роль змінено');


    // ════ УВІМК / ВИМК ДОСТУП ═══════════════════════════════
    case 'toggle_access':
        $myLevel = ($sess['global_level'] ?? 0) >= 100 ? 100 : ($access['level'] ?? 0);
        if (!Auth::can($sess, $clubId, 'users.manage')) Response::forbidden();

        $userId  = (int)($input['user_id'] ?? 0);
        $enabled = isset($input['enabled']) ? (bool)$input['enabled'] : true;

        if (!$userId) Response::error('Не вказано user_id');
        if ($userId === (int)$sess['user_id']) {
            Response::error('Не можна вимкнути власний доступ');
        }

        if ($enabled) {
            // Реактивація — це нова активна людина в команді, гейтимо тим самим
            // лімітом, що й запрошення (щоб не можна було обійти auto-block
            // просто увімкнувши назад заблокованого учасника).
            $wasActiveStmt = $pdo->prepare("SELECT is_active FROM sys_user_clubs WHERE user_id=? AND club_id=? LIMIT 1");
            $wasActiveStmt->execute([$userId, $clubId]);
            if (!(int)($wasActiveStmt->fetchColumn() ?: 0)) {
                Billing::requireWriteAccess($clubId);
                Billing::checkTeamLimit($clubId);
            }
        }

        $pdo->prepare("
            UPDATE sys_user_clubs SET is_active = ?
            WHERE user_id = ? AND club_id = ?
        ")->execute([$enabled ? 1 : 0, $userId, $clubId]);

        Response::ok([], $enabled ? 'Доступ відновлено' : 'Доступ вимкнено');


    // ════ ВИДАЛИТИ З КЛУБУ ══════════════════════════════════
    case 'remove':
        $myLevel = ($sess['global_level'] ?? 0) >= 100 ? 100 : ($access['level'] ?? 0);
        if (!Auth::can($sess, $clubId, 'users.manage')) Response::forbidden();

        $userId = (int)($input['user_id'] ?? 0);
        if (!$userId) Response::error('Не вказано user_id');
        if ($userId === (int)$sess['user_id']) {
            Response::error('Не можна видалити себе з клубу');
        }

        $pdo->prepare("
            DELETE FROM sys_user_clubs WHERE user_id = ? AND club_id = ?
        ")->execute([$userId, $clubId]);

        Response::ok([], 'Співробітника видалено з клубу');


    // ════ СПИСОК РОЛЕЙ ══════════════════════════════════════
    case 'get_roles':
        $myLevel = ($sess['global_level'] ?? 0) >= 100 ? 100 : 80;
        $stmt = $pdo->prepare("
            SELECT id, slug, name_ua, level
            FROM sys_roles
            WHERE level < ? AND slug != 'superadmin'
            ORDER BY level DESC
        ");
        $stmt->execute([$myLevel]);
        Response::ok(['roles' => $stmt->fetchAll()]);


    // ════ РЕДАГУВАТИ СВІЙ ПРОФІЛЬ ════════════════════════════
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


    // ════ ЗМІНИТИ ПАРОЛЬ ════════════════════════════════════
    case 'change_password':
        $current = $input['current_password'] ?? '';
        $new     = $input['new_password']     ?? '';
        $confirm = $input['confirm_password'] ?? '';

        if (!$current || !$new) Response::error('Заповніть всі поля');
        if (strlen($new) < 8)  Response::error('Новий пароль — мінімум 8 символів');
        if ($new !== $confirm)  Response::error('Паролі не збігаються');

        $userRow = $pdo->prepare("SELECT password_hash FROM sys_users WHERE id = ?");
        $userRow->execute([$sess['user_id']]);
        $user = $userRow->fetch();

        if (!$user || !password_verify($current, $user['password_hash'])) {
            Response::error('Поточний пароль невірний', 401);
        }

        $pdo->prepare("UPDATE sys_users SET password_hash = ? WHERE id = ?")
            ->execute([
                password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]),
                $sess['user_id'],
            ]);

        // Інвалідуємо всі інші сесії
        $currentToken = hash('sha256', $_COOKIE[SESSION_COOKIE] ?? '');
        $pdo->prepare("
            DELETE FROM sys_sessions WHERE user_id = ? AND session_token != ?
        ")->execute([$sess['user_id'], $currentToken]);

        Response::ok([], 'Пароль змінено. Всі інші сесії завершено.');


    // ════ ПЕРЕВІРКА EMAIL ════════════════════════════════════
    case 'check_email':
        $email = strtolower(trim($input['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::ok(['status' => 'invalid']);
        }

        $existStmt = $pdo->prepare("SELECT full_name FROM sys_users WHERE email = ? LIMIT 1");
        $existStmt->execute([$email]);
        $found = $existStmt->fetch();

        if ($found) {
            $inClub = $pdo->prepare("
                SELECT 1 FROM sys_user_clubs uc
                JOIN sys_users u ON u.id = uc.user_id
                WHERE u.email = ? AND uc.club_id = ?
            ");
            $inClub->execute([$email, $clubId]);
            if ($inClub->fetchColumn()) {
                Response::ok(['status' => 'already_in_club', 'name' => $found['full_name']]);
            }
            Response::ok(['status' => 'exists', 'name' => $found['full_name']]);
        }
        Response::ok(['status' => 'new']);


    default:
        Response::error("Невідома дія: {$action}", 400);

}} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}

// ── Email запрошення ─────────────────────────────────────────
function self_buildInviteEmail(
    string $name, string $club, string $role,
    string $email, string $tmpPwd
): string {
    $url = APP_URL . '/login';
    return "<!DOCTYPE html><html lang='uk'><head><meta charset='UTF-8'></head>
    <body style='background:#0f1117;font-family:Arial,sans-serif;color:#e2e8f0;padding:40px 20px'>
    <table width='540' cellpadding='0' cellspacing='0' style='margin:0 auto;background:#1a1f2e;
           border-radius:12px;border:1px solid #252d40;'>
      <tr><td style='padding:24px 32px;border-bottom:1px solid #252d40;font-size:18px;font-weight:700'>
        Sport CRM
      </td></tr>
      <tr><td style='padding:32px;font-size:15px;line-height:1.7'>
        <p>Привіт, <strong>{$name}</strong>!</p>
        <p>Вас додано до клубу <strong>«{$club}»</strong> як <strong>{$role}</strong>.</p>
        <table style='width:100%;background:#0f1117;border-radius:8px;padding:16px;
                      margin:20px 0;font-size:14px;border-collapse:collapse'>
          <tr><td style='padding:6px 0;color:#718096'>Email</td>
              <td style='padding:6px 0'>{$email}</td></tr>
          <tr><td style='padding:6px 0;color:#718096'>Пароль</td>
              <td style='padding:6px 0;font-weight:700;color:#4f9cf9'>{$tmpPwd}</td></tr>
        </table>
        <p style='color:#718096;font-size:13px'>Змініть пароль після першого входу.</p>
        <p style='margin-top:24px'>
          <a href='{$url}' style='background:#4f9cf9;color:#fff;padding:12px 24px;
             border-radius:8px;text-decoration:none;font-weight:600'>Увійти →</a>
        </p>
      </td></tr>
    </table></body></html>";
}