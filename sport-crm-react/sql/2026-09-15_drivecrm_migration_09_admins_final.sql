-- Локальний тестовий прогін: адміни/модератори з drivecrm -> crm4fitness
-- Джерело: er452618_drivecrm.usertable (74 записи: 34 клуб1 + 1 модератор клуб1
--          + 30 клуб2 + 9 клуб3 "Драйв 3.0")
-- Ціль: sys_users (профіль людини) + sys_user_clubs (роль у конкретному клубі)
--
-- Правила (узгоджено з користувачем 2026-09-15):
--  - UserRoles "Адміністратор"/"Модератор" -> роль 'manager' у sys_roles
--    (рецепшн-рівень доступу, level=50).
--  - ClubID=3 ("Драйв 3.0") відсутній у поточній sys_clubs (є лише 1, 2, 4)
--    -> ці 9 записів ПРОПУЩЕНО (WHERE ClubID IN (1,2)).
--  - ClubID 1/2 старої бази 1:1 відповідають sys_clubs.id 1/2 (те саме
--    припущення, що й у міграції тренерів/клієнтів).
--  - email: якщо UserEmail валідний — беремо його; інакше синтетичний
--    placeholder (унікальний по старому ID), бо UserEmail здебільшого
--    порожній (люди діляться спільним ClubEmail), а sys_users.email
--    UNIQUE NOT NULL.
--  - password_hash: старий Password — це PIN-код (напр. "2", "111"), а не
--    справжній хеш -> заглушка, вхід під нею неможливий (як для тренерів).
--  - is_active = NOT Dead (в обох таблицях).
--  - Комісії/бонуси (Commission*, Threshold*, Bonus*, PromoCode) НЕ
--    переносяться — окрема економічна модель, поза межами цього завдання
--    (та сама логіка, що й "фінансову історію тренерів не відтворюємо").
--  - Одна людина вже існує в sys_users (Ярослав / de.slavik@gmail.com,
--    id=1, SuperAdmin) -> цей рядок старої таблиці пропущено, щоб не
--    створювати дубль і не занижувати його роль до 'manager'.

USE er452618_crm4fitness;

CREATE TABLE IF NOT EXISTS migration_admin_id_map (
  old_id   BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  user_id  INT UNSIGNED NOT NULL,
  migrated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @start_user_id := (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE table_schema='er452618_crm4fitness' AND table_name='sys_users');
SET @manager_role_id := (SELECT id FROM sys_roles WHERE slug = 'manager');

DROP TEMPORARY TABLE IF EXISTS tmp_admins;
CREATE TEMPORARY TABLE tmp_admins AS
SELECT u.*, ROW_NUMBER() OVER (ORDER BY u.ID) AS rn
FROM er452618_drivecrm.usertable u
WHERE u.ClubID IN (1,2)
  AND NOT EXISTS (
    SELECT 1 FROM sys_users su WHERE su.email = u.UserEmail AND u.UserEmail != ''
  );

INSERT INTO migration_admin_id_map (old_id, user_id)
SELECT ID, @start_user_id - 1 + rn FROM tmp_admins;

INSERT INTO sys_users (id, email, password_hash, full_name, phone, is_active, created_at)
SELECT
  m.user_id,
  COALESCE(NULLIF(TRIM(a.UserEmail), ''), CONCAT('admin', a.ID, '@drivecrm.migrated')),
  '$2y$12$migratedNoLoginPlaceholderHashXXXXXXXXXXXXXXXXXXXXXX',
  a.User,
  NULL,
  NOT a.Dead,
  NOW()
FROM tmp_admins a
JOIN migration_admin_id_map m ON m.old_id = a.ID
WHERE NOT EXISTS (SELECT 1 FROM sys_users u WHERE u.id = m.user_id);

INSERT INTO sys_user_clubs (user_id, club_id, role_id, is_active, granted_at)
SELECT
  m.user_id,
  a.ClubID,
  @manager_role_id,
  NOT a.Dead,
  NOW()
FROM tmp_admins a
JOIN migration_admin_id_map m ON m.old_id = a.ID
WHERE NOT EXISTS (
  SELECT 1 FROM sys_user_clubs uc WHERE uc.user_id = m.user_id AND uc.club_id = a.ClubID
);

-- ── Перевірка ────────────────────────────────────────────────────────────
SELECT COUNT(*) AS migrated_admins FROM migration_admin_id_map;
SELECT u.id, u.full_name, u.email, u.is_active AS user_active,
       uc.club_id, r.slug AS role_slug, uc.is_active AS club_access
FROM sys_user_clubs uc
JOIN sys_users u ON u.id = uc.user_id
JOIN sys_roles r ON r.id = uc.role_id
JOIN migration_admin_id_map m ON m.user_id = u.id
ORDER BY u.id DESC LIMIT 15;
