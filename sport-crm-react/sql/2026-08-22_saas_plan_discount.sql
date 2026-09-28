-- ============================================================================
-- Знижка/акція на тарифний план SaaS (SuperAdmin задає % знижки, назву акції
-- та дату дії безпосередньо на плані).
-- Використовується: api/saas_api.php (get_plans/update_plan),
--                    api/billing_api.php (get_plans/create_payment/record_manual),
--                    src/pages/SaasPage.jsx, src/pages/BillingPage.jsx
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-08-22
-- ============================================================================

ALTER TABLE saas_plans
  ADD COLUMN discount_percent     INT          NOT NULL DEFAULT 0     AFTER price_monthly,
  ADD COLUMN discount_label       VARCHAR(100) NULL     DEFAULT NULL  AFTER discount_percent,
  ADD COLUMN discount_valid_until DATE         NULL     DEFAULT NULL  AFTER discount_label;
