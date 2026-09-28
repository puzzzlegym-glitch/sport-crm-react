-- Локальний тестовий прогін етапу 4a (оплати абонементів) з drivecrm -> crm4fitness
-- Джерело: er452618_drivecrm.tblInvoicesOplatu (129 845 рядків)
-- Ціль:    er452618_crm4fitness.club_payments
--
-- Правила:
--  - Тільки рядки, де і клієнт (ClientID), і інвойс (InvoicesID) вже
--    мігровані (migration_client_id_map / migration_invoice_id_map).
--    116 179 з 129 845 підпадають (решта — Драйв3.0 інвойси/клієнти,
--    включно з ~сотнею випадків, де сам платіж мав ClubID 1/2, а його
--    інвойс був ClubID=3 — аномалія вихідних даних, природно відсіюється).
--  - FormaOplatu -> payment_method: готівка->cash, картка/карта->card,
--    термінал (безготівка)->terminal, депозит->deposit.
--  - shift_id, fiscal_* — НЕ заповнюємо (історичні дані, немає відповідних
--    змін кас; узгоджено з рішенням не бекафілити club_cashflow).
--  - trainer_id=NULL, trainer_name=Trener (текст) — без відновлення
--    нарахувань тренерам 1:1 (як і в етапі інвойсів).

USE er452618_crm4fitness;

INSERT INTO club_payments
  (club_id, client_id, invoice_id, amount, payment_method,
   trainer_name, admin_name, created_at)
SELECT
  op.ClubID,
  cm.new_id,
  im.new_id,
  op.Oplacheno,
  CASE op.FormaOplatu
    WHEN 'готівка' THEN 'cash'
    WHEN 'картка' THEN 'card'
    WHEN 'карта' THEN 'card'
    WHEN 'термінал (безготівка)' THEN 'terminal'
    WHEN 'депозит' THEN 'deposit'
    ELSE 'other'
  END,
  NULLIF(TRIM(op.Trener), ''),
  NULLIF(TRIM(op.UserName), ''),
  op.PaymentDate
FROM er452618_drivecrm.tblInvoicesOplatu op
JOIN migration_client_id_map cm ON cm.old_id = op.ClientID
JOIN migration_invoice_id_map im ON im.old_id = op.InvoicesID
WHERE op.ClubID IN (1,2);

-- ── Перевірка ────────────────────────────────────────────────────────────
SELECT COUNT(*) AS imported_payments FROM club_payments;

SELECT payment_method, COUNT(*), SUM(amount) FROM club_payments GROUP BY payment_method;

-- Звірка: сума оплат по кожному інвойсу vs paid_amount, який ми вже
-- записали на етапі 3 (з Oplacheno самого інвойса) — мають збігатись,
-- бо це один і той же показник з двох різних таблиць джерела.
SELECT COUNT(*) AS invoices_paid_mismatch
FROM client_invoices ci
JOIN migration_invoice_id_map im ON im.new_id = ci.id
JOIN (
  SELECT invoice_id, SUM(amount) AS total FROM club_payments GROUP BY invoice_id
) p ON p.invoice_id = ci.id
WHERE ROUND(p.total,2) <> ROUND(ci.paid_amount,2);
