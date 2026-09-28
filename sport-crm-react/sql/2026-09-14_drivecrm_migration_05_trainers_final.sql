-- Локальний тестовий прогін: тренери з drivecrm -> crm4fitness
-- Джерело: er452618_drivecrm.tblTrener (37, 31 у клубах 1/2)
-- Ціль: sys_users (профіль людини) + club_trainers (профіль у конкретному клубі)
--
-- Правила:
--  - Жоден тренер не зустрічається в кількох клубах одночасно (перевірено) ->
--    1 sys_users + 1 club_trainers на кожного.
--  - email: якщо Mailing валідний і унікальний — беремо його; інакше
--    синтетичний placeholder (унікальний по старому ID), щоб задовольнити
--    UNIQUE NOT NULL — вхід під ним неможливий (password_hash — заглушка,
--    ніколи не пройде password_verify).
--  - Фінансову історію (Narahovano/Vuplacheno/Zalushok) НЕ відтворюємо —
--    узгоджено раніше (trg_visit_trainer_earning, план 11.09): це окремі
--    таблиці нарахувань (trainer_earnings/trainer_ledger/trainer_payouts),
--    які потребують реконструкції по кожному інвойсу, а не одне число.
--  - is_active = NOT Dead, specialization = Naprjamok, photo_url = TrenerPic.

USE er452618_crm4fitness;

CREATE TABLE IF NOT EXISTS migration_trainer_id_map (
  old_id   BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  user_id  INT UNSIGNED NOT NULL,
  club_trainer_id INT UNSIGNED NOT NULL,
  migrated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @start_user_id := (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE table_schema='er452618_crm4fitness' AND table_name='sys_users');
SET @start_ct_id    := (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE table_schema='er452618_crm4fitness' AND table_name='club_trainers');

DROP TEMPORARY TABLE IF EXISTS tmp_trainers;
CREATE TEMPORARY TABLE tmp_trainers AS
SELECT t.*, ROW_NUMBER() OVER (ORDER BY t.ID) AS rn
FROM er452618_drivecrm.tblTrener t
WHERE t.ClubID IN (1,2);

INSERT INTO migration_trainer_id_map (old_id, user_id, club_trainer_id)
SELECT ID, @start_user_id - 1 + rn, @start_ct_id - 1 + rn FROM tmp_trainers;

INSERT INTO sys_users (id, email, password_hash, full_name, phone, is_active, created_at)
SELECT
  m.user_id,
  COALESCE(NULLIF(TRIM(t.Mailing), ''), CONCAT('trainer', t.ID, '@drivecrm.migrated')),
  '$2y$12$migratedNoLoginPlaceholderHashXXXXXXXXXXXXXXXXXXXXXX',
  t.UserName,
  NULLIF(TRIM(t.Phone), ''),
  NOT t.Dead,
  NOW()
FROM tmp_trainers t
JOIN migration_trainer_id_map m ON m.old_id = t.ID
WHERE NOT EXISTS (SELECT 1 FROM sys_users u WHERE u.id = m.user_id);

INSERT INTO club_trainers (id, club_id, user_id, specialization, photo_url, is_active, created_at)
SELECT
  m.club_trainer_id,
  t.ClubID,
  m.user_id,
  NULLIF(TRIM(t.Naprjamok), ''),
  NULLIF(TRIM(t.TrenerPic), ''),
  NOT t.Dead,
  NOW()
FROM tmp_trainers t
JOIN migration_trainer_id_map m ON m.old_id = t.ID
WHERE NOT EXISTS (SELECT 1 FROM club_trainers ct WHERE ct.id = m.club_trainer_id);

-- ── Перевірка ────────────────────────────────────────────────────────────
SELECT COUNT(*) AS migrated_trainers FROM migration_trainer_id_map;
SELECT ct.id, u.full_name, u.email, u.phone, ct.club_id, ct.specialization, ct.is_active
FROM club_trainers ct JOIN sys_users u ON u.id = ct.user_id
JOIN migration_trainer_id_map m ON m.club_trainer_id = ct.id
ORDER BY ct.id DESC LIMIT 10;
