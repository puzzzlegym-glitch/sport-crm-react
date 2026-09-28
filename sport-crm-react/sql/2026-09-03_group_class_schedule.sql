-- ============================================================================
-- Розклад групових занять — закриває прогалину, через яку поля
-- club_trainers.group_earn_rate / group_earn_bonus_per_client /
-- group_bonus_threshold існували в схемі й формі редагування тренера з
-- самого початку, але ніде не рахувались: не було жодного джерела даних
-- "хто був у групі на конкретному занятті". Ця міграція додає розклад
-- (group_sessions) і ростер учасників (group_session_clients).
--
-- Відвідування групового заняття СПИСУЄ відвідування з абонемента клієнта
-- через наявну систему client_invoices/visits (як і персональні тренування):
-- при відмітці 'attended' group_session_clients.visit_id заповнюється
-- реальним рядком visits (через Attendance::recordVisit), а group_earn_rate/
-- group_earn_bonus_per_client рахуються ОДИН РАЗ при завершенні заняття
-- через Attendance::createGroupSessionEarning (нарахування — trainer_earnings,
-- source='group_session', джерело коду для anti-double-count — вже наявний
-- UNIQUE KEY uniq_earning_source(source, source_id) з 2026-08-26_trainer_earnings_from_visits.sql).
--
-- group_monthly_bonus_sessions/group_monthly_bonus_amount свідомо НЕ
-- реалізовано в цій міграції/цьому етапі — окрема задача на майбутнє.
--
-- ПЕРЕД ЗАСТОСУВАННЯМ: trainer_earnings не має CREATE TABLE в цій sql/ теці
-- (створена до введення конвенції міграцій, є лише в живій БД) — я не мав
-- змоги перевірити наживо, чи колонки source/earn_type/release_trigger/status
-- у ній ENUM чи VARCHAR. Attendance::createGroupSessionEarning() пише туди
-- НОВІ значення: source='group_session', earn_type='group_session',
-- release_trigger='on_session_complete' (status='available' — уже наявне
-- значення, ризику нема). Якщо котрась із цих колонок — ENUM, перед першим
-- використанням групових занять треба виконати щось на кшталт:
--   ALTER TABLE trainer_earnings MODIFY COLUMN source VARCHAR(30) NOT NULL; (або
--   ALTER TABLE trainer_earnings MODIFY COLUMN source ENUM('visit','group_session') NOT NULL;)
-- аналогічно для earn_type/release_trigger — інакше INSERT впаде на
-- "Data truncated"/"Incorrect enum value" в режимі STRICT_TRANS_TABLES.
-- Перевірити типи: SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
-- WHERE TABLE_SCHEMA='er452618_crm4fitness' AND TABLE_NAME='trainer_earnings';
--
-- ОНОВЛЕННЯ 2026-09-03 (за фактом застосування, структура visits перевірена
-- в phpMyAdmin): club_trainers.id — INT UNSIGNED (trainer_id відпрацював
-- одразу). visits.id — BIGINT UNSIGNED (не INT!) — саме тому
-- fk_group_session_clients_visit двічі падав з #3780 "incompatible", доки
-- не виправили visit_id на BIGINT UNSIGNED нижче. clients.id/client_invoices.id
-- лишились INT UNSIGNED, як і передбачалось (client_id/invoice_id відпрацювали
-- без помилок з першої спроби).
--
-- Використовується: api/group_sessions_api.php, app/core/Attendance.php
--                    (createGroupSessionEarning, findActiveInvoice),
--                    src/api/groupSessions.js, src/pages/GroupSessionsPage.jsx
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-09-03
-- ============================================================================

-- 1) Заплановане заняття ------------------------------------------------------
CREATE TABLE IF NOT EXISTS group_sessions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    club_id         INT UNSIGNED NOT NULL,
    trainer_id      INT UNSIGNED NOT NULL,
    name            VARCHAR(150) NOT NULL,
    session_date    DATE NOT NULL,
    start_time      TIME NOT NULL,
    end_time        TIME NULL,
    capacity        INT NULL,  -- максимум учасників, NULL = без обмеження
    status          ENUM('scheduled','completed','canceled') NOT NULL DEFAULT 'scheduled',
    notes           VARCHAR(500) NULL,
    created_by      INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_group_sessions_club_date (club_id, session_date),
    KEY idx_group_sessions_trainer (trainer_id),
    CONSTRAINT fk_group_sessions_club    FOREIGN KEY (club_id)    REFERENCES sys_clubs(id)      ON DELETE CASCADE,
    CONSTRAINT fk_group_sessions_trainer FOREIGN KEY (trainer_id) REFERENCES club_trainers(id)  ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Ростер учасників заняття -------------------------------------------------
CREATE TABLE IF NOT EXISTS group_session_clients (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    session_id      INT NOT NULL,
    client_id       INT UNSIGNED NOT NULL,
    invoice_id      INT UNSIGNED NULL,  -- активний абонемент клієнта на момент запису, якщо був
    visit_id        BIGINT UNSIGNED NULL,  -- visits.id — bigint unsigned (перевірено 2026-09-03), не int
    status          ENUM('booked','attended','no_show','canceled') NOT NULL DEFAULT 'booked',
    checked_in_at   DATETIME NULL,
    created_by      INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_group_session_client (session_id, client_id),
    KEY idx_group_session_clients_client (client_id),
    CONSTRAINT fk_group_session_clients_session FOREIGN KEY (session_id) REFERENCES group_sessions(id)   ON DELETE CASCADE,
    CONSTRAINT fk_group_session_clients_client  FOREIGN KEY (client_id)  REFERENCES clients(id)          ON DELETE CASCADE,
    CONSTRAINT fk_group_session_clients_invoice FOREIGN KEY (invoice_id) REFERENCES client_invoices(id)  ON DELETE SET NULL,
    CONSTRAINT fk_group_session_clients_visit   FOREIGN KEY (visit_id)   REFERENCES visits(id)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Права доступу: перегляд і керування розкладом групових занять ----------
--    group_sessions.view — усім трьом ролям (owner/manager/trainer), той
--    самий патерн, що й trainers.view в 2026-08-08_permissions_matrix.sql
--    (рядок 124-126) — інакше ProtectedRoute permission="group_sessions.view"
--    заблокує тренеру доступ до /group-sessions узагалі, а йому потрібен
--    свій розклад (дія my_schedule). group_sessions.manage (створення/
--    редагування/ростер/завершення) — лише owner+manager, за золотим
--    стандартом з 2026-08-27_certificates_permissions.sql.
INSERT INTO sys_permissions (slug, label, category, sort_order) VALUES
    ('group_sessions.view',   'Перегляд розкладу групових занять',  'trainers', 70),
    ('group_sessions.manage', 'Керування розкладом групових занять', 'trainers', 71)
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category), sort_order = VALUES(sort_order);

INSERT IGNORE INTO sys_role_permissions (role_id, permission_slug)
SELECT r.id, p.slug
FROM sys_roles r
JOIN sys_permissions p ON p.slug = 'group_sessions.view'
WHERE r.slug IN ('owner', 'manager', 'trainer');

INSERT IGNORE INTO sys_role_permissions (role_id, permission_slug)
SELECT r.id, p.slug
FROM sys_roles r
JOIN sys_permissions p ON p.slug = 'group_sessions.manage'
WHERE r.slug IN ('owner', 'manager');
