-- ============================================================================
-- Розділ підтримки, фаза 2: позначки "є непрочитане" + сповіщення клубу
-- Використовується: api/support_api.php, src/pages/SupportPage.jsx
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-08-12
-- ============================================================================
-- Доповнює sql/2026-08-12_support_center.sql (support_tickets вже існує).
-- unread_by_club   — клуб ще не бачив останню відповідь підтримки/зміну статусу
-- unread_by_admin  — SuperAdmin ще не бачив останнє повідомлення клубу
-- ============================================================================

ALTER TABLE support_tickets
    ADD COLUMN unread_by_club  TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN unread_by_admin TINYINT(1) NOT NULL DEFAULT 1 AFTER unread_by_club;
