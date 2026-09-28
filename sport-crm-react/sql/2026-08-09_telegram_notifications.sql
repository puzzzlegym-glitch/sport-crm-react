-- ============================================================================
-- Telegram-бот, Фаза 2: прив'язка персоналу, розсилки, налаштування нагадувань
-- Використовується: api/telegram_webhook_api.php, api/telegram_api.php,
--                    api/register_api.php, api/billing_api.php, api/cash_api.php,
--                    api/finance_api.php, src/api/telegram.js, src/pages/SettingsPage.jsx
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-08-09
-- ============================================================================

-- 1) Telegram-акаунт персоналу (SuperAdmin / власники) ------------------------
ALTER TABLE sys_users ADD COLUMN telegram_id VARCHAR(32) NULL DEFAULT NULL AFTER phone;

-- 2) Одноразові токени прив'язки персоналу (дзеркало client_telegram_links) --
CREATE TABLE IF NOT EXISTS staff_telegram_links (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    token       CHAR(32)     NOT NULL,
    expires_at  DATETIME     NOT NULL,
    used_at     DATETIME     NULL DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stl_token (token),
    KEY idx_stl_user (user_id),
    CONSTRAINT fk_stl_user FOREIGN KEY (user_id) REFERENCES sys_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Глобальні налаштування нагадувань (один рядок, редагує SuperAdmin) ------
CREATE TABLE IF NOT EXISTS telegram_settings (
    id                    TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
    reminders_enabled     TINYINT(1) NOT NULL DEFAULT 1,
    reminder_days_before  INT        NOT NULL DEFAULT 1,
    updated_at            DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO telegram_settings (id, reminders_enabled, reminder_days_before) VALUES (1, 1, 1);

-- 4) Нове право: розсилка власника своїм клієнтам ------------------------------
--    + перекатегоризація telegram.manage/telegram.broadcast під власну категорію
INSERT INTO sys_permissions (slug, label, category, sort_order) VALUES
    ('telegram.broadcast', 'Розсилка клієнтам у Telegram', 'telegram', 20)
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category), sort_order = VALUES(sort_order);

UPDATE sys_permissions SET category = 'telegram', sort_order = 10 WHERE slug = 'telegram.manage';

INSERT IGNORE INTO sys_role_permissions (role_id, permission_slug)
SELECT r.id, p.slug
FROM sys_roles r
JOIN sys_permissions p ON p.slug = 'telegram.broadcast'
WHERE r.slug = 'owner';
