-- Локальний тестовий прогін: очищення дат реєстрації + сирітські клієнти
-- 1) 14 клієнтів отримали created_at = CURDATE() при міграції (етап 01),
--    бо в drivecrm (tblNewTable.DataDodavannja) дата була невідома
--    (NULL або '0000-00-00'). У всіх 14 є відвідування — підставляємо
--    дату першого відвідування як реальну дату реєстрації.
-- 2) Клієнти без жодного пов'язаного запису в жодній з 10 таблиць
--    (visits, client_invoices, club_payments, client_deposits, sale_orders,
--    product_sales, group_session_clients, certificate_sales.redeemed_client_id,
--    client_telegram_links, telegram_notifications_log) — видаляються.
--    Перевірено: усі мають balance=0 і credit=0 (жодного боргу не втрачається).

USE er452618_crm4fitness;

-- ── 1) Бекфіл дати реєстрації з першого відвідування ──────────────────────
UPDATE clients c
JOIN (
    SELECT client_id, MIN(visited_at) AS first_visit
    FROM visits
    GROUP BY client_id
) v ON v.client_id = c.id
JOIN migration_client_id_map m ON m.new_id = c.id
JOIN er452618_drivecrm.tblNewTable t ON t.ID = m.old_id
SET c.created_at = v.first_visit
WHERE (t.DataDodavannja IS NULL OR CAST(t.DataDodavannja AS CHAR) = '0000-00-00');

-- ── 2) Видалення клієнтів без жодного пов'язаного запису ──────────────────
DELETE c FROM clients c
WHERE NOT EXISTS (SELECT 1 FROM visits v WHERE v.client_id = c.id)
  AND NOT EXISTS (SELECT 1 FROM client_invoices ci WHERE ci.client_id = c.id)
  AND NOT EXISTS (SELECT 1 FROM club_payments cp WHERE cp.client_id = c.id)
  AND NOT EXISTS (SELECT 1 FROM client_deposits cd WHERE cd.client_id = c.id)
  AND NOT EXISTS (SELECT 1 FROM sale_orders so WHERE so.client_id = c.id)
  AND NOT EXISTS (SELECT 1 FROM product_sales ps WHERE ps.client_id = c.id)
  AND NOT EXISTS (SELECT 1 FROM group_session_clients gsc WHERE gsc.client_id = c.id)
  AND NOT EXISTS (SELECT 1 FROM certificate_sales cs WHERE cs.redeemed_client_id = c.id)
  AND NOT EXISTS (SELECT 1 FROM client_telegram_links ctl WHERE ctl.client_id = c.id)
  AND NOT EXISTS (SELECT 1 FROM telegram_notifications_log tnl WHERE tnl.client_id = c.id);

-- ── Перевірка ────────────────────────────────────────────────────────────
SELECT COUNT(*) AS total_clients_left FROM clients;
SELECT DATE(created_at) d, COUNT(*) c FROM clients WHERE created_at >= '2026-09-01' GROUP BY d ORDER BY d;
