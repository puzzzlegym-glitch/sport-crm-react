-- Локальний тестовий прогін: чеки (sale_orders) для вже залитих product_sales
-- Причина: сторінка "Продажі" в UI читає sale_orders (chekи), product_sales.order_id
-- лише лінк на позицію. У drivecrm нема поняття "кошик з кількох товарів" —
-- кожен рядок tblPayments/product_sales стає окремим чеком 1:1.

USE er452618_crm4fitness;

CREATE TABLE IF NOT EXISTS migration_sale_order_map (
  ps_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  so_id INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_so_id (so_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @start_id := (
  SELECT AUTO_INCREMENT FROM information_schema.TABLES
  WHERE table_schema = 'er452618_crm4fitness' AND table_name = 'sale_orders'
);

INSERT INTO migration_sale_order_map (ps_id, so_id)
SELECT ps.id, @start_id - 1 + ROW_NUMBER() OVER (ORDER BY ps.id)
FROM product_sales ps
WHERE NOT EXISTS (SELECT 1 FROM migration_sale_order_map m WHERE m.ps_id = ps.id);

INSERT INTO sale_orders
  (id, club_id, order_number, client_id, client_name, items_count, subtotal,
   discount_amount, total_amount, payment_method, status, admin_name, created_at)
SELECT
  map.so_id,
  ps.club_id,
  ROW_NUMBER() OVER (PARTITION BY ps.club_id ORDER BY ps.created_at, ps.id),
  ps.client_id,
  c.full_name,
  1,
  ps.total_amount + ps.discount,
  ps.discount,
  ps.total_amount,
  ps.payment_method,
  'completed',
  ps.admin_name,
  ps.created_at
FROM product_sales ps
JOIN migration_sale_order_map map ON map.ps_id = ps.id
LEFT JOIN clients c ON c.id = ps.client_id
WHERE NOT EXISTS (SELECT 1 FROM sale_orders so WHERE so.id = map.so_id);

UPDATE product_sales ps
JOIN migration_sale_order_map map ON map.ps_id = ps.id
SET ps.order_id = map.so_id,
    ps.client_name = (SELECT full_name FROM clients WHERE id = ps.client_id)
WHERE ps.order_id IS NULL;

-- ── Перевірка ────────────────────────────────────────────────────────────
SELECT COUNT(*) AS orders_created FROM sale_orders;
SELECT COUNT(*) AS items_linked FROM product_sales WHERE order_id IS NOT NULL;
SELECT club_id, MAX(order_number) FROM sale_orders GROUP BY club_id;
