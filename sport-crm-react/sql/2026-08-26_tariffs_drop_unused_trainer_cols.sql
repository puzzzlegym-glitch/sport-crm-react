-- ============================================================================
-- Видалення невикористовуваних стовпців таблиці tariffs.
-- sum_group_trainer — ніде в коді не читається і не пишеться взагалі.
-- narah_summ_type — читається лише при створенні абонемента (invoices_api.php)
--   для запису в client_invoices.trainer_narah_type, а те поле саме по собі
--   теж більше ніде не читається (мертвий запис у мертве поле).
-- Обидва вийшли з ужитку після переходу нарахувань тренера на модель
-- "заробіток = факт відвідування" (club_trainers.personal_earn_type/value),
-- див. 2026-08-26_trainer_earnings_from_visits.sql.
-- Це виконана частина раніше відкладеної 2026-08-26_trainer_earnings_cleanup_deferred.sql
-- (client_invoices.trainer_narah_* лишається відкладеним — див. той файл).
-- Код (tariffs_api.php, invoices_api.php, TariffsPage.jsx) жодних змін не
-- потребує: ці стовпці ніде явно не SELECT-яться/не пишуться, а
-- invoices_api.php читає тариф через SELECT * з фолбеком `?? null`.
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-08-26
-- ============================================================================

ALTER TABLE tariffs
  DROP COLUMN sum_group_trainer,
  DROP COLUMN narah_summ_type;
