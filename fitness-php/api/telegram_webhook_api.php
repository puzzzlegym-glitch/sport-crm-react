<?php
/**
 * telegram_webhook_api.php — публічний вебхук Telegram-бота для клієнтів клубу
 *
 * ВАЖЛИВО: назва файлу має закінчуватись на "_api.php" — так вимагає роутинг
 * у index.php (`str_ends_with($file, '_api.php')`), інакше запит на цей файл
 * впаде в 404 ще до того, як дійде сюди.
 *
 * Приймає POST-и від Telegram (Update object). Без Auth::requireAuth() —
 * автентичність перевіряється заголовком X-Telegram-Bot-Api-Secret-Token,
 * встановленим один раз при виклику setWebhook (аналог wfp_callback у
 * billing_api.php, тільки секрет у заголовку, а не в підписі тіла).
 *
 * Підтримує:
 *   /start <token> — прив'язка клієнта АБО персоналу (client_telegram_links /
 *                    staff_telegram_links — токени з різних таблиць, не перетинаються)
 *   /start (без токена) — пропонує самостійну прив'язку кнопкою "поділитися номером"
 *   contact (номер телефону, надісланий кнопкою request_contact) — самостійна
 *                    прив'язка клієнта за збігом з clients.phone_normalized, без токена
 *   "Мій абонемент" — статус активного абонемента по кожному прив'язаному клубу (клієнт)
 *   "Історія відвідувань" — останні 5 відвідувань по кожному прив'язаному клубу (клієнт)
 *   Reply на повідомлення про звернення підтримки — персонал (SuperAdmin/власник клубу)
 *   продовжує переписку прямо в Telegram, без входу в CRM (Support::postMessage, див.
 *   self_handleSupportReply нижче); будь-який інший текст від персоналу — просте
 *   підтвердження без меню
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$secretHeader = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!defined('TELEGRAM_WEBHOOK_SECRET') || !hash_equals(TELEGRAM_WEBHOOK_SECRET, $secretHeader)) {
    http_response_code(403);
    exit;
}

$update  = json_decode(file_get_contents('php://input'), true) ?? [];
$pdo     = Database::get();
$message = $update['message'] ?? null;

// Ігноруємо edited_message/callback_query/інші типи update — Telegram все одно
// очікує 200, інакше почне ретраїти доставку.
if (!$message) { http_response_code(200); exit; }

$chatId       = (string)($message['chat']['id'] ?? '');
$text         = trim($message['text'] ?? '');
$replyToId    = (int)($message['reply_to_message']['message_id'] ?? 0);

if (!$chatId) { http_response_code(200); exit; }

try {
    // Reply на повідомлення про звернення підтримки має пріоритет над усім іншим —
    // це явний жест користувача, який продовжує конкретну переписку (Support::postMessage).
    if ($replyToId && $text !== '' && self_handleSupportReply($pdo, $chatId, $replyToId, $text)) {
        // оброблено — нічого більше робити не треба
    } elseif (isset($message['contact'])) {
        self_handlePhoneShare($pdo, $chatId, $message['contact']);
    } elseif (str_starts_with($text, '/start')) {
        $parts = explode(' ', $text, 2);
        self_handleStart($pdo, $chatId, trim($parts[1] ?? ''));
    } elseif (str_contains(mb_strtolower($text), 'абонемент')) {
        self_replyMembership($pdo, $chatId);
    } elseif (str_contains(mb_strtolower($text), 'відвідуван')) {
        self_replyVisits($pdo, $chatId);
    } elseif (self_findLinkedClients($pdo, $chatId)) {
        // Прив'язаний як клієнт (навіть якщо ще й персонал того ж клубу —
        // напр. власник, який сам є клієнтом) — клієнтське меню пріоритетне,
        // інакше "тихий" режим персоналу нижче назавжди заблокував би команди.
        self_replyMenu($pdo, $chatId);
    } elseif (self_isLinkedStaff($pdo, $chatId)) {
        // Лише персонал (не клієнт) — команд немає, крім Reply на звернення підтримки вище.
        Telegram::sendMessage($chatId, "Щоб відповісти на звернення підтримки, натисніть Reply на моє повідомлення про нього. Інші сповіщення надходитимуть сюди автоматично.");
    } else {
        self_replyMenu($pdo, $chatId);
    }
} catch (Throwable $e) {
    error_log('[TelegramWebhook] ' . $e->getMessage());
}

http_response_code(200);
exit;

// ── Reply на повідомлення про звернення підтримки — двостороння переписка через бота ──
// Повертає true, якщо повідомлення оброблено як відповідь у зверненні (навіть якщо
// з відмовою через права/статус) — тоді дальший dispatch у диспетчері вище не виконується.
function self_handleSupportReply(PDO $pdo, string $chatId, int $repliedToMessageId, string $text): bool
{
    $ticketId = Support::resolveTicketByReply($pdo, $chatId, $repliedToMessageId);
    if (!$ticketId) return false;

    $userStmt = $pdo->prepare("SELECT id, global_role_id FROM sys_users WHERE telegram_id = ? LIMIT 1");
    $userStmt->execute([$chatId]);
    $user = $userStmt->fetch();
    if (!$user) return false; // chat_id не прив'язаний до персоналу — нехай впаде у звичайний dispatch

    $ticketStmt = $pdo->prepare("SELECT club_id, status FROM support_tickets WHERE id = ?");
    $ticketStmt->execute([$ticketId]);
    $ticket = $ticketStmt->fetch();
    if (!$ticket) return false;

    $roleLevel = 0;
    if ($user['global_role_id']) {
        $roleStmt = $pdo->prepare("SELECT level FROM sys_roles WHERE id = ?");
        $roleStmt->execute([$user['global_role_id']]);
        $roleLevel = (int)$roleStmt->fetchColumn();
    }

    if ($roleLevel >= 100) {
        // SuperAdmin — відповідає як підтримка, звернення будь-якого клубу.
        $senderType = 'admin';
    } else {
        // Персонал клубу — лише якщо це звернення саме їхнього клубу.
        $access = $pdo->prepare("
            SELECT 1 FROM sys_user_clubs WHERE user_id = ? AND club_id = ? AND is_active = 1 LIMIT 1
        ");
        $access->execute([$user['id'], $ticket['club_id']]);
        if (!$access->fetchColumn()) {
            Telegram::sendMessage($chatId, 'Це звернення не належить вашому клубу.');
            return true;
        }
        if ($ticket['status'] === 'closed') {
            Telegram::sendMessage($chatId, 'Це звернення вже закрито. Відкрийте нове через CRM.');
            return true;
        }
        $senderType = 'club';
    }

    Support::postMessage($pdo, $ticketId, $senderType, (int)$user['id'], $text);
    Telegram::sendMessage($chatId, '✅ Додано до звернення.');
    return true;
}

// ── /start <token> — прив'язка клієнта АБО персоналу до чату ────
function self_handleStart(PDO $pdo, string $chatId, string $token): void
{
    if (!$token) {
        Telegram::sendMessage(
            $chatId,
            "Вітаємо! Щоб побачити свій абонемент, поділіться номером телефону — ми знайдемо вас автоматично. Або попросіть персонал клубу створити персональне посилання.",
            ['reply_markup' => self_phoneKeyboard()]
        );
        return;
    }

    // Спершу пробуємо як токен клієнта
    $stmt = $pdo->prepare("
        SELECT id, client_id FROM client_telegram_links
        WHERE token = ? AND used_at IS NULL AND expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $link = $stmt->fetch();

    if ($link) {
        $pdo->prepare("UPDATE clients SET telegram_id = ? WHERE id = ?")->execute([$chatId, $link['client_id']]);
        $pdo->prepare("UPDATE client_telegram_links SET used_at = NOW() WHERE id = ?")->execute([$link['id']]);

        Telegram::sendMessage($chatId, "✅ Акаунт прив'язано! Тепер ви можете перевірити свій абонемент і відвідування.", [
            'reply_markup' => self_keyboard(),
        ]);
        return;
    }

    // Інакше пробуємо як токен персоналу (SuperAdmin/власник)
    $stmt = $pdo->prepare("
        SELECT id, user_id FROM staff_telegram_links
        WHERE token = ? AND used_at IS NULL AND expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $staffLink = $stmt->fetch();

    if ($staffLink) {
        $pdo->prepare("UPDATE sys_users SET telegram_id = ? WHERE id = ?")->execute([$chatId, $staffLink['user_id']]);
        $pdo->prepare("UPDATE staff_telegram_links SET used_at = NOW() WHERE id = ?")->execute([$staffLink['id']]);

        Telegram::sendMessage($chatId, "✅ Акаунт прив'язано! Сюди надходитимуть сповіщення платформи/клубу.");
        return;
    }

    Telegram::sendMessage($chatId, 'Посилання недійсне або протерміноване. Попросіть створити нове.');
}

// ── Перевірка, чи цей chat_id прив'язаний як персонал ────────────
function self_isLinkedStaff(PDO $pdo, string $chatId): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM sys_users WHERE telegram_id = ? LIMIT 1");
    $stmt->execute([$chatId]);
    return (bool)$stmt->fetchColumn();
}

// ── Клавіатура з кнопкою "поділитися номером" (Telegram сам гарантує,
//    що це номер саме відправника — підмінити чужий номер нею не можна) ──
function self_phoneKeyboard(): array
{
    return [
        'keyboard' => [[
            ['text' => '📞 Надіслати номер телефону', 'request_contact' => true],
        ]],
        'resize_keyboard'   => true,
        'one_time_keyboard' => true,
    ];
}

// ── Самостійна прив'язка клієнта за номером телефону (без токена) ────
function self_handlePhoneShare(PDO $pdo, string $chatId, array $contact): void
{
    $phone = preg_replace('/\D/', '', $contact['phone_number'] ?? '');
    if (strlen($phone) < 9) {
        Telegram::sendMessage($chatId, 'Не вдалося розпізнати номер. Зверніться на рецепції клубу.');
        return;
    }
    $last9 = substr($phone, -9);

    $stmt = $pdo->prepare("
        SELECT c.id, cl.name AS club_name
        FROM clients c
        JOIN sys_clubs cl ON cl.id = c.club_id
        WHERE c.phone_normalized LIKE ?
    ");
    $stmt->execute(['%' . $last9]);
    $matches = $stmt->fetchAll();

    if (!$matches) {
        Telegram::sendMessage($chatId, "Ми не знайшли ваш номер у жодному клубі. Зверніться на рецепції — там прив'яжуть вручну.");
        return;
    }

    $ids = array_column($matches, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("UPDATE clients SET telegram_id = ? WHERE id IN ({$placeholders})")
        ->execute(array_merge([$chatId], $ids));

    $clubNames = implode(', ', array_unique(array_column($matches, 'club_name')));
    Telegram::sendMessage($chatId, "✅ Прив'язано: {$clubNames}. Тепер можете перевірити свій абонемент і відвідування.", [
        'reply_markup' => self_keyboard(),
    ]);
}

// ── Меню за замовчуванням ───────────────────────────────────────
function self_replyMenu(PDO $pdo, string $chatId): void
{
    $linked = self_findLinkedClients($pdo, $chatId);
    $text = $linked
        ? 'Оберіть дію:'
        : "Ви ще не прив'язані до жодного клубу. Отримайте посилання на рецепції.";

    Telegram::sendMessage($chatId, $text, ['reply_markup' => self_keyboard()]);
}

function self_keyboard(): array
{
    return [
        'keyboard' => [[
            ['text' => '📋 Мій абонемент'],
            ['text' => '📅 Історія відвідувань'],
        ]],
        'resize_keyboard' => true,
    ];
}

// ── Мій абонемент (по кожному прив'язаному клубу) ────────────────
function self_replyMembership(PDO $pdo, string $chatId): void
{
    $linked = self_findLinkedClients($pdo, $chatId);
    if (!$linked) {
        Telegram::sendMessage($chatId, "Ви ще не прив'язані до жодного клубу. Отримайте посилання на рецепції.");
        return;
    }

    $lines = [];
    foreach ($linked as $c) {
        $inv = $pdo->prepare("
            SELECT tariff_name, end_date, visits_total, visits_used
            FROM client_invoices
            WHERE client_id = ? AND status NOT IN ('cancelled','frozen') AND end_date >= CURDATE()
            ORDER BY end_date ASC LIMIT 1
        ");
        $inv->execute([$c['client_id']]);
        $row = $inv->fetch();

        $lines[] = "🏋️ <b>{$c['club_name']}</b>";
        if (!$row) {
            $lines[] = 'Активного абонементу немає.';
            continue;
        }

        if ($row['visits_total']) {
            $left = max(0, (int)$row['visits_total'] - (int)$row['visits_used']);
            $visitsInfo = "залишилось {$left} з {$row['visits_total']} відвідувань";
        } else {
            $visitsInfo = 'безлімітні відвідування';
        }

        $lines[] = "«{$row['tariff_name']}» до " . date('d.m.Y', strtotime($row['end_date'])) . " ({$visitsInfo})";
    }

    Telegram::sendMessage($chatId, implode("\n", $lines), ['reply_markup' => self_keyboard()]);
}

// ── Історія відвідувань (по кожному прив'язаному клубу) ──────────
function self_replyVisits(PDO $pdo, string $chatId): void
{
    $linked = self_findLinkedClients($pdo, $chatId);
    if (!$linked) {
        Telegram::sendMessage($chatId, "Ви ще не прив'язані до жодного клубу. Отримайте посилання на рецепції.");
        return;
    }

    $lines = [];
    foreach ($linked as $c) {
        $stmt = $pdo->prepare("SELECT visited_at FROM visits WHERE client_id = ? ORDER BY visited_at DESC LIMIT 5");
        $stmt->execute([$c['client_id']]);
        $visits = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $lines[] = "🏋️ <b>{$c['club_name']}</b>";
        $lines[] = $visits
            ? implode("\n", array_map(fn($v) => '• ' . date('d.m.Y H:i', strtotime($v)), $visits))
            : 'Відвідувань ще немає.';
    }

    Telegram::sendMessage($chatId, implode("\n", $lines), ['reply_markup' => self_keyboard()]);
}

// ── Усі client-записи, прив'язані до цього chat_id (можливо кілька клубів) ──
function self_findLinkedClients(PDO $pdo, string $chatId): array
{
    $stmt = $pdo->prepare("
        SELECT c.id AS client_id, cl.name AS club_name
        FROM clients c
        JOIN sys_clubs cl ON cl.id = c.club_id
        WHERE c.telegram_id = ?
    ");
    $stmt->execute([$chatId]);
    return $stmt->fetchAll();
}
