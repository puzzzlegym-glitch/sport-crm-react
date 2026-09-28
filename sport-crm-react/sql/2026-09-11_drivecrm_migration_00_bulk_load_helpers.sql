-- ============================================================================
-- Допоміжний скрипт для масового завантаження на етапі 4 (оплати/депозити/
-- відвідування, ~800 000+ рядків з drivecrm). Проблема: тригери на
-- client_deposits / club_payments / visits перераховують SUM()/COUNT() по
-- ВСІХ рядках клієнта/інвойса на КОЖЕН INSERT — кумулятивно це може тягнутись
-- годинами на великих обсягах.
--
-- Порядок дій для кожного важкого етапу (депозити, оплати, відвідування):
--   1) Виконати відповідний блок СЕКЦІЇ A (DROP тригерів для однієї таблиці).
--   2) Залити дані звичайним INSERT...SELECT (без тригерів це вже швидко —
--      секунди/хвилини навіть на мільйон рядків, бо це просто append).
--   3) Виконати відповідний UPDATE із СЕКЦІЇ B (один агрегатний перерахунок
--      замість тисяч часткових).
--   4) Виконати відповідний блок СЕКЦІЇ C (CREATE тригерів назад — тексти
--      1-в-1 як у продовій БД, звірено з дампом er452618_crm4fitness.sql
--      від 10.09.2026).
--
-- Якщо навіть без тригерів один INSERT...SELECT на 800k рядків впаде по
-- таймауту phpMyAdmin (типово ліміт виконання скрипта на shared-хостингу) —
-- розбити на шматки по ID (наприклад `WHERE t.ID BETWEEN 1 AND 20000`,
-- `BETWEEN 20001 AND 40000` і т.д.) і виконати послідовно.
--
-- УВАГА: club_cashflow. trg_cashflow_payment_insert / trg_cashflow_sale_insert
-- при кожній готівковій оплаті/продажу пишуть рядок у club_cashflow — таблицю,
-- яка виглядає як "жива" стрічка руху коштів для звірки каси за зміну/день.
-- Якщо залити туди 800 000 історичних рядків за роки — вона перетвориться на
-- архів замість оперативного інструменту. РЕКОМЕНДАЦІЯ: не відновлювати ці
-- два тригери одразу (лишити їх вимкненими) під час імпорту оплат/продажів,
-- і НЕ бекафілити club_cashflow заднім числом — воно і так не бере участі в
-- жодному FK/перерахунку, лише в звітах "на сьогодні". Погодьте це рішення
-- окремо, якщо потрібна повна історична стрічка каси — тоді розкоментувати
-- відповідний INSERT...SELECT у СЕКЦІЇ D.
-- Дата: 2026-09-11
-- ============================================================================


-- ============================================================================
-- СЕКЦІЯ A: прибрати тригери (виконати перед заливкою відповідної таблиці)
-- ============================================================================

-- --- A1: client_deposits (перед заливкою депозитів) -------------------------
DROP TRIGGER IF EXISTS trg_deposit_after_insert;
DROP TRIGGER IF EXISTS trg_deposit_delete;
DROP TRIGGER IF EXISTS trg_deposit_update;

-- --- A2: club_payments (перед заливкою оплат) -------------------------------
DROP TRIGGER IF EXISTS trg_cashflow_payment_delete;
DROP TRIGGER IF EXISTS trg_cashflow_payment_insert;
DROP TRIGGER IF EXISTS trg_payment_after_delete;
DROP TRIGGER IF EXISTS trg_payment_after_insert;
DROP TRIGGER IF EXISTS trg_payment_after_update;
DROP TRIGGER IF EXISTS trg_payment_status_after_insert;
DROP TRIGGER IF EXISTS trg_payment_status_after_update;

-- --- A3: visits (перед заливкою відвідувань) --------------------------------
DROP TRIGGER IF EXISTS trg_visit_after_delete;
DROP TRIGGER IF EXISTS trg_visit_after_insert;
DROP TRIGGER IF EXISTS trg_visit_after_update;
DROP TRIGGER IF EXISTS trg_visit_trainer_earning;


-- ============================================================================
-- СЕКЦІЯ B: одноразовий агрегатний перерахунок (виконати одразу після
-- заливки відповідної таблиці, ДО повернення тригерів)
-- ============================================================================

-- --- B1: clients.balance з усіх client_deposits -----------------------------
UPDATE clients c
JOIN (
  SELECT client_id, SUM(amount) AS total
  FROM client_deposits
  GROUP BY client_id
) d ON d.client_id = c.id
SET c.balance = d.total;

