-- ІСТОРИЧНИЙ ДОКУМЕНТ (не застосовувати повторно). Знімок усіх 33 тригерів
-- прод-БД er452618_crm4fitness станом на 2026-09-12, до їх видалення.
-- Використовуйте цей файл лише щоб подивитись, ЩО саме робив кожен тригер
-- до переносу в app/core/Recalc.php.
--
-- ФІНАЛЬНИЙ СТАН (2026-09-12): усі 33 тригери видалено. Реальна діюча
-- міграція видалення — sql/2026-09-12_drop_all_remaining_triggers.sql
-- (+ dependency-міграції club_cashflow/cash_shifts перед нею, названі
-- в її ж коментарі). Уся логіка тепер у класі Recalc (app/core/Recalc.php).
--
-- Це перший крок плану переносу тригерів у код застосунку (обговорення
-- 2026-09-12): спершу зафіксувати поточний стан у файл, потім поступово
-- переносити логіку в PHP-сервіси і видаляти відповідний CREATE TRIGGER
-- звідси. Файл БЕЗПЕЧНО повторно застосовувати на проді просто зараз —
-- DROP IF EXISTS + CREATE відтворює той самий тригер, жодних змін поведінки.
--
-- ВАЖЛИВО про порядок спрацювання на club_payments: MySQL виконує кілька
-- AFTER-тригерів однієї події в порядку їх створення (ACTION_ORDER), а не
-- за назвою. На проді зараз (звірено по ACTION_ORDER/CREATED):
--   AFTER INSERT: trg_payment_after_insert → trg_payment_status_after_insert → trg_cashflow_payment_insert
--   AFTER UPDATE: trg_payment_after_update → trg_payment_status_after_update
--   AFTER DELETE: trg_payment_after_delete → trg_cashflow_payment_delete
-- trg_payment_status_after_* ЧИТАЄ client_invoices.paid_amount, який мусить
-- бути вже оновлений trg_payment_after_* — це і є прихована залежність, яку
-- варто зробити явною при переносі в код (Категорія 2 плану).
-- Порядок CREATE TRIGGER нижче навмисно повторює цей реальний порядок.

-- ============================================================
-- cash_shifts — тригери ВИДАЛЕНО 2026-09-12, замінено на
-- UNIQUE KEY uniq_cash_shifts_one_open_per_club на генерованій колонці
-- (не тригер — нативне обмеження InnoDB, атомарне, без вікна гонки).
-- Деталі: 2026-09-12_cash_shifts_drop_triggers_use_constraint.sql.
-- Помилку дублікату (SQLSTATE 23000) в app-коді (cash_api.php, open_shift)
-- перетворено на те саме повідомлення "У клубі вже є відкрита зміна".
-- ============================================================

-- ============================================================
-- clients — Категорія 1: проста нормалізація, легко перенести в PHP.
-- ============================================================

DROP TRIGGER IF EXISTS trg_clients_phone_insert;
DELIMITER $$
CREATE TRIGGER trg_clients_phone_insert BEFORE INSERT ON clients FOR EACH ROW BEGIN
  IF NEW.phone IS NOT NULL THEN
    SET NEW.phone_normalized = REGEXP_REPLACE(NEW.phone, '[^0-9]', '');
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_clients_phone_update;
DELIMITER $$
CREATE TRIGGER trg_clients_phone_update BEFORE UPDATE ON clients FOR EACH ROW BEGIN
  IF NEW.phone IS NOT NULL THEN
    SET NEW.phone_normalized = REGEXP_REPLACE(NEW.phone, '[^0-9]', '');
  END IF;
END$$
DELIMITER ;

-- ============================================================
-- client_deposits — Категорія 1: clients.balance = SUM(amount).
-- ============================================================

DROP TRIGGER IF EXISTS trg_deposit_after_insert;
DELIMITER $$
CREATE TRIGGER trg_deposit_after_insert AFTER INSERT ON client_deposits FOR EACH ROW BEGIN
  UPDATE clients
  SET balance = (
    SELECT COALESCE(SUM(amount), 0)
    FROM client_deposits
    WHERE client_id = NEW.client_id
  )
  WHERE id = NEW.client_id;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_deposit_update;
