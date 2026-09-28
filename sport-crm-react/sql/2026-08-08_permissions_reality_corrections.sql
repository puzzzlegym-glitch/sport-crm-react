-- ============================================================================
-- Виправлення "золотого стандарту" відповідно до РЕАЛЬНОЇ логіки коду,
-- виявлені під час підключення Auth::can() до всіх *_api.php ендпоінтів
-- (2026-08-08). Без цього деякі дії або несправедливо заблокують персонал,
-- який раніше мав доступ, або чекбокс у матриці буде показувати доступ,
-- якого насправді немає (бо ендпоінт має власний, окремий, жорсткіший гейт).
-- ============================================================================

-- 1) visits.checkin — трен реально МОЖЕ сканувати/відмічати відвідування
--    (scan/check_in у visits_api.php не мали жодного обмеження рівня,
--    окрім базового 30). Було: лише manager+owner. Стало: + trainer.
INSERT IGNORE INTO sys_role_permissions (role_id, permission_slug)
SELECT r.id, 'visits.checkin' FROM sys_roles r WHERE r.slug = 'trainer';

-- 2) arrivals.delete — реально manager+ (delete у arrivals_api.php: "менеджер+"
--    за докблоком і коду), а не лише owner. Було: лише owner. Стало: + manager.
INSERT IGNORE INTO sys_role_permissions (role_id, permission_slug)
SELECT r.id, 'arrivals.delete' FROM sys_roles r WHERE r.slug = 'manager';

-- 3) finance.manage — add_expense/update_expense/add_deposit у finance_api.php
--    вимагають лише canWrite (manager+owner), не isOwner. Було: лише owner.
--    Стало: + manager.
INSERT IGNORE INTO sys_role_permissions (role_id, permission_slug)
SELECT r.id, 'finance.manage' FROM sys_roles r WHERE r.slug = 'manager';

-- 4) finance.delete — НОВИЙ permission (delete_expense у finance_api.php
--    реально isOwner-only, окремо від finance.manage — цей slug був
--    помилково видалений як "дублікат" під час чистки каталогу раніше
--    в цій сесії; насправді закривав реальну потребу).
INSERT INTO sys_permissions (slug, label, category, sort_order)
VALUES ('finance.delete', 'Видалення витрати', 'finance', 30)
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category), sort_order = VALUES(sort_order);

INSERT IGNORE INTO sys_role_permissions (role_id, permission_slug)
SELECT r.id, 'finance.delete' FROM sys_roles r WHERE r.slug = 'owner';

-- 5) cash.view — cash_api.php (get_summary/get_list/get_shift/get_shifts)
--    відкриті для будь-кого з рівнем 30+, тренер теж бачить касу.
--    Було: лише manager+owner. Стало: + trainer.
INSERT IGNORE INTO sys_role_permissions (role_id, permission_slug)
SELECT r.id, 'cash.view' FROM sys_roles r WHERE r.slug = 'trainer';

-- 6) payments.view — payments_api.php вимагає рівень 50+ ще ДО будь-якої дії
--    (навіть get_list), тренер фізично не може достукатись до ендпоінту.
--    Чекбокс "trainer: payments.view" був нефункціональним оманливим —
--    прибираємо його.
DELETE srp FROM sys_role_permissions srp
JOIN sys_roles r ON r.id = srp.role_id
WHERE r.slug = 'trainer' AND srp.permission_slug = 'payments.view';

-- 7) tariffs.view — те саме: tariffs_api.php вимагає рівень 50+ на вході.
DELETE srp FROM sys_role_permissions srp
JOIN sys_roles r ON r.id = srp.role_id
WHERE r.slug = 'trainer' AND srp.permission_slug = 'tariffs.view';

-- 8) products.view — те саме: products_api.php вимагає рівень 50+ на вході.
DELETE srp FROM sys_role_permissions srp
JOIN sys_roles r ON r.id = srp.role_id
WHERE r.slug = 'trainer' AND srp.permission_slug = 'products.view';
