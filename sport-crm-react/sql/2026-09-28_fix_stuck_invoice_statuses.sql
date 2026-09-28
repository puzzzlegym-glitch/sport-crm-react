-- ============================================================================
-- Розблокування абонементів, що "зависли" через дві виправлені помилки
-- (див. Recalc::invoiceStatus і Recalc::invoiceVisitsUsed).
-- Застосувати вручну через phpMyAdmin ПІСЛЯ заливки нових PHP-файлів.
-- Дата: 2026-09-28
-- ============================================================================
-- КРОК 1 — ПОДИВИТИСЬ (нічого не змінює). Виконайте ці два SELECT-и
-- і перегляньте списки.
-- ============================================================================

-- 1a) Абонементи зі статусом 'expired' (ліміт занять вичерпано), хоча заняття
--     ще лишились — наслідок видалення відмітки.
SELECT ci.id, ci.club_id, c.full_name, ci.tariff_name,
       ci.visits_used, ci.visits_total, ci.end_date
FROM client_invoices ci
JOIN clients c ON c.id = ci.client_id
WHERE ci.status = 'expired'
  AND ci.visits_total IS NOT NULL
  AND ci.visits_used < ci.visits_total;

-- 1b) Абонементи, які система сама перевела у 'cancelled' через неповну оплату.
--     Ручні скасування власником мають у примітці "[Дострокове припинення: ...]"
--     і сюди НЕ потрапляють.
--     ⚠ Перегляньте список: якщо там є абонементи, які ви справді скасовували
--     (наприклад, перенесені зі старої CRM) — не виконуйте 2b, напишіть мені.
SELECT ci.id, ci.club_id, c.full_name, ci.tariff_name,
       ci.price, ci.paid_amount, ci.start_date, ci.end_date, ci.notes
FROM client_invoices ci
JOIN clients c ON c.id = ci.client_id
WHERE ci.status = 'cancelled'
  AND ci.paid_amount < ci.price
  AND (ci.notes IS NULL OR ci.notes NOT LIKE '%[Дострокове припинення:%');


-- ============================================================================
-- КРОК 2 — ВИПРАВИТИ. Виконуйте лише після перегляду кроку 1.
-- ============================================================================

-- 2a) Безпечно: повертає 'active' абонементам, у яких ще є заняття.
UPDATE client_invoices
SET status = 'active'
WHERE status = 'expired'
  AND visits_total IS NOT NULL
  AND visits_used < visits_total;

-- 2b) Повертає 'active' абонементам, скасованим через неповну оплату.
UPDATE client_invoices
SET status = 'active'
WHERE status = 'cancelled'
  AND paid_amount < price
  AND (notes IS NULL OR notes NOT LIKE '%[Дострокове припинення:%');
