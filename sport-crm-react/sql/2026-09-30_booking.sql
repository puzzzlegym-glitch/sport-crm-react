-- ============================================================================
-- Бронювання і запис на тренування (групові + персональні), зали/ресурси.
--
-- Розширює наявний розклад (2026-09-03_group_class_schedule.sql):
--   group_sessions         — будь-яке заняття: kind='group' | 'personal'
--                            (персональне = заняття на 1 місце, створюється
--                            при записі на вільну годину тренера);
--   group_session_clients  — запис клієнта: booked / waitlist / attended /
--                            no_show / canceled + джерело запису (admin/telegram/app).
--
-- Нові таблиці:
--   club_rooms             — зали, корти, ресурси (накладки в одному залі заборонені);
--   class_types            — типи занять (тривалість, місця, колір, зал за замовч.);
--   class_type_tariffs     — які тарифи дають право запису (порожньо = будь-який);
--   schedule_templates     — повторюваний щотижневий розклад → заняття генеруються;
--   trainer_availability   — робочі години тренера для персональних тренувань;
--   club_booking_settings  — правила запису/скасування клубу.
--
-- Логіка — app/core/Booking.php (єдине ядро для CRM, Telegram-бота і застосунку).
--
-- MySQL на цьому хостингу не підтримує ALTER TABLE ... IF NOT EXISTS —
-- якщо колонка вже існує, пропустіть відповідний ALTER.
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-09-30
-- ============================================================================

-- 1) Зали / ресурси ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS club_rooms (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id     INT UNSIGNED NOT NULL,
    name        VARCHAR(100) NOT NULL,
    capacity    INT NULL,                 -- максимум людей у залі, NULL = без обмеження
    color       VARCHAR(20) NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_club_rooms_club (club_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Типи занять ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS class_types (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id         INT UNSIGNED NOT NULL,
    name            VARCHAR(150) NOT NULL,
    kind            ENUM('group','personal') NOT NULL DEFAULT 'group',
    duration_min    INT NOT NULL DEFAULT 60,
    capacity        INT NULL,             -- для group; для personal завжди 1
    color           VARCHAR(20) NULL,
    default_room_id INT UNSIGNED NULL,
    description     VARCHAR(500) NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    sort_order      INT NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_class_types_club (club_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS class_type_tariffs (
    class_type_id INT UNSIGNED NOT NULL,
    tariff_id     INT UNSIGNED NOT NULL,
    PRIMARY KEY (class_type_id, tariff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Повторюваний розклад -------------------------------------------------------
CREATE TABLE IF NOT EXISTS schedule_templates (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id       INT UNSIGNED NOT NULL,
    class_type_id INT UNSIGNED NOT NULL,
    trainer_id    INT UNSIGNED NOT NULL,
    room_id       INT UNSIGNED NULL,
    weekday       TINYINT NOT NULL,       -- 1 = понеділок … 7 = неділя
    start_time    TIME NOT NULL,
    duration_min  INT NULL,               -- NULL = з типу заняття
    capacity      INT NULL,               -- NULL = з типу заняття
    valid_from    DATE NOT NULL,
    valid_to      DATE NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_by    INT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_schedule_templates_club (club_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) Робочий графік тренера (для персональних тренувань) -----------------------
CREATE TABLE IF NOT EXISTS trainer_availability (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id     INT UNSIGNED NOT NULL,
    trainer_id  INT UNSIGNED NOT NULL,
    weekday     TINYINT NOT NULL,         -- 1 = понеділок … 7 = неділя
    start_time  TIME NOT NULL,
    end_time    TIME NOT NULL,
    room_id     INT UNSIGNED NULL,
    KEY idx_trainer_availability (club_id, trainer_id, weekday)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5) Правила запису клубу -------------------------------------------------------
CREATE TABLE IF NOT EXISTS club_booking_settings (
    club_id                 INT UNSIGNED NOT NULL PRIMARY KEY,
    book_ahead_days         INT NOT NULL DEFAULT 14,   -- на скільки днів наперед можна записатися
    book_close_minutes      INT NOT NULL DEFAULT 0,    -- запис закривається за N хв до початку
    cancel_deadline_minutes INT NOT NULL DEFAULT 180,  -- скасувати можна не пізніше ніж за N хв
    waitlist_enabled        TINYINT(1) NOT NULL DEFAULT 1,
    require_invoice         TINYINT(1) NOT NULL DEFAULT 1, -- для самозапису потрібен діючий абонемент
    personal_slot_step_min  INT NOT NULL DEFAULT 60,   -- крок вільних годин для персональних
    generate_weeks          INT NOT NULL DEFAULT 4,    -- на скільки тижнів наперед будувати розклад
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6) Заняття: тип, зал, шаблон, вид ------------------------------------------
ALTER TABLE group_sessions
  ADD COLUMN kind ENUM('group','personal') NOT NULL DEFAULT 'group' AFTER club_id,
  ADD COLUMN class_type_id INT UNSIGNED NULL AFTER kind,
  ADD COLUMN room_id INT UNSIGNED NULL AFTER trainer_id,
  ADD COLUMN template_id INT UNSIGNED NULL AFTER room_id,
  ADD UNIQUE KEY uq_group_sessions_template_date (template_id, session_date),
  ADD KEY idx_group_sessions_room_date (room_id, session_date);

-- 7) Записи: лист очікування, скасування, джерело ---------------------------
ALTER TABLE group_session_clients
  MODIFY COLUMN status ENUM('booked','waitlist','attended','no_show','canceled') NOT NULL DEFAULT 'booked',
  ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'admin' AFTER status,
  ADD COLUMN canceled_at DATETIME NULL AFTER checked_in_at,
  ADD COLUMN canceled_by VARCHAR(20) NULL AFTER canceled_at;
