-- ============================================================================
-- Транзакційна модель продажів товарів: чек (sale_orders) з кількома
-- позиціями та статусом (completed/returned) — замість пласкої моделі,
-- де 1 рядок product_sales = 1 завершений продаж без групування й без
-- статусу повернення. product_sales лишається таблицею позицій чека
-- (1 рядок = 1 товар у чеку), додається order_id.
-- Використовується: api/sales_api.php (нові дії get_orders/get_order/
--                    create_order/return_order), api/sell_api.php,
--                    src/api/sales.js, src/pages/SalesPage.jsx
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-08-25
-- ============================================================================
-- ВАЖЛИВО для бекенду (ще не реалізовано, тільки схема + фронт):
--  • create_order — в одній транзакції: 1 INSERT sale_orders (order_number =
--    MAX(order_number)+1 у межах club_id), потім по 1 INSERT product_sales
--    на кожну позицію з order_id = lastInsertId(). Тригери trg_sale_insert/
--    trg_sale_delete на product_sales не чіпаються — списання stock лишається
--    на них, як і зараз.
--  • return_order — НЕ видаляє рядки product_sales (інакше зникне історія
--    для "Товари → Продажі" / totalSold на картці товару): робимо UPDATE
--    sale_orders SET status='returned', returned_at=NOW(), returned_by_id,
--    returned_by_name, return_reason; і окремим UPDATE products
--    SET stock_qty = stock_qty + quantity по кожній позиції чека (тригер на
--    DELETE тут не спрацює, бо рядки не видаляються). Оплату депозитом при
--    поверненні назад на баланс клієнта не зараховуємо автоматично.
--  • sell_api.php (одиночний продаж із /products) — для консистентності
--    історії на сторінці "Продаж товарів" повинен також створювати
--    sale_orders (1 позиція) і проставляти product_sales.order_id, а не
--    лишати order_id NULL.
--  • Оплата депозитом на кілька позицій — по одному рядку client_deposits на
--    кожну позицію чека (sale_id = id відповідного product_sales), як і зараз.
-- ============================================================================

-- 1) Заголовок чека ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS sale_orders (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    club_id           INT UNSIGNED  NOT NULL,
    order_number      INT UNSIGNED  NOT NULL,
    client_id         INT UNSIGNED  NULL,
    client_name       VARCHAR(191)  NULL,
    items_count       INT UNSIGNED  NOT NULL DEFAULT 0,
    subtotal          DECIMAL(10,2) NOT NULL DEFAULT 0,
    discount_amount   DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_amount      DECIMAL(10,2) NOT NULL DEFAULT 0,
    payment_method    ENUM('cash','card','terminal','deposit','other') NOT NULL DEFAULT 'cash',
    status            ENUM('completed','returned') NOT NULL DEFAULT 'completed',
    shift_id          INT UNSIGNED  NULL,
    admin_id          INT UNSIGNED  NULL,
    admin_name        VARCHAR(191)  NULL,
    notes             VARCHAR(500)  NULL,
    return_reason     VARCHAR(500)  NULL,
    returned_at       DATETIME      NULL,
    returned_by_id    INT UNSIGNED  NULL,
    returned_by_name  VARCHAR(191)  NULL,
    created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_so_club_number (club_id, order_number),
    KEY idx_so_club_created (club_id, created_at),
    KEY idx_so_club_status (club_id, status),
    CONSTRAINT fk_so_club   FOREIGN KEY (club_id)   REFERENCES sys_clubs(id) ON DELETE CASCADE,
    CONSTRAINT fk_so_client FOREIGN KEY (client_id) REFERENCES clients(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Прив'язка позицій чека до заголовка ------------------------------------
ALTER TABLE product_sales
  ADD COLUMN order_id INT NULL AFTER id,
  ADD KEY idx_ps_order (order_id),
  ADD CONSTRAINT fk_ps_order FOREIGN KEY (order_id) REFERENCES sale_orders(id) ON DELETE SET NULL;

-- 3) Бекфіл: кожен наявний рядок product_sales — окремий завершений чек
--    із 1 позицією; order_number тимчасово = id рядка product_sales
--    (унікальний у межах club_id, подальші чеки нумеруються від MAX+1).
INSERT INTO sale_orders
    (club_id, order_number, client_id, client_name, items_count,
     subtotal, discount_amount, total_amount, payment_method, status,
     shift_id, admin_id, admin_name, notes, created_at)
SELECT ps.club_id, ps.id, ps.client_id, ps.client_name, 1,
       ps.quantity * ps.sale_price, ps.discount, ps.total_amount,
       ps.payment_method, 'completed',
       ps.shift_id, ps.admin_id, ps.admin_name, ps.notes, ps.created_at
FROM product_sales ps
WHERE ps.order_id IS NULL;

UPDATE product_sales ps
JOIN sale_orders so ON so.club_id = ps.club_id AND so.order_number = ps.id
SET ps.order_id = so.id
WHERE ps.order_id IS NULL;
