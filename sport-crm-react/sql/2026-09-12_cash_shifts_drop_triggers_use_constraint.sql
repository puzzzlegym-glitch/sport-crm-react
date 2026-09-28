-- Замінюємо trg_cash_shifts_before_insert/_update на нативне обмеження БД —
-- не тригер, а звичайний UNIQUE-індекс на згенерованій колонці. Той самий
-- результат («одна відкрита зміна на клуб»), але атомарно на рівні InnoDB
-- (без вікна гонки між SELECT-перевіркою і INSERT у застосунку) і без
-- жодного явного коду тригера в БД.
--
-- open_marker = club_id, коли status='open', інакше NULL. MySQL не вважає
-- кілька NULL конфліктом у UNIQUE-індексі — тож "closed"-зміни між собою
-- ніяк не заважають, а от дві "open"-зміни одного клубу — заборонені на
-- рівні сховища.
ALTER TABLE cash_shifts
  ADD COLUMN open_marker INT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN status = 'open' THEN club_id ELSE NULL END) STORED
    COMMENT 'Технічна колонка для UNIQUE — не використовувати напряму в запитах',
  ADD UNIQUE KEY uniq_cash_shifts_one_open_per_club (open_marker);

DROP TRIGGER IF EXISTS trg_cash_shifts_before_insert;
DROP TRIGGER IF EXISTS trg_cash_shifts_before_update;
