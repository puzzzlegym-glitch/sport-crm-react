-- Локальний тестовий прогін етапу 2 (тарифи) з drivecrm -> crm4fitness
-- Джерело: er452618_drivecrm.tblProducts (588 рядків, суто абонементи —
-- фізичних товарів у drivecrm немає окремою таблицею).
-- Ціль: er452618_crm4fitness.tariffs
--
-- Рішення власника 2026-09-13:
--  - Club='Загальний' (439) -> дублюється на club_id 1 і 2 (два окремих
--    рядки tariffs з різними id, той самий старий ProductID).
--  - Club='Драйв 1.0' -> club_id=1, Club='Драйв 2.0' -> club_id=2.
--  - Club='Драйв 3.0' -> пропускається (клуб не переноситься).
--  - Club='Онлайн' (3 рядки) -> пропускається (немає такого клубу).
--
-- migration_tariff_id_map: ключ (old_id, club_id), бо один старий ProductID
-- може дати ДВА нових tariffs.id (дубль на 2 клуби для "Загальний").

USE er452618_crm4fitness;

CREATE TABLE IF NOT EXISTS migration_tariff_id_map (
  old_id   INT UNSIGNED NOT NULL,
  club_id  INT UNSIGNED NOT NULL,
  new_id   INT UNSIGNED NOT NULL,
  migrated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (old_id, club_id),
  UNIQUE KEY uq_new_id (new_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Розгортаємо джерело в "плоский" список (old_id, target_club_id) —
-- по одному рядку на кожну цільову пару.
DROP TEMPORARY TABLE IF EXISTS tmp_tariff_targets;
CREATE TEMPORARY TABLE tmp_tariff_targets (
  old_id INT UNSIGNED NOT NULL,
  club_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (old_id, club_id)
);

INSERT INTO tmp_tariff_targets (old_id, club_id)
SELECT ID, 1 FROM er452618_drivecrm.tblProducts WHERE Club = 'Драйв 1.0'
UNION ALL
SELECT ID, 2 FROM er452618_drivecrm.tblProducts WHERE Club = 'Драйв 2.0'
UNION ALL
SELECT ID, 1 FROM er452618_drivecrm.tblProducts WHERE Club = 'Загальний'
UNION ALL
SELECT ID, 2 FROM er452618_drivecrm.tblProducts WHERE Club = 'Загальний';

SET @start_id := (
  SELECT AUTO_INCREMENT FROM information_schema.TABLES
  WHERE table_schema = 'er452618_crm4fitness' AND table_name = 'tariffs'
);

INSERT INTO migration_tariff_id_map (old_id, club_id, new_id)
SELECT old_id, club_id, @start_id - 1 + ROW_NUMBER() OVER (ORDER BY old_id, club_id)
FROM tmp_tariff_targets x
WHERE NOT EXISTS (
  SELECT 1 FROM migration_tariff_id_map m WHERE m.old_id = x.old_id AND m.club_id = x.club_id
);

INSERT INTO tariffs
  (id, club_id, name, category, duration_days, visits_limit, price,
   description, is_active, prolong_sum, freeze_days_max, has_trainer, created_at)
SELECT
  m.new_id,
  m.club_id,
  p.ProductName,
  NULLIF(TRIM(p.ProductType), ''),
  GREATEST(p.Period, 1),
  CASE
    WHEN p.ProductType = 'Безлімітний' AND p.QuantityVidviduvan = p.Period THEN NULL
    ELSE NULLIF(p.QuantityVidviduvan, 0)
  END,
  p.Price,
  NULLIF(TRIM(p.Prumitka), ''),
  NOT p.Dead,
  p.ProlongSum,
  p.Zamorozka,
  (p.PriceTrener > 0 OR p.SumGroupTrener > 0 OR p.SumSerRazova > 0),
  NOW()
FROM er452618_drivecrm.tblProducts p
JOIN migration_tariff_id_map m ON m.old_id = p.ID
WHERE NOT EXISTS (SELECT 1 FROM tariffs t WHERE t.id = m.new_id);

-- ── Перевірка ────────────────────────────────────────────────────────────
SELECT COUNT(*) AS imported_tariff_rows FROM migration_tariff_id_map;

SELECT club_id, COUNT(*), SUM(is_active) AS active_cnt FROM tariffs WHERE id > 6 GROUP BY club_id;

SELECT t.id, t.club_id, t.name, t.category, t.duration_days, t.visits_limit, t.price, t.is_active, t.has_trainer
FROM tariffs t
JOIN migration_tariff_id_map m ON m.new_id = t.id
ORDER BY t.id DESC LIMIT 15;
