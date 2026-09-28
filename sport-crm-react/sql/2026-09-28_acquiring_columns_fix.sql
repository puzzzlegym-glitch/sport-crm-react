-- ============================================================================
-- «ПРРО / Термінали» → Еквайринг падав з помилкою 500 (Unknown column 'device_name').
-- На сервері таблиця club_acquiring_settings існувала ще до 2026-09-02_club_acquiring_settings.sql
-- (стара структура: terminal_type / connection_type / terminal_id), тому CREATE TABLE IF NOT EXISTS
-- з того файлу нічого не змінив. Додаємо колонки, яких очікує acquiring_api.php / VchasnoDmService.
-- Старі колонки не видаляємо. Дата: 2026-09-28
-- ============================================================================
ALTER TABLE club_acquiring_settings
  ADD COLUMN device_name        VARCHAR(100) NULL COMMENT 'Назва термінала в кабінеті Device Manager (Вчасно.Каса)' AFTER provider,
  ADD COLUMN dm_proxy_token_enc VARCHAR(500) NULL COMMENT 'X-AP-DM-PROXY-TOKEN, зашифровано' AFTER device_name;
