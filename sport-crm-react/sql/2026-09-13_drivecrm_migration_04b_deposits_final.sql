-- Локальний тестовий прогін етапу 4b (депозити) з drivecrm -> crm4fitness
-- Джерело: er452618_drivecrm.tblDepozit (4 416 рядків)
-- Ціль:    er452618_crm4fitness.client_deposits
--
-- Правила (виведені з аналізу 2026-09-13):
--  - Тільки ClubID 1,2 і клієнт вже мігрований.
--  - Summ УЖЕ зі знаком, що збігається з конвенцією client_deposits.amount
--    (Поповнення: 1431/1442 додатні, Списання: 2929/2933 від'ємні) —
--    переносимо як є, без інверсії.
--  - operation: Поповнення/порожнє(+Summ) -> top_up; Списання (незалежно від
--    причини) -> correction. Рішення власника 2026-09-14: у депозитах лише
--    2 категорії — поповнення/списання, без розрізнення по SertificateInvoisesID
--    (раніше окремо тегувалось як 'certificate' — об'єднано з 'correction';
--    фронтенд показує від'ємний correction як "Списання").
--    (TovarID у цій таблиці НЕ посилається на products/tariffs — перевірено,
--    0 збігів — тому ігнорується як непридатне поле).
--  - FormaOplatu -> payment_method: готівка->cash, картка->card,
--    термінал (безготівка)/безготівка (термінал)->terminal,
--    WayForPay/Онлайн->transfer, порожньо/'0'->NULL.
--  - Після заливки: агрегатний перерахунок clients.balance (тригера нема).

USE er452618_crm4fitness;

INSERT INTO client_deposits
  (club_id, client_id, amount, operation, payment_method, admin_name, notes, created_at)
SELECT
  d.ClubID,
  cm.new_id,
  d.Summ,
  CASE WHEN d.Operacia = 'Списання' THEN 'correction' ELSE 'top_up' END,
  CASE d.FormaOplatu
    WHEN 'готівка' THEN 'cash'
    WHEN 'картка' THEN 'card'
    WHEN 'термінал (безготівка)' THEN 'terminal'
    WHEN 'безготівка (термінал)' THEN 'terminal'
    WHEN 'WayForPay' THEN 'transfer'
    WHEN 'Онлайн' THEN 'transfer'
    ELSE NULL
  END,
  NULLIF(TRIM(d.UserNames), ''),
  CONCAT('Міграція з drivecrm, tblDepozit.ID=', d.ID,
         IF(d.SertificateInvoisesID IS NOT NULL, CONCAT(', SertificateInvoisesID=', d.SertificateInvoisesID), '')),
  TIMESTAMP(d.PaymentDate, d.TimeSet)
FROM er452618_drivecrm.tblDepozit d
JOIN migration_client_id_map cm ON cm.old_id = d.ClientID
WHERE d.ClubID IN (1,2);

-- Ручний перерахунок balance (тригера trg_deposit_after_insert більше нема)
UPDATE clients c
JOIN (
  SELECT client_id, SUM(amount) AS total
  FROM client_deposits
  GROUP BY client_id
) d ON d.client_id = c.id
SET c.balance = d.total;

-- ── Перевірка ────────────────────────────────────────────────────────────
SELECT COUNT(*) AS imported_deposits FROM client_deposits;

SELECT operation, payment_method, COUNT(*), SUM(amount) FROM client_deposits GROUP BY operation, payment_method;

SELECT COUNT(*) AS clients_with_balance FROM clients WHERE balance <> 0;

SELECT id, full_name, balance FROM clients WHERE balance <> 0 ORDER BY balance DESC LIMIT 10;
