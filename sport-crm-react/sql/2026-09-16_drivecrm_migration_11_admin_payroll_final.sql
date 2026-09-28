-- Локальний тестовий прогін: виплати зарплати адмінам з drivecrm -> crm4fitness
-- Джерело: er452618_drivecrm.tblpaymentdayadmin (1522 щоденних записи,
--          2024-08-01..2026-09-13, 14 унікальних email).
-- Ціль: staff_payroll (1 рядок = місяць на людину, а не на день).
--
-- Правила (узгоджено з користувачем 2026-09-16):
--  - Агрегація по (UserEmail, MONTH(PaymentDate)): SUM(Amount) -> pay_day,
--    COUNT(*) -> days_worked. pct_tovar/pct_abon/plan_bonus/pay_month/
--    pay_hour/hours_worked = 0 — стара база не розбивала Amount на
--    складові, це вже підсумкова денна виплата.
--  - Перенесено ПОВНІСТЮ до останнього наявного запису (2026-09-13) —
--    стара система ще активно пише дані, повторний перенос при
--    розбіжності потрібно буде звірити вручну.
--  - status: усі дні місяця Paid=1 -> 'paid' (paid_amount=total);
--    жоден не Paid -> 'pending' (paid_amount=0); суміш -> 'partial'
--    (paid_amount = сума лише Paid=1 записів).
--  - club_id = ClubID (1/2 напряму, як і в міграції адмінів/тренерів).
--  - user_id: тільки для 12 email, що збігаються зі sys_users з
--    migration_admin_id_map (тобто вже мігрованих адмінів).
--  - ПРОПУЩЕНО: de.slavik@gmail.com (5 записів, 3450₴ — вже SuperAdmin
--    id=1, не було в migration_admin_id_map) та psenichnaarina0@gmail.com
--    (20 записів, 15700₴ — відсутня в usertable, не мігрована як адмін).
--  - created_by = NULL (історичний імпорт, не дія конкретного юзера).

USE er452618_crm4fitness;
SET collation_connection = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS migration_admin_payroll_map (
  user_id      INT UNSIGNED NOT NULL,
  period_month CHAR(7)      NOT NULL,
  payroll_id   INT UNSIGNED NOT NULL,
  migrated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, period_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TEMPORARY TABLE IF EXISTS tmp_admin_payroll;
CREATE TEMPORARY TABLE tmp_admin_payroll AS
SELECT
  m.user_id,
  p.ClubID AS club_id,
  DATE_FORMAT(p.PaymentDate, '%Y-%m') AS period_month,
  SUM(p.Amount) AS total_amount,
  COUNT(*) AS days_worked,
  SUM(CASE WHEN p.Paid = 1 THEN p.Amount ELSE 0 END) AS paid_amount,
  SUM(p.Paid = 1) AS paid_days,
  COUNT(*) AS total_days
FROM er452618_drivecrm.tblpaymentdayadmin p
JOIN sys_users su ON su.email = CONVERT(p.UserEmail USING utf8mb4) COLLATE utf8mb4_unicode_ci
JOIN migration_admin_id_map m ON m.user_id = su.id
GROUP BY m.user_id, p.ClubID, DATE_FORMAT(p.PaymentDate, '%Y-%m');

INSERT INTO staff_payroll
  (user_id, club_id, period_month, pay_day, days_worked, total_amount, paid_amount, status, created_by, created_at)
SELECT
  t.user_id, t.club_id, t.period_month, t.total_amount, t.days_worked, t.total_amount,
  t.paid_amount,
  CASE
    WHEN t.paid_days = t.total_days THEN 'paid'
    WHEN t.paid_days = 0 THEN 'pending'
    ELSE 'partial'
  END,
  NULL, NOW()
FROM tmp_admin_payroll t
WHERE NOT EXISTS (
  SELECT 1 FROM staff_payroll sp WHERE sp.user_id = t.user_id AND sp.period_month = t.period_month
);

INSERT INTO migration_admin_payroll_map (user_id, period_month, payroll_id)
SELECT t.user_id, t.period_month, sp.id
FROM tmp_admin_payroll t
JOIN staff_payroll sp ON sp.user_id = t.user_id AND sp.period_month = t.period_month;

-- ── Перевірка ────────────────────────────────────────────────────────────
SELECT COUNT(*) AS migrated_payroll_rows, SUM(total_amount) AS total_sum FROM tmp_admin_payroll;
SELECT sp.id, u.full_name, sp.club_id, sp.period_month, sp.pay_day, sp.days_worked,
       sp.total_amount, sp.paid_amount, sp.status
FROM staff_payroll sp
JOIN sys_users u ON u.id = sp.user_id
JOIN migration_admin_payroll_map m ON m.payroll_id = sp.id
ORDER BY u.full_name, sp.period_month LIMIT 30;