-- --- B2: client_invoices.paid_amount з усіх club_payments -------------------
UPDATE client_invoices ci
JOIN (
  SELECT invoice_id, SUM(amount) AS total
  FROM club_payments
  WHERE invoice_id IS NOT NULL
  GROUP BY invoice_id
) p ON p.invoice_id = ci.id
SET ci.paid_amount = p.total;

-- --- B3: client_invoices.visits_used з усіх visits --------------------------
UPDATE client_invoices ci
JOIN (
  SELECT invoice_id, COUNT(*) AS cnt
  FROM visits
  WHERE invoice_id IS NOT NULL
  GROUP BY invoice_id
) v ON v.invoice_id = ci.id
SET ci.visits_used = v.cnt;

-- --- B4: client_invoices.status після масового перерахунку paid_amount -----
-- Тригер trg_payment_status_after_insert під час імпорту буде вимкнено, тож
-- статус, виставлений на етапі 3 (абонементи), лишається як є — цей UPDATE
-- лише підчищає випадок "оплачено повністю, але позначено cancelled",
-- який той тригер відловлював по одному рядку.
UPDATE client_invoices
SET status = 'active'
WHERE status = 'cancelled' AND paid_amount >= price;


-- ============================================================================
-- СЕКЦІЯ C: повернути тригери (текст 1-в-1 з er452618_crm4fitness.sql,
-- дамп від 10.09.2026 — не редагувати без потреби)
-- ============================================================================

-- --- C1: client_deposits -----------------------------------------------------
DELIMITER $$
CREATE TRIGGER `trg_deposit_after_insert` AFTER INSERT ON `client_deposits` FOR EACH ROW BEGIN
  UPDATE clients
  SET balance = (
    SELECT COALESCE(SUM(amount), 0)
    FROM client_deposits
    WHERE client_id = NEW.client_id
  )
  WHERE id = NEW.client_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_deposit_delete` AFTER DELETE ON `client_deposits` FOR EACH ROW BEGIN
  UPDATE clients
  SET balance = (
    SELECT COALESCE(SUM(amount), 0)
    FROM client_deposits
    WHERE client_id = OLD.client_id
  )
  WHERE id = OLD.client_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_deposit_update` AFTER UPDATE ON `client_deposits` FOR EACH ROW BEGIN
  UPDATE clients
  SET balance = (
    SELECT COALESCE(SUM(amount), 0)
    FROM client_deposits
    WHERE client_id = NEW.client_id
  )
  WHERE id = NEW.client_id;
END
$$
DELIMITER ;

-- --- C2: club_payments -------------------------------------------------------
-- УВАГА: trg_cashflow_payment_insert/delete навмисно НЕ повертаються тут —
-- див. пояснення про club_cashflow вгорі файлу. Повернути окремо (СЕКЦІЯ D),
-- якщо після узгодження вирішите бекафілити історію каси.
DELIMITER $$
CREATE TRIGGER `trg_payment_after_delete` AFTER DELETE ON `club_payments` FOR EACH ROW BEGIN
  IF OLD.invoice_id IS NOT NULL THEN
    UPDATE client_invoices
    SET paid_amount = (
      SELECT COALESCE(SUM(amount), 0)
      FROM club_payments
      WHERE invoice_id = OLD.invoice_id
    )
    WHERE id = OLD.invoice_id;
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_payment_after_insert` AFTER INSERT ON `club_payments` FOR EACH ROW BEGIN
  IF NEW.invoice_id IS NOT NULL THEN
    UPDATE client_invoices
    SET paid_amount = (
      SELECT COALESCE(SUM(amount), 0)
      FROM club_payments
      WHERE invoice_id = NEW.invoice_id
    )
    WHERE id = NEW.invoice_id;
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_payment_after_update` AFTER UPDATE ON `club_payments` FOR EACH ROW BEGIN
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
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_payment_status_after_insert` AFTER INSERT ON `club_payments` FOR EACH ROW BEGIN
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
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_payment_status_after_update` AFTER UPDATE ON `club_payments` FOR EACH ROW BEGIN
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
        ELSEIF v_paid < v_price AND v_status = 'active' THEN
            UPDATE client_invoices
            SET status = 'cancelled'
            WHERE id = NEW.invoice_id;
        END IF;
    END IF;

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
END
$$
DELIMITER ;

