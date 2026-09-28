-- Додає компонент "Бонус за перевищення плану" до налаштувань зарплати
-- та зберігає нараховану суму бонусу в історії нарахувань.
-- "Ставка за зміну" (pay_day_on/pay_day_amount) і "Ставка за годину" (pay_hour_on/pay_hour_amount)
-- лишаються в схемі без змін — годинна ставка просто більше не показується в UI.

ALTER TABLE staff_salary_settings
  ADD COLUMN plan_on TINYINT(1) NOT NULL DEFAULT 0 AFTER pct_abon_value,
  ADD COLUMN plan_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER plan_on,
  ADD COLUMN plan_bonus_pct DECIMAL(6,4) NOT NULL DEFAULT 0 AFTER plan_amount;

ALTER TABLE staff_payroll
  ADD COLUMN plan_bonus DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER pct_abon;
