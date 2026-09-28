-- ============================================================================
-- Виправлення імен/назв, збережених з HTML-кодуванням лапок:
--   "Мар&#039;яна" → "Мар'яна",  "&quot;Олімп&quot;" → "\"Олімп\""
-- Раніше API зберігало текст через htmlspecialchars(ENT_QUOTES); тепер лапки
-- зберігаються як є (ENT_NOQUOTES), а старі записи виправляє цей скрипт.
-- Змінюються ЛИШЕ рядки, що містять саме "&#039;" або "&quot;".
-- Застосувати через phpMyAdmin → SQL ПІСЛЯ заливки нових PHP-файлів.
-- Дата: 2026-09-28
-- ============================================================================

-- Перегляд (нічого не змінює): скільки записів буде виправлено в основних таблицях
SELECT 'clients' AS t, COUNT(*) FROM clients WHERE full_name LIKE '%&#039;%' OR full_name LIKE '%&quot;%'
UNION ALL SELECT 'sys_users', COUNT(*) FROM sys_users WHERE full_name LIKE '%&#039;%' OR full_name LIKE '%&quot;%'
UNION ALL SELECT 'sys_clubs', COUNT(*) FROM sys_clubs WHERE name LIKE '%&#039;%' OR name LIKE '%&quot;%';

