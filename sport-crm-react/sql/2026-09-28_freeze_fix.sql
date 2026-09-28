-- ============================================================================
-- Заморозка абонементів: тривалість ПОТОЧНОЇ заморозки + виправлення зайвого дня.
-- Виконати в phpMyAdmin → SQL ДО заливки нових PHP-файлів
-- (старий код з новою колонкою працює як раніше — порядок безпечний).
-- Дата: 2026-09-28
-- ============================================================================

-- 1) Тривалість поточної (активної або запланованої) заморозки.
--    freeze_days лишається ЗАГАЛЬНОЮ сумою всіх заморозок абонемента.
ALTER TABLE client_invoices
  ADD COLUMN freeze_current_days SMALLINT NULL DEFAULT NULL
      COMMENT 'Днів поточної/запланованої заморозки (NULL — заморозки немає)' AFTER freeze_start;

-- Для заморозок, що діють зараз, найкраще наближення — загальна кількість днів.
UPDATE client_invoices
SET freeze_current_days = freeze_days
WHERE freeze_start IS NOT NULL AND freeze_days > 0;


-- ============================================================================
-- 2) Зайвий день від заморозки (стара формула давала +1 день).
--    Правильно: кінець = початок + (днів тарифу − 1) + дні заморозки + дні продовження.
--    Знаходимо абонементи, де кінець рівно на 1 день пізніше.
-- ============================================================================

-- 2а) ПЕРЕГЛЯД (нічого не змінює). Перегляньте список перед кроком 2б.
--     ⚠ Якщо тариф змінювали вже ПІСЛЯ продажу абонемента, рядок може потрапити сюди
--     помилково — такі абонементи перевірте вручну.
SELECT ci.id, c.full_name, ci.tariff_name, ci.start_date, ci.end_date,
       t.duration_days, ci.freeze_days, ci.prolong_days,
       DATE_SUB(ci.end_date, INTERVAL 1 DAY) AS correct_end_date
FROM client_invoices ci
JOIN tariffs t  ON t.id = ci.tariff_id
JOIN clients c  ON c.id = ci.client_id
WHERE ci.freeze_days > 0
  AND ci.status <> 'cancelled'
  AND DATEDIFF(ci.end_date, ci.start_date)
      = t.duration_days + ci.freeze_days + COALESCE(ci.prolong_days, 0);

-- 2б) ВИПРАВЛЕННЯ: мінус 1 зайвий день.
UPDATE client_invoices ci
JOIN tariffs t ON t.id = ci.tariff_id
SET ci.end_date = DATE_SUB(ci.end_date, INTERVAL 1 DAY)
WHERE ci.freeze_days > 0
  AND ci.status <> 'cancelled'
  AND DATEDIFF(ci.end_date, ci.start_date)
      = t.duration_days + ci.freeze_days + COALESCE(ci.prolong_days, 0);
