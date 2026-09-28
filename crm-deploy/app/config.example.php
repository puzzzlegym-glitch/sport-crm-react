<?php
/**
 * config.example.php — ШАБЛОН налаштувань
 *
 * Скопіюйте цей файл як config.php і заповніть реальними даними.
 * ВАЖЛИВО: config.php додано до .gitignore — не комітити!
 *
 * Розмістіть: /home/ваш_логін/neobot.pp.ua/app/config.php
 */

if (!defined('SPORT_CRM')) { http_response_code(403); die('Access denied'); }

// ── База даних ────────────────────────────────────────────
define('DB_HOST',    'ваш_хост.mysql.tools');
define('DB_NAME',    'ваш_логін_crm');
define('DB_USER',    'ваш_логін_crm');
define('DB_PASS',    'ваш_пароль');
define('DB_CHARSET', 'utf8mb4');

// ── Безпека сесій ─────────────────────────────────────────
define('SESSION_LIFETIME', 86400);
define('SESSION_COOKIE',   'sc_sess');
define('MAX_LOGIN_FAILS',  5);
define('LOGIN_BLOCK_SECS', 900);
// Лише якщо сайт працює через Cloudflare/проксі — інакше залишити закоментованим
// (заголовок з IP може підробити будь-хто):
// define('TRUSTED_PROXY_IP_HEADER', 'HTTP_CF_CONNECTING_IP');

// ── Домен ─────────────────────────────────────────────────
define('APP_URL',       'https://ваш-домен.ua/');
define('APP_NAME',      'Sport CRM');
define('COOKIE_DOMAIN', '.ваш-домен.ua');

// ── Шляхи ─────────────────────────────────────────────────
define('ROOT_PATH',   __DIR__);
define('PUBLIC_PATH', dirname(__DIR__));

// ── Режим розробки ────────────────────────────────────────
define('DEV_MODE', false); // true лише локально!
define('IS_LOCAL_TEST', false); // true лише для локального http (вимикає Secure у кукі сесії)
define('APP_VERSION', '1.0.1'); // змінювати при кожному деплої
// define('APP_TIMEZONE', 'Europe/Kyiv'); // часовий пояс клубу (за замовчуванням — Київ)

// ── WayForPay ─────────────────────────────────────────────
// Отримати на: https://wiki.wayforpay.com/
define('WFP_MERCHANT_LOGIN',  'ваш_merchant_login');
define('WFP_MERCHANT_SECRET', 'ваш_merchant_secret');

// ── Cron ─────────────────────────────────────────────────
// Згенерувати: php -r "echo bin2hex(random_bytes(32));"
// Cron задача: 0 9 * * * curl "https://ВАШ-ДОМЕН/api/billing_api.php?action=cron_check&key=КЛЮЧ"
define('CRON_SECRET', 'згенерований_випадковий_рядок_32_символи');

// ── ПРРО (Checkbox) ────────────────────────────────────────
// Згенерувати: php -r "echo bin2hex(random_bytes(32));" — не змінювати після
// першого збереження налаштувань ПРРО жодного клубу.
define('PRRO_ENCRYPTION_KEY', 'згенерований_випадковий_рядок_32_байти_hex');

// ── Telegram-бот (клієнтський) ────────────────────────────
// Токен отримати у @BotFather; secret — довільний випадковий рядок,
// той самий передати в setWebhook як secret_token.
// Встановити вебхук (один раз):
//   https://api.telegram.org/bot<ТОКЕН>/setWebhook?url=https://ВАШ-ДОМЕН/api/telegram_webhook_api.php&secret_token=<TELEGRAM_WEBHOOK_SECRET>
// Cron нагадувань: 0 9 * * * curl "https://ВАШ-ДОМЕН/api/telegram_api.php?action=cron_send_reminders&key=КЛЮЧ_ВІД_CRON_SECRET"
define('TELEGRAM_BOT_TOKEN',      'токен_від_BotFather');
define('TELEGRAM_BOT_USERNAME',   'ваш_bot_username'); // без @, для формування t.me/<username>?start=...
define('TELEGRAM_WEBHOOK_SECRET', 'згенерований_випадковий_рядок_32_символи');

// ── SMTP ─────────────────────────────────────────────────
define('SMTP_HOST',       'mail.ваш-хостинг.ua');
define('SMTP_PORT',       465);
define('SMTP_SECURE',     'ssl');
define('SMTP_USERNAME',   'admin@ваш-домен.ua');
define('SMTP_PASSWORD',   'пароль_від_email');
define('SMTP_FROM_EMAIL', 'admin@ваш-домен.ua');
define('SMTP_FROM_NAME',  'Sport CRM');
