-- ============================================================================
-- Автоматичне закриття зміни каси у визначений власником час.
-- Використовується: api/settings_api.php (get_club/update_club),
--                    api/cash_api.php + classes/CashShiftService.php,
--                    cron/auto_close_shifts.php, src/pages/SettingsPage.jsx.
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-09-16
--
-- ПЕРЕД ЗАСТОСУВАННЯМ: перевірити через information_schema.COLUMNS, чи
-- колонки вже існують у sys_clubs (ALTER ... ADD COLUMN тут не має
-- IF NOT EXISTS — на цьому хостингу MySQL він не підтримується).
-- ============================================================================

ALTER TABLE sys_clubs
  ADD COLUMN cash_shift_auto_close_enabled TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Автоматично закривати відкриту зміну каси за розкладом (owner-only налаштування)',
  ADD COLUMN cash_shift_auto_close_time TIME NULL DEFAULT NULL
    COMMENT 'Час автозакриття зміни (локальний час сервера, як і решта дат у застосунку)';
