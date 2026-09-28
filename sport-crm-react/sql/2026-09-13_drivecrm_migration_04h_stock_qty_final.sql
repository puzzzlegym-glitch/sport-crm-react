-- Локальний тестовий прогін: розрахунок products.stock_qty з історії
-- (прихід - списання/перенесення - продаж), бо міграція товарів (4f) не
-- рахувала залишок.
-- stock_qty = SUM(arrival) - SUM(overdue+repack+transfer) - SUM(product_sales.quantity)
-- Без затискання знизу (рішення власника 2026-09-13: показувати реальний
-- розрахунок, навіть якщо від'ємний — самостійно звірить/спише різницю).
-- На практиці від'ємних не виявилось (SUM(sold) ніде не перевищив
-- SUM(net_arrivals)) — 1044 товари вийшли рівно 0, 300 — з додатним залишком.

USE er452618_crm4fitness;

UPDATE products p
LEFT JOIN (
  SELECT product_id,
    SUM(CASE WHEN operation='arrival' THEN quantity ELSE -quantity END) AS net_arrivals
  FROM product_arrivals
  GROUP BY product_id
) a ON a.product_id = p.id
LEFT JOIN (
  SELECT product_id, SUM(quantity) AS sold
  FROM product_sales
  WHERE product_id IS NOT NULL
  GROUP BY product_id
) s ON s.product_id = p.id
SET p.stock_qty = COALESCE(a.net_arrivals,0) - COALESCE(s.sold,0);

-- ── Перевірка ────────────────────────────────────────────────────────────
SELECT COUNT(*) AS total, SUM(stock_qty=0) AS zero_stock, SUM(stock_qty>0) AS in_stock
FROM products;

SELECT id, name, stock_qty FROM products ORDER BY stock_qty DESC LIMIT 10;
