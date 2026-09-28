-- ============================================================================
-- Подарункові сертифікати з кодами, що переоформлюються циклічно:
-- продаж → гроші "лежать на сертифікаті" (клієнт ще не прив'язаний) →
-- активація (прив'язка до конкретного клієнта, сума падає йому на депозит
-- через вже наявний механізм client_deposits) → код повертається в пул
-- available і знову доступний до продажу — НЕ одноразовий ваучер.
-- Використовується: api/finance_api.php (нові дії get_certificates/
--                    sell_certificate/redeem_certificate/cancel_certificate_sale),
--                    src/api/finance.js, src/pages/FinancePage.jsx (нова
--                    вкладка "Сертифікати")
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-08-27
-- ============================================================================
-- ВАЖЛИВО для бекенду (ще не реалізовано, тільки схема + фронт):
--  • sell_certificate — якщо код уже є в certificates: вимагає status='available'
--    (інакше "Сертифікат вже продано і очікує активації"); якщо коду ще нема —
--    upsert (створити новий рядок certificates одразу зі status='sold', без
--    окремого кроку реєстрації картки). Потім INSERT certificate_sales
--    (status='sold'), UPDATE certificates SET status='sold'.
--  • redeem_certificate — знайти certificates WHERE code+club_id+status='sold'
--    і його відкритий (status='sold') рядок у certificate_sales. Однією
--    транзакцією: INSERT client_deposits (operation='certificate',
--    payment_method = той самий, що був при продажу, amount = сума з
--    certificate_sales) → тригер сам підніме clients.balance (як і в
--    add_deposit); UPDATE certificate_sales SET status='redeemed',
--    redeemed_client_id/admin/at; UPDATE certificates SET status='available'
--    (рециклюємо код — це не одноразовий ваучер).
--  • cancel_certificate_sale (власник, тільки поки status='sold') —
--    UPDATE certificate_sales SET status='cancelled', cancel_reason; UPDATE
--    certificates SET status='available'. Готівку покупцю повертають поза
--    системою (як і скасування в InvoicesPage/PaymentsPage).
--  • Сума НЕ фіксована на коді — вводиться заново при кожному продажу
--    (certificate_sales.amount), тому той самий код можна наступного разу
--    продати на іншу суму.
-- ============================================================================

-- 1) Сам код (довготривала "картка", що переоформлюється) -------------------
CREATE TABLE IF NOT EXISTS certificates (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    club_id             INT UNSIGNED NOT NULL,
    code                VARCHAR(30)  NOT NULL,
    status              ENUM('available','sold') NOT NULL DEFAULT 'available',
    created_admin_id    INT UNSIGNED NULL,
    created_admin_name  VARCHAR(191) NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cert_club_code (club_id, code),
    KEY idx_cert_club_status (club_id, status),
    CONSTRAINT fk_cert_club FOREIGN KEY (club_id) REFERENCES sys_clubs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Append-only лог кожного циклу продаж → активація/скасування ------------
CREATE TABLE IF NOT EXISTS certificate_sales (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    certificate_id       INT NOT NULL,
    club_id              INT UNSIGNED NOT NULL,
    amount               DECIMAL(10,2) NOT NULL,
    payment_method       ENUM('cash','card','terminal','transfer','other') NOT NULL DEFAULT 'cash',
    buyer_name           VARCHAR(191) NULL,
    notes                VARCHAR(500) NULL,
    status               ENUM('sold','redeemed','cancelled') NOT NULL DEFAULT 'sold',
    sold_admin_id        INT UNSIGNED NULL,
    sold_admin_name      VARCHAR(191) NULL,
    sold_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    redeemed_client_id   INT UNSIGNED NULL,
    redeemed_admin_id    INT UNSIGNED NULL,
    redeemed_admin_name  VARCHAR(191) NULL,
    redeemed_at          DATETIME NULL,
    cancel_reason        VARCHAR(500) NULL,
    cancelled_at         DATETIME NULL,
    KEY idx_certsale_cert (certificate_id),
    KEY idx_certsale_club_status (club_id, status),
    KEY idx_certsale_club_sold (club_id, sold_at),
    CONSTRAINT fk_certsale_cert   FOREIGN KEY (certificate_id) REFERENCES certificates(id) ON DELETE CASCADE,
    CONSTRAINT fk_certsale_club   FOREIGN KEY (club_id) REFERENCES sys_clubs(id) ON DELETE CASCADE,
    CONSTRAINT fk_certsale_client FOREIGN KEY (redeemed_client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Нове значення операції для client_deposits (щоб позначити поповнення
--    саме через активацію сертифіката — окремо від top_up/pay_invoice/
--    pay_product/refund/correction, які вже є). Додаткове, не звужує наявні
--    значення — сумісно з усім, що вже пише в цю колонку (add_deposit тощо).
ALTER TABLE client_deposits
  MODIFY COLUMN operation ENUM('top_up','pay_invoice','pay_product','refund','correction','certificate') NOT NULL;
