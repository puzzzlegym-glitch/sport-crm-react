-- ============================================================================
-- Каса: повернення (сторно) оплат і закриття зміни з фактичним залишком.
-- Виконати в phpMyAdmin → SQL ДО заливки нових PHP-файлів
-- (старий код з цими колонками працює як раніше — порядок безпечний).
-- Дата: 2026-09-28
-- ============================================================================

-- 1) Повернення оплат: рядок club_payments з від'ємною сумою, що посилається
--    на початкову оплату. Оплати закритих змін більше не редагуються/видаляються.
ALTER TABLE club_payments
  ADD COLUMN refund_of_id  BIGINT UNSIGNED NULL DEFAULT NULL
      COMMENT 'Повернення: id початкової оплати (amount < 0)' AFTER invoice_id,
  ADD COLUMN refund_reason VARCHAR(255) NULL DEFAULT NULL
      COMMENT 'Причина повернення (обовʼязкова)' AFTER refund_of_id,
  ADD KEY idx_refund_of (refund_of_id);

-- 2) Закриття зміни з перерахунком готівки.
ALTER TABLE cash_shifts
  ADD COLUMN balance_counted DECIMAL(10,2) NULL DEFAULT NULL
      COMMENT 'Фактично пораховано в касі при закритті' AFTER balance_close,
  ADD COLUMN discrepancy DECIMAL(10,2) NULL DEFAULT NULL
      COMMENT 'Фактично − очікувано: < 0 недостача, > 0 надлишок' AFTER balance_counted,
  ADD COLUMN adjustments_shift DECIMAL(10,2) NOT NULL DEFAULT 0.00
      COMMENT 'Сума коригувань за зміну (у т.ч. недостача/надлишок)' AFTER expense_shift;
