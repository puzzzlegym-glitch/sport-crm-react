-- Локальний тестовий прогін етапу 3 (абонементи/інвойси) з drivecrm -> crm4fitness
-- Джерело: er452618_drivecrm.tblInvoices (94 321 рядків)
-- Ціль:    er452618_crm4fitness.client_invoices
--
-- Правила мапінгу (виведені з аналізу даних 2026-09-13):
--  - ClubID: тільки 1,2 (Драйв 3.0 і сміттєвий ClubID=0 — пропускаються,
--    консистентно з виключенням клієнтів).
--  - ClientID: має бути в migration_client_id_map (інакше клієнта не
--    перенесли — Драйв3.0-клієнт або сирота). 1143 таких інвойсів пропускаються.
--  - PaketyID + ClubID -> migration_tariff_id_map (тариф дубльований на клуб,
--    тому потрібні обидва). Якщо не знайдено (118 випадків) -> tariff_id NULL,
--    але tariff_name (текст Paket) зберігається завжди.
--  - price = Narahovano ("нараховано" = повна вартість інвойса),
--    paid_amount = Oplacheno (Balance = Oplacheno - Narahovano, перевірено
--    на вибірці — узгоджується).
--  - start_date = InvoiceDate, end_date = DeliveryDate (перевірено:
--    DeliveryDate = InvoiceDate + Period днів на вибірці).
--  - visits_total = KtVidvid (реальне число з інвойса; НЕ з тарифу —
--    1743 інвойси мають своє значення, відмінне від шаблону тарифу).
--  - visits_used = 0 зараз, буде перераховано агрегатно після заливки
--    tblWorks -> visits (етап 5, як і в оригінальному плані 2026-09-11).
--  - trainer_id = NULL, trainer_name = Trener (текст) — історичні нарахування
--    тренерам 1:1 свідомо НЕ відтворюємо (рішення з оригінального плану
--    2026-09-11, п. trg_visit_trainer_earning).
--  - status: 'frozen' якщо Zamorozka>0, інакше 'expired' якщо DeliveryDate
--    в минулому, інакше 'active'. НЕ використовуємо 'cancelled' для
--    недоплачених (цеінша семантика в оригінальному тригері прода,
--    тут свідомо спрощено — уточнити з власником, якщо потрібно інакше).
--  - sale_type = 'new' для всіх (немає надійного джерела для renewal/return).

USE er452618_crm4fitness;

CREATE TABLE IF NOT EXISTS migration_invoice_id_map (
  old_id   BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  new_id   INT UNSIGNED NOT NULL,
  migrated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_new_id (new_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @start_id := (
  SELECT AUTO_INCREMENT FROM information_schema.TABLES
  WHERE table_schema = 'er452618_crm4fitness' AND table_name = 'client_invoices'
);

INSERT INTO migration_invoice_id_map (old_id, new_id)
SELECT inv.ID, @start_id - 1 + ROW_NUMBER() OVER (ORDER BY inv.ID)
FROM er452618_drivecrm.tblInvoices inv
JOIN migration_client_id_map cm ON cm.old_id = inv.ClientID
WHERE inv.ClubID IN (1,2)
  AND inv.ID NOT IN (SELECT old_id FROM migration_invoice_id_map);

INSERT INTO client_invoices
  (id, club_id, client_id, tariff_id, tariff_name, price, paid_amount,
   start_date, end_date, visits_total, visits_used, status, sale_type,
   trainer_name, freeze_days, freeze_start, prolong_days, created_by, created_at)
SELECT
  m.new_id,
  inv.ClubID,
  cm.new_id,
  tm.new_id,
  inv.Paket,
  inv.Narahovano,
  inv.Oplacheno,
  inv.InvoiceDate,
  inv.DeliveryDate,
  NULLIF(inv.KtVidvid, 0),
  0,
  CASE
    WHEN inv.Zamorozka > 0 THEN 'frozen'
    WHEN inv.DeliveryDate < CURDATE() THEN 'expired'
    ELSE 'active'
  END,
  'new',
  NULLIF(TRIM(inv.Trener), ''),
  inv.Zamorozka,
  inv.StartZamorozka,
  inv.ProlongDay,
  1,
  inv.InvoiceDate
FROM er452618_drivecrm.tblInvoices inv
JOIN migration_client_id_map cm ON cm.old_id = inv.ClientID
JOIN migration_invoice_id_map m ON m.old_id = inv.ID
LEFT JOIN migration_tariff_id_map tm ON tm.old_id = inv.PaketyID AND tm.club_id = inv.ClubID
WHERE NOT EXISTS (SELECT 1 FROM client_invoices ci WHERE ci.id = m.new_id);

-- ── Перевірка ────────────────────────────────────────────────────────────
SELECT COUNT(*) AS imported_invoices FROM migration_invoice_id_map;

SELECT club_id, status, COUNT(*) FROM client_invoices WHERE id >= @start_id GROUP BY club_id, status;

SELECT COUNT(*) AS null_tariff_id FROM client_invoices WHERE id >= @start_id AND tariff_id IS NULL;

SELECT ci.id, ci.club_id, ci.client_id, ci.tariff_id, ci.tariff_name, ci.price, ci.paid_amount,
       ci.start_date, ci.end_date, ci.visits_total, ci.status
FROM client_invoices ci
WHERE ci.id >= @start_id
ORDER BY ci.id DESC LIMIT 15;

-- Звірка Balance: Oplacheno - Narahovano має збігатись з paid_amount-price
SELECT COUNT(*) AS balance_mismatch
FROM er452618_drivecrm.tblInvoices inv
JOIN migration_invoice_id_map m ON m.old_id = inv.ID
JOIN client_invoices ci ON ci.id = m.new_id
WHERE ROUND(ci.paid_amount - ci.price, 2) <> ROUND(inv.Balance, 2);
