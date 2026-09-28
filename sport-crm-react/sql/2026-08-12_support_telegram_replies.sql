-- ============================================================================
-- Розділ підтримки, фаза 3: двостороння переписка через Telegram-бота (Reply)
-- Використовується: app/core/Support.php, api/telegram_webhook_api.php
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-08-12
-- ============================================================================
-- Кожне Telegram-сповіщення про звернення трекається тут (chat_id + message_id).
-- Коли персонал (SuperAdmin або власник клубу) відповідає в Telegram через "Reply"
-- саме на це повідомлення, вебхук за message_id знаходить потрібне звернення —
-- без цього неможливо надійно визначити, до якого звернення відноситься відповідь,
-- якщо відкритих звернень декілька одночасно.
-- ============================================================================

CREATE TABLE IF NOT EXISTS support_telegram_messages (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id             INT          NOT NULL,
    chat_id               VARCHAR(32)  NOT NULL,
    telegram_message_id   INT          NOT NULL,
    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_stm_lookup (chat_id, telegram_message_id),
    KEY idx_stm_ticket (ticket_id),
    CONSTRAINT fk_stm_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
