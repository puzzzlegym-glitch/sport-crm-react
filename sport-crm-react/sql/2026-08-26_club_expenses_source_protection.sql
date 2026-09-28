-- Захист системних витрат (напр. виплат тренерам) від ручного редагування/видалення
-- у Фінансах, і можливість сторнувати їх (видалити пов'язаний запис), якщо
-- породжуюча дія (виплата) сама скасовується через виправлення помилки менеджера.

ALTER TABLE club_expenses
  ADD COLUMN source VARCHAR(30) NOT NULL DEFAULT 'manual'
    COMMENT 'manual = ручний запис (можна редагувати/видаляти), інше = системний, захищений'
    AFTER notes,
  ADD COLUMN source_id INT NULL
    COMMENT 'id сутності-джерела (напр. trainer_earnings.id для source=trainer_payout)'
    AFTER source;

-- Індекс для швидкого пошуку/видалення пов'язаних витрат при сторно.
ALTER TABLE club_expenses
  ADD INDEX idx_source (source, source_id);
