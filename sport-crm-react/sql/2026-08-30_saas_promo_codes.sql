-- ============================================================================
-- Промокоди на знижку для SaaS-тарифів (оплата підписки на сам Sport CRM,
-- НЕ клієнтські абонементи). Кожен код одноразовий — рівно 1 використання,
-- після чого назавжди позначається як used і більше не застосовується.
-- Використовується: api/billing_api.php (check_promo, create_payment),
--                    api/saas_api.php (get_promo_codes/save_promo_code/
--                    delete_promo_code/reset_promo_code — тільки SuperAdmin),
--                    src/pages/BillingPage.jsx, src/pages/SaasPage.jsx.
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-08-30
-- ============================================================================
-- ВАЖЛИВО: FK на saas_plans/saas_invoices навмисно НЕ додано — тип їх PK
-- невідомий (ці таблиці існують поза цим репозиторієм, без збереженої
-- схеми), а в проєкті вже були реальні MySQL-помилки #3780 через
-- невідповідність signed/unsigned. plan_id/used_invoice_id перевіряються
-- на рівні PHP-коду. FK на sys_clubs залишено — цей тип (INT UNSIGNED)
-- підтверджений іншими міграціями (див. sql/2026-08-27_gift_certificates.sql).
-- ============================================================================

CREATE TABLE IF NOT EXISTS saas_promo_codes (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    code              VARCHAR(32)  NOT NULL,
    discount_type     ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    discount_value    DECIMAL(10,2) NOT NULL,
    plan_id           INT NULL,                 -- NULL = діє на будь-який тариф
    valid_until       DATE NULL,                -- NULL = без обмеження строку
    is_active         TINYINT(1) NOT NULL DEFAULT 1,
    is_used           TINYINT(1) NOT NULL DEFAULT 0,
    used_club_id      INT UNSIGNED NULL,
    used_invoice_id   INT NULL,
    used_at           DATETIME NULL,
    notes             VARCHAR(255) NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_promo_code (code),
    KEY idx_promo_active (is_active, is_used),
    CONSTRAINT fk_promo_used_club FOREIGN KEY (used_club_id) REFERENCES sys_clubs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Слід застосованого промокоду на рахунку — для історії платежів
ALTER TABLE saas_invoices ADD COLUMN promo_code VARCHAR(32) NULL AFTER amount;
ALTER TABLE saas_invoices ADD COLUMN discount_amount DECIMAL(10,2) NULL AFTER promo_code;
