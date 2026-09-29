-- ============================================================================
-- Групові абонементи (абонемент "оптом": корпоративні, сімейні, командні).
--
-- Модель:
--   invoice_groups — сама група (договір): тариф, спільні дати для всіх,
--                    ціна для учасника, мінімальна оплата для активації,
--                    ліміт учасників (NULL = без обмеження).
--   client_invoices.group_id — абонемент учасника належить групі. Кожен
--                    учасник має СВІЙ звичайний абонемент (відвідування,
--                    заморозка, сканування працюють як завжди).
--   client_invoices.min_paid_to_activate — абонемент не діє (статус
--                    "Очікує оплати", вхід заборонено), доки paid_amount
--                    менше цієї суми. Для звичайних абонементів — NULL.
--
-- Дати абонемента учасника = дати групи. Учасник, що долучився пізніше,
-- отримує ті самі дати (термін не зсувається).
--
-- Оплата: кожен платить за себе (зі свого депозиту / готівкою / карткою)
-- або хтось один платить за всіх — тоді платіж записується на абонемент
-- кожного учасника, а депозит списується з депозиту ПЛАТНИКА.
--
-- MySQL на цьому хостингу не підтримує ALTER TABLE ... IF NOT EXISTS —
-- якщо колонка вже існує, пропустіть відповідний ALTER.
--
-- Використовується: crm/api/invoices_api.php (дії group_*),
--                    crm/api/visits_api.php, app/core/Attendance.php,
--                    src/api/invoices.js, src/pages/InvoiceGroupsTab.jsx,
--                    src/pages/InvoiceGroupCardPage.jsx
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-09-29
-- ============================================================================

-- 1) Група -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoice_groups (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id             INT UNSIGNED NOT NULL,
    name                VARCHAR(150) NOT NULL,
    tariff_id           INT UNSIGNED NULL,
    tariff_name         VARCHAR(255) NOT NULL,
    owner_client_id     INT UNSIGNED NULL,       -- контактна особа / платник за замовчуванням
    start_date          DATE NOT NULL,
    end_date            DATE NOT NULL,
    member_price        DECIMAL(10,2) NOT NULL DEFAULT 0,  -- вартість абонемента одного учасника
    min_payment         DECIMAL(10,2) NOT NULL DEFAULT 0,  -- мінімальна оплата учасника для активації
    max_members         INT NULL,                -- NULL = без обмеження
    status              ENUM('active','closed') NOT NULL DEFAULT 'active',
    notes               VARCHAR(500) NULL,
    legacy_old_id       BIGINT UNSIGNED NULL,    -- id з drivecrm.tblInvoicesGroup (для перенесення)
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_invoice_groups_club (club_id, status),
    UNIQUE KEY uq_invoice_groups_legacy (legacy_old_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Прив'язка абонемента до групи + поріг активації --------------------------
ALTER TABLE client_invoices
  ADD COLUMN group_id INT UNSIGNED NULL AFTER tariff_name,
  ADD COLUMN min_paid_to_activate DECIMAL(10,2) NULL AFTER group_id,
  ADD KEY idx_client_invoices_group (group_id);
