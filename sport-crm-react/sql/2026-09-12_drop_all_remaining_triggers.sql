-- Фінальний крок переносу тригерів у бекенд: видаляє ВСІ 29 тригерів, що
-- лишались в er452618_crm4fitness. Уся їхня логіка тепер живе в
-- app/core/Recalc.php (клас Recalc — єдине джерело істини):
--
--   clients.phone_normalized      → Recalc::normalizePhone()
--   clients.balance               → Recalc::clientBalance()
--   client_invoices.paid_amount   → Recalc::invoicePaidAmount()
--   client_invoices.status        → Recalc::invoiceStatus()
--   client_invoices.visits_used   → Recalc::invoiceVisitsUsed()
--   trainer_earnings (unlock)     → Recalc::unlockInvoiceTrainerEarnings()
--   club_cashflow (дзеркало)      → Recalc::cashflowSyncExpense/-Payment/-Sale()
--   saas_invoices/subscriptions   → Recalc::saasInvoiceStatus()
--   products.stock_qty            → Recalc::adjustStock()
--   cash_shifts (одна відкрита)   → UNIQUE KEY uniq_cash_shifts_one_open_per_club
--                                    (застосовано окремо, див.
--                                    2026-09-12_cash_shifts_drop_triggers_use_constraint.sql)
--   trg_visit_trainer_earning      → видалено як мертвий код (нічого не робив)
--   trg_sub_after_delete           → видалено як мертвий код (жоден PHP-шлях
--                                    не видаляє рядки saas_subscriptions)
--
-- ПОПЕРЕДНЬО ЗАСТОСУВАТИ (якщо ще не застосовані):
--   2026-09-12_club_cashflow_unique_source.sql
--   2026-09-12_cash_shifts_drop_triggers_use_constraint.sql
--   2026-09-12_drop_dead_visit_trainer_earning_trigger.sql
--   2026-09-12_drop_invoice_trainer_earning_trigger.sql
-- (Ці 4 вже включені нижче через DROP TRIGGER IF EXISTS — можна застосувати
-- напряму цей файл, якщо club_cashflow.uniq_cashflow_source вже існує.)

DROP TRIGGER IF EXISTS trg_deposit_after_insert;
DROP TRIGGER IF EXISTS trg_deposit_delete;
DROP TRIGGER IF EXISTS trg_deposit_update;
DROP TRIGGER IF EXISTS trg_clients_phone_insert;
DROP TRIGGER IF EXISTS trg_clients_phone_update;
DROP TRIGGER IF EXISTS trg_cashflow_expense_delete;
DROP TRIGGER IF EXISTS trg_cashflow_expense_insert;
DROP TRIGGER IF EXISTS trg_cashflow_expense_update;
DROP TRIGGER IF EXISTS trg_cashflow_payment_delete;
DROP TRIGGER IF EXISTS trg_cashflow_payment_insert;
DROP TRIGGER IF EXISTS trg_payment_after_delete;
DROP TRIGGER IF EXISTS trg_payment_after_insert;
DROP TRIGGER IF EXISTS trg_payment_after_update;
DROP TRIGGER IF EXISTS trg_payment_status_after_insert;
DROP TRIGGER IF EXISTS trg_payment_status_after_update;
DROP TRIGGER IF EXISTS trg_arrival_delete;
DROP TRIGGER IF EXISTS trg_arrival_insert;
DROP TRIGGER IF EXISTS trg_arrival_update;
DROP TRIGGER IF EXISTS trg_cashflow_sale_delete;
DROP TRIGGER IF EXISTS trg_cashflow_sale_insert;
DROP TRIGGER IF EXISTS trg_sale_delete;
DROP TRIGGER IF EXISTS trg_sale_insert;
DROP TRIGGER IF EXISTS trg_sale_update;
DROP TRIGGER IF EXISTS trg_saas_payment_after_delete;
DROP TRIGGER IF EXISTS trg_saas_payment_after_insert;
DROP TRIGGER IF EXISTS trg_sub_after_delete;
DROP TRIGGER IF EXISTS trg_visit_after_delete;
DROP TRIGGER IF EXISTS trg_visit_after_insert;
DROP TRIGGER IF EXISTS trg_visit_after_update;
DROP TRIGGER IF EXISTS trg_visit_trainer_earning;
DROP TRIGGER IF EXISTS trg_invoice_trainer_earning;
DROP TRIGGER IF EXISTS trg_cash_shifts_before_insert;
DROP TRIGGER IF EXISTS trg_cash_shifts_before_update;
