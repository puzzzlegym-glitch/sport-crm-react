-- Локальний тестовий прогін етапу 4f (товари: каталог, прихід, продажі)
-- з drivecrm -> crm4fitness.
-- Джерела: tblOrders (6216, прихід), tblPayments (79193, продажі — уже
-- аналізували в 4c, тепер перероблюємо з product_id).
-- Ціль: products, product_arrivals, product_sales
--
-- Ключове відкриття: TovarID — це реальний стабільний артикул товару,
-- СПІЛЬНИЙ між tblOrders і tblPayments (939 спільних ID, лише 4 з різною
-- назвою — дрібні перейменування). У drivecrm нема окремої таблиці-каталогу
-- товарів (на відміну від tblProducts для абонементів) — каталог відновлюємо
-- з фактичних приходів/продажів.
--
-- Правила:
--  - Один TovarID може зустрічатись у кількох клубах (як і "Загальні"
--    тарифи) -> дублюємо на кожен клуб, де товар реально фігурував
--    (migration_product_id_map: ключ (old_tovar_id, club_id), як для тарифів).
--  - Атрибути каталогу (назва/категорія/ціни/постачальник) беремо з
--    НАЙНОВІШОГО запису tblOrders для цього (TovarID,ClubID); якщо приходу
--    в цьому клубі не було — з найновішого tblPayments.
--  - tblOrders.Operacion -> product_arrivals.operation: Прихід->arrival,
--    Перенесення склад->transfer, Прострочка->overdue, Розфасування->repack.
--  - tblOrders.Status -> status: Оплачено->paid, Не оплачено->unpaid.

USE er452618_crm4fitness;

-- --- Крок 1: зведений список (TovarID, ClubID, назва) з обох джерел -------
DROP TEMPORARY TABLE IF EXISTS tmp_product_targets;
CREATE TEMPORARY TABLE tmp_product_targets (
  old_id INT NOT NULL,
  club_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (old_id, club_id)
);

INSERT IGNORE INTO tmp_product_targets (old_id, club_id)
SELECT TovarID, ClubID FROM er452618_drivecrm.tblOrders WHERE ClubID IN (1,2) AND TovarID IS NOT NULL
UNION
SELECT TovarID, ClubID FROM er452618_drivecrm.tblPayments WHERE ClubID IN (1,2) AND TovarID IS NOT NULL;