-- --- C3: visits ---------------------------------------------------------------
DELIMITER $$
CREATE TRIGGER `trg_visit_after_delete` AFTER DELETE ON `visits` FOR EACH ROW BEGIN
  IF OLD.invoice_id IS NOT NULL THEN
    UPDATE client_invoices
    SET visits_used = (
      SELECT COUNT(*)
      FROM visits
      WHERE invoice_id = OLD.invoice_id
    )
    WHERE id = OLD.invoice_id;
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_visit_after_insert` AFTER INSERT ON `visits` FOR EACH ROW BEGIN
  IF NEW.invoice_id IS NOT NULL THEN
    UPDATE client_invoices
    SET visits_used = (
      SELECT COUNT(*)
      FROM visits
      WHERE invoice_id = NEW.invoice_id
    )
    WHERE id = NEW.invoice_id;
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_visit_after_update` AFTER UPDATE ON `visits` FOR EACH ROW BEGIN
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
END
$$
DELIMITER ;
-- trg_visit_trainer_earning навмисно НЕ повертається автоматично тут разом з
-- рештою — якщо на імпортованих visits.trainer_id лишиться NULL (рекомендація
-- з плану міграції: історичні нарахування тренерам 1:1 не відтворюємо), цей
-- тригер і так нічого не робить (guard `NEW.trainer_id IS NOT NULL`), тож
-- його можна повернути одразу — ось повний текст:
DELIMITER $$
CREATE TRIGGER `trg_visit_trainer_earning` AFTER INSERT ON `visits` FOR EACH ROW BEGIN
  DECLARE v_visits_total   smallint DEFAULT NULL;
  DECLARE v_earn_id        bigint   DEFAULT NULL;
  DECLARE v_amount         decimal(10,2) DEFAULT 0;
  DECLARE v_avail          decimal(10,2) DEFAULT 0;
  DECLARE v_paid           decimal(10,2) DEFAULT 0;
  DECLARE v_trigger        varchar(20) DEFAULT '';
  DECLARE v_trainer_row_id int DEFAULT 0;
  DECLARE v_unlock         decimal(10,2) DEFAULT 0;

  IF NEW.invoice_id IS NOT NULL AND NEW.trainer_id IS NOT NULL THEN

    SELECT ci.visits_total INTO v_visits_total
    FROM client_invoices ci WHERE ci.id = NEW.invoice_id LIMIT 1;

    SELECT id INTO v_trainer_row_id
    FROM club_trainers
    WHERE club_id = NEW.club_id AND user_id = NEW.trainer_id LIMIT 1;

    IF v_trainer_row_id > 0 THEN
      SELECT id, amount, available_amount, paid_amount, release_trigger
      INTO v_earn_id, v_amount, v_avail, v_paid, v_trigger
      FROM trainer_earnings
      WHERE invoice_id = NEW.invoice_id AND trainer_id = v_trainer_row_id LIMIT 1;

      IF v_earn_id IS NOT NULL AND v_trigger = 'on_each_visit' AND COALESCE(v_visits_total,0) > 0 THEN
        SET v_unlock = ROUND(v_amount / v_visits_total, 2);
        SET v_avail  = LEAST(v_amount, v_avail + v_unlock);
        UPDATE trainer_earnings SET
          available_amount = v_avail,
          status = CASE
            WHEN v_avail >= amount AND v_paid >= amount THEN 'paid'
            WHEN v_avail >= amount                      THEN 'available'
            ELSE 'partial'
          END,
          available_at = CASE WHEN v_avail >= amount THEN NOW() ELSE available_at END,
          updated_at   = NOW()
        WHERE id = v_earn_id;
      END IF;
    END IF;
  END IF;
END
$$
DELIMITER ;


-- ============================================================================
-- СЕКЦІЯ D: (ВІДКЛАДЕНО, потребує окремого рішення) — повернути
-- club_cashflow-тригери і за бажання бекафілити історію каси
-- ============================================================================
-- DELIMITER $$
-- CREATE TRIGGER `trg_cashflow_payment_insert` AFTER INSERT ON `club_payments` FOR EACH ROW BEGIN
--     IF NEW.payment_method = 'cash' THEN
--         INSERT INTO club_cashflow
--           (club_id, type, category, description, amount, payment_method,
--            source, source_id, shift_id, admin_id, admin_name)
--         VALUES
--           (NEW.club_id, 'income', 'Абонемент', 'Оплата абонементу',
--            NEW.amount, 'cash', 'invoice', NEW.id, NEW.shift_id,
--            NEW.admin_id, NEW.admin_name);
--     END IF;
-- END
-- $$
-- DELIMITER ;
-- (+ trg_cashflow_payment_delete, trg_cashflow_sale_insert/delete аналогічно —
--  повний текст див. у er452618_crm4fitness.sql)
