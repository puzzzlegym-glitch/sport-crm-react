-- Перехід нарахувань тренеру з "оплата абонемента" на "факт відвідування".
-- Див. обговорення в сесії 2026-08-26. БД порожня по всіх грошових таблицях
-- тренерів (trainer_earnings/trainer_ledger/trainer_payouts) — міграція даних
-- не потрібна, лише зміна схеми.

-- 1. Новий явний прапорець тарифу: чи можна призначати тренера на абонемент
--    цього тарифу (і, відповідно, отримувати нарахування за відвідування).
ALTER TABLE tariffs
  ADD COLUMN has_trainer TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Чи можна призначати тренера на абонементи цього тарифу'
    AFTER earn_release_trigger;

-- Існуючі дані: тариф(и), де вже було щось задано в narah_summ_type,
-- очевидно замислювались "з тренером" — переносимо прапорець за фактом.
UPDATE tariffs SET has_trainer = 1 WHERE narah_summ_type IS NOT NULL;

-- 2. Захист від подвійного нарахування за одне й те саме відвідування
--    (source='visit' + source_id=visits.id визначає нарахування унікально,
--    оскільки в одного visit лише одне поле trainer_id).
ALTER TABLE trainer_earnings
  ADD UNIQUE KEY uniq_earning_source (source, source_id);
