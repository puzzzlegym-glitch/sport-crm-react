-- Тимчасові паролі для 4 РЕАЛЬНО активних мігрованих адмінів (is_active=1
-- в sys_user_clubs після міграції 09) — щоб вони могли увійти в систему.
-- Решта 60 мігрованих лишаються із заглушкою password_hash (вхід неможливий,
-- узгоджено раніше) — вони не активні (Dead=1 у старій базі).
--
-- Прив'язка йде по email (не по id) — id локальної тестової БД і продової
-- можуть відрізнятись, а email — унікальний і стабільний ідентифікатор.
-- Виконати ПІСЛЯ 2026-09-15_drivecrm_migration_09_admins_final.sql.
--
-- Паролі згенеровано 2026-09-15, потрібно передати людям особисто (email
-- зі сторони застосунку я не надсилав — SMTP цього проєкту веде на реальний
-- поштовий сервер навіть з локальної тестової БД).

USE er452618_crm4fitness;

-- Яцук Евеліна <evelinka00@gmail.com> — тимчасовий пароль: pjxg-4237-PKTF
UPDATE sys_users SET password_hash='$2y$12$y4WL9wdCe7Vuljuh3qzpLOmnNNFh6S4VpU.AtPLCyDs/lazxaAISi'
WHERE email='evelinka00@gmail.com';

-- Міськів Максим <miskivmaksim11@gmail.com> — тимчасовий пароль: qnkb-2935-XBCD
UPDATE sys_users SET password_hash='$2y$12$n65nkwsc1C2jq2jYXeLoHuypu8CoJLZh2qIoCu7pFeqbAfybmZXzi'
WHERE email='miskivmaksim11@gmail.com';

-- Довгань Захар <dovganzahar@gmail.com> — тимчасовий пароль: aqnh-8732-QRVY
UPDATE sys_users SET password_hash='$2y$12$y.LMEyHR7Ga7yUEsgfkeNuIiSLL0XHSgfgHaYs1eo98uM2nBGPTsi'
WHERE email='dovganzahar@gmail.com';

-- Дацків Вікторія <viktoriadackiv12@gmail.com> — тимчасовий пароль: tgfz-3786-EZKH
UPDATE sys_users SET password_hash='$2y$12$sR0tUdrSJJyy1XenZc2fL.o7pJgRe9cKrash3Nb5z7PzfiuwIanqK'
WHERE email='viktoriadackiv12@gmail.com';

-- ── Перевірка ────────────────────────────────────────────────────────────
SELECT id, full_name, email FROM sys_users
WHERE email IN ('evelinka00@gmail.com','miskivmaksim11@gmail.com','dovganzahar@gmail.com','viktoriadackiv12@gmail.com');
