<?php
/**
 * Telegram.php — Обгортка над Telegram Bot API (raw curl, без SDK/Composer)
 *
 * Використання:
 *   Telegram::sendMessage($chatId, 'Привіт!');
 *   Telegram::call('setWebhook', ['url' => ..., 'secret_token' => ...]);
 *   Telegram::notifySuperAdmins('🆕 Нова реєстрація...');
 *   Telegram::notifyClubOwners($clubId, '💵 Інкасація...');
 */

class Telegram
{
    private const API_BASE = 'https://api.telegram.org/bot';

    /** Надіслати текстове повідомлення */
    public static function sendMessage(string $chatId, string $text, array $opts = []): array
    {
        return self::call('sendMessage', array_merge([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ], $opts));
    }

    /** Будь-який метод Telegram Bot API */
    public static function call(string $method, array $params): array
    {
        if (!defined('TELEGRAM_BOT_TOKEN') || !TELEGRAM_BOT_TOKEN) {
            error_log('[Telegram] TELEGRAM_BOT_TOKEN не задано в config.php');
            return ['ok' => false, 'error' => 'no_token'];
        }

        $ch = curl_init(self::API_BASE . TELEGRAM_BOT_TOKEN . '/' . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("[Telegram] curl-помилка ({$method}): {$curlErr}");
            return ['ok' => false, 'error' => $curlErr];
        }

        $data = json_decode((string)$response, true) ?? [];
        if (empty($data['ok'])) {
            error_log("[Telegram] Помилка API ({$method}): " . $response);
        }
        return $data;
    }

    /**
     * Надіслати текст усім прив'язаним SuperAdmin (role level >= 100).
     * @return array<int, array{chat_id: string, message_id: int}> успішно надіслані — для reply-трекінгу (див. Support.php)
     */
    public static function notifySuperAdmins(string $text): array
    {
        $rows = Database::get()->query("
            SELECT telegram_id FROM sys_users u
            JOIN sys_roles r ON r.id = u.global_role_id
            WHERE r.level >= 100 AND u.is_active = 1 AND u.telegram_id IS NOT NULL
        ")->fetchAll(PDO::FETCH_COLUMN);

        return self::sendToMany($rows, $text);
    }

    /**
     * Надіслати текст усім прив'язаним власникам конкретного клубу.
     * @return array<int, array{chat_id: string, message_id: int}> успішно надіслані — для reply-трекінгу (див. Support.php)
     */
    public static function notifyClubOwners(int $clubId, string $text): array
    {
        $stmt = Database::get()->prepare("
            SELECT DISTINCT u.telegram_id
            FROM sys_user_clubs uc
            JOIN sys_roles r ON r.id = uc.role_id AND r.slug = 'owner'
            JOIN sys_users u ON u.id = uc.user_id
            WHERE uc.club_id = ? AND uc.is_active = 1 AND u.telegram_id IS NOT NULL
            UNION
            SELECT u.telegram_id
            FROM sys_clubs c
            JOIN sys_users u ON u.id = c.owner_id
            WHERE c.id = ? AND u.telegram_id IS NOT NULL
        ");
        $stmt->execute([$clubId, $clubId]);

        return self::sendToMany($stmt->fetchAll(PDO::FETCH_COLUMN), $text);
    }

    /** @return array<int, array{chat_id: string, message_id: int}> */
    private static function sendToMany(array $chatIds, string $text): array
    {
        $sent = [];
        foreach ($chatIds as $chatId) {
            $res = self::sendMessage($chatId, $text);
            $messageId = $res['result']['message_id'] ?? null;
            if ($messageId) $sent[] = ['chat_id' => $chatId, 'message_id' => $messageId];
        }
        return $sent;
    }
}