DELIMITER $$
CREATE TRIGGER trg_deposit_update AFTER UPDATE ON client_deposits FOR EACH ROW BEGIN
  UPDATE clients
  SET balance = (
    SELECT COALESCE(SUM(amount), 0)
    FROM client_deposits
    WHERE client_id = NEW.client_id
  )
  WHERE id = NEW.client_id;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_deposit_delete;
DELIMITER $$
CREATE TRIGGER trg_deposit_delete AFTER DELETE ON client_deposits FOR EACH ROW BEGIN
  UPDATE clients
  SET balance = (
    SELECT COALESCE(SUM(amount), 0)
    FROM client_deposits
    WHERE client_id = OLD.client_id
  )
  WHERE id = OLD.client_id;
END$$
DELIMITER ;

-- ============================================================
-- client_invoices — trg_invoice_trainer_earning ВИДАЛЕНО 2026-09-12.
-- Перенесено в Recalc::unlockInvoiceTrainerEarnings() (PHP), викликається
-- з invoiceVisitsUsed()/invoiceStatus() і з усіх прямих UPDATE
-- client_invoices у invoices_api.php. Деталі й емпірична перевірка:
-- 2026-09-12_drop_invoice_trainer_earning_trigger.sql.
-- ============================================================

-- ============================================================
-- club_expenses — Категорія 2: дзеркалення готівкових витрат у club_cashflow.
-- ============================================================

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
END$$
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
END$$
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
END$$
DELIMITER ;

-- ============================================================
-- club_payments — Категорія 1 (paid_amount) + Категорія 2 (cashflow-дзеркало,
-- авто-статус активний/скасований). Порядок CREATE тут = реальний
-- ACTION_ORDER на проді, дивись примітку про залежність на початку файлу.
-- ============================================================

