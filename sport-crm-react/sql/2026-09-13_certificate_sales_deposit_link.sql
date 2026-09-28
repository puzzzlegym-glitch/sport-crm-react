-- ============================================================================
-- Пов'язує certificate_sales з конкретним рядком client_deposits, який
-- redeem_certificate створив при активації — без цього неможливо надійно
-- знайти "той самий" депозит, щоб скасувати вже активований сертифікат
-- (власник видаляє бізнес-запис клієнта з картки клієнта).
-- Використовується: api/certificates_api.php (redeem_certificate записує
--                    deposit_id; нова дія cancel_redemption його читає/чистить)
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-09-13
-- ============================================================================

ALTER TABLE certificate_sales
  ADD COLUMN deposit_id BIGINT UNSIGNED NULL AFTER redeemed_at,
  ADD CONSTRAINT fk_certsale_deposit FOREIGN KEY (deposit_id) REFERENCES client_deposits(id) ON DELETE SET NULL;