-- Виправлення
UPDATE `cash_shifts` SET `closed_name` = REPLACE(REPLACE(`closed_name`, '&#039;', ''''), '&quot;', '"') WHERE `closed_name` LIKE '%&#039;%' OR `closed_name` LIKE '%&quot;%';
UPDATE `cash_shifts` SET `notes` = REPLACE(REPLACE(`notes`, '&#039;', ''''), '&quot;', '"') WHERE `notes` LIKE '%&#039;%' OR `notes` LIKE '%&quot;%';
UPDATE `cash_shifts` SET `opened_name` = REPLACE(REPLACE(`opened_name`, '&#039;', ''''), '&quot;', '"') WHERE `opened_name` LIKE '%&#039;%' OR `opened_name` LIKE '%&quot;%';
UPDATE `certificates` SET `created_admin_name` = REPLACE(REPLACE(`created_admin_name`, '&#039;', ''''), '&quot;', '"') WHERE `created_admin_name` LIKE '%&#039;%' OR `created_admin_name` LIKE '%&quot;%';
UPDATE `certificate_sales` SET `buyer_name` = REPLACE(REPLACE(`buyer_name`, '&#039;', ''''), '&quot;', '"') WHERE `buyer_name` LIKE '%&#039;%' OR `buyer_name` LIKE '%&quot;%';
UPDATE `certificate_sales` SET `notes` = REPLACE(REPLACE(`notes`, '&#039;', ''''), '&quot;', '"') WHERE `notes` LIKE '%&#039;%' OR `notes` LIKE '%&quot;%';
UPDATE `certificate_sales` SET `redeemed_admin_name` = REPLACE(REPLACE(`redeemed_admin_name`, '&#039;', ''''), '&quot;', '"') WHERE `redeemed_admin_name` LIKE '%&#039;%' OR `redeemed_admin_name` LIKE '%&quot;%';
UPDATE `certificate_sales` SET `sold_admin_name` = REPLACE(REPLACE(`sold_admin_name`, '&#039;', ''''), '&quot;', '"') WHERE `sold_admin_name` LIKE '%&#039;%' OR `sold_admin_name` LIKE '%&quot;%';
UPDATE `clients` SET `full_name` = REPLACE(REPLACE(`full_name`, '&#039;', ''''), '&quot;', '"') WHERE `full_name` LIKE '%&#039;%' OR `full_name` LIKE '%&quot;%';
UPDATE `clients` SET `notes` = REPLACE(REPLACE(`notes`, '&#039;', ''''), '&quot;', '"') WHERE `notes` LIKE '%&#039;%' OR `notes` LIKE '%&quot;%';
UPDATE `client_deposits` SET `admin_name` = REPLACE(REPLACE(`admin_name`, '&#039;', ''''), '&quot;', '"') WHERE `admin_name` LIKE '%&#039;%' OR `admin_name` LIKE '%&quot;%';
UPDATE `client_deposits` SET `notes` = REPLACE(REPLACE(`notes`, '&#039;', ''''), '&quot;', '"') WHERE `notes` LIKE '%&#039;%' OR `notes` LIKE '%&quot;%';
UPDATE `client_invoices` SET `admin_name` = REPLACE(REPLACE(`admin_name`, '&#039;', ''''), '&quot;', '"') WHERE `admin_name` LIKE '%&#039;%' OR `admin_name` LIKE '%&quot;%';
UPDATE `client_invoices` SET `notes` = REPLACE(REPLACE(`notes`, '&#039;', ''''), '&quot;', '"') WHERE `notes` LIKE '%&#039;%' OR `notes` LIKE '%&quot;%';
UPDATE `client_invoices` SET `tariff_name` = REPLACE(REPLACE(`tariff_name`, '&#039;', ''''), '&quot;', '"') WHERE `tariff_name` LIKE '%&#039;%' OR `tariff_name` LIKE '%&quot;%';
UPDATE `client_invoices` SET `trainer_name` = REPLACE(REPLACE(`trainer_name`, '&#039;', ''''), '&quot;', '"') WHERE `trainer_name` LIKE '%&#039;%' OR `trainer_name` LIKE '%&quot;%';
UPDATE `club_cashflow` SET `admin_name` = REPLACE(REPLACE(`admin_name`, '&#039;', ''''), '&quot;', '"') WHERE `admin_name` LIKE '%&#039;%' OR `admin_name` LIKE '%&quot;%';
UPDATE `club_cashflow` SET `category` = REPLACE(REPLACE(`category`, '&#039;', ''''), '&quot;', '"') WHERE `category` LIKE '%&#039;%' OR `category` LIKE '%&quot;%';
UPDATE `club_cashflow` SET `description` = REPLACE(REPLACE(`description`, '&#039;', ''''), '&quot;', '"') WHERE `description` LIKE '%&#039;%' OR `description` LIKE '%&quot;%';
UPDATE `club_cashflow` SET `notes` = REPLACE(REPLACE(`notes`, '&#039;', ''''), '&quot;', '"') WHERE `notes` LIKE '%&#039;%' OR `notes` LIKE '%&quot;%';
UPDATE `club_equipment` SET `category` = REPLACE(REPLACE(`category`, '&#039;', ''''), '&quot;', '"') WHERE `category` LIKE '%&#039;%' OR `category` LIKE '%&quot;%';
UPDATE `club_equipment` SET `name` = REPLACE(REPLACE(`name`, '&#039;', ''''), '&quot;', '"') WHERE `name` LIKE '%&#039;%' OR `name` LIKE '%&quot;%';
UPDATE `club_equipment` SET `notes` = REPLACE(REPLACE(`notes`, '&#039;', ''''), '&quot;', '"') WHERE `notes` LIKE '%&#039;%' OR `notes` LIKE '%&quot;%';
UPDATE `club_expenses` SET `admin_name` = REPLACE(REPLACE(`admin_name`, '&#039;', ''''), '&quot;', '"') WHERE `admin_name` LIKE '%&#039;%' OR `admin_name` LIKE '%&quot;%';
UPDATE `club_expenses` SET `category` = REPLACE(REPLACE(`category`, '&#039;', ''''), '&quot;', '"') WHERE `category` LIKE '%&#039;%' OR `category` LIKE '%&quot;%';
UPDATE `club_expenses` SET `description` = REPLACE(REPLACE(`description`, '&#039;', ''''), '&quot;', '"') WHERE `description` LIKE '%&#039;%' OR `description` LIKE '%&quot;%';
UPDATE `club_expenses` SET `notes` = REPLACE(REPLACE(`notes`, '&#039;', ''''), '&quot;', '"') WHERE `notes` LIKE '%&#039;%' OR `notes` LIKE '%&quot;%';
UPDATE `club_payments` SET `admin_name` = REPLACE(REPLACE(`admin_name`, '&#039;', ''''), '&quot;', '"') WHERE `admin_name` LIKE '%&#039;%' OR `admin_name` LIKE '%&quot;%';
UPDATE `club_payments` SET `notes` = REPLACE(REPLACE(`notes`, '&#039;', ''''), '&quot;', '"') WHERE `notes` LIKE '%&#039;%' OR `notes` LIKE '%&quot;%';
UPDATE `club_payments` SET `trainer_name` = REPLACE(REPLACE(`trainer_name`, '&#039;', ''''), '&quot;', '"') WHERE `trainer_name` LIKE '%&#039;%' OR `trainer_name` LIKE '%&quot;%';
UPDATE `deleted_clubs_audit` SET `club_name` = REPLACE(REPLACE(`club_name`, '&#039;', ''''), '&quot;', '"') WHERE `club_name` LIKE '%&#039;%' OR `club_name` LIKE '%&quot;%';
UPDATE `deleted_clubs_audit` SET `deleted_by_name` = REPLACE(REPLACE(`deleted_by_name`, '&#039;', ''''), '&quot;', '"') WHERE `deleted_by_name` LIKE '%&#039;%' OR `deleted_by_name` LIKE '%&quot;%';
UPDATE `deleted_clubs_audit` SET `owner_name` = REPLACE(REPLACE(`owner_name`, '&#039;', ''''), '&quot;', '"') WHERE `owner_name` LIKE '%&#039;%' OR `owner_name` LIKE '%&quot;%';
UPDATE `group_sessions` SET `name` = REPLACE(REPLACE(`name`, '&#039;', ''''), '&quot;', '"') WHERE `name` LIKE '%&#039;%' OR `name` LIKE '%&quot;%';
UPDATE `group_sessions` SET `notes` = REPLACE(REPLACE(`notes`, '&#039;', ''''), '&quot;', '"') WHERE `notes` LIKE '%&#039;%' OR `notes` LIKE '%&quot;%';
UPDATE `products` SET `category` = REPLACE(REPLACE(`category`, '&#039;', ''''), '&quot;', '"') WHERE `category` LIKE '%&#039;%' OR `category` LIKE '%&quot;%';
UPDATE `products` SET `description` = REPLACE(REPLACE(`description`, '&#039;', ''''), '&quot;', '"') WHERE `description` LIKE '%&#039;%' OR `description` LIKE '%&quot;%';
UPDATE `products` SET `name` = REPLACE(REPLACE(`name`, '&#039;', ''''), '&quot;', '"') WHERE `name` LIKE '%&#039;%' OR `name` LIKE '%&quot;%';
UPDATE `product_arrivals` SET `admin_name` = REPLACE(REPLACE(`admin_name`, '&#039;', ''''), '&quot;', '"') WHERE `admin_name` LIKE '%&#039;%' OR `admin_name` LIKE '%&quot;%';
UPDATE `product_arrivals` SET `notes` = REPLACE(REPLACE(`notes`, '&#039;', ''''), '&quot;', '"') WHERE `notes` LIKE '%&#039;%' OR `notes` LIKE '%&quot;%';
UPDATE `product_arrivals` SET `product_name` = REPLACE(REPLACE(`product_name`, '&#039;', ''''), '&quot;', '"') WHERE `product_name` LIKE '%&#039;%' OR `product_name` LIKE '%&quot;%';
UPDATE `product_sales` SET `admin_name` = REPLACE(REPLACE(`admin_name`, '&#039;', ''''), '&quot;', '"') WHERE `admin_name` LIKE '%&#039;%' OR `admin_name` LIKE '%&quot;%';
UPDATE `product_sales` SET `client_name` = REPLACE(REPLACE(`client_name`, '&#039;', ''''), '&quot;', '"') WHERE `client_name` LIKE '%&#039;%' OR `client_name` LIKE '%&quot;%';
UPDATE `product_sales` SET `notes` = REPLACE(REPLACE(`notes`, '&#039;', ''''), '&quot;', '"') WHERE `notes` LIKE '%&#039;%' OR `notes` LIKE '%&quot;%';
UPDATE `product_sales` SET `product_name` = REPLACE(REPLACE(`product_name`, '&#039;', ''''), '&quot;', '"') WHERE `product_name` LIKE '%&#039;%' OR `product_name` LIKE '%&quot;%';
UPDATE `saas_invoices` SET `notes` = REPLACE(REPLACE(`notes`, '&#039;', ''''), '&quot;', '"') WHERE `notes` LIKE '%&#039;%' OR `notes` LIKE '%&quot;%';
UPDATE `saas_plans` SET `discount_label` = REPLACE(REPLACE(`discount_label`, '&#039;', ''''), '&quot;', '"') WHERE `discount_label` LIKE '%&#039;%' OR `discount_label` LIKE '%&quot;%';
UPDATE `saas_plans` SET `name` = REPLACE(REPLACE(`name`, '&#039;', ''''), '&quot;', '"') WHERE `name` LIKE '%&#039;%' OR `name` LIKE '%&quot;%';
UPDATE `saas_promo_codes` SET `notes` = REPLACE(REPLACE(`notes`, '&#039;', ''''), '&quot;', '"') WHERE `notes` LIKE '%&#039;%' OR `notes` LIKE '%&quot;%';
UPDATE `sale_orders` SET `admin_name` = REPLACE(REPLACE(`admin_name`, '&#039;', ''''), '&quot;', '"') WHERE `admin_name` LIKE '%&#039;%' OR `admin_name` LIKE '%&quot;%';
UPDATE `sale_orders` SET `cancel_confirmed_by_name` = REPLACE(REPLACE(`cancel_confirmed_by_name`, '&#039;', ''''), '&quot;', '"') WHERE `cancel_confirmed_by_name` LIKE '%&#039;%' OR `cancel_confirmed_by_name` LIKE '%&quot;%';
UPDATE `sale_orders` SET `cancel_requested_by_name` = REPLACE(REPLACE(`cancel_requested_by_name`, '&#039;', ''''), '&quot;', '"') WHERE `cancel_requested_by_name` LIKE '%&#039;%' OR `cancel_requested_by_name` LIKE '%&quot;%';
UPDATE `sale_orders` SET `client_name` = REPLACE(REPLACE(`client_name`, '&#039;', ''''), '&quot;', '"') WHERE `client_name` LIKE '%&#039;%' OR `client_name` LIKE '%&quot;%';
UPDATE `sale_orders` SET `notes` = REPLACE(REPLACE(`notes`, '&#039;', ''''), '&quot;', '"') WHERE `notes` LIKE '%&#039;%' OR `notes` LIKE '%&quot;%';
UPDATE `sale_orders` SET `returned_by_name` = REPLACE(REPLACE(`returned_by_name`, '&#039;', ''''), '&quot;', '"') WHERE `returned_by_name` LIKE '%&#039;%' OR `returned_by_name` LIKE '%&quot;%';
UPDATE `sale_orders` SET `return_requested_by_name` = REPLACE(REPLACE(`return_requested_by_name`, '&#039;', ''''), '&quot;', '"') WHERE `return_requested_by_name` LIKE '%&#039;%' OR `return_requested_by_name` LIKE '%&quot;%';
UPDATE `staff_payroll` SET `notes` = REPLACE(REPLACE(`notes`, '&#039;', ''''), '&quot;', '"') WHERE `notes` LIKE '%&#039;%' OR `notes` LIKE '%&quot;%';
UPDATE `staff_salary_settings` SET `notes` = REPLACE(REPLACE(`notes`, '&#039;', ''''), '&quot;', '"') WHERE `notes` LIKE '%&#039;%' OR `notes` LIKE '%&quot;%';
UPDATE `sys_clubs` SET `city` = REPLACE(REPLACE(`city`, '&#039;', ''''), '&quot;', '"') WHERE `city` LIKE '%&#039;%' OR `city` LIKE '%&quot;%';
UPDATE `sys_clubs` SET `name` = REPLACE(REPLACE(`name`, '&#039;', ''''), '&quot;', '"') WHERE `name` LIKE '%&#039;%' OR `name` LIKE '%&quot;%';
UPDATE `sys_permissions` SET `category` = REPLACE(REPLACE(`category`, '&#039;', ''''), '&quot;', '"') WHERE `category` LIKE '%&#039;%' OR `category` LIKE '%&quot;%';
UPDATE `sys_roles` SET `name_ua` = REPLACE(REPLACE(`name_ua`, '&#039;', ''''), '&quot;', '"') WHERE `name_ua` LIKE '%&#039;%' OR `name_ua` LIKE '%&quot;%';
UPDATE `sys_users` SET `full_name` = REPLACE(REPLACE(`full_name`, '&#039;', ''''), '&quot;', '"') WHERE `full_name` LIKE '%&#039;%' OR `full_name` LIKE '%&quot;%';
UPDATE `tariffs` SET `category` = REPLACE(REPLACE(`category`, '&#039;', ''''), '&quot;', '"') WHERE `category` LIKE '%&#039;%' OR `category` LIKE '%&quot;%';
UPDATE `tariffs` SET `description` = REPLACE(REPLACE(`description`, '&#039;', ''''), '&quot;', '"') WHERE `description` LIKE '%&#039;%' OR `description` LIKE '%&quot;%';
UPDATE `tariffs` SET `name` = REPLACE(REPLACE(`name`, '&#039;', ''''), '&quot;', '"') WHERE `name` LIKE '%&#039;%' OR `name` LIKE '%&quot;%';
UPDATE `trainer_earnings` SET `notes` = REPLACE(REPLACE(`notes`, '&#039;', ''''), '&quot;', '"') WHERE `notes` LIKE '%&#039;%' OR `notes` LIKE '%&quot;%';
UPDATE `trainer_ledger` SET `notes` = REPLACE(REPLACE(`notes`, '&#039;', ''''), '&quot;', '"') WHERE `notes` LIKE '%&#039;%' OR `notes` LIKE '%&quot;%';
UPDATE `trainer_payouts` SET `notes` = REPLACE(REPLACE(`notes`, '&#039;', ''''), '&quot;', '"') WHERE `notes` LIKE '%&#039;%' OR `notes` LIKE '%&quot;%';
UPDATE `trainer_rent` SET `notes` = REPLACE(REPLACE(`notes`, '&#039;', ''''), '&quot;', '"') WHERE `notes` LIKE '%&#039;%' OR `notes` LIKE '%&quot;%';
UPDATE `visits` SET `admin_name` = REPLACE(REPLACE(`admin_name`, '&#039;', ''''), '&quot;', '"') WHERE `admin_name` LIKE '%&#039;%' OR `admin_name` LIKE '%&quot;%';
UPDATE `visits` SET `notes` = REPLACE(REPLACE(`notes`, '&#039;', ''''), '&quot;', '"') WHERE `notes` LIKE '%&#039;%' OR `notes` LIKE '%&quot;%';
UPDATE `visits` SET `trainer_name` = REPLACE(REPLACE(`trainer_name`, '&#039;', ''''), '&quot;', '"') WHERE `trainer_name` LIKE '%&#039;%' OR `trainer_name` LIKE '%&quot;%';

-- ============================================================================
-- Журнал каси: відновити справжнє ім'я адміністратора замість "Адмін"
-- (cash_api.php писав $sess['name'], якого не існує → завжди "Адмін").
-- Ім'я береться за admin_id, який зберігався правильно.
-- ============================================================================
UPDATE club_cashflow cf JOIN sys_users u ON u.id = cf.admin_id
SET cf.admin_name = u.full_name
WHERE cf.admin_name = 'Адмін';

UPDATE club_expenses e JOIN sys_users u ON u.id = e.admin_id
SET e.admin_name = u.full_name
WHERE e.admin_name = 'Адмін';
