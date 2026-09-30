-- ============================================================================
-- Кількість занять з тренером у тарифі / абонементі.
--
-- Правило клубу: тренера можна призначити лише на тариф «з тренером», у якому
-- відомо, скільки занять з тренером входить:
--   тариф на N відвідувань  → занять з тренером = N (visits_limit);
--   БЕЗЛІМІТНИЙ тариф       → trainer_sessions обов'язково (інакше тариф «з тренером»
--                             не зберігається і тренера призначити не можна).
-- client_invoices.trainer_sessions_total — знімок на момент продажу (як visits_total);
-- використані заняття з тренером рахуються з visits (див. Attendance::trainerSessionsUsed).
--
-- MySQL на цьому хостингу не підтримує ALTER TABLE ... IF NOT EXISTS —
-- якщо колонка вже існує, пропустіть відповідний ALTER.
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-10-02
-- ============================================================================

ALTER TABLE tariffs
  ADD COLUMN trainer_sessions INT NULL AFTER has_trainer;

ALTER TABLE client_invoices
  ADD COLUMN trainer_sessions_total INT NULL AFTER visits_used;

-- Наявні абонементи тарифів "з тренером" на N відвідувань: занять з тренером = N
UPDATE client_invoices ci
JOIN tariffs t ON t.id = ci.tariff_id
SET ci.trainer_sessions_total = ci.visits_total
WHERE t.has_trainer = 1 AND ci.visits_total IS NOT NULL AND ci.trainer_sessions_total IS NULL;

-- Перевірка: безлімітні тарифи "з тренером" без кількості занять — їх треба відредагувати
-- (вказати "Занять з тренером"), інакше нових абонементів з тренером на них не продати:
-- SELECT id, name FROM tariffs WHERE has_trainer = 1 AND visits_limit IS NULL AND trainer_sessions IS NULL;
