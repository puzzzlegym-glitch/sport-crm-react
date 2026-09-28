-- ============================================================================
-- Telegram-бот для клієнтів клубу: прив'язка + лог нагадувань + права доступу
-- Використовується: api/telegram_webhook_api.php, api/telegram_api.php,
--                    src/api/telegram.js, src/pages/ClientsPage.jsx
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-08-09
-- ============================================================================
-- ПРИМІТКА: clients.telegram_id вже існує в схемі — міграція для нього не потрібна.
-- ============================================================================

-- 1) Одноразові токени прив'язки (посилання/QR, які генерує персонал у CRM) ----
CREATE TABLE IF NOT EXISTS client_telegram_links (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    client_id   INT UNSIGNED NOT NULL,
    club_id     INT UNSIGNED NOT NULL,
    token       CHAR(32)     NOT NULL,
    expires_at  DATETIME     NOT NULL,
    used_at     DATETIME     NULL DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ctl_token (token),
    KEY idx_ctl_client (client_id),
    CONSTRAINT fk_ctl_client FOREIGN KEY (client_id) REFERENCES clients(id)   ON DELETE CASCADE,
    CONSTRAINT fk_ctl_club   FOREIGN KEY (club_id)   REFERENCES sys_clubs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Лог надісланих нагадувань — дедуплікація повторних запусків крону ---------
CREATE TABLE IF NOT EXISTS telegram_notifications_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    client_id   INT UNSIGNED NOT NULL,
    invoice_id  INT UNSIGNED NOT NULL,
    notif_type  VARCHAR(32)  NOT NULL, -- напр. 'expiry_3d', 'expiry_today'
    sent_at     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tnl_dedupe (client_id, invoice_id, notif_type),
    CONSTRAINT fk_tnl_client  FOREIGN KEY (client_id)  REFERENCES clients(id)         ON DELETE CASCADE,
    CONSTRAINT fk_tnl_invoice FOREIGN KEY (invoice_id) REFERENCES client_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Право керування прив'язкою — за тим самим золотим-стандарт патерном,
--    що й 2026-08-08_permissions_matrix.sql (manager + owner) ------------------
INSERT INTO sys_permissions (slug, label, category, sort_order) VALUES
    ('telegram.manage', 'Прив\'язка клієнтів до Telegram-бота', 'clients', 50)
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category), sort_order = VALUES(sort_order);

INSERT IGNORE INTO sys_role_permissions (role_id, permission_slug)
SELECT r.id, p.slug
FROM sys_roles r
JOIN sys_permissions p ON p.slug = 'telegram.manage'
WHERE r.slug IN ('manager', 'owner');
