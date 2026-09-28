-- Локальний тестовий прогін етапу 4d (відвідування) з drivecrm -> crm4fitness
-- Джерело: er452618_drivecrm.tblWorks (491 665 рядків, найбільша таблиця)
-- Ціль:    er452618_crm4fitness.visits
--
-- Правила:
--  - ClubID тільки 1,2 (3=Драйв3.0, 100=сміття — виключені), клієнт має
--    бути мігрований. 442 362 з 491 665 підпадають.
--  - invoice_id: ВИПРАВЛЕНО 2026-09-15 (виявлено на реальному прикладі клієнтки
--    з нульовими відвідуваннями на абонементі, хоча дані переносились) —
--    tblWorks.OplataID є ПРЯМИМ посиланням на tblInvoices.ID, а НЕ на
--    tblInvoicesOplatu.ID. Перевірено на всій таблиці: пряме
--    inv.ID = w.OplataID матчиться на 448 124 з 448 178 рядків (99.99%),
--    тоді як стара (хибна) гіпотеза через tblInvoicesOplatu.ID матчила лише
--    347 843 — і то здебільшого випадковими числовими збігами, не реальним
--    зв'язком (у клієнтки з прикладу жоден з ~80 візитів не мав збігу за
--    старою логікою, хоча всі коректно резолвились напряму). Тепер:
--    invoice_id = migration_invoice_id_map.new_id WHERE old_id = w.OplataID.
--    Рядки без матчу (0.01%, ~54) — дійсно "висячі" посилання в джерелі,
--    invoice_id NULL, рядок все одно переноситься (invoice_id nullable).
--  - trainer_id=NULL, trainer_name=Trener (текст) — без відновлення 1:1.
--  - method='admin' (історичний імпорт, не сканування штрихкоду).

USE er452618_crm4fitness;

INSERT INTO visits
  (club_id, client_id, invoice_id, visited_at, method, admin_name, trainer_name, created_by)
SELECT
  w.ClubID,
  cm.new_id,
  im.new_id,
  w.Dat,
  'admin',
  NULLIF(TRIM(w.UserName), ''),
  NULLIF(TRIM(w.Trener), ''),
  1
FROM er452618_drivecrm.tblWorks w
JOIN migration_client_id_map cm ON cm.old_id = w.ClientID
LEFT JOIN migration_invoice_id_map im ON im.old_id = w.OplataID
WHERE w.ClubID IN (1,2);

-- Ручний перерахунок visits_used (тригера trg_visit_after_insert більше нема)
UPDATE client_invoices ci
JOIN (
  SELECT invoice_id, COUNT(*) AS cnt
  FROM visits
  WHERE invoice_id IS NOT NULL
  GROUP BY invoice_id
) v ON v.invoice_id = ci.id
SET ci.visits_used = v.cnt;

-- ── Перевірка ────────────────────────────────────────────────────────────
SELECT COUNT(*) AS imported_visits FROM visits;

SELECT COUNT(*) AS with_invoice FROM visits WHERE invoice_id IS NOT NULL;

SELECT club_id, COUNT(*) FROM visits GROUP BY club_id;

-- Скільки інвойсів тепер мають visits_used > visits_total (переперевищення ліміту)
SELECT COUNT(*) AS over_limit
FROM client_invoices
WHERE visits_total IS NOT NULL AND visits_used > visits_total;
