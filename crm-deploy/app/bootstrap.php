<?php
/**
 * bootstrap.php — Завантажує всі ядрові класи
 *
 * Підключається ПЕРШИМ рядком у кожному API-файлі:
 *   require_once __DIR__ . '/../../app/bootstrap.php';
 *
 * Після цього доступні: Database, Auth, Response + всі константи config.php
 */

define('SPORT_CRM', true);   // дозволяє відкривати config.php
define('APP_ROOT', __DIR__); // абсолютний шлях до /app — використовуй замість dirname()

// Шлях до папки app (де лежить цей файл)
$appDir = __DIR__;

require_once $appDir . '/config.php';
require_once $appDir . '/core/Database.php';
require_once $appDir . '/core/Response.php';
require_once $appDir . '/core/Auth.php';
require_once $appDir . '/core/Mailer.php';
require_once $appDir . '/core/Billing.php';
require_once $appDir . '/core/Telegram.php';
require_once $appDir . '/core/Support.php';
require_once $appDir . '/core/DriveHubBridge.php';
require_once $appDir . '/core/Attendance.php';
require_once $appDir . '/core/Recalc.php';

// Налаштування PHP-помилок залежно від режиму
if (DEV_MODE) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// Глобальний обробник невловлених помилок
set_exception_handler(function (Throwable $e) {
    error_log('[UNCAUGHT] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'error'   => DEV_MODE ? $e->getMessage() : 'Внутрішня помилка сервера',
    ], JSON_UNESCAPED_UNICODE);
    exit;
});