DROP TRIGGER IF EXISTS trg_payment_after_insert;
DELIMITER $$
CREATE TRIGGER trg_payment_after_insert AFTER INSERT ON club_payments FOR EACH ROW BEGIN
  IF NEW.invoice_id IS NOT NULL THEN
    UPDATE client_invoices
    SET paid_amount = (
      SELECT COALESCE(SUM(amount), 0)
      FROM club_payments
      WHERE invoice_id = NEW.invoice_id
    )
    WHERE id = NEW.invoice_id;
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_payment_status_after_insert;
DELIMITER $$
CREATE TRIGGER trg_payment_status_after_insert AFTER INSERT ON club_payments FOR EACH ROW BEGIN
    DECLARE v_paid DECIMAL(10,2);
    DECLARE v_price DECIMAL(10,2);
    DECLARE v_status VARCHAR(20);

    IF NEW.invoice_id IS NOT NULL THEN
        SELECT paid_amount, price, status
        INTO v_paid, v_price, v_status
        FROM client_invoices
        WHERE id = NEW.invoice_id;

        IF v_paid >= v_price AND v_status = 'cancelled' THEN
            UPDATE client_invoices
            SET status = 'active'
            WHERE id = NEW.invoice_id;
        END IF;
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_cashflow_payment_insert;
DELIMITER $$
CREATE TRIGGER trg_cashflow_payment_insert AFTER INSERT ON club_payments FOR EACH ROW BEGIN
    IF NEW.payment_method = 'cash' THEN
        INSERT INTO club_cashflow
          (club_id, type, category, description, amount, payment_method,
           source, source_id, shift_id, admin_id, admin_name)
        VALUES
          (NEW.club_id, 'income', 'Абонемент', 'Оплата абонементу',
           NEW.amount, 'cash', 'invoice', NEW.id, NEW.shift_id,
           NEW.admin_id, NEW.admin_name);
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_payment_after_update;
DELIMITER $$
CREATE TRIGGER trg_payment_after_update AFTER UPDATE ON club_payments FOR EACH ROW BEGIN
  IF NEW.invoice_id IS NOT NULL THEN
    UPDATE client_invoices
    SET paid_amount = (
      SELECT COALESCE(SUM(amount), 0)
      FROM club_payments
      WHERE invoice_id = NEW.invoice_id
    )
    WHERE id = NEW.invoice_id;
  END IF;

  IF OLD.invoice_id IS NOT NULL AND OLD.invoice_id != NEW.invoice_id THEN
    UPDATE client_invoices
    SET paid_amount = (
      SELECT COALESCE(SUM(amount), 0)
      FROM club_payments
      WHERE invoice_id = OLD.invoice_id
    )
    WHERE id = OLD.invoice_id;
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_payment_status_after_update;
DELIMITER $$
CREATE TRIGGER trg_payment_status_after_update AFTER UPDATE ON club_payments FOR EACH ROW BEGIN
    DECLARE v_paid DECIMAL(10,2);
    DECLARE v_price DECIMAL(10,2);
    DECLARE v_status VARCHAR(20);

    -- Перевіряємо новий invoice
    IF NEW.invoice_id IS NOT NULL THEN
        SELECT paid_amount, price, status
        INTO v_paid, v_price, v_status
        FROM client_invoices
        WHERE id = NEW.invoice_id;

        IF v_paid >= v_price AND v_status = 'cancelled' THEN
            UPDATE client_invoices
            SET status = 'active'
            WHERE id = NEW.invoice_id;
        ELSEIF v_paid < v_price AND v_status = 'active' THEN
            UPDATE client_invoices
            SET status = 'cancelled'
            WHERE id = NEW.invoice_id;
        END IF;
    END IF;

    -- Якщо invoice_id змінився — перевіряємо старий
    IF OLD.invoice_id IS NOT NULL AND OLD.invoice_id != NEW.invoice_id THEN
        SELECT paid_amount, price, status
        INTO v_paid, v_price, v_status
        FROM client_invoices
        WHERE id = OLD.invoice_id;

        IF v_paid < v_price AND v_status = 'active' THEN
            UPDATE client_invoices
            SET status = 'cancelled'
            WHERE id = OLD.invoice_id;
        END IF;
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_payment_after_delete;
DELIMITER $$
CREATE TRIGGER trg_payment_after_delete AFTER DELETE ON club_payments FOR EACH ROW BEGIN
  IF OLD.invoice_id IS NOT NULL THEN
    UPDATE client_invoices
    SET paid_amount = (
      SELECT COALESCE(SUM(amount), 0)
      FROM club_payments
      WHERE invoice_id = OLD.invoice_id
    )
    WHERE id = OLD.invoice_id;
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_cashflow_payment_delete;
DELIMITER $$
CREATE TRIGGER trg_cashflow_payment_delete AFTER DELETE ON club_payments FOR EACH ROW BEGIN
    DELETE FROM club_cashflow
     WHERE source = 'invoice'
       AND source_id = OLD.id
       AND club_id = OLD.club_id
     LIMIT 1;
END$$
DELIMITER ;

-- ============================================================
-- product_arrivals — Категорія 1: products.stock_qty (прихід/списання).
-- ============================================================

DROP TRIGGER IF EXISTS trg_arrival_insert;
DELIMITER $$
CREATE TRIGGER trg_arrival_insert AFTER INSERT ON product_arrivals FOR EACH ROW BEGIN
  IF NEW.operation = 'arrival' AND NEW.status != 'pending' THEN
    UPDATE products SET stock_qty = stock_qty + NEW.quantity WHERE id = NEW.product_id;
  ELSEIF NEW.operation IN ('overdue','repack','transfer') THEN
    UPDATE products SET stock_qty = stock_qty - NEW.quantity WHERE id = NEW.product_id;
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_arrival_update;
DELIMITER $$
CREATE TRIGGER trg_arrival_update AFTER UPDATE ON product_arrivals FOR EACH ROW BEGIN
  -- Відкочуємо старий вплив
  IF OLD.operation = 'arrival' AND OLD.status != 'pending' THEN
    UPDATE products SET stock_qty = stock_qty - OLD.quantity WHERE id = OLD.product_id;
  ELSEIF OLD.operation IN ('overdue','repack','transfer') THEN
    UPDATE products SET stock_qty = stock_qty + OLD.quantity WHERE id = OLD.product_id;
  END IF;
  -- Застосовуємо новий вплив
  IF NEW.operation = 'arrival' AND NEW.status != 'pending' THEN
    UPDATE products SET stock_qty = stock_qty + NEW.quantity WHERE id = NEW.product_id;
  ELSEIF NEW.operation IN ('overdue','repack','transfer') THEN
    UPDATE products SET stock_qty = stock_qty - NEW.quantity WHERE id = NEW.product_id;
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_arrival_delete;
DELIMITER $$
CREATE TRIGGER trg_arrival_delete AFTER DELETE ON product_arrivals FOR EACH ROW BEGIN
  IF OLD.operation = 'arrival' AND OLD.status != 'pending' THEN
    UPDATE products SET stock_qty = stock_qty - OLD.quantity WHERE id = OLD.product_id;
  ELSEIF OLD.operation IN ('overdue','repack','transfer') THEN
    UPDATE products SET stock_qty = stock_qty + OLD.quantity WHERE id = OLD.product_id;
  END IF;
