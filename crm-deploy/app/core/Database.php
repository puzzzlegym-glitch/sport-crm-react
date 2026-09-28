<?php
/**
 * Database.php — Підключення до MySQL
 *
 * Використовуємо патерн Singleton:
 * підключення до БД створюється ОДИН раз на весь запит.
 * Це економить ресурси сервера.
 *
 * Як користуватись:
 *   $pdo = Database::get();
 *   $stmt = $pdo->prepare("SELECT * FROM sys_users WHERE id = ?");
 *   $stmt->execute([$id]);
 */

class Database
{
    private static ?PDO $instance = null;

    /**
     * Повертає підключення до БД.
     * Якщо підключення вже є — повертає те саме (Singleton).
     */
    public static function get(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_NAME,
                DB_CHARSET
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // кидати виняток при помилці
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // результат як масив
                PDO::ATTR_EMULATE_PREPARES   => false,                     // справжні prepared statements
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                // Логуємо справжню помилку, але НЕ показуємо її в браузері
                error_log('[DB] Помилка підключення: ' . $e->getMessage());

                // Клієнту показуємо загальну помилку
                http_response_code(503);
                echo json_encode([
                    'success' => false,
                    'error'   => 'Сервіс тимчасово недоступний'
                ]);
                exit;
            }
        }

        return self::$instance;
    }

    // Забороняємо клонування та серіалізацію (захист Singleton)
    private function __construct() {}
    private function __clone() {}
}