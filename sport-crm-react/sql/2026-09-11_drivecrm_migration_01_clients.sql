-- ============================================================================
-- Міграція даних (не схеми!) зі старої бази er452618_drivecrm у поточну
-- er452618_crm4fitness. Етап 1 з N: КЛІЄНТИ.
--
-- Джерело: er452618_drivecrm.tblNewTable (+ tblInvoices для визначення клубу)
-- Ціль:    er452618_crm4fitness.clients
-- Застосувати ВРУЧНУ через phpMyAdmin, під'єднання до er452618_crm4fitness
-- (обидві бази на одному хості er452618.mysql.ukraine.com.ua — крос-БД
-- запит `er452618_drivecrm.таблиця` спрацює, якщо у користувача є права
-- на обидві бази; якщо ні — див. ПЛАН Б в кінці файлу).
--
-- ПЕРЕД ЗАПУСКОМ ПЕРЕВІРТЕ ЦІ ПРИПУЩЕННЯ (заміняють досі відкриті рішення):
--   1) club_id визначається як клуб, у якому в клієнта найбільше tblInvoices
--      (fallback = 1, "Драйв 1.0"). Якщо старий ClubID НЕ збігається 1:1
--      з новими sys_clubs.id (1=Драйв 1.0, 2=Драйв 2.0) — виправити CASE нижче.
--   2) tblNewTable.Credit -> сідиться як стартовий client_deposits, який
--      тригер trg_deposit_after_insert перетворить на clients.balance
--      (підтверджено власником: старий Credit = новий balance).
--   3) tblNewTable.Depozit НЕ переноситься — його значення ще не з'ясоване
--      (може задвоїти баланс, якщо це те саме, що Credit). Розкоментувати
--      блок у кінці, коли буде відповідь.
--   4) RegistrationStatus 'очікується' мапиться в 'regular' (нема окремого
--      статусу в новій схемі) — змінити CASE, якщо потрібно інакше.
--   5) created_by = 1 (Super Administrator) як технічний автор імпортованих
--      рядків — замінити на реальний user_id, якщо є інший.
-- Дата: 2026-09-11
-- ============================================================================

-- --- Крок 0: перевірка крос-БД доступу -------------------------------------
-- Якщо це впаде з Access denied — переходьте до ПЛАНУ Б в кінці файлу.
SELECT COUNT(*) AS old_clients_count FROM er452618_drivecrm.tblNewTable;

-- --- Крок 1: таблиця відповідності старий ID -> новий ID -------------------
-- Лишається назавжди як журнал міграції (не видаляти) — знадобиться на
-- наступних етапах (абонементи/оплати/депозити посилаються на client_id).
CREATE TABLE IF NOT EXISTS migration_client_id_map (
  old_id   BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  new_id   INT UNSIGNED NOT NULL,
  migrated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_new_id (new_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Резервуємо блок нових ID одразу за поточним максимумом, щоб не
-- зіткнутися з уже існуючими клієнтами (id 1-5 і далі — тестові дані).
SET @start_id := (
  SELECT AUTO_INCREMENT FROM information_schema.TABLES
  WHERE table_schema = 'er452618_crm4fitness' AND table_name = 'clients'
);

INSERT INTO migration_client_id_map (old_id, new_id)
SELECT t.ID, @start_id - 1 + ROW_NUMBER() OVER (ORDER BY t.ID)
FROM er452618_drivecrm.tblNewTable t
WHERE t.ID NOT IN (SELECT old_id FROM migration_client_id_map);

-- --- Крок 2: сам імпорт клієнтів --------------------------------------------
INSERT INTO er452618_crm4fitness.clients
  (id, club_id, full_name, phone, email, birthday, gender, address,
   status, source, notes, photo_url, telegram_id, barcode,
   credit, created_by, assigned_trainer_id, created_at)
SELECT
  m.new_id,
  COALESCE(
    (SELECT inv.ClubID
     FROM er452618_drivecrm.tblInvoices inv
     WHERE inv.ClientID = t.ID
     GROUP BY inv.ClubID
     ORDER BY COUNT(*) DESC
     LIMIT 1),
    1
  ) AS club_id,
  t.SomeName,
  NULLIF(TRIM(COALESCE(NULLIF(TRIM(t.Telefon), ''), t.PhoneInput)), ''),
  NULLIF(TRIM(t.Email), ''),
  t.Birthday,
  CASE t.Gender WHEN 'чоловік' THEN 'M' WHEN 'жінка' THEN 'F' ELSE '' END,
  NULL,                                             -- address: немає джерела
  CASE t.RegistrationStatus
    WHEN 'заблокований' THEN 'blocked'
    ELSE 'regular'                                  -- 'активний' і 'очікується' обидва -> regular
  END,
  NULLIF(TRIM(t.RegisterSource), ''),
  NULLIF(TRIM(t.Prumitka), ''),
  NULLIF(TRIM(t.PhotoPic), ''),
  CASE WHEN t.TelegramToken REGEXP '^[0-9]+$'
       THEN CAST(t.TelegramToken AS UNSIGNED) ELSE NULL END,
  NULLIF(TRIM(t.BarCod), ''),
  0.00,                                              -- credit (борг): немає джерела в старій базі
  1,                                                  -- created_by: Super Administrator — замінити за потреби
  NULL,                                               -- assigned_trainer_id
  COALESCE(t.DataDodavannja, CURDATE())
FROM er452618_drivecrm.tblNewTable t
JOIN migration_client_id_map m ON m.old_id = t.ID
WHERE NOT EXISTS (SELECT 1 FROM er452618_crm4fitness.clients c WHERE c.id = m.new_id);

-- --- Крок 3: стартовий баланс (Credit -> clients.balance через тригер) -----
-- Тригер trg_deposit_after_insert сам перерахує clients.balance =
-- SUM(client_deposits.amount) після цієї вставки. Пишемо лише ненульові,
-- щоб не засмічувати історію нульовими проводками.
INSERT INTO er452618_crm4fitness.client_deposits
  (club_id, client_id, amount, operation, admin_id, admin_name, notes, created_at)
SELECT
  c.club_id,
  c.id,
  t.Credit,
  'correction',
  1,
  'Міграція з drivecrm',
  CONCAT('Стартовий баланс при імпорті (старий Credit, tblNewTable.ID=', t.ID, ')'),
  NOW()
FROM er452618_drivecrm.tblNewTable t
JOIN migration_client_id_map m ON m.old_id = t.ID
JOIN er452618_crm4fitness.clients c ON c.id = m.new_id
WHERE t.Credit <> 0
  AND NOT EXISTS (
    SELECT 1 FROM er452618_crm4fitness.client_deposits d
    WHERE d.client_id = c.id AND d.notes LIKE CONCAT('%tblNewTable.ID=', t.ID, ')')
  );

-- --- Крок 4: перевірка після імпорту ----------------------------------------
SELECT COUNT(*) AS impoted_clients FROM migration_client_id_map;

SELECT c.id, c.full_name, c.phone, c.phone_normalized, c.balance, c.club_id
FROM er452618_crm4fitness.clients c
JOIN migration_client_id_map m ON m.new_id = c.id
ORDER BY c.id DESC
LIMIT 20;

-- Дублікати по phone_normalized у межах одного клубу (варто розібрати вручну)
SELECT club_id, phone_normalized, COUNT(*) AS cnt
FROM er452618_crm4fitness.clients
WHERE phone_normalized IS NOT NULL
GROUP BY club_id, phone_normalized
HAVING cnt > 1;

-- ============================================================================
-- ПЛАН Б: якщо крос-БД запит (Крок 0) видав Access denied
-- ============================================================================
-- 1. У phpMyAdmin на базі er452618_drivecrm: Export -> тільки таблиці
--    tblNewTable і tblInvoices -> формат CSV.
-- 2. У phpMyAdmin на базі er452618_crm4fitness: створити тимчасові таблиці
--    з тією ж структурою (skip AUTO_INCREMENT/PK на ID, просто дані) і
--    Import ці CSV у них, наприклад `stg_tblNewTable`, `stg_tblInvoices`.
-- 3. У всьому скрипті вище замінити `er452618_drivecrm.tblNewTable` на
--    `stg_tblNewTable` і `er452618_drivecrm.tblInvoices` на `stg_tblInvoices`,
--    прибрати префікс бази — решта логіки не міняється.
-- ============================================================================

-- ============================================================================
-- ВІДКЛАДЕНО: старий Depozit. Розкоментувати й адаптувати, коли з'ясується
-- його реальне значення (окрема історія готівкових поповнень? дублікат
-- Credit? щось інше) — інакше ризик задвоїти clients.balance.
-- ============================================================================
-- INSERT INTO er452618_crm4fitness.client_deposits
--   (club_id, client_id, amount, operation, admin_id, admin_name, notes, created_at)
-- SELECT c.club_id, c.id, t.Depozit, 'top_up', 1, 'Міграція з drivecrm',
--        CONCAT('Старий Depozit (tblNewTable.ID=', t.ID, ')'), NOW()
-- FROM er452618_drivecrm.tblNewTable t
-- JOIN migration_client_id_map m ON m.old_id = t.ID
-- JOIN er452618_crm4fitness.clients c ON c.id = m.new_id
-- WHERE t.Depozit <> 0;