END$$
DELIMITER ;

-- ============================================================
-- product_sales — Категорія 1 (stock_qty) + Категорія 2 (cashflow-дзеркало).
-- Порядок CREATE = реальний ACTION_ORDER на проді.
-- ============================================================

DROP TRIGGER IF EXISTS trg_sale_insert;
DELIMITER $$
CREATE TRIGGER trg_sale_insert AFTER INSERT ON product_sales FOR EACH ROW BEGIN
  UPDATE products
  SET stock_qty = stock_qty - NEW.quantity
  WHERE id = NEW.product_id AND NEW.product_id IS NOT NULL;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_cashflow_sale_insert;
DELIMITER $$
CREATE TRIGGER trg_cashflow_sale_insert AFTER INSERT ON product_sales FOR EACH ROW BEGIN
    IF NEW.payment_method = 'cash' THEN
        INSERT INTO club_cashflow
          (club_id, type, category, description, amount, payment_method,
           source, source_id, shift_id, admin_id, admin_name)
        VALUES
          (NEW.club_id, 'income', 'Товар', CONCAT('Продаж: ', NEW.product_name),
           NEW.total_amount, 'cash', 'product_sale', NEW.id, NEW.shift_id,
           NEW.admin_id, NEW.admin_name);
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_sale_update;
DELIMITER $$
CREATE TRIGGER trg_sale_update AFTER UPDATE ON product_sales FOR EACH ROW BEGIN
  UPDATE products
  SET stock_qty = stock_qty + OLD.quantity - NEW.quantity
  WHERE id = NEW.product_id AND NEW.product_id IS NOT NULL;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_sale_delete;
DELIMITER $$
CREATE TRIGGER trg_sale_delete AFTER DELETE ON product_sales FOR EACH ROW BEGIN
  UPDATE products
  SET stock_qty = stock_qty + OLD.quantity
  WHERE id = OLD.product_id AND OLD.product_id IS NOT NULL;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_cashflow_sale_delete;
DELIMITER $$
CREATE TRIGGER trg_cashflow_sale_delete AFTER DELETE ON product_sales FOR EACH ROW BEGIN
    DELETE FROM club_cashflow
     WHERE source = 'product_sale'
       AND source_id = OLD.id
       AND club_id = OLD.club_id
     LIMIT 1;
END$$
DELIMITER ;

-- ============================================================
-- saas_payments / saas_subscriptions — Категорія 2: каскад білінгу
-- SaaS-підписки (invoice → subscription → club status).
-- ============================================================

