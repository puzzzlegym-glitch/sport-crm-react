<?php
/**
 * register_api.php — Публічна реєстрація нового клубу
 *
 * Дії:
 *   register   — створити власника + клуб + тріал
 *   check_email — перевірити чи email вільний (для live-валідації)
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';
$pdo    = Database::get();

try { switch ($action) {

    // ── ПЕРЕВІРКА EMAIL (live, без авторизації) ──────────────
    case 'check_email':
        $email = strtolower(trim($input['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::ok(['available' => false, 'reason' => 'invalid']);
        }
        $exists = $pdo->prepare("SELECT 1 FROM sys_users WHERE email=? LIMIT 1");
        $exists->execute([$email]);
        Response::ok(['available' => !$exists->fetchColumn()]);


    // ── РЕЄСТРАЦІЯ ──────────────────────────────────────────
    case 'register':
        // Захист від спаму: не більше 3 реєстрацій з одного IP за годину
        $ip = Auth::getIp();

        $spamCheck = $pdo->prepare("
            SELECT COUNT(*) FROM saas_registrations
            WHERE ip_address = ?
              AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $spamCheck->execute([$ip]);
        if ((int)$spamCheck->fetchColumn() >= 3) {
            Response::error('Забагато спроб реєстрації. Спробуйте через годину.', 429);
        }

        // Валідація полів
        $ownerName = trim($input['owner_name'] ?? '');
        $email     = strtolower(trim($input['email'] ?? ''));
        $password  = $input['password'] ?? '';
        $password2 = $input['password2'] ?? '';
        $clubName  = trim($input['club_name'] ?? '');
        $clubCity  = trim($input['club_city'] ?? '');
        $planId    = (int)($input['plan_id'] ?? 2); // 2 = Business за замовчуванням

        $errors = [];
        if (strlen($ownerName) < 2)                         $errors[] = 'Введіть ваше ім\'я (мінімум 2 символи)';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))     $errors[] = 'Невірний формат email';
        if (strlen($password) < 8)                          $errors[] = 'Пароль — мінімум 8 символів';
        if ($password !== $password2)                       $errors[] = 'Паролі не збігаються';
        if (strlen($clubName) < 2)                          $errors[] = 'Введіть назву клубу';
        if (!empty($errors)) Response::error(implode('. ', $errors));

        // Перевірка що email вільний
        $dup = $pdo->prepare("SELECT 1 FROM sys_users WHERE email=? LIMIT 1");
        $dup->execute([$email]);
        if ($dup->fetchColumn()) Response::error('Цей email вже зареєстровано. Спробуйте увійти.', 409);

        // Slug клубу (URL-friendly)
        $slug = self_makeSlug($clubName, $pdo);

        // Транзакція: юзер → клуб → роль → підписка
        $pdo->beginTransaction();
        try {
            // 1. Власник
            $pwdHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare("
                INSERT INTO sys_users
                    (email, password_hash, full_name, global_role_id, is_active)
                VALUES (?, ?, ?, NULL, 1)
            ")->execute([$email, $pwdHash,
                htmlspecialchars($ownerName, ENT_NOQUOTES, 'UTF-8')]);
            $ownerId = (int)$pdo->lastInsertId();

            // 2. Клуб
            $trialEnds = date('Y-m-d H:i:s', strtotime('+' . Billing::TRIAL_DAYS . ' days'));
            $pdo->prepare("
                INSERT INTO sys_clubs
                    (owner_id, name, slug, city, is_active, subscription_status, trial_ends_at)
                VALUES (?, ?, ?, ?, 1, 'trial', ?)
            ")->execute([
                $ownerId,
                htmlspecialchars($clubName, ENT_NOQUOTES, 'UTF-8'),
                $slug,
                htmlspecialchars($clubCity, ENT_NOQUOTES, 'UTF-8') ?: null,
                $trialEnds,
            ]);
            $clubId = (int)$pdo->lastInsertId();

            // 3. Роль owner (id=2) у цьому клубі
            $pdo->prepare("
                INSERT INTO sys_user_clubs (user_id, club_id, role_id)
                VALUES (?, ?, 2)
            ")->execute([$ownerId, $clubId]);

            // 4. Підписка (тріал з обраним планом)
            Billing::createTrial($clubId, $planId);

            // 5. Лог реєстрації
            $pdo->prepare("
                INSERT INTO saas_registrations (club_id, owner_email, ip_address, user_agent)
                VALUES (?, ?, ?, ?)
            ")->execute([
                $clubId, $email, $ip,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);

            // 6. Токен підтвердження email (термін — 48 год)
            $verifyToken   = bin2hex(random_bytes(32));
            $tokenExpires  = date('Y-m-d H:i:s', time() + 172800);
            $pdo->prepare("
                UPDATE sys_users
                SET email_verify_token=?, email_verify_token_expires=?, is_active=0
                WHERE id=?
            ")->execute([$verifyToken, $tokenExpires, $ownerId]);

            $pdo->commit();

        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::serverError('Помилка реєстрації: ' . $e->getMessage());
        }

        // Листи після транзакції (поза try-catch щоб не відкатити через помилку пошти)
        $trialEndsFormatted = date('d.m.Y', strtotime('+' . Billing::TRIAL_DAYS . ' days'));

        // Email підтвердження (власнику)
        try {
            $verifyUrl = rtrim(APP_URL, '/') . '/api/register_api.php?action=verify_email&token=' . $verifyToken;
            $sent = Mailer::sendTemplate('verify_email', [
                'owner_name' => $ownerName,
                'club_name'  => $clubName,
                'verify_url' => $verifyUrl,
                'trial_ends' => $trialEndsFormatted,
            ], $email, $ownerName);
            // SMTP впав, а резервний mail() повернув false без винятку — лист не пішов.
            if (!$sent) throw new RuntimeException('Mailer returned false');
        } catch (Throwable $e) {
            // Якщо пошта не налаштована — активуємо одразу (не блокуємо)
            error_log('[Register] Verify email failed: ' . $e->getMessage());
            $pdo->prepare("UPDATE sys_users SET is_active=1 WHERE id=?")
                ->execute([$ownerId]);
        }

        // SuperAdmin
        try {
            $adminRow = $pdo->query("SELECT email FROM sys_users WHERE global_role_id=1 LIMIT 1")->fetch();
            if ($adminRow) {
                Mailer::sendTemplate('new_club_admin', [
                    'club_name'   => $clubName,
                    'club_city'   => $clubCity ?: '—',
                    'owner_name'  => $ownerName,
                    'owner_email' => $email,
                ], $adminRow['email']);
            }
        } catch (Throwable $e) {
            error_log('[Register] Admin notify failed: ' . $e->getMessage());
        }

        // Telegram: сповіщення SuperAdmin (не блокує основний потік)
        try {
            Telegram::notifySuperAdmins(
                "🆕 Нова реєстрація клубу: <b>{$clubName}</b>" . ($clubCity ? " ({$clubCity})" : '') .
                "\nВласник: {$ownerName} ({$email})"
            );
        } catch (Throwable $e) {
            error_log('[Register] Telegram notify failed: ' . $e->getMessage());
        }

        Response::ok([
            'club_id'    => $clubId,
            'trial_ends' => $trialEndsFormatted,
            'redirect'   => '/login',
        ], 'Реєстрацію завершено! Перевірте пошту.');


    // ── ПОВТОРНИЙ ЛИСТ ПІДТВЕРДЖЕННЯ ─────────────────────────────
    case 'resend_verification':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') Response::error('Метод не підтримується', 405);

        $email = strtolower(trim($input['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Response::error('Введіть коректний email');

        // Відповідь однакова незалежно від того, чи є такий акаунт — щоб не дати
        // перевіряти список зареєстрованих пошт.
        $okMessage = 'Якщо акаунт очікує підтвердження — ми надіслали новий лист. Перевірте також папку «Спам».';

        $stmt = $pdo->prepare("
            SELECT id, full_name, email_verify_token_expires FROM sys_users
            WHERE email = ? AND is_active = 0
              AND email_verify_token IS NOT NULL AND email_verified_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user) Response::ok([], $okMessage);

        // Не частіше разу на 5 хв (термін токена — 48 год, тож
        // "видано < 5 хв тому" ⇔ expires > now + 48 год − 5 хв).
        if ($user['email_verify_token_expires']
            && strtotime($user['email_verify_token_expires']) > time() + 172800 - 300) {
            Response::ok([], $okMessage);
        }

        $verifyToken  = bin2hex(random_bytes(32));
        $tokenExpires = date('Y-m-d H:i:s', time() + 172800);
        $pdo->prepare("UPDATE sys_users SET email_verify_token=?, email_verify_token_expires=? WHERE id=?")
            ->execute([$verifyToken, $tokenExpires, $user['id']]);

        $clubStmt = $pdo->prepare("SELECT name, trial_ends_at FROM sys_clubs WHERE owner_id=? ORDER BY id LIMIT 1");
        $clubStmt->execute([$user['id']]);
        $clubRow = $clubStmt->fetch() ?: [];

        $sent = false;
        try {
            $sent = Mailer::sendTemplate('verify_email', [
                'owner_name' => $user['full_name'],
                'club_name'  => $clubRow['name'] ?? '',
                'verify_url' => rtrim(APP_URL, '/') . '/api/register_api.php?action=verify_email&token=' . $verifyToken,
                'trial_ends' => !empty($clubRow['trial_ends_at']) ? date('d.m.Y', strtotime($clubRow['trial_ends_at'])) : '—',
            ], $email, $user['full_name']);
        } catch (Throwable $e) {
            error_log('[Register] Resend verify email failed: ' . $e->getMessage());
        }

        if (!$sent) {
            // Пошта не працює — не лишаємо людину без доступу (як і при реєстрації).
            $pdo->prepare("UPDATE sys_users SET is_active=1 WHERE id=?")->execute([$user['id']]);
            Response::ok(['activated' => true], 'Не вдалося надіслати лист, тому акаунт активовано. Можете входити.');
        }

        Response::ok([], $okMessage);


    // ── ПІДТВЕРДЖЕННЯ EMAIL ────────────────────────────────────
    case 'verify_email':
        $token = trim($_GET['token'] ?? '');
        if (!$token) Response::error('Невірне посилання', 400);

        $stmt = $pdo->prepare("
            SELECT id, full_name, email FROM sys_users
            WHERE email_verify_token=? AND is_active=0
              AND (email_verify_token_expires IS NULL OR email_verify_token_expires > NOW())
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            // Можливо вже підтверджено або токен невірний
            header('Location: /login?verified=already');
            exit;
        }

        // Активуємо
        $pdo->prepare("
            UPDATE sys_users
            SET is_active=1, email_verify_token=NULL, email_verified_at=NOW()
            WHERE id=?
        ")->execute([$user['id']]);

        // Welcome email після підтвердження
        try {
            $clubStmt = $pdo->prepare("SELECT name, trial_ends_at FROM sys_clubs WHERE owner_id=? LIMIT 1");
            $clubStmt->execute([$user['id']]);
            $clubRow  = $clubStmt->fetch();
            $clubName = $clubRow['name'] ?? '';
            $trialEnd = $clubRow['trial_ends_at']
                ? date('d.m.Y', strtotime($clubRow['trial_ends_at']))
                : '—';
            Mailer::sendTemplate('welcome', [
                'owner_name' => $user['full_name'],
                'club_name'  => $clubName,
                'trial_ends' => $trialEnd,
            ], $user['email'], $user['full_name']);
        } catch (Throwable $e) {}

        header('Location: /login?verified=1');
        exit;


    default:
        Response::error("Невідома дія: {$action}", 400);

}} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}

// ── Генерація унікального slug ───────────────────────────────
function self_makeSlug(string $name, PDO $pdo): string
{
    // Транслітерація (спрощена)
    $map = [
        'а'=>'a','б'=>'b','в'=>'v','г'=>'h','д'=>'d','е'=>'e','є'=>'ye',
        'ж'=>'zh','з'=>'z','и'=>'y','і'=>'i','ї'=>'yi','й'=>'y','к'=>'k',
        'л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s',
        'т'=>'t','у'=>'u','ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh',
        'щ'=>'shch','ь'=>'','ю'=>'yu','я'=>'ya',
    ];
    $slug = strtolower(strtr(mb_strtolower($name, 'UTF-8'), $map));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-') ?: 'club';

    // Якщо slug зайнятий — додаємо число
    $original = $slug;
    $i = 1;
    while (true) {
        $exists = $pdo->prepare("SELECT 1 FROM sys_clubs WHERE slug=? LIMIT 1");
        $exists->execute([$slug]);
        if (!$exists->fetchColumn()) break;
        $slug = $original . '-' . $i++;
        if ($i > 99) { $slug = $original . '-' . time(); break; }
    }

    return $slug;
}