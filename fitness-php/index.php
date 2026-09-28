<?php
/**
 * index.php — Єдина точка входу (Front Controller)
 *
 * Структура public_html/:
 *   index.php          — цей файл
 *   .htaccess
 *   assets/css/        — стилі
 *   assets/js/         — скрипти
 *   pages/             — PHP-сторінки (dashboard.php, clients.php ...)
 *   api/               — API-файли (*_api.php)
 *   partials/          — head.php, scripts.php, head_auth.php
 */

define('SPORT_CRM', true);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = rtrim($uri, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

// ── API запити ───────────────────────────────────────────────
if (str_starts_with($uri, '/api/')) {

    if ($uri === '/api/version') {
        header('Content-Type: application/json');
        echo json_encode(['version' => APP_VERSION]);
        exit;
    }

    $file = __DIR__ . $uri;
    if (is_file($file) && str_ends_with($file, '_api.php')) {
        require $file;
        exit;
    }
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'API не знайдено']);
    exit;
}

// ── Маршрути сторінок: URI → файл у /pages/ ─────────────────
// Формат: 'uri' => ['file' => 'filename.php', 'auth' => true/false]
// auth: false — публічні (login, register); true — потребують авторизації
$routes = [
    '/'          => ['file' => 'login.php',     'auth' => false],
    '/login'     => ['file' => 'login.php',     'auth' => false],
    '/register'  => ['file' => 'register.php',  'auth' => false],
    '/dashboard' => ['file' => 'dashboard.php', 'auth' => true],
    '/clients'   => ['file' => 'clients.php',   'auth' => true],
    '/invoices'  => ['file' => 'invoices.php',  'auth' => true],
    '/products'  => ['file' => 'products.php',  'auth' => true],
    '/sales'     => ['file' => 'sales.php',     'auth' => true],
    '/finance'   => ['file' => 'finance.php',   'auth' => true],
    '/cash'      => ['file' => 'cash.php',      'auth' => true],
    '/visits'    => ['file' => 'visits.php',    'auth' => true],
    '/users'     => ['file' => 'users.php',     'auth' => true],
    '/billing'   => ['file' => 'billing_page.php', 'auth' => true],
    '/arrivals'  => ['file' => 'arrivals.php',     'auth' => true],
    '/settings'  => ['file' => 'settings.php',  'auth' => true],
    '/clubs'     => ['file' => 'clubs.php',     'auth' => true],
    '/saas'          => ['file' => 'saas.php',          'auth' => true],
    '/saas-payments'  => ['file' => 'saas_payments.php', 'auth' => true],
    '/tariffs'   => ['file' => 'tariffs.php',   'auth' => true],
    '/payments'  => ['file' => 'payments.php',  'auth' => true],
    '/trainers'  => ['file' => 'trainers.php',  'auth' => true],
    '/access'    => ['file' => 'access.php',    'auth' => true],
];

$route = $routes[$uri] ?? null;

// 404
if ($route === null) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="uk"><head><meta charset="UTF-8"><title>404</title></head>';
    echo '<body style="font-family:sans-serif;text-align:center;padding:60px;color:#8b95b0;background:#0b0d14">';
    echo '<h1 style="color:#e8ecf4;font-size:48px;margin-bottom:8px">404</h1>';
    echo '<p>Сторінку не знайдено</p>';
    echo '<a href="/" style="color:#4f9cf9;margin-top:20px;display:inline-block">← На головну</a>';
    echo '</body></html>';
    exit;
}

$filePath = __DIR__ . '/pages/' . $route['file'];

// Сторінка ще не створена
if (!is_file($filePath)) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="uk"><head><meta charset="UTF-8"><title>В розробці</title>';
    echo '<link rel="stylesheet" href="/assets/css/admin.css"></head><body>';
    echo '<div style="display:flex;align-items:center;justify-content:center;min-height:100vh">';
    echo '<div class="card" style="text-align:center;max-width:400px">';
    echo '<div style="font-size:36px;margin-bottom:16px">🔧</div>';
    echo '<h2>Розділ у розробці</h2>';
    echo '<a href="/dashboard" class="btn btn-ghost" style="margin-top:20px">← Дашборд</a>';
    echo '</div></div></body></html>';
    exit;
}

// Підключаємо PHP-сторінку (вона сама виводить HTML через partials)
header('Content-Type: text/html; charset=utf-8');
require $filePath;
