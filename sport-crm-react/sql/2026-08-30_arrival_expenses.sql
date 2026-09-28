-- Оплачений прихід товару має враховуватися як витрата клубу (Фінанси/Витрати).
-- 1) Додаємо спосіб оплати приходу (раніше цього поля не було) — потрібен,
--    щоб коректно рахувати "Касу" (готівка) так само, як для club_expenses/product_sales.
-- 2) Автогенеровані записи витрат для оплачених приходів створюються/оновлюються/
--    видаляються в API (arrivals_api.php, products_api.php) за тим самим принципом,
--    що й виплати тренерам — див. 2026-08-26_club_expenses_source_protection.sql
--    (club_expenses.source = 'product_arrival', source_id = product_arrivals.id).

ALTER TABLE product_arrivals
  ADD COLUMN payment_method VARCHAR(20) NOT NULL DEFAULT 'cash'
    COMMENT 'cash/card/terminal/transfer — спосіб оплати приходу (для paid)'
    AFTER status;