DROP TRIGGER IF EXISTS trg_saas_payment_after_insert;
DELIMITER $$
CREATE TRIGGER trg_saas_payment_after_insert AFTER INSERT ON saas_payments FOR EACH ROW BEGIN
    DECLARE v_total_paid DECIMAL(10,2);
    DECLARE v_amount DECIMAL(10,2);

    IF NEW.status = 'success' AND NEW.invoice_id IS NOT NULL THEN
        SELECT COALESCE(SUM(amount), 0) INTO v_total_paid
        FROM saas_payments
        WHERE invoice_id = NEW.invoice_id
          AND status = 'success';

        SELECT amount INTO v_amount
        FROM saas_invoices
        WHERE id = NEW.invoice_id;

        IF v_total_paid >= v_amount THEN
            UPDATE saas_invoices
            SET status = 'paid',
                paid_at = NOW()
            WHERE id = NEW.invoice_id;
        END IF;
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_saas_payment_after_delete;
DELIMITER $$
CREATE TRIGGER trg_saas_payment_after_delete AFTER DELETE ON saas_payments FOR EACH ROW BEGIN
    DECLARE v_total_paid DECIMAL(10,2);
    DECLARE v_amount DECIMAL(10,2);

    IF OLD.invoice_id IS NOT NULL THEN
        SELECT COALESCE(SUM(amount), 0) INTO v_total_paid
        FROM saas_payments
        WHERE invoice_id = OLD.invoice_id
          AND status = 'success';

        SELECT amount INTO v_amount
        FROM saas_invoices
        WHERE id = OLD.invoice_id;

        IF v_total_paid < v_amount THEN
            UPDATE saas_invoices
            SET status = 'draft',
                paid_at = NULL
            WHERE id = OLD.invoice_id;

            -- Переводимо підписку і клуб в past_due
            UPDATE saas_subscriptions ss
            JOIN saas_invoices si ON si.id = OLD.invoice_id
            SET ss.status = 'past_due'
            WHERE ss.club_id = si.club_id
              AND ss.status = 'active';

            UPDATE sys_clubs sc
            JOIN saas_invoices si ON si.id = OLD.invoice_id
            SET sc.subscription_status = 'past_due'
            WHERE sc.id = si.club_id;
        END IF;
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_sub_after_delete;
DELIMITER $$
CREATE TRIGGER trg_sub_after_delete AFTER DELETE ON saas_subscriptions FOR EACH ROW BEGIN
    UPDATE sys_clubs
    SET subscription_status = 'trial_expired',
        is_active = 0
    WHERE id = OLD.club_id;
END$$
DELIMITER ;

-- ============================================================
-- visits — Категорія 1 (visits_used) + Категорія 2 (розблокування
-- зарплати тренера release_trigger='on_each_visit').
-- ============================================================

DROP TRIGGER IF EXISTS trg_visit_after_insert;
DELIMITER $$
CREATE TRIGGER trg_visit_after_insert AFTER INSERT ON visits FOR EACH ROW BEGIN
  IF NEW.invoice_id IS NOT NULL THEN
    UPDATE client_invoices
    SET visits_used = (
      SELECT COUNT(*)
      FROM visits
      WHERE invoice_id = NEW.invoice_id
    )
    WHERE id = NEW.invoice_id;
  END IF;
END$$
DELIMITER ;

-- trg_visit_trainer_earning ВИДАЛЕНО 2026-09-12 (не перенесено — видалено,
-- бо не робив нічого корисного). Аналіз + емпірична перевірка на локальному
-- стенді: Attendance::createTrainerEarning() створює окремий запис
-- trainer_earnings на кожне відвідування і для release_trigger='on_each_visit'
-- (єдине значення, яке реально використовує хоч один тариф) одразу робить
-- його повністю розблокованим — LEAST(amount, available_amount+unlock) у
-- цьому тригері завжди впирався у вже досягнутий максимум. Деталі:
-- 2026-09-12_drop_dead_visit_trainer_earning_trigger.sql.

DROP TRIGGER IF EXISTS trg_visit_after_update;
DELIMITER $$
CREATE TRIGGER trg_visit_after_update AFTER UPDATE ON visits FOR EACH ROW BEGIN
  IF NEW.invoice_id IS NOT NULL THEN
    UPDATE client_invoices
    SET visits_used = (
      SELECT COUNT(*)
      FROM visits
      WHERE invoice_id = NEW.invoice_id
    )
    WHERE id = NEW.invoice_id;
  END IF;
  IF OLD.invoice_id IS NOT NULL AND OLD.invoice_id != NEW.invoice_id THEN
    UPDATE client_invoices
    SET visits_used = (
      SELECT COUNT(*)
      FROM visits
      WHERE invoice_id = OLD.invoice_id
    )
    WHERE id = OLD.invoice_id;
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_visit_after_delete;
DELIMITER $$
CREATE TRIGGER trg_visit_after_delete AFTER DELETE ON visits FOR EACH ROW BEGIN
  IF OLD.invoice_id IS NOT NULL THEN
    UPDATE client_invoices
    SET visits_used = (
      SELECT COUNT(*)
      FROM visits
      WHERE invoice_id = OLD.invoice_id
    )
    WHERE id = OLD.invoice_id;
  END IF;
END$$
DELIMITER ;
