-- ============================================================================
-- Мінімальна кількість днів заморозки для тарифу (поряд з існуючим
-- freeze_days_max). Дозволяє власнику клубу задати діапазон [min, max] днів
-- заморозки для абонементів цього тарифу.
-- Використовується: api/tariffs_api.php (create/update/get_list/get_one),
--                    api/invoices_api.php (freeze/update_freeze),
--                    src/pages/TariffsPage.jsx, src/pages/InvoicesPage.jsx
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-08-26
-- ============================================================================

ALTER TABLE tariffs
  ADD COLUMN freeze_days_min INT NOT NULL DEFAULT 0 AFTER freeze_days_max;
