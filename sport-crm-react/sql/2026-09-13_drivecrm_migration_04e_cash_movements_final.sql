-- Локальний тестовий прогін етапу 4e (рух готівки: виплати/зняття/повернення)
-- з drivecrm -> crm4fitness.
-- Джерела: tblReestrGotivka (2983), tblZnjatoZKasu (3619), tblVurtatuNeZkasu (1078)
-- Ціль: club_expenses (НЕ club_cashflow — той свідомо не бекафілимо історією,
-- рішення від 11.09.2026, бо це "сьогоднішня" операційна каса, а не архів).
--
-- Аналіз показав: tblReestrGotivka — це НЕ дзеркало продажів (як я спершу
-- припустив), а реальні видаткові операції (видача готівки власнику,
-- зарплати, закупка товару, оренда) — тобто справжня фінансова історія,
-- вартує перенесення.
--
-- Мапінг:
--  - category: tblReestrGotivka/tblVurtatuNeZkasu мають текстову Kategory ->
--    напряму в category. tblZnjatoZKasu категорії нема -> фіксоване
--    'Зняття з каси'.
--  - amount: як є, зі знаком з джерела (мала кількість від'ємних записів —
--    "повернення в касу" — це коректно, не інвертую).
--  - source: окремий тег на кожну з 3 таблиць-джерел для трасування.
--  - club_id тільки 1,2 (Драйв3.0 виключено, як і в усіх попередніх етапах).

USE er452618_crm4fitness;

-- --- tblReestrGotivka ---------------------------------------------------
-- source_id (int) замалий для старих bigint ID (2026091210133003 і т.п.) ->
-- старий ID переноситься в notes для трасування замість source_id.
INSERT INTO club_expenses
  (club_id, category, description, amount, expense_date, payment_method,
   admin_name, source, notes, created_at)
SELECT
  r.ClubID,
  r.Kategory,
  LEFT(COALESCE(NULLIF(TRIM(r.PrumitkaGotivka), ''), r.Kategory), 255),
  r.Summ,
  DATE(r.PaymentDate),
  CASE
    WHEN r.Kategory LIKE '%термінал%' THEN 'terminal'
    WHEN r.Kategory LIKE '%картка%' THEN 'card'
    ELSE 'cash'
  END,
  NULLIF(TRIM(r.Username), ''),
  'drivecrm_reestr',
  CONCAT('Міграція з drivecrm, tblReestrGotivka.ID=', r.ID),
  r.PaymentDate
FROM er452618_drivecrm.tblReestrGotivka r
WHERE r.ClubID IN (1,2);

-- --- tblZnjatoZKasu -------------------------------------------------------
INSERT INTO club_expenses
  (club_id, category, description, amount, expense_date, payment_method,
   admin_name, source, notes, created_at)
SELECT
  z.ClubID,
  'Зняття з каси',
  LEFT(COALESCE(NULLIF(TRIM(z.Prumitka), ''), 'Зняття з каси'), 255),
  z.Summ,
  DATE(z.PaymentDate),
  'cash',
  NULLIF(TRIM(z.Userok), ''),
  'drivecrm_znyato',
  CONCAT('Міграція з drivecrm, tblZnjatoZKasu.ID=', z.ID),
  z.PaymentDate
FROM er452618_drivecrm.tblZnjatoZKasu z
WHERE z.ClubID IN (1,2);

-- --- tblVurtatuNeZkasu -----------------------------------------------------
INSERT INTO club_expenses
  (club_id, category, description, amount, expense_date, payment_method,
   source, notes, created_at)
SELECT
  v.ClubID,
  v.Kategory,
  LEFT(COALESCE(NULLIF(TRIM(v.NamePrich), ''), v.Kategory), 255),
  v.Summ,
  DATE(v.PaymentDate),
  'other',
  'drivecrm_vurtatu',
  CONCAT('Міграція з drivecrm, tblVurtatuNeZkasu.ID=', v.ID),
  v.PaymentDate
FROM er452618_drivecrm.tblVurtatuNeZkasu v
WHERE v.ClubID IN (1,2);

-- ── Перевірка ────────────────────────────────────────────────────────────
SELECT source, COUNT(*), SUM(amount) FROM club_expenses GROUP BY source;

SELECT club_id, category, COUNT(*), SUM(amount) FROM club_expenses GROUP BY club_id, category ORDER BY 4 DESC LIMIT 15;
