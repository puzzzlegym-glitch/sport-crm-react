-- ============================================================================
-- Фіскалізація чеків через Checkbox ПРРО (https://wiki.checkbox.ua/uk/api).
-- Підключення/каса/касир — per-club (кожен клуб має свій кабінет у Checkbox),
-- на відміну від WayForPay (WFP_MERCHANT_*), який платформний і живе в
-- константах app/config.php — тому тут окремі таблиці, а не константи.
-- Використовується: classes/CheckboxService.php, api/prro_api.php,
--                    api/invoices_api.php (self_maybeFiscalize),
--                    cron/retry_fiscal_receipts.php,
--                    src/api/prro.js, src/pages/PrroSettingsPage.jsx
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-08-30
--
-- ПЕРЕД ЗАСТОСУВАННЯМ: перевірити через information_schema.COLUMNS, чи
-- колонки з розділу 2 вже існують у club_payments (ALTER ... ADD COLUMN тут
-- не має IF NOT EXISTS — на цьому хостингу MySQL він не підтримується).
--
-- ВАЖЛИВО (безпека, ТЗ розділ 7): cashier_password_enc і license_key_enc
-- зберігаються зашифрованими (AES-256-CBC, CheckboxService::encrypt/decrypt).
-- Перед першим використанням треба додати в app/config.php (поруч із
-- WFP_MERCHANT_SECRET) новий рядок:
--   define('PRRO_ENCRYPTION_KEY', '<32+ випадкових байти, напр. bin2hex(random_bytes(32))>');
-- Без цієї константи CheckboxService навмисно кидає виняток — жодного
-- небезпечного фолбеку "зберегти як є".
-- ============================================================================

-- 1) Підключення клубу до Checkbox (каса, касир, стан синхронізації) --------
CREATE TABLE IF NOT EXISTS club_prro_settings (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    club_id                 INT UNSIGNED NOT NULL,
    provider                VARCHAR(20)  NOT NULL DEFAULT 'checkbox',
    license_key_enc         VARCHAR(500) NULL,     -- ліцензійний ключ ПРРО, зашифровано
    cashier_login           VARCHAR(100) NULL,
    cashier_password_enc    VARCHAR(500) NULL,     -- зашифровано, не plain text
    cash_register_id        VARCHAR(100) NULL,     -- UUID каси в Checkbox
    cashier_id               VARCHAR(100) NULL,     -- UUID касира в Checkbox
    access_token_enc        VARCHAR(1000) NULL,    -- кеш токена сесії касира (authenticate())
    access_token_expires_at DATETIME NULL,
    is_active               TINYINT(1) NOT NULL DEFAULT 0,  -- ввімк/вимк фіскалізацію для клубу загалом
    last_sync_at            DATETIME NULL,
    last_sync_status        VARCHAR(50) NULL,      -- 'ok' | 'error'
    last_sync_error         TEXT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_prro_club (club_id),
    CONSTRAINT fk_prro_settings_club FOREIGN KEY (club_id) REFERENCES sys_clubs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Які способи оплати фіскалізувати автоматично (per club) ----------------
CREATE TABLE IF NOT EXISTS club_prro_payment_methods (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    club_id         INT UNSIGNED NOT NULL,
    payment_method  VARCHAR(20) NOT NULL,  -- cash | card | terminal | deposit | other
    auto_fiscalize  TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_prro_club_method (club_id, payment_method),
    CONSTRAINT fk_prro_methods_club FOREIGN KEY (club_id) REFERENCES sys_clubs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Статус фіскалізації на самому платежі — club_payments (НЕ club_cashflow:
--    та таблиця — ручний журнал каси/готівки, не пов'язаний з оплатами
--    клієнтів; реальні оплати абонементів пишуться в club_payments через
--    self_addPayment() в invoices_api.php). ------------------------------
ALTER TABLE club_payments
  ADD COLUMN fiscal_status      VARCHAR(20)  NULL DEFAULT NULL COMMENT 'null=не потрібен | pending | sent | failed | skipped_manual',
  ADD COLUMN fiscal_receipt_id  VARCHAR(100) NULL,
  ADD COLUMN fiscal_receipt_url VARCHAR(500) NULL,
  ADD COLUMN fiscal_error       TEXT NULL,
  ADD COLUMN fiscal_attempts    INT NOT NULL DEFAULT 0 COMMENT 'лічильник спроб для cron/retry_fiscal_receipts.php, обмежує повтори';

ALTER TABLE club_payments
  ADD INDEX idx_club_payments_fiscal_retry (fiscal_status, fiscal_attempts);

-- 4) Право керування підключенням ПРРО — лише owner (рівень 80), за золотим
--    стандартом з 2026-08-27_certificates_permissions.sql. ------------------
INSERT INTO sys_permissions (slug, label, category, sort_order) VALUES
    ('prro.manage', 'Підключення та налаштування Checkbox ПРРО', 'settings', 60)
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category), sort_order = VALUES(sort_order);

INSERT IGNORE INTO sys_role_permissions (role_id, permission_slug)
SELECT r.id, p.slug
FROM sys_roles r
JOIN sys_permissions p ON p.slug = 'prro.manage'
WHERE r.slug = 'owner';
