-- Локальний тестовий прогін: сертифікати з drivecrm -> crm4fitness
-- Джерело: er452618_drivecrm.tblSertificateInvoises (34, 31 у клубах 1/2)
-- Ціль: certificates (сам сертифікат) + certificate_sales (подія продажу/
-- використання) — в drivecrm це один рядок на подію, тому 1:1.
--
-- Правила:
--  - BarCode -> code. У джерелі є повторні BarCode в межах клубу (штрих-коди
--    фізично перевикористовувались) -> додаю "-<старий ID>" для унікальності
--    (UNIQUE(club_id,code) в цільовій схемі).
--  - status(certificates)='sold' завжди (усі перенесені — вже продані).
--  - Status: 'Проданий'->certificate_sales.status='sold',
--    'Використаний'->'redeemed' (+ redeemed_client_id через ClientID).
--  - redeemed_at НЕ заповнюю: DeliveryDate в джерелі — це дата закінчення
--    дії (+365 днів від PaymentDate завжди), а не дата фактичного
--    використання, якої в джерелі просто нема.
--  - buyer_name НЕ заповнюю: UserName в джерелі — це адмін, а не покупець;
--    Client/ClientID — це той, хто ПОГАСИВ сертифікат (може бути інша
--    людина, ніж покупець-даритель) — немає надійного поля "покупець".

USE er452618_crm4fitness;

CREATE TABLE IF NOT EXISTS migration_certificate_id_map (
  old_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  cert_id INT UNSIGNED NOT NULL,
  sale_id INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @start_cert_id := (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE table_schema='er452618_crm4fitness' AND table_name='certificates');
SET @start_sale_id  := (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE table_schema='er452618_crm4fitness' AND table_name='certificate_sales');

DROP TEMPORARY TABLE IF EXISTS tmp_certs;
CREATE TEMPORARY TABLE tmp_certs AS
SELECT s.*, ROW_NUMBER() OVER (ORDER BY s.ID) AS rn
FROM er452618_drivecrm.tblSertificateInvoises s
WHERE s.ClubID IN (1,2);

INSERT INTO migration_certificate_id_map (old_id, cert_id, sale_id)
SELECT ID, @start_cert_id - 1 + rn, @start_sale_id - 1 + rn FROM tmp_certs;

INSERT INTO certificates (id, club_id, code, status, created_admin_name, created_at)
SELECT
  m.cert_id,
  s.ClubID,
  CONCAT(s.BarCode, '-', s.ID),
  'sold',
  NULLIF(TRIM(s.UserName), ''),
  s.PaymentDate
FROM tmp_certs s
JOIN migration_certificate_id_map m ON m.old_id = s.ID
WHERE NOT EXISTS (SELECT 1 FROM certificates c WHERE c.id = m.cert_id);

INSERT INTO certificate_sales
  (id, certificate_id, club_id, amount, payment_method, status,
   sold_admin_name, sold_at, redeemed_client_id, redeemed_admin_name)
SELECT
  m.sale_id,
  m.cert_id,
  s.ClubID,
  s.Summ,
  CASE s.FormaOplatu
    WHEN 'готівка' THEN 'cash'
    WHEN 'картка' THEN 'card'
    WHEN 'термінал (безготівка)' THEN 'terminal'
    ELSE 'other'
  END,
  CASE s.Status WHEN 'Використаний' THEN 'redeemed' ELSE 'sold' END,
  NULLIF(TRIM(s.UserName), ''),
  s.PaymentDate,
  CASE WHEN s.Status = 'Використаний' THEN cm.new_id ELSE NULL END,
  CASE WHEN s.Status = 'Використаний' THEN NULLIF(TRIM(s.UserName), '') ELSE NULL END
FROM tmp_certs s
JOIN migration_certificate_id_map m ON m.old_id = s.ID
LEFT JOIN migration_client_id_map cm ON cm.old_id = s.ClientID
WHERE NOT EXISTS (SELECT 1 FROM certificate_sales cs WHERE cs.id = m.sale_id);

-- ── Перевірка ────────────────────────────────────────────────────────────
SELECT COUNT(*) AS migrated_certs FROM migration_certificate_id_map;
SELECT status, COUNT(*) FROM certificate_sales GROUP BY status;
SELECT c.id, c.club_id, c.code, cs.amount, cs.status, cs.redeemed_client_id
FROM certificates c JOIN certificate_sales cs ON cs.certificate_id = c.id
ORDER BY c.id DESC LIMIT 10;
