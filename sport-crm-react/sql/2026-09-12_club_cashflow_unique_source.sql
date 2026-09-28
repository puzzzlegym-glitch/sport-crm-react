-- Фаза 3 переносу тригерів у код (Категорія 2): готуємо club_cashflow до
-- безпечного idempotent-upsert з PHP замість blind INSERT.
--
-- trg_cashflow_expense_insert/trg_cashflow_payment_insert/trg_cashflow_sale_insert
-- зараз при кожній події просто INSERT-ять новий рядок club_cashflow. Якщо
-- продублювати цю вставку в PHP, поки тригер лишається активним (як зроблено
-- для Категорії 1), кожна подія дасть ДВА рядки в club_cashflow замість
-- одного — подвоєння доходів/витрат у фінансовому журналі. На момент
-- застосування дублікатів (source, source_id) в таблиці не було (звірено
-- 2026-09-12, 23 рядки).
--
-- UNIQUE дозволяє PHP-коду робити
--   INSERT ... ON DUPLICATE KEY UPDATE ...
-- — якщо тригер уже вставив рядок, PHP лише оновить його (без дублю); коли
-- тригер згодом приберуть, той самий INSERT ... ON DUPLICATE KEY просто
-- вставить рядок сам. NULL в source/source_id (ручні записи каси) НЕ
-- конфліктують між собою — MySQL не вважає NULL=NULL для UNIQUE.
ALTER TABLE club_cashflow
  ADD UNIQUE KEY uniq_cashflow_source (source, source_id);
