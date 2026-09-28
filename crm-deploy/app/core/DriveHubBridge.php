<?php
/**
 * DriveHubBridge.php — Міст від Sport CRM до DRIVEHUB (ds-hub.pp.ua, клієнтський застосунок).
 *
 * Зворотний напрямок до sportcrm_bridge.php (той міст читає Sport CRM з коду DRIVEHUB).
 * Тут — навпаки: сторінка "Клієнтський сервіс" читає з бази DRIVEHUB, щоб показати,
 * скільки клієнтів клубу вже користуються застосунком. Ідентифікація — виключно по
 * телефону (останні 10 цифр), без жодного запису в DRIVEHUB.
 *
 * DRIVEHUB має ОКРЕМОГО MySQL-користувача — навіть якщо бази на тому самому сервері,
 * потрібне окреме PDO-з'єднання (той самий патерн, що й getSportCrmPdo() у
 * sportcrm_bridge.php, дзеркально).
 *
 * Підключення: уже підключається через bootstrap.php, окремо require_once не потрібен.
 * Constants DRIVEHUB_DB_* мають бути додані в app/config.php (за замовчуванням не задані —
 * усі функції нижче тоді просто повертають "не підключено", без фатальних помилок,
 * щоб решта сторінки "Клієнтський сервіс" працювала і без цієї інтеграції).
 */

/** Чи налаштовано підключення до DRIVEHUB (constants у config.php). */
function driveHubConfigured(): bool
{
    return defined('DRIVEHUB_DB_HOST') && DRIVEHUB_DB_HOST
        && defined('DRIVEHUB_DB_NAME') && DRIVEHUB_DB_NAME
        && defined('DRIVEHUB_DB_USER') && DRIVEHUB_DB_USER;
}

/**
 * Singleton PDO до DRIVEHUB БД (read-only використання).
 */
function getDriveHubPdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            "mysql:host=" . DRIVEHUB_DB_HOST . ";dbname=" . DRIVEHUB_DB_NAME . ";charset=utf8mb4",
            DRIVEHUB_DB_USER,
            DRIVEHUB_DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

/**
 * Скільки клієнтів клубу (за списком їх 10-значних телефонів) вже зареєстровані
 * в застосунку DRIVEHUB, і скільки з них мають підтверджений номер телефону
 * (PhoneVerified=1 — саме ця умова вмикає міст у cabinet_api.php, тобто це
 * реально "розпізнані" в CRM користувачі, а не просто збіг номера).
 *
 * @param string[] $phones10 10-значні телефони клієнтів клубу (без коду країни)
 * @return array{connected:bool, app_users:int, app_users_verified:int}
 */
function getDriveHubClubStats(array $phones10): array
{
    if (!driveHubConfigured()) {
        return ['connected' => false, 'app_users' => 0, 'app_users_verified' => 0];
    }
    $phones10 = array_values(array_unique(array_filter($phones10)));
    if (!$phones10) {
        return ['connected' => true, 'app_users' => 0, 'app_users_verified' => 0];
    }

    try {
        $pdo = getDriveHubPdo();
        $placeholders = implode(',', array_fill(0, count($phones10), '?'));
        $stmt = $pdo->prepare("
            SELECT
                COUNT(DISTINCT ID) AS app_users,
                COUNT(DISTINCT CASE WHEN PhoneVerified = 1 THEN ID END) AS app_users_verified
            FROM tblNewTable
            WHERE RIGHT(REGEXP_REPLACE(COALESCE(PhoneE164, PhoneInput, Telefon, ''), '[^0-9]', ''), 10) IN ($placeholders)
        ");
        $stmt->execute($phones10);
        $row = $stmt->fetch();
        return [
            'connected'          => true,
            'app_users'          => (int)($row['app_users'] ?? 0),
            'app_users_verified' => (int)($row['app_users_verified'] ?? 0),
        ];
    } catch (Throwable $e) {
        error_log('[DriveHubBridge] getDriveHubClubStats error: ' . $e->getMessage());
        return ['connected' => false, 'app_users' => 0, 'app_users_verified' => 0];
    }
}

/**
 * Статус ОДНОГО клієнта в DRIVEHUB (для картки клієнта в CRM) — за 10-значним телефоном.
 *
 * @return array{connected:bool, is_app_user:bool, is_verified:bool, has_hub_telegram:bool}
 */
function getDriveHubClientStatus(string $phone10): array
{
    $default = ['connected' => driveHubConfigured(), 'is_app_user' => false, 'is_verified' => false, 'has_hub_telegram' => false];
    if (!driveHubConfigured() || !$phone10) {
        return $default;
    }

    try {
        $pdo = getDriveHubPdo();
        $stmt = $pdo->prepare("
            SELECT PhoneVerified, TelegramToken
            FROM tblNewTable
            WHERE RIGHT(REGEXP_REPLACE(COALESCE(PhoneE164, PhoneInput, Telefon, ''), '[^0-9]', ''), 10) = ?
            LIMIT 1
        ");
        $stmt->execute([$phone10]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['connected' => true, 'is_app_user' => false, 'is_verified' => false, 'has_hub_telegram' => false];
        }
        return [
            'connected'        => true,
            'is_app_user'      => true,
            'is_verified'      => (int)($row['PhoneVerified'] ?? 0) === 1,
            'has_hub_telegram' => !empty($row['TelegramToken']),
        ];
    } catch (Throwable $e) {
        error_log('[DriveHubBridge] getDriveHubClientStatus error: ' . $e->getMessage());
        return $default;
    }
}