-- --- Крок 2: мапінг старий(TovarID,club) -> новий products.id -------------
CREATE TABLE IF NOT EXISTS migration_product_id_map (
  old_id   INT NOT NULL,
  club_id  INT UNSIGNED NOT NULL,
  new_id   INT UNSIGNED NOT NULL,
  migrated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (old_id, club_id),
  UNIQUE KEY uq_new_id (new_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @start_id := (
  SELECT AUTO_INCREMENT FROM information_schema.TABLES
  WHERE table_schema = 'er452618_crm4fitness' AND table_name = 'products'
);

INSERT INTO migration_product_id_map (old_id, club_id, new_id)
SELECT old_id, club_id, @start_id - 1 + ROW_NUMBER() OVER (ORDER BY old_id, club_id)
FROM tmp_product_targets x
WHERE NOT EXISTS (SELECT 1 FROM migration_product_id_map m WHERE m.old_id=x.old_id AND m.club_id=x.club_id);

-- --- Крок 3: останній відомий запис по кожному (TovarID,ClubID) з tblOrders
DROP TEMPORARY TABLE IF EXISTS tmp_last_order;
CREATE TEMPORARY TABLE tmp_last_order AS
SELECT TovarID, ClubID, NazvaTovary, Kategory, CenaVhod, CenaVuhod, Firm1
FROM (
  SELECT o.*, ROW_NUMBER() OVER (PARTITION BY o.TovarID, o.ClubID ORDER BY o.OrderDate DESC, o.ID DESC) AS rn
  FROM er452618_drivecrm.tblOrders o
  WHERE o.ClubID IN (1,2)
) x WHERE rn = 1;

-- --- Крок 4: fallback з tblPayments, де приходу в цьому клубі не було -----
DROP TEMPORARY TABLE IF EXISTS tmp_last_payment;
CREATE TEMPORARY TABLE tmp_last_payment AS
SELECT TovarID, ClubID, NazvaTovary, Kategory1 AS Kategory, CenaVhod2 AS CenaVhod, CenaVuhod1 AS CenaVuhod
FROM (
  SELECT p.*, ROW_NUMBER() OVER (PARTITION BY p.TovarID, p.ClubID ORDER BY p.PaymentDate DESC, p.ID DESC) AS rn
  FROM er452618_drivecrm.tblPayments p
  WHERE p.ClubID IN (1,2) AND p.TovarID IS NOT NULL
) x WHERE rn = 1;

-- --- Крок 5: заповнюємо каталог products -----------------------------------
INSERT INTO products (id, club_id, name, category, supplier, purchase_price, sale_price, is_active, created_at)
SELECT
  m.new_id,
  m.club_id,
  COALESCE(o.NazvaTovary, p.NazvaTovary),
  COALESCE(NULLIF(TRIM(o.Kategory),''), NULLIF(TRIM(p.Kategory),'')),
  o.Firm1,
  COALESCE(o.CenaVhod, p.CenaVhod, 0),
  COALESCE(o.CenaVuhod, p.CenaVuhod, 0),
  1,
  NOW()
FROM migration_product_id_map m
LEFT JOIN tmp_last_order o ON o.TovarID=m.old_id AND o.ClubID=m.club_id
LEFT JOIN tmp_last_payment p ON p.TovarID=m.old_id AND p.ClubID=m.club_id
WHERE NOT EXISTS (SELECT 1 FROM products pr WHERE pr.id=m.new_id);

-- --- Крок 6: прихід товару (product_arrivals) з tblOrders -------------------
INSERT INTO product_arrivals
  (club_id, product_id, product_name, quantity, operation, status, purchase_price,
   sale_price, supplier, total_cost, admin_name, created_at)
SELECT
  o.ClubID,
  m.new_id,
  o.NazvaTovary,
  o.OrderAmount,
  CASE o.Operacion
    WHEN 'Прихід' THEN 'arrival'
    WHEN 'Перенесення склад' THEN 'transfer'
    WHEN 'Прострочка' THEN 'overdue'
    WHEN 'Розфасування' THEN 'repack'
    ELSE 'arrival'
  END,
  CASE o.Status WHEN 'Оплачено' THEN 'paid' WHEN 'Не оплачено' THEN 'unpaid' ELSE 'paid' END,
  o.CenaVhod,
  o.CenaVuhod,
  NULLIF(TRIM(o.Firm1), ''),
  o.CenaVhod * o.OrderAmount,
  NULLIF(TRIM(o.UserName), ''),
  o.OrderDate
FROM er452618_drivecrm.tblOrders o
JOIN migration_product_id_map m ON m.old_id = o.TovarID AND m.club_id = o.ClubID
WHERE o.ClubID IN (1,2);

-- --- Крок 7: продажі товару (product_sales) з tblPayments, тепер з product_id
INSERT INTO product_sales
  (club_id, product_id, product_name, client_id, quantity, purchase_price, sale_price,
   discount, total_amount, profit, payment_method, admin_name, notes, created_at)
SELECT
  p.ClubID,
  m.new_id,
  p.NazvaTovary,
  cm.new_id,
  p.PaymentAmount,
  p.CenaVhod2,
  p.CenaVuhod1,
  p.Znugka,
  p.SummVuhod1,
  p.Vurychka,
  CASE p.FormaOplatu
    WHEN 'готівка' THEN 'cash'
    WHEN 'картка' THEN 'card'
    WHEN 'термінал (безготівка)' THEN 'terminal'
    WHEN 'депозит' THEN 'deposit'
    ELSE 'other'
  END,
  NULLIF(TRIM(p.UserName), ''),
  NULLIF(TRIM(p.Kategory1), ''),
  p.PaymentDate
FROM er452618_drivecrm.tblPayments p
LEFT JOIN migration_client_id_map cm ON cm.old_id = p.ClientID
LEFT JOIN migration_product_id_map m ON m.old_id = p.TovarID AND m.club_id = p.ClubID
WHERE p.ClubID IN (1,2);

-- ── Перевірка ────────────────────────────────────────────────────────────
SELECT COUNT(*) AS products_catalog FROM products;
SELECT COUNT(*) AS arrivals FROM product_arrivals;
SELECT COUNT(*) AS sales_total, SUM(product_id IS NOT NULL) AS sales_with_product FROM product_sales;

SELECT club_id, COUNT(*) FROM products GROUP BY club_id;
SELECT operation, status, COUNT(*), SUM(total_cost) FROM product_arrivals GROUP BY operation, status;

SELECT p.id, p.club_id, p.name, p.category, p.supplier, p.purchase_price, p.sale_price
FROM products p ORDER BY p.id DESC LIMIT 10;
