-- Виправлення бага в club_payments/client_invoices.paid_amount + побудова
-- trainer_earnings з негативних рядків tblInvoicesOplatu (нарахування
-- тренеру за конкретне відвідування, НЕ виплата — виплати окремо в
-- club_expenses, етап 7, з tblVuplatuTrener; підтверджено власником
-- 2026-09-14).
--
-- Корінь бага: tblInvoices.Oplacheno / tblInvoicesOplatu.Oplacheno — це
-- НЕТТО-сума (реальні оплати клієнта МІНУС комісії тренера, які записані
-- як від'ємні рядки з тим самим InvoicesID+TrenerID). Підтверджено на
-- 99.3% інвойсів (93649/94321): Oplacheno(інвойс) = SUM(усіх рядків
-- tblInvoicesOplatu.Oplacheno, включно з від'ємними).
--
-- Наслідок:
--  1) club_payments (етап 4a) хибно затягнув усі 35574 "мінусових"
--     рядки як ніби це оплати клієнта -> видалити.
--  2) client_invoices.paid_amount (етап 3) занижений на суму комісій
--     тренера (бо брався НЕТТО Oplacheno) -> перерахувати з чистого
--     club_payments.

USE er452618_crm4fitness;

-- --- Крок 1: прибрати сміттєві "мінусові оплати" з club_payments --------
DELETE FROM club_payments WHERE amount < 0;

-- --- Крок 2: перерахувати paid_amount на інвойсах (тепер з чистих даних) -
UPDATE client_invoices ci
JOIN (
  SELECT invoice_id, SUM(amount) AS total FROM club_payments GROUP BY invoice_id
) p ON p.invoice_id = ci.id
SET ci.paid_amount = p.total
WHERE ci.id IN (SELECT DISTINCT new_id FROM migration_invoice_id_map);

-- Інвойси, де ВСІ оплати виявились "мінусовими" (видалені) -> paid_amount=0
UPDATE client_invoices ci
JOIN migration_invoice_id_map im ON im.new_id = ci.id
LEFT JOIN club_payments cp ON cp.invoice_id = ci.id
SET ci.paid_amount = 0
WHERE cp.id IS NULL;

-- --- Крок 3: trainer_earnings з негативних рядків tblInvoicesOplatu ------
-- УВАГА: trainer_earnings.UNIQUE(source,source_id) -> одне нарахування на
-- інвойс (як і в живому коді: інвойс продається з ОДНИМ тренером, кожне
-- відвідування лише "розблоковує" available_amount, а не створює новий
-- рядок). Тому тут агрегуємо ВСІ мінусові рядки одного InvoicesID в ОДНЕ
-- нарахування (сума = скільки вже фактично нараховано з відвідувань).
-- Один інвойс (89410 з 4413) мав 2 різних TrenerID серед мінусових рядків
-- (аномалія джерела) -> лишаємо тренера з більшою сумою, менша частина
-- (менша за розміром) губиться — типова похибка на 1 запис з 4413.
DROP TEMPORARY TABLE IF EXISTS tmp_earn_agg;
CREATE TEMPORARY TABLE tmp_earn_agg AS
SELECT InvoicesID, ClubID, TrenerID, total, first_date, last_date, is_group
FROM (
  SELECT
    op.InvoicesID, op.ClubID, op.TrenerID,
    SUM(ABS(op.Oplacheno)) AS total,
    MIN(op.PaymentDate) AS first_date,
    MAX(op.PaymentDate) AS last_date,
    MAX(op.GroupTarif IS NOT NULL) AS is_group,
    ROW_NUMBER() OVER (PARTITION BY op.InvoicesID ORDER BY SUM(ABS(op.Oplacheno)) DESC) AS rn
  FROM er452618_drivecrm.tblInvoicesOplatu op
  WHERE op.Oplacheno < 0
  GROUP BY op.InvoicesID, op.ClubID, op.TrenerID
) x WHERE rn = 1;

INSERT INTO trainer_earnings
  (club_id, trainer_id, source, source_id, invoice_id, earn_type, amount,
   available_amount, paid_amount, release_trigger, available_at, status, created_at)
SELECT
  a.ClubID,
  tm.club_trainer_id,
  'invoice',
  im.new_id,
  im.new_id,
  IF(a.is_group, 'group_fixed', 'personal_fixed'),
  a.total,
  a.total,
  0,
  'on_each_visit',
  a.last_date,
  'available',
  a.first_date
FROM tmp_earn_agg a
JOIN migration_invoice_id_map im ON im.old_id = a.InvoicesID
JOIN migration_trainer_id_map tm ON tm.old_id = a.TrenerID
WHERE NOT EXISTS (SELECT 1 FROM trainer_earnings te WHERE te.source='invoice' AND te.source_id = im.new_id);

-- ── Перевірка ────────────────────────────────────────────────────────────
SELECT COUNT(*) AS remaining_negative_payments FROM club_payments WHERE amount<0;
SELECT COUNT(*) AS migrated_earnings, SUM(amount) FROM trainer_earnings WHERE release_trigger='on_each_visit';
SELECT COUNT(*) AS invoices_now_zero_paid FROM client_invoices ci JOIN migration_invoice_id_map im ON im.new_id=ci.id WHERE ci.paid_amount=0;
