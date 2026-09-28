-- ============================================================================
-- Уніфікація каси: club_cashflow стає єдиним журналом готівки (register+safe)
-- замість трьох неузгоджених джерел (Фінанси рахують "на льоту" з
-- club_payments/product_sales/club_expenses, а модуль "Каса" не бачив ручні
-- витрати з Фінансів). Додає другу локацію готівки — сейф.
--
-- Використовується: api/cash_api.php (cashBalance/get_summary/add_expense/
--                    encashment/refill_from_safe/adjust_balance),
--                    api/finance_api.php (get_dashboard),
--                    src/api/cash.js, src/pages/CashPage.jsx,
--                    pages/cash.php (легасі паралельний UI)
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-09-11
--
-- ІСТОРІЯ ЗАСТОСУВАННЯ ЦІЄЇ МІГРАЦІЇ (важливо!):
-- Перша спроба виконання цього файлу (2026-09-11) вже ЧАСТКОВО пройшла до
-- того, як дійшла до CREATE TRIGGER trg_cashflow_payment_insert і впала з
-- помилкою #1359 "Trigger already exists" — тому що в БД вже існували старі
-- продові тригери trg_cashflow_payment_insert/delete та trg_cashflow_sale_
-- insert/delete (звірено з реальним дампом er452618_crm4fitness.sql від
-- 2026-09-11, наданим власником), які ВЖЕ коректно пишуть готівкові
-- оплати/продажі в club_cashflow (з location='register' за замовчуванням).
-- Це спростовує попереднє припущення, що ці тригери вимкнені — вони активні.
--
-- Тому нижче ЛИШАЮТЬСЯ ТІЛЬКИ statements, які ще не застосовані:
--   1) розширення ENUM club_cashflow.type новими значеннями для переказів/
--      коригування (transfer_in/transfer_out/adjustment)
--   2) нові тригери-дзеркала на club_expenses (раніше такого дзеркала не
--      було взагалі — ручні витрати з Фінансів у club_cashflow не потрапляли)
-- Вже застосовані раніше й НЕ повторюються тут (щоб не ловити "Duplicate
-- column"): ALTER TABLE club_cashflow ADD COLUMN location (+ ADD INDEX
-- idx_cashflow_location), ALTER TABLE club_expenses ADD COLUMN shift_id.
-- Існуючі trg_cashflow_payment_insert/delete та trg_cashflow_sale_insert/
-- delete НЕ чіпаються — вони вже коректні (INSERT без явної колонки
-- location спирається на DEFAULT 'register', що те саме, що нам треба).
--
-- РІШЕННЯ (погоджено з власником): історію НЕ бекафілимо для нових типів —
-- стартовий залишок каси/сейфа (за фактичним перерахунком готівки) власник
-- вносить вручну через нову дію 'adjust_balance' (type='adjustment').
-- ============================================================================

-- 1) Нові типи операцій у club_cashflow.type (перекази register<->safe,
--    ручне коригування балансу). Реальний ENUM на 2026-09-11:
--    enum('income','expense','transfer','encashment') — 'transfer' лишається
--    невикористаним легасі-значенням, нові типи додаються поруч з ним.
ALTER TABLE club_cashflow
  MODIFY COLUMN type ENUM('income','expense','transfer','encashment','transfer_in','transfer_out','adjustment')
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'income';

-- 2) Тригери-дзеркала club_expenses -> club_cashflow (location='register') --
--    Раніше такого дзеркала не існувало: ручні витрати з Фінансів
--    (finance_api.php add_expense) у club_cashflow не потрапляли — тільки
--    ручні витрати, заведені напряму через cash_api.php. Тепер cash_api.php
--    теж пише в club_expenses (див. окрему зміну в api/cash_api.php), і цей
--    тригер приводить обидва джерела до одного журналу.
DROP TRIGGER IF EXISTS trg_cashflow_expense_insert;
DELIMITER $$
CREATE TRIGGER trg_cashflow_expense_insert AFTER INSERT ON club_expenses FOR EACH ROW BEGIN
    IF NEW.payment_method = 'cash' THEN
        INSERT INTO club_cashflow
          (club_id, type, category, description, amount, payment_method,
           source, source_id, shift_id, admin_id, admin_name)
        VALUES
          (NEW.club_id, 'expense', NEW.category, NEW.description, NEW.amount,
           'cash', 'expense', NEW.id, NEW.shift_id, NEW.admin_id, NEW.admin_name);
    END IF;
END
$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_cashflow_expense_update;
DELIMITER $$
CREATE TRIGGER trg_cashflow_expense_update AFTER UPDATE ON club_expenses FOR EACH ROW BEGIN
    IF OLD.payment_method = 'cash' THEN
        DELETE FROM club_cashflow
         WHERE source = 'expense'
           AND source_id = OLD.id
           AND club_id = OLD.club_id
         LIMIT 1;
    END IF;
    IF NEW.payment_method = 'cash' THEN
        INSERT INTO club_cashflow
          (club_id, type, category, description, amount, payment_method,
           source, source_id, shift_id, admin_id, admin_name)
        VALUES
          (NEW.club_id, 'expense', NEW.category, NEW.description, NEW.amount,
           'cash', 'expense', NEW.id, NEW.shift_id, NEW.admin_id, NEW.admin_name);
    END IF;
END
$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_cashflow_expense_delete;
DELIMITER $$
CREATE TRIGGER trg_cashflow_expense_delete AFTER DELETE ON club_expenses FOR EACH ROW BEGIN
    IF OLD.payment_method = 'cash' THEN
        DELETE FROM club_cashflow
         WHERE source = 'expense'
           AND source_id = OLD.id
           AND club_id = OLD.club_id
         LIMIT 1;
    END IF;
END
$$
DELIMITER ;
