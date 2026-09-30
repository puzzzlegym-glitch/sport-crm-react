-- ============================================================================
-- Оренда тренерів (оплата / утримання з виплати) + сторно виплат.
--
-- trainer_rent: оренду тепер можна закривати:
--   rent_type='manual'    — тренер платить клубу сам: кнопка «Оплачено»
--                           (готівка → прихід у касу; картка / рахунок — облік);
--   rent_type='deduction' — утримується автоматично при виплаті тренеру.
--   paid_amount / status: pending → partial → paid.
-- trainer_rent_payments: кожна оплата оренди (у т.ч. утримання з виплати,
--   прив'язане до витрати-виплати expense_id — сторно виплати повертає й оренду).
-- payout_reversals: журнал сторно (хто, коли, що, сума, причина).
--
-- trainer_rent не має CREATE TABLE у цій теці (створена до конвенції міграцій),
-- тому status переводиться у VARCHAR — безпечно, чи він був ENUM, чи VARCHAR.
-- MySQL на цьому хостингу не підтримує ALTER TABLE ... IF NOT EXISTS —
-- якщо колонка вже існує, пропустіть відповідний рядок.
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-10-01
-- ============================================================================

ALTER TABLE trainer_rent
  MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending',
  ADD COLUMN paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER amount,
  ADD COLUMN paid_at DATETIME NULL AFTER paid_amount;

CREATE TABLE IF NOT EXISTS trainer_rent_payments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id         INT UNSIGNED NOT NULL,
    rent_id         INT UNSIGNED NOT NULL,
    trainer_id      INT UNSIGNED NOT NULL,
    amount          DECIMAL(12,2) NOT NULL,
    payment_method  VARCHAR(20) NOT NULL,           -- cash / card / transfer
    source          VARCHAR(20) NOT NULL,           -- manual (тренер заплатив) / deduction (утримано з виплати)
    expense_id      INT UNSIGNED NULL,              -- виплата тренеру, з якої утримано (для deduction)
    admin_id        INT UNSIGNED NULL,
    admin_name      VARCHAR(191) NULL,
    notes           VARCHAR(500) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_trp_rent (rent_id),
    KEY idx_trp_expense (expense_id),
    KEY idx_trp_club_date (club_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payout_reversals (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id         INT UNSIGNED NOT NULL,
    kind            VARCHAR(30) NOT NULL,           -- trainer_payout / staff_payroll / trainer_rent
    ref_id          INT UNSIGNED NOT NULL,          -- id витрати або оплати оренди
    amount          DECIMAL(12,2) NOT NULL,
    payment_method  VARCHAR(20) NULL,
    description     VARCHAR(500) NULL,
    reason          VARCHAR(500) NOT NULL,
    reversed_by     INT UNSIGNED NULL,
    reversed_by_name VARCHAR(191) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_payout_reversals_club (club_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
