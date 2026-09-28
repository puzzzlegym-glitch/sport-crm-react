-- ============================================================================
-- Редагування проданих товарів/оплат/депозитів (з часовим вікном по ролі) +
-- повернення товару з двоетапним підтвердженням і реальним обліком рефанду.
--
-- Використовується: api/sales_api.php (update_item/return_order/confirm_return/
--                    cancel_return/confirm_cancel_return), api/payments_api.php
--                    (update/delete), api/finance_api.php (update_deposit/
--                    delete_deposit), src/api/sales.js, src/pages/SalesPage.jsx
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-09-12
--
-- Правила (погоджено з власником):
--   • Власник — без часових обмежень на редагування оплат/товарів/депозитів;
--     адміністратор (manager) — лише в день продажу/внесення.
--   • Повернення товару: адміністратор — лише в день продажу; власник — до 14 днів.
--   • Скасування повернення — окремі permission slug'и, за замовчуванням лише owner
--     (звичайний slug, власник може делегувати через матрицю прав клубу).
--   • "Зробити повернення" і "скасувати повернення" — двоетапний процес:
--     ініціатор без права підтвердження лишає чек "на підтвердженні"
--     (status='pending_return'/'pending_cancel'); якщо ініціатор МАЄ й право
--     підтвердження — обидва кроки виконуються одразу.
-- ============================================================================

-- 1) sale_orders: нові статуси + колонки для двоетапного workflow і рефанду ---
-- Перевірити перед виконанням (чи вже застосовано):
--   SELECT COLUMN_NAME FROM information_schema.COLUMNS
--   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sale_orders' AND COLUMN_NAME='refund_method';
ALTER TABLE sale_orders
  MODIFY COLUMN status ENUM('completed','returned','pending_return','pending_cancel')
    NOT NULL DEFAULT 'completed';

ALTER TABLE sale_orders
  ADD COLUMN return_requested_at      DATETIME NULL COMMENT 'Момент ІНІЦІАЦІЇ повернення (returned_at/returned_by_* — момент ПІДТВЕРДЖЕННЯ)' AFTER return_reason,
  ADD COLUMN return_requested_by_id   INT UNSIGNED NULL AFTER return_requested_at,
  ADD COLUMN return_requested_by_name VARCHAR(191) NULL AFTER return_requested_by_id,
  ADD COLUMN cancel_reason             VARCHAR(500) NULL AFTER returned_by_name,
  ADD COLUMN cancel_requested_at       DATETIME NULL AFTER cancel_reason,
  ADD COLUMN cancel_requested_by_id    INT UNSIGNED NULL AFTER cancel_requested_at,
  ADD COLUMN cancel_requested_by_name  VARCHAR(191) NULL AFTER cancel_requested_by_id,
  ADD COLUMN cancel_confirmed_at       DATETIME NULL AFTER cancel_requested_by_name,
  ADD COLUMN cancel_confirmed_by_id    INT UNSIGNED NULL AFTER cancel_confirmed_at,
  ADD COLUMN cancel_confirmed_by_name  VARCHAR(191) NULL AFTER cancel_confirmed_by_id,
  ADD COLUMN refund_method   ENUM('cash','deposit','other') NULL COMMENT 'Спосіб фактичного повернення коштів клієнту' AFTER cancel_confirmed_by_name,
  ADD COLUMN refund_location VARCHAR(20) NULL COMMENT 'register/safe — лише коли refund_method=cash' AFTER refund_method;

-- 2) Нові permission slugs (категорія sales) --------------------------------
INSERT INTO sys_permissions (slug, label, category, sort_order) VALUES
  ('sales.return',               'Оформити повернення товару',        'sales', 40),
  ('sales.return_confirm',       'Підтвердити повернення товару',      'sales', 41),
  ('sales.return_cancel',        'Скасувати повернення товару',        'sales', 42),
  ('sales.return_cancel_confirm','Підтвердити скасування повернення',  'sales', 43),
  ('sales.edit',                 'Редагувати продану позицію',         'sales', 44)
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category), sort_order = VALUES(sort_order);

-- sales.return + sales.edit: manager + owner
INSERT IGNORE INTO sys_role_permissions (role_id, permission_slug)
SELECT r.id, p.slug FROM sys_roles r
JOIN sys_permissions p ON p.slug IN ('sales.return','sales.edit')
WHERE r.slug IN ('manager','owner');

-- sales.return_confirm / sales.return_cancel / sales.return_cancel_confirm: лише owner
INSERT IGNORE INTO sys_role_permissions (role_id, permission_slug)
SELECT r.id, p.slug FROM sys_roles r
JOIN sys_permissions p ON p.slug IN ('sales.return_confirm','sales.return_cancel','sales.return_cancel_confirm')
WHERE r.slug = 'owner';

-- 3) Розширити payments.edit/payments.delete на manager (наразі owner-only) --
INSERT IGNORE INTO sys_role_permissions (role_id, permission_slug)
SELECT r.id, p.slug FROM sys_roles r
JOIN sys_permissions p ON p.slug IN ('payments.edit','payments.delete')
WHERE r.slug = 'manager';
