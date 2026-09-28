<?php
/**
 * Billing.php — Управління підписками SaaS
 *
 * Відповідає за:
 *  - перевірку статусу підписки при кожному запиті
 *  - створення підписки при реєстрації (тріал)
 *  - активацію після оплати
 *  - автоматичне закінчення тріалу
 *  - перевірку лімітів (клієнти, співробітники)
 *
 * Використання:
 *   // Перевірка що клуб може писати дані
 *   Billing::requireWriteAccess($clubId);
 *
 *   // Перевірка ліміту клієнтів
 *   Billing::checkClientLimit($clubId);
 */

class Billing
{
    // Кількість днів тріалу
    const TRIAL_DAYS = 14;

    // Днів після закінчення до видалення
    const DELETE_AFTER_DAYS = 90;

    // ── ПЕРЕВІРКА ДОСТУПУ ────────────────────────────────────

    /**
     * Перевіряє чи клуб може змінювати дані (POST-запити).
     * Якщо ні — повертає 403 з деталями.
     */
    public static function requireWriteAccess(int $clubId): void
    {
        $status = self::getStatus($clubId);

        if (in_array($status, ['trial', 'active'])) {
            return; // Дозволено
        }

        $messages = [
            'trial_expired' => [
                'code'    => 'trial_expired',
                'message' => 'Тріал закінчився. Оберіть план підписки для продовження роботи.',
                'action'  => '/billing',
            ],
            'past_due' => [
                'code'    => 'past_due',
                'message' => 'Підписка прострочена. Поновіть оплату.',
                'action'  => '/billing',
            ],
            'cancelled' => [
                'code'    => 'cancelled',
                'message' => 'Підписку скасовано. Оберіть план для відновлення.',
                'action'  => '/billing',
            ],
            'deleted' => [
                'code'    => 'deleted',
                'message' => 'Доступ до цього клубу закрито.',
                'action'  => '/login',
            ],
        ];

        $info = $messages[$status] ?? [
            'code'    => 'no_subscription',
            'message' => 'Немає активної підписки.',
            'action'  => '/billing',
        ];

        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'          => false,
            'billing_error'    => true,
            'billing_code'     => $info['code'],
            'error'            => $info['message'],
            'billing_action'   => $info['action'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Повертає поточний статус підписки клубу.
     * Попутно оновлює статус якщо тріал закінчився.
     */
    public static function getStatus(int $clubId): string
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare("
            SELECT s.status, s.trial_ends_at, s.current_period_end
            FROM saas_subscriptions s
            WHERE s.club_id = ?
            LIMIT 1
        ");
        $stmt->execute([$clubId]);
        $sub = $stmt->fetch();

        if (!$sub) return 'no_subscription';

        $status = $sub['status'];

        // Автоматичне закінчення тріалу
        if ($status === 'trial' && $sub['trial_ends_at'] && strtotime($sub['trial_ends_at']) < time()) {
            $status = 'trial_expired';
            self::updateStatus($clubId, 'trial_expired');

            // Надсилаємо email власнику (один раз)
            self::notifyTrialExpired($clubId);
        }

        // Автоматичне прострочення підписки
        if ($status === 'active' && $sub['current_period_end'] && strtotime($sub['current_period_end']) < time()) {
            $status = 'past_due';
            self::updateStatus($clubId, 'past_due');
        }

        return $status;
    }

    /**
     * Повна інформація про підписку для сторінки білінгу.
     */
    public static function getInfo(int $clubId): array
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare("
            SELECT
                s.*,
                p.slug       AS plan_slug,
                p.name       AS plan_name,
                p.price_monthly,
                p.clients_limit,
                p.users_limit,
                p.invoices_limit,
                p.features,
                -- Дні до кінця тріалу
                GREATEST(0, DATEDIFF(s.trial_ends_at, NOW())) AS trial_days_left
            FROM saas_subscriptions s
            JOIN saas_plans p ON p.id = s.plan_id
            WHERE s.club_id = ?
        ");
        $stmt->execute([$clubId]);
        $info = $stmt->fetch();

        if (!$info) return [];

        // Поточне використання — prepared statements (захист від SQL-ін'єкції)
        // users_limit = ліміт команди (owner+manager+trainer разом, по людині).
        return array_merge($info, [
            'clients_used'   => self::countActiveClients($clubId),
            'users_used'     => self::countActiveTeam($clubId),
            'invoices_used'  => self::countActiveInvoices($clubId),
            'features'       => json_decode($info['features'] ?? '[]', true),
        ]);
    }

    // ── ПЕРЕВІРКА ЛІМІТІВ ────────────────────────────────────

    /**
     * SQL-вираз для "активний абонемент" (без залежності від status).
     * Активний = не скасований, не заморожений, дата не минула, візити не вичерпані
     * (visits_total NULL = безліміт — раніше такі абонементи не рахувались).
     */
    private static function activeInvoiceExpr(): string
    {
        return "status NOT IN ('cancelled','frozen')
            AND end_date >= CURDATE()
            AND (visits_total IS NULL OR visits_total = 0 OR visits_used < visits_total)";
    }

    /**
     * SQL-вираз ефективного статусу абонемента (5-статусна модель:
     * future/active/frozen/finished/cancelled). Дублює $EFFECTIVE_STATUS_SQL
     * з invoices_api.php — навмисно (той файл не чіпаємо, він уже покритий
     * бізнес-правилами); тримати обидва в синхроні при зміні статусної моделі.
     */
    private static function effectiveInvoiceStatusExpr(string $alias = 'ci'): string
    {
        return "(CASE
            WHEN {$alias}.status = 'cancelled' THEN 'cancelled'
            WHEN {$alias}.status = 'frozen' THEN 'frozen'
            WHEN {$alias}.start_date > CURDATE() THEN 'future'
            WHEN {$alias}.end_date < CURDATE() THEN 'finished'
            WHEN {$alias}.visits_total IS NOT NULL AND {$alias}.visits_used >= {$alias}.visits_total THEN 'finished'
            ELSE 'active'
        END)";
    }

    /**
     * Кількість "активних" клієнтів клубу — клієнтів, що мають хоча б один
     * абонемент зі статусом future/active/frozen. Архівна історія (finished/
     * cancelled) на лічильник не впливає.
     */
    public static function countActiveClients(int $clubId): int
    {
        $pdo  = Database::get();
        $expr = self::effectiveInvoiceStatusExpr('ci');
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT ci.client_id)
            FROM client_invoices ci
            WHERE ci.club_id = ? AND {$expr} IN ('future','active','frozen')
        ");
        $stmt->execute([$clubId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Кількість активних учасників команди клубу — ВСІ активні членства
     * (owner + manager + trainer, по одному на людину). Власник рахується
     * з дня реєстрації незалежно від того, чи має він профіль тренера —
     * самопризначення тренером (club_trainers) НЕ змінює цей лічильник,
     * бо це та сама людина, а не нова. Слот займає лише запрошення/
     * реактивація НОВОГО активного члена (будь-якої ролі).
     */
    public static function countActiveTeam(int $clubId): int
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sys_user_clubs WHERE club_id = ? AND is_active = 1");
        $stmt->execute([$clubId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Повертає кількість активних абонементів клубу.
     */
    public static function countActiveInvoices(int $clubId): int
    {
        $pdo  = Database::get();
        $expr = self::activeInvoiceExpr();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM client_invoices
            WHERE club_id = ? AND {$expr}
        ");
        $stmt->execute([$clubId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Перевіряє чи можна продати ще один абонемент (ліміт плану).
     * Кидає 403 якщо ліміт перевищено.
     */
    public static function checkInvoiceLimit(int $clubId): void
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare("
            SELECT p.invoices_limit
            FROM saas_subscriptions s
            JOIN saas_plans p ON p.id = s.plan_id
            WHERE s.club_id = ?
        ");
        $stmt->execute([$clubId]);
        $row = $stmt->fetch();

        if (!$row || $row['invoices_limit'] === null) return; // безліміт

        $current = self::countActiveInvoices($clubId);

        if ($current >= (int)$row['invoices_limit']) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'        => false,
                'billing_error'  => true,
                'billing_code'   => 'invoice_limit',
                'error'          => "Досягнуто ліміт активних абонементів ({$row['invoices_limit']}) вашого плану. Перейдіть на вищий план.",
                'billing_action' => '/billing',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    /**
     * Перевіряє чи можна додати ще одного (активного) клієнта.
     */
    public static function checkClientLimit(int $clubId): void
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare("
            SELECT p.clients_limit
            FROM saas_subscriptions s
            JOIN saas_plans p ON p.id = s.plan_id
            WHERE s.club_id = ?
        ");
        $stmt->execute([$clubId]);
        $row = $stmt->fetch();

        if (!$row || $row['clients_limit'] === null) return; // безліміт

        $current = self::countActiveClients($clubId);

        if ($current >= (int)$row['clients_limit']) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'        => false,
                'billing_error'  => true,
                'billing_code'   => 'client_limit',
                'error'          => "Досягнуто ліміт активних клієнтів ({$row['clients_limit']}) вашого плану. Перейдіть на вищий план.",
                'billing_action' => '/billing',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    /**
     * Перевіряє чи можна додати ще одного учасника команди (будь-якої ролі —
     * owner/manager/trainer). Викликати лише коли дія реально додає НОВУ
     * активну людину (запрошення, реактивація) — не при зміні ролі чи
     * самопризначенні тренером існуючого учасника.
     */
    public static function checkTeamLimit(int $clubId): void
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare("
            SELECT p.users_limit
            FROM saas_subscriptions s
            JOIN saas_plans p ON p.id = s.plan_id
            WHERE s.club_id = ?
        ");
        $stmt->execute([$clubId]);
        $row = $stmt->fetch();

        if (!$row || $row['users_limit'] === null) return; // безліміт

        $current = self::countActiveTeam($clubId);

        if ($current >= (int)$row['users_limit']) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'        => false,
                'billing_error'  => true,
                'billing_code'   => 'team_limit',
                'error'          => "Досягнуто ліміт команди ({$row['users_limit']}) вашого плану. Перейдіть на вищий план.",
                'billing_action' => '/billing',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    /**
     * Деактивує "зайвих" учасників команди понад ліміт НОВОГО плану клубу.
     * Викликати ЛИШЕ одразу після зміни плану на менший (downgrade, авто-
     * переведення простроченого тріалу на free) — НЕ на кожному запиті.
     *
     * Владника (роль owner) НІКОЛИ не блокує. Серед решти деактивує
     * найновіших за granted_at (хто останній приєднався — той перший під
     * паузу). Той самий перемикач, що й ручна кнопка "Призупинити"
     * (sys_user_clubs.is_active) — club_trainers НЕ чіпає, нарахування й
     * історія тренера зберігаються, дію можна скасувати вручну (з урахуванням
     * ліміту — див. checkTeamLimit у toggle_access).
     */
    public static function enforceTeamLimit(int $clubId): void
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare("
            SELECT p.users_limit
            FROM saas_subscriptions s
            JOIN saas_plans p ON p.id = s.plan_id
            WHERE s.club_id = ?
        ");
        $stmt->execute([$clubId]);
        $row = $stmt->fetch();

        if (!$row || $row['users_limit'] === null) return; // безліміт

        $excess = self::countActiveTeam($clubId) - (int)$row['users_limit'];
        if ($excess <= 0) return;

        $candidates = $pdo->prepare("
            SELECT uc.id
            FROM sys_user_clubs uc
            JOIN sys_roles r ON r.id = uc.role_id
            WHERE uc.club_id = ? AND uc.is_active = 1 AND r.slug != 'owner'
            ORDER BY uc.granted_at DESC
            LIMIT " . (int)$excess . "
        ");
        $candidates->execute([$clubId]);
        $ids = $candidates->fetchAll(PDO::FETCH_COLUMN);

        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE sys_user_clubs SET is_active = 0 WHERE id IN ({$placeholders})")
                ->execute($ids);
        }
    }

    // ── ENTITLEMENT-СТАН ДЛЯ ФРОНТЕНДУ ───────────────────────

    /**
     * Єдине джерело істини для фронтенду: план, використання, ліміти,
     * похідні permissions і рекомендований план. Не розкидати цю логіку
     * по інших файлах — розширювати тут.
     */
    public static function getEntitlements(int $clubId): array
    {
        $pdo  = Database::get();
        $stmt = $pdo->prepare("
            SELECT s.status, p.slug AS plan_slug, p.name AS plan_name, p.price_monthly,
                   p.clients_limit, p.users_limit
            FROM saas_subscriptions s
            JOIN saas_plans p ON p.id = s.plan_id
            WHERE s.club_id = ?
        ");
        $stmt->execute([$clubId]);
        $sub = $stmt->fetch();

        if (!$sub) {
            return [
                'plan'            => null,
                'recommendedPlan' => null,
                'usage'           => ['team' => 0, 'activeClients' => 0],
                'limits'          => ['maxTeam' => null, 'maxActiveClients' => null],
                'permissions'     => ['canAddClient' => false, 'canAddMember' => false],
            ];
        }

        $team    = self::countActiveTeam($clubId);
        $clients = self::countActiveClients($clubId);
        $usage   = ['team' => $team, 'activeClients' => $clients];

        return [
            'plan' => [
                'slug'  => $sub['plan_slug'],
                'name'  => $sub['plan_name'],
                'price' => (float)$sub['price_monthly'],
            ],
            'recommendedPlan' => self::getRecommendedPlan($usage),
            'usage'  => $usage,
            'limits' => [
                'maxTeam'          => $sub['users_limit']   !== null ? (int)$sub['users_limit']   : null,
                'maxActiveClients' => $sub['clients_limit'] !== null ? (int)$sub['clients_limit'] : null,
            ],
            'permissions' => [
                'canAddClient' => $sub['clients_limit'] === null || $clients < (int)$sub['clients_limit'],
                'canAddMember' => $sub['users_limit']   === null || $team    < (int)$sub['users_limit'],
            ],
        ];
    }

    /**
     * Обирає найдешевший активний план, чиї ліміти (або NULL = безліміт)
     * покривають поточне використання. Повертає null якщо жоден не підходить.
     */
    public static function getRecommendedPlan(array $usage): ?array
    {
        $pdo   = Database::get();
        $plans = $pdo->query("
            SELECT slug, name, price_monthly, clients_limit, users_limit
            FROM saas_plans
            WHERE is_active = 1
            ORDER BY price_monthly ASC
        ")->fetchAll();

        foreach ($plans as $p) {
            $teamOk    = $p['users_limit']   === null || $usage['team']         <= (int)$p['users_limit'];
            $clientsOk = $p['clients_limit'] === null || $usage['activeClients'] <= (int)$p['clients_limit'];
            if ($teamOk && $clientsOk) {
                return ['slug' => $p['slug'], 'name' => $p['name'], 'price' => (float)$p['price_monthly']];
            }
        }

        return null;
    }

    // ── СТВОРЕННЯ ТРІАЛУ ─────────────────────────────────────

    /**
     * Викликається після реєстрації нового клубу.
     * Бере найдорожчий активний план з trial_days > 0.
     * Після закінчення тріалу cron переведе на is_free план.
     */
    public static function createTrial(int $clubId, int $planId = 0): void
    {
        $pdo = Database::get();

        // Якщо planId не передано — беремо найдорожчий план з trial_days > 0
        if (!$planId) {
            $row = $pdo->query("
                SELECT id, trial_days FROM saas_plans
                WHERE is_active = 1 AND trial_days > 0
                ORDER BY price_monthly DESC LIMIT 1
            ")->fetch();
            $planId   = $row ? (int)$row['id']         : 3; // fallback: Pro
            $trialDays = $row ? (int)$row['trial_days'] : self::TRIAL_DAYS;
        } else {
            $row = $pdo->prepare("SELECT trial_days FROM saas_plans WHERE id = ? LIMIT 1");
            $row->execute([$planId]);
            $r = $row->fetch();
            $trialDays = $r ? (int)$r['trial_days'] : self::TRIAL_DAYS;
        }

        $trialEnds = date('Y-m-d H:i:s', strtotime("+{$trialDays} days"));

        $pdo->prepare("
            INSERT INTO saas_subscriptions
                (club_id, plan_id, status, trial_ends_at, auto_renew)
            VALUES (?, ?, 'trial', ?, 0)
            ON DUPLICATE KEY UPDATE
                status        = 'trial',
                trial_ends_at = ?,
                plan_id       = ?
        ")->execute([$clubId, $planId, $trialEnds, $trialEnds, $planId]);

        $pdo->prepare("
            UPDATE sys_clubs
            SET subscription_status = 'trial', trial_ends_at = ?
            WHERE id = ?
        ")->execute([$trialEnds, $clubId]);
    }

    // ── АКТИВАЦІЯ ПІСЛЯ ОПЛАТИ ───────────────────────────────

    /**
     * Активує підписку після успішного платежу.
     * Викликається з billing_api.php після callback від WayForPay.
     */
    public static function activate(int $clubId, int $planId, int $months = 1): void
    {
        $pdo         = Database::get();
        $periodStart = date('Y-m-d H:i:s');
        $periodEnd   = date('Y-m-d H:i:s', strtotime("+{$months} months"));

        $pdo->prepare("
            UPDATE saas_subscriptions SET
                plan_id              = ?,
                status               = 'active',
                current_period_start = ?,
                current_period_end   = ?,
                auto_renew           = 1,
                updated_at           = NOW()
            WHERE club_id = ?
        ")->execute([$planId, $periodStart, $periodEnd, $clubId]);

        $pdo->prepare("
            UPDATE sys_clubs SET subscription_status = 'active' WHERE id = ?
        ")->execute([$clubId]);

        self::enforceTeamLimit($clubId);
    }

    // ── ВНУТРІШНІ МЕТОДИ ─────────────────────────────────────

    private static function updateStatus(int $clubId, string $status): void
    {
        $pdo = Database::get();
        $pdo->prepare("UPDATE saas_subscriptions SET status=? WHERE club_id=?")->execute([$status, $clubId]);
        $pdo->prepare("UPDATE sys_clubs SET subscription_status=? WHERE id=?")->execute([$status, $clubId]);
    }

    private static function notifyTrialExpired(int $clubId): void
    {
        try {
            $pdo  = Database::get();
            $stmt = $pdo->prepare("
                SELECT u.email, u.full_name, c.name AS club_name
                FROM sys_clubs c
                JOIN sys_users u ON u.id = c.owner_id
                WHERE c.id = ?
            ");
            $stmt->execute([$clubId]);
            $row = $stmt->fetch();
            if ($row) {
                Mailer::sendTemplate('trial_expired', [
                    'owner_name' => $row['full_name'],
                    'club_name'  => $row['club_name'],
                ], $row['email'], $row['full_name']);
            }
        } catch (Throwable $e) {
            error_log('[Billing] notifyTrialExpired error: ' . $e->getMessage());
        }
    }

    // ── КРОН: відправка попереджень ──────────────────────────

    /**
     * Викликається з cron раз на добу.
     * Надсилає попередження про закінчення тріалу.
     *
     * Додайте в cron хостингу:
     *   0 9 * * * php /home/.../public_html/api/billing_api.php?action=cron_check&key=YOUR_CRON_KEY
     */
    public static function runDailyCheck(PDO $pdo): array
    {
        $sent = [];

        // Тріали що закінчуються через 7 днів
        $stmt = $pdo->query("
            SELECT s.club_id, u.email, u.full_name, c.name AS club_name,
                   DATE_FORMAT(s.trial_ends_at, '%d.%m.%Y') AS trial_ends
            FROM saas_subscriptions s
            JOIN sys_clubs  c ON c.id = s.club_id
            JOIN sys_users  u ON u.id = c.owner_id
            WHERE s.status = 'trial'
              AND DATE(s.trial_ends_at) = DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ");
        foreach ($stmt->fetchAll() as $row) {
            Mailer::sendTemplate('trial_warning_7', [
                'owner_name' => $row['full_name'],
                'club_name'  => $row['club_name'],
                'trial_ends' => $row['trial_ends'],
            ], $row['email']);
            $sent[] = "7d warning → {$row['email']}";
        }

        // Тріали що закінчуються завтра
        $stmt = $pdo->query("
            SELECT s.club_id, u.email, u.full_name, c.name AS club_name,
                   DATE_FORMAT(s.trial_ends_at, '%d.%m.%Y') AS trial_ends
            FROM saas_subscriptions s
            JOIN sys_clubs  c ON c.id = s.club_id
            JOIN sys_users  u ON u.id = c.owner_id
            WHERE s.status = 'trial'
              AND DATE(s.trial_ends_at) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
        ");
        foreach ($stmt->fetchAll() as $row) {
            Mailer::sendTemplate('trial_warning_1', [
                'owner_name' => $row['full_name'],
                'club_name'  => $row['club_name'],
                'trial_ends' => $row['trial_ends'],
            ], $row['email']);
            $sent[] = "1d warning → {$row['email']}";
        }

        // Закінчені тріали → переводимо на Free план
        $freePlan = $pdo->query("
            SELECT id FROM saas_plans WHERE is_free = 1 AND is_active = 1 LIMIT 1
        ")->fetchColumn();

        if ($freePlan) {
            // Переводимо trial_expired → active на Free плані
            $expired = $pdo->query("
                SELECT s.club_id FROM saas_subscriptions s
                WHERE s.status = 'trial'
                  AND s.trial_ends_at < NOW()
            ")->fetchAll(PDO::FETCH_COLUMN);

            foreach ($expired as $cid) {
                $pdo->prepare("
                    UPDATE saas_subscriptions SET
                        status               = 'active',
                        plan_id              = ?,
                        current_period_start = NOW(),
                        current_period_end   = NULL,
                        auto_renew           = 0,
                        updated_at           = NOW()
                    WHERE club_id = ?
                ")->execute([$freePlan, $cid]);
                $pdo->prepare("
                    UPDATE sys_clubs SET subscription_status = 'active' WHERE id = ?
                ")->execute([$cid]);
                self::enforceTeamLimit((int)$cid);
                $sent[] = "trial→free plan → club #{$cid}";
            }
        } else {
            // Якщо Free плану немає — залишаємо trial_expired (старий fallback)
            $pdo->query("
                UPDATE saas_subscriptions s
                JOIN sys_clubs c ON c.id = s.club_id
                SET s.status = 'trial_expired',
                    c.subscription_status = 'trial_expired'
                WHERE s.status = 'trial'
                  AND s.trial_ends_at < NOW()
            ");
        }

        // Видалення через 90 днів
        $pdo->query("
            UPDATE saas_subscriptions s
            JOIN sys_clubs c ON c.id = s.club_id
            SET s.status = 'deleted',
                c.subscription_status = 'deleted',
                c.is_active = 0
            WHERE s.status IN ('trial_expired','cancelled','past_due')
              AND s.updated_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
        ");

        return $sent;
    }
}