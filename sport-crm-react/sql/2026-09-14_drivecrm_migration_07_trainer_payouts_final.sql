-- Локальний тестовий прогін: виплати тренерам з drivecrm -> crm4fitness
-- Джерело: er452618_drivecrm.tblVuplatuTrener (7556, 7229 у клубах 1/2)
-- Ціль: club_expenses (category='Зарплата тренера', як і жива виплата з
-- trainers_api.php::pay_earning) — НЕ trainer_payouts/trainer_earnings.
--
-- Чому не trainer_earnings/trainer_payouts:
--  - trainer_payouts взагалі не використовується жодним API-файлом (мертва
--    таблиця в схемі, ніде не читається).
--  - Виплата в живому коді (pay_earning) вимагає ІСНУЮЧОГО trainer_earnings
--    (нарахування "за конкретний абонемент/відвідування"), яке ми свідомо
--    не реконструюємо (рішення 11.09 — занадто складно й ненадійно рахувати
--    заднім числом комісії по кожному історичному інвойсу).
--  - club_expenses з тим самим category — саме те, що й так показувалось би
--    у Фінансах/Касі при реальній виплаті, тож фінансова картина коректна.
--  - source='drivecrm_trainer_payout' (НЕ 'trainer_payout'!) — той тег
--    зарезервований живим кодом (visits_api.php автоматично видаляє
--    club_expenses із source='trainer_payout' при скасуванні відвідування,
--    очікуючи реальний trainer_earnings.id у source_id; наші рядки такого
--    id не мають).

USE er452618_crm4fitness;

INSERT INTO club_expenses
  (club_id, category, description, amount, expense_date, payment_method,
   admin_name, source, notes, created_at)
SELECT
  v.ClubID,
  'Зарплата тренера',
  CONCAT('Виплата тренеру ', v.Trener),
  v.Resultat,
  v.DataOtrum,
  CASE v.SposibVuplatu WHEN 'Картка' THEN 'card' ELSE 'cash' END,
  NULLIF(TRIM(v.UserName), ''),
  'drivecrm_trainer_payout',
  CONCAT('Міграція з drivecrm, tblVuplatuTrener.ID=', v.ID),
  v.DataOperation
FROM er452618_drivecrm.tblVuplatuTrener v
JOIN migration_trainer_id_map m ON m.old_id = v.IdTrener
WHERE v.ClubID IN (1,2);

-- ── Перевірка ────────────────────────────────────────────────────────────
SELECT COUNT(*) AS payouts, SUM(amount) FROM club_expenses WHERE source='drivecrm_trainer_payout';
SELECT club_id, COUNT(*), SUM(amount) FROM club_expenses WHERE source='drivecrm_trainer_payout' GROUP BY club_id;
