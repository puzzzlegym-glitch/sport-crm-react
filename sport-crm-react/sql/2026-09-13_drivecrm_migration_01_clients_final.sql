-- Локальний тестовий прогін етапу 1 (клієнти) з drivecrm -> crm4fitness
-- Адаптовано з sport-crm-react/sql/2026-09-11_drivecrm_migration_01_clients.sql:
-- прибрано "ПЛАН Б" (крос-БД працює локально напряму), додано ручний
-- перерахунок clients.balance замість тригера trg_deposit_after_insert
-- (тригерів у crm4fitness більше нема — видалені власником 2026-09-12/13).

USE er452618_crm4fitness;

CREATE TABLE IF NOT EXISTS migration_client_id_map (
  old_id   BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  new_id   INT UNSIGNED NOT NULL,
  migrated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_new_id (new_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @start_id := (
  SELECT AUTO_INCREMENT FROM information_schema.TABLES
  WHERE table_schema = 'er452618_crm4fitness' AND table_name = 'clients'
);

-- Клієнти "Драйв 3.0" (старий ClubID=3, неактивний клуб у drivecrm) НЕ
-- переносяться — рішення власника 2026-09-13: клуб не існує в crm4fitness
-- і не потрібен.
INSERT INTO migration_client_id_map (old_id, new_id)
SELECT t.ID, @start_id - 1 + ROW_NUMBER() OVER (ORDER BY t.ID)
FROM er452618_drivecrm.tblNewTable t
WHERE t.ID NOT IN (SELECT old_id FROM migration_client_id_map)
  AND COALESCE(
    (SELECT inv.ClubID FROM er452618_drivecrm.tblInvoices inv
     WHERE inv.ClientID = t.ID GROUP BY inv.ClubID ORDER BY COUNT(*) DESC LIMIT 1),
    1
  ) <> 3;

INSERT INTO clients
  (id, club_id, full_name, phone, email, birthday, gender, address,
   status, source, notes, photo_url, telegram_id, barcode,
   credit, created_by, assigned_trainer_id, created_at)
SELECT
  m.new_id,
  COALESCE(
    (SELECT inv.ClubID FROM er452618_drivecrm.tblInvoices inv
     WHERE inv.ClientID = t.ID GROUP BY inv.ClubID ORDER BY COUNT(*) DESC LIMIT 1),
    1
  ) AS club_id,
  t.SomeName,
  NULLIF(TRIM(COALESCE(NULLIF(TRIM(t.Telefon), ''), t.PhoneInput)), ''),
  NULLIF(TRIM(t.Email), ''),
  IF(CAST(t.Birthday AS CHAR) = '0000-00-00', NULL, t.Birthday),
  CASE t.Gender WHEN 'чоловік' THEN 'M' WHEN 'жінка' THEN 'F' ELSE '' END,
  NULL,
  CASE t.RegistrationStatus
    WHEN 'заблокований' THEN 'blocked'
    ELSE 'regular'
  END,
  NULLIF(TRIM(t.RegisterSource), ''),
  NULLIF(TRIM(t.Prumitka), ''),
  NULLIF(TRIM(t.PhotoPic), ''),
  CASE WHEN t.TelegramToken REGEXP '^[0-9]+$'
       THEN CAST(t.TelegramToken AS UNSIGNED) ELSE NULL END,
  NULLIF(TRIM(t.BarCod), ''),
  ABS(t.Credit),                                     -- Credit у drivecrm = борг (завжди <=0), тут як додатна сума
  1,
  NULL,
  COALESCE(IF(CAST(t.DataDodavannja AS CHAR) = '0000-00-00', NULL, t.DataDodavannja), CURDATE())
FROM er452618_drivecrm.tblNewTable t
JOIN migration_client_id_map m ON m.old_id = t.ID
WHERE NOT EXISTS (SELECT 1 FROM clients c WHERE c.id = m.new_id);

-- phone_normalized: раніше писав тригер/додаток при INSERT через API,
-- тут пишемо напряму (лише цифри з +380...)
UPDATE clients c
JOIN migration_client_id_map m ON m.new_id = c.id
SET c.phone_normalized = NULLIF(REGEXP_REPLACE(c.phone, '[^0-9]', ''), '')
WHERE c.phone_normalized IS NULL AND c.phone IS NOT NULL;

-- Credit (борг) уже записаний напряму в clients.credit вище.
-- Депозити/баланс (balance) з drivecrm поки НЕ переносимо: tblNewTable.Depozit
-- лишається відкритим питанням (див. нотатку в оригінальному 01_clients.sql,
-- п.3) — окремо з'ясувати перед етапом депозитів (tblDepozit).

-- ── Перевірка ────────────────────────────────────────────────────────────
SELECT COUNT(*) AS imported_clients FROM migration_client_id_map;

SELECT club_id, COUNT(*) FROM clients GROUP BY club_id;

SELECT c.id, c.full_name, c.phone, c.phone_normalized, c.credit, c.club_id
FROM clients c
JOIN migration_client_id_map m ON m.new_id = c.id
ORDER BY c.id DESC LIMIT 10;

SELECT COUNT(*) AS clients_with_debt FROM clients WHERE credit <> 0 AND id > 5;

SELECT club_id, phone_normalized, COUNT(*) AS cnt
FROM clients
WHERE phone_normalized IS NOT NULL
GROUP BY club_id, phone_normalized
HAVING cnt > 1
LIMIT 20;
