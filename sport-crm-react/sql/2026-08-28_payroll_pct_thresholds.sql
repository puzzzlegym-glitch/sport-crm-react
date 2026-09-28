-- Мінімальний план продажів товарів / абонементів, з якого починається нарахування %
-- (до цього порогу % не нараховується, після — нараховується на суму понад поріг).

ALTER TABLE staff_salary_settings
  ADD COLUMN pct_tovar_min DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER pct_tovar_value,
  ADD COLUMN pct_abon_min DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER pct_abon_value;
