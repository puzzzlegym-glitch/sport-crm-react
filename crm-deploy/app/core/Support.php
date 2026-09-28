<?php
/**
 * Support.php — спільна логіка розділу підтримки.
 * Використовується і з api/support_api.php (CRM), і з api/telegram_webhook_api.php
 * (відповіді прямо в Telegram-боті, через Reply на повідомлення про звернення),
 * щоб обидва шляхи однаково оновлювали статус/непрочитане й сповіщали іншу сторону.
 */
class Support
{
    /** Українська назва статусу звернення */
    public static function statusLabel(string $status): string
    {
        return [
            'open'        => 'Нове',
            'in_progress' => 'В роботі',
            'resolved'    => 'Вирішено',
            'closed'      => 'Закрито',
        ][$status] ?? $status;
    }

    /** Обрізає текст для Telegram-сповіщення, щоб повідомлення не було занадто довгим */
    public static function notifyPreview(string $text, int $limit = 300): string
    {
        $text = trim($text);
        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit) . '…';
    }

    /**
     * Додає повідомлення до звернення, оновлює статус/позначки непрочитаного
     * й сповіщає іншу сторону в Telegram (з трекінгом message_id для Reply-відповідей).
     * Єдина точка входу для CRM (support_api.php) і бота (telegram_webhook_api.php).
     */
    public static function postMessage(PDO $pdo, int $ticketId, string $senderType, int $senderUserId, string $message): void
    {
        $pdo->prepare("
            INSERT INTO support_messages (ticket_id, sender_type, sender_user_id, message, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ")->execute([$ticketId, $senderType, $senderUserId, $message]);

        $stmt = $pdo->prepare("SELECT club_id, subject, status FROM support_tickets WHERE id = ?");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();
        if (!$ticket) return;

        // Відповідь клубу в вирішеному/закритому зверненні повертає його в роботу;
        // відповідь підтримки в новому зверненні одразу переводить його "в роботу".
        $nextStatus = null;
        if ($senderType === 'club' && in_array($ticket['status'], ['resolved', 'closed'], true)) {
            $nextStatus = 'open';
        } elseif ($senderType === 'admin' && $ticket['status'] === 'open') {
            $nextStatus = 'in_progress';
        }

        // Сторона, що написала, бачила звернення щойно (0); інша сторона ще не бачила цього повідомлення (1).
        $unreadSet = $senderType === 'club'
            ? 'unread_by_admin = 1, unread_by_club = 0'
            : 'unread_by_club = 1, unread_by_admin = 0';

        if ($nextStatus) {
            $pdo->prepare("UPDATE support_tickets SET status = ?, {$unreadSet}, last_message_at = NOW() WHERE id = ?")
                ->execute([$nextStatus, $ticketId]);
        } else {
            $pdo->prepare("UPDATE support_tickets SET {$unreadSet}, last_message_at = NOW() WHERE id = ?")
                ->execute([$ticketId]);
        }

        self::notifyOtherSide($pdo, $ticketId, (int)$ticket['club_id'], $ticket['subject'], $senderType, $message);
    }

    private static function notifyOtherSide(PDO $pdo, int $ticketId, int $clubId, string $subject, string $senderType, string $message): void
    {
        if ($senderType === 'club') {
            $clubNameStmt = $pdo->prepare("SELECT name FROM sys_clubs WHERE id = ?");
            $clubNameStmt->execute([$clubId]);
            $clubName = $clubNameStmt->fetchColumn() ?: 'Клуб';

            $sent = Telegram::notifySuperAdmins(
                "💬 Нове повідомлення від «{$clubName}» у зверненні «{$subject}»\n\n" . self::notifyPreview($message)
            );
        } else {
            $sent = Telegram::notifyClubOwners(
                $clubId,
                "💬 Підтримка Sport CRM відповіла на звернення «{$subject}»\n\n" . self::notifyPreview($message)
            );
        }

        self::trackSent($pdo, $ticketId, $sent);
    }

    /** Записує message_id надісланих сповіщень — щоб пізніше розпізнати Reply на них із боку бота */
    public static function trackSent(PDO $pdo, int $ticketId, array $sent): void
    {
        if (!$sent) return;
        $stmt = $pdo->prepare("
            INSERT INTO support_telegram_messages (ticket_id, chat_id, telegram_message_id, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        foreach ($sent as $row) {
            $stmt->execute([$ticketId, $row['chat_id'], $row['message_id']]);
        }
    }

    /** Знаходить ticket_id за telegram message_id, на яке відповіли (Reply) у чаті chat_id */
    public static function resolveTicketByReply(PDO $pdo, string $chatId, int $repliedToMessageId): ?int
    {
        $stmt = $pdo->prepare("
            SELECT ticket_id FROM support_telegram_messages
            WHERE chat_id = ? AND telegram_message_id = ?
            LIMIT 1
        ");
        $stmt->execute([$chatId, $repliedToMessageId]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }
}
