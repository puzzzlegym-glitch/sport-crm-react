-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Час створення: Вер 28 2026 р., 14:00
-- Версія сервера: 8.4.11-11
-- Версія PHP: 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База даних: (структура продакшн-бази, без даних)
--

-- --------------------------------------------------------

--
-- Структура таблиці `cash_shifts`
--

CREATE TABLE `cash_shifts` (
  `id` bigint UNSIGNED NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `opened_by` int UNSIGNED NOT NULL COMMENT 'sys_users.id адміністратора',
  `opened_name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `opened_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `balance_open` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'Залишок у касі на момент відкриття',
  `closed_by` int UNSIGNED DEFAULT NULL,
  `closed_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `balance_close` decimal(10,2) DEFAULT NULL COMMENT 'Залишок у касі на момент закриття',
  `income_shift` decimal(10,2) DEFAULT '0.00' COMMENT 'Надходження за зміну',
  `expense_shift` decimal(10,2) DEFAULT '0.00' COMMENT 'Витрати + інкасації за зміну',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('open','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `closed_reason` enum('manual','auto') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `open_marker` int UNSIGNED GENERATED ALWAYS AS ((case when (`status` = _utf8mb4'open') then `club_id` else NULL end)) STORED COMMENT 'Технічна колонка для UNIQUE — не використовувати напряму в запитах'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Зміни адміністраторів каси';

-- --------------------------------------------------------

--
-- Структура таблиці `certificates`
--

CREATE TABLE `certificates` (
  `id` int NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `code` varchar(30) NOT NULL,
  `status` enum('available','sold') NOT NULL DEFAULT 'available',
  `created_admin_id` int UNSIGNED DEFAULT NULL,
  `created_admin_name` varchar(191) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `certificate_sales`
--

CREATE TABLE `certificate_sales` (
  `id` int NOT NULL,
  `certificate_id` int NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','card','terminal','transfer','other') NOT NULL DEFAULT 'cash',
  `buyer_name` varchar(191) DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `status` enum('sold','redeemed','cancelled') NOT NULL DEFAULT 'sold',
  `sold_admin_id` int UNSIGNED DEFAULT NULL,
  `sold_admin_name` varchar(191) DEFAULT NULL,
  `sold_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `redeemed_client_id` int UNSIGNED DEFAULT NULL,
  `redeemed_admin_id` int UNSIGNED DEFAULT NULL,
  `redeemed_admin_name` varchar(191) DEFAULT NULL,
  `redeemed_at` datetime DEFAULT NULL,
  `deposit_id` bigint UNSIGNED DEFAULT NULL,
  `cancel_reason` varchar(500) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `clients`
--

CREATE TABLE `clients` (
  `id` int UNSIGNED NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `full_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone_normalized` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Лише цифри: 380671234567',
  `email` varchar(180) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `gender` enum('M','F','') COLLATE utf8mb4_unicode_ci DEFAULT '',
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('regular','premium','blocked') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'regular',
  `status_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status_changed_at` datetime DEFAULT NULL,
  `status_changed_by` int DEFAULT NULL,
  `source` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Звідки дізнались: реклама, друг...',
  `notes` text COLLATE utf8mb4_unicode_ci COMMENT 'Нотатки менеджера',
  `photo_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telegram_id` bigint DEFAULT NULL COMMENT 'ID у Telegram (для бота)',
  `telegram_username` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `barcode` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Штрих-код картки клієнта',
  `balance` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'Баланс передплат',
  `credit` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'Борг',
  `created_by` int UNSIGNED DEFAULT NULL COMMENT 'sys_users.id',
  `assigned_trainer_id` int UNSIGNED DEFAULT NULL COMMENT 'Закріплений тренер',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `client_deposits`
--

CREATE TABLE `client_deposits` (
  `id` bigint UNSIGNED NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `amount` decimal(10,2) NOT NULL COMMENT 'Позитивне = поповнення, негативне = списання',
  `balance_after` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'Баланс після операції',
  `operation` enum('top_up','pay_invoice','pay_product','refund','correction','certificate') COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_method` enum('cash','card','terminal','transfer','other') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invoice_id` int UNSIGNED DEFAULT NULL,
  `sale_id` bigint UNSIGNED DEFAULT NULL,
  `admin_id` int UNSIGNED DEFAULT NULL,
  `admin_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `client_invoices`
--

CREATE TABLE `client_invoices` (
  `id` int UNSIGNED NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `tariff_id` int UNSIGNED DEFAULT NULL COMMENT 'NULL = ручний запис',
  `tariff_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `paid_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `visits_total` smallint DEFAULT NULL COMMENT 'NULL = безліміт',
  `visits_used` smallint NOT NULL DEFAULT '0',
  `status` enum('active','expired','frozen','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `sale_type` enum('new','renewal','return') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `trainer_id` int UNSIGNED DEFAULT NULL,
  `trainer_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trainer_narah_type` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trainer_narah_amount` decimal(10,2) DEFAULT '0.00',
  `trainer_narah_group` decimal(10,2) DEFAULT '0.00',
  `freeze_days` smallint DEFAULT '0',
  `freeze_start` date DEFAULT NULL,
  `prolong_days` smallint DEFAULT '0',
  `admin_id` int UNSIGNED DEFAULT NULL,
  `admin_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `client_telegram_links`
--

CREATE TABLE `client_telegram_links` (
  `id` int NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `token` char(32) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `club_acquiring_settings`
--

CREATE TABLE `club_acquiring_settings` (
  `id` int NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `provider` varchar(20) NOT NULL DEFAULT 'privatbank',
  `terminal_type` varchar(20) NOT NULL DEFAULT 'pos',
  `connection_type` varchar(20) NOT NULL DEFAULT 'wifi',
  `terminal_id` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `club_cashflow`
--

CREATE TABLE `club_cashflow` (
  `id` bigint UNSIGNED NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `type` enum('income','expense','transfer','encashment','transfer_in','transfer_out','adjustment') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'income',
  `category` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Абонемент, Товар, Зарплата, Оренда...',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','card','terminal','transfer','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `location` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'register' COMMENT 'register = денна каса/зміна, safe = сейф',
  `source` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Звідки: invoice, product_sale, manual',
  `source_id` bigint UNSIGNED DEFAULT NULL COMMENT 'ID запису у таблиці-джерелі',
  `shift_id` bigint UNSIGNED DEFAULT NULL COMMENT 'cash_shifts.id',
  `admin_id` int UNSIGNED DEFAULT NULL,
  `admin_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `club_deletion_requests`
--

CREATE TABLE `club_deletion_requests` (
  `id` int NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `requested_by` int UNSIGNED NOT NULL,
  `reason` text NOT NULL,
  `code_hash` char(64) NOT NULL,
  `attempts` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `club_equipment`
--

CREATE TABLE `club_equipment` (
  `id` int NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `location_note` varchar(200) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'working',
  `notes` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `club_expenses`
--

CREATE TABLE `club_expenses` (
  `id` int UNSIGNED NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `category` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Оренда, Комунальні, Зарплата, Закупка...',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `expense_date` date NOT NULL,
  `payment_method` enum('cash','card','terminal','transfer','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `shift_id` int DEFAULT NULL COMMENT 'id відкритої зміни (cash_shifts), якщо витрату заведено з Каси; NULL для витрат з Фінансів',
  `admin_id` int UNSIGNED DEFAULT NULL,
  `admin_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `source` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual' COMMENT 'manual = ручний запис (можна редагувати/видаляти), інше = системний, захищений',
  `source_id` int DEFAULT NULL COMMENT 'id сутності-джерела (напр. trainer_earnings.id для source=trainer_payout)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `club_menu_settings`
--

CREATE TABLE `club_menu_settings` (
  `club_id` int UNSIGNED NOT NULL,
  `page_slug` varchar(60) NOT NULL,
  `min_role_level` tinyint NOT NULL DEFAULT '30',
  `is_visible` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `club_payments`
--

CREATE TABLE `club_payments` (
  `id` bigint UNSIGNED NOT NULL,
  `shift_id` bigint UNSIGNED DEFAULT NULL COMMENT 'cash_shifts.id',
  `club_id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `invoice_id` int UNSIGNED DEFAULT NULL COMMENT 'Абонемент за який платіж',
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','card','terminal','deposit','transfer','free','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash' COMMENT 'готівка / карта / термінал / депозит',
  `trainer_id` int UNSIGNED DEFAULT NULL,
  `trainer_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `admin_id` int UNSIGNED DEFAULT NULL,
  `admin_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fiscal_status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'null=не потрібен | pending | sent | failed | skipped_manual',
  `fiscal_receipt_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fiscal_receipt_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fiscal_error` text COLLATE utf8mb4_unicode_ci,
  `fiscal_attempts` int NOT NULL DEFAULT '0' COMMENT 'лічильник спроб для cron/retry_fiscal_receipts.php, обмежує повтори'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `club_prro_payment_methods`
--

CREATE TABLE `club_prro_payment_methods` (
  `id` int NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `payment_method` varchar(20) NOT NULL,
  `auto_fiscalize` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `club_prro_settings`
--

CREATE TABLE `club_prro_settings` (
  `id` int NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `provider` varchar(20) NOT NULL DEFAULT 'checkbox',
  `license_key_enc` varchar(500) DEFAULT NULL,
  `cashier_login` varchar(100) DEFAULT NULL,
  `cashier_password_enc` varchar(500) DEFAULT NULL,
  `cash_register_id` varchar(100) DEFAULT NULL,
  `cashier_id` varchar(100) DEFAULT NULL,
  `access_token_enc` varchar(1000) DEFAULT NULL,
  `access_token_expires_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `last_sync_at` datetime DEFAULT NULL,
  `last_sync_status` varchar(50) DEFAULT NULL,
  `last_sync_error` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `club_role_permissions`
--

CREATE TABLE `club_role_permissions` (
  `club_id` int UNSIGNED NOT NULL,
  `role_id` int NOT NULL,
  `permission_slug` varchar(60) NOT NULL,
  `is_allowed` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `club_trainers`
--

CREATE TABLE `club_trainers` (
  `id` int UNSIGNED NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL COMMENT 'sys_users.id',
  `specialization` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Йога, Силові, Кардіо...',
  `bio` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `photo_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `work_type` enum('employee','rent','both') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'employee' COMMENT 'employee=найманий, rent=орендар, both=обидва',
  `personal_earn_type` enum('percent','fixed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'percent' COMMENT 'Тип нарахування для персональних',
  `personal_earn_value` decimal(8,2) NOT NULL DEFAULT '50.00' COMMENT 'Якщо percent — це %, якщо fixed — грн за заняття',
  `personal_tier_threshold` smallint UNSIGNED NOT NULL DEFAULT '0' COMMENT 'К-сть клієнтів для підвищення % (0 = вимкнено)',
  `personal_tier_value` decimal(8,2) NOT NULL DEFAULT '0.00' COMMENT 'Підвищений % або сума при перевищенні порогу',
  `group_earn_rate` decimal(8,2) NOT NULL DEFAULT '0.00' COMMENT 'Фіксована ставка за проведене групове заняття',
  `group_earn_bonus_per_client` decimal(6,2) NOT NULL DEFAULT '0.00' COMMENT 'Бонус грн за кожного учасника понад group_bonus_threshold',
  `group_bonus_threshold` tinyint UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Поріг учасників для бонусу (0 = з першого)',
  `group_monthly_bonus_sessions` tinyint UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Поріг занять/місяць для бонусу (0 = вимкнено)',
  `group_monthly_bonus_amount` decimal(8,2) NOT NULL DEFAULT '0.00' COMMENT 'Сума місячного бонусу при перевищенні порогу',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Профілі тренерів по клубах';

-- --------------------------------------------------------

--
-- Структура таблиці `deleted_clubs_audit`
--

CREATE TABLE `deleted_clubs_audit` (
  `id` int NOT NULL,
  `club_id` int NOT NULL,
  `club_name` varchar(255) NOT NULL,
  `owner_name` varchar(255) NOT NULL,
  `owner_email` varchar(255) NOT NULL,
  `reason` text NOT NULL,
  `deleted_by` int UNSIGNED NOT NULL,
  `deleted_by_name` varchar(255) NOT NULL,
  `total_paid_before_delete` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `group_sessions`
--

CREATE TABLE `group_sessions` (
  `id` int NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `trainer_id` int UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `session_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time DEFAULT NULL,
  `capacity` int DEFAULT NULL,
  `status` enum('scheduled','completed','canceled') NOT NULL DEFAULT 'scheduled',
  `notes` varchar(500) DEFAULT NULL,
  `created_by` int UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `group_session_clients`
--

CREATE TABLE `group_session_clients` (
  `id` int NOT NULL,
  `session_id` int NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `invoice_id` int UNSIGNED DEFAULT NULL,
  `visit_id` bigint UNSIGNED DEFAULT NULL,
  `status` enum('booked','attended','no_show','canceled') NOT NULL DEFAULT 'booked',
  `checked_in_at` datetime DEFAULT NULL,
  `created_by` int UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `products`
--

CREATE TABLE `products` (
  `id` int UNSIGNED NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `name` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Постачальник',
  `barcode` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purchase_price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'Ціна закупки',
  `sale_price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'Ціна продажу',
  `stock_qty` int NOT NULL DEFAULT '0' COMMENT 'Залишок на складі',
  `stock_min` int NOT NULL DEFAULT '0' COMMENT 'Мінімальний залишок (сповіщення)',
  `photo_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `product_arrivals`
--

CREATE TABLE `product_arrivals` (
  `id` int UNSIGNED NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `product_id` int UNSIGNED NOT NULL,
  `product_name` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Копія назви на момент приходу',
  `quantity` int NOT NULL,
  `operation` enum('arrival','overdue','repack','transfer') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'arrival' COMMENT 'arrival=прихід(+), overdue=прострочка(-), repack=розфасування(-), transfer=перенесення склад(-)',
  `status` enum('paid','unpaid','pending') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'paid' COMMENT 'paid=оплачено, unpaid=борг перед постачальником, pending=замовлено/не доставлено',
  `payment_method` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash' COMMENT 'cash/card/terminal/transfer — спосіб оплати приходу (для paid)',
  `expected_qty` int DEFAULT NULL COMMENT 'Очікувана кількість (для статусу pending)',
  `purchase_price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `sale_price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `supplier` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_cost` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'quantity * purchase_price',
  `admin_id` int UNSIGNED DEFAULT NULL,
  `admin_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `product_sales`
--

CREATE TABLE `product_sales` (
  `id` bigint UNSIGNED NOT NULL,
  `order_id` int DEFAULT NULL,
  `shift_id` bigint UNSIGNED DEFAULT NULL COMMENT 'cash_shifts.id',
  `club_id` int UNSIGNED NOT NULL,
  `product_id` int UNSIGNED DEFAULT NULL,
  `product_name` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Копія назви на момент продажу',
  `client_id` int UNSIGNED DEFAULT NULL,
  `client_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int NOT NULL DEFAULT '1',
  `purchase_price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `sale_price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `discount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'Знижка в грн',
  `total_amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'quantity * sale_price - discount',
  `profit` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'total_amount - quantity * purchase_price',
  `payment_method` enum('cash','card','terminal','deposit','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `admin_id` int UNSIGNED DEFAULT NULL,
  `admin_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `saas_invoices`
--

CREATE TABLE `saas_invoices` (
  `id` int UNSIGNED NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `subscription_id` int UNSIGNED NOT NULL,
  `plan_id` tinyint UNSIGNED NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `promo_code` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `discount_amount` decimal(10,2) DEFAULT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'UAH',
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `status` enum('draft','sent','paid','void') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `paid_at` datetime DEFAULT NULL,
  `due_date` date NOT NULL,
  `payment_gateway` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'liqpay | wayforpay | manual',
  `gateway_order_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `saas_payments`
--

CREATE TABLE `saas_payments` (
  `id` int UNSIGNED NOT NULL,
  `invoice_id` int UNSIGNED NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'UAH',
  `gateway` enum('liqpay','wayforpay','manual') COLLATE utf8mb4_unicode_ci NOT NULL,
  `gateway_txn_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ID транзакції у платіжній системі',
  `gateway_status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'success | failure | wait_secure | ...',
  `gateway_raw` json DEFAULT NULL COMMENT 'Сирий callback від платіжки',
  `status` enum('pending','success','failed','refunded') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `recorded_by` int UNSIGNED DEFAULT NULL COMMENT 'sys_users.id SuperAdmin',
  `recorded_note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `saas_plans`
--

CREATE TABLE `saas_plans` (
  `id` tinyint UNSIGNED NOT NULL,
  `slug` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'starter | business | pro',
  `name` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price_monthly` decimal(10,2) NOT NULL COMMENT 'Грн/місяць',
  `discount_percent` int NOT NULL DEFAULT '0',
  `discount_label` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `discount_valid_until` date DEFAULT NULL,
  `clients_limit` smallint DEFAULT NULL COMMENT 'NULL = безліміт',
  `users_limit` tinyint DEFAULT NULL COMMENT 'NULL = безліміт',
  `invoices_limit` smallint DEFAULT NULL COMMENT 'NULL = безліміт. Кількість активних абонементів',
  `features` json DEFAULT NULL COMMENT 'Список функцій для UI',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` tinyint NOT NULL DEFAULT '0',
  `trial_days` tinyint NOT NULL DEFAULT '0',
  `is_free` tinyint NOT NULL DEFAULT '0',
  `allowed_pages` json DEFAULT NULL COMMENT 'NULL = всі сторінки. Масив slug: ["dashboard","clients",...]'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `saas_promo_codes`
--

CREATE TABLE `saas_promo_codes` (
  `id` int NOT NULL,
  `code` varchar(32) NOT NULL,
  `discount_type` enum('percent','fixed') NOT NULL DEFAULT 'percent',
  `discount_value` decimal(10,2) NOT NULL,
  `plan_id` int DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_used` tinyint(1) NOT NULL DEFAULT '0',
  `used_club_id` int UNSIGNED DEFAULT NULL,
  `used_invoice_id` int DEFAULT NULL,
  `used_at` datetime DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `saas_registrations`
--

CREATE TABLE `saas_registrations` (
  `id` int UNSIGNED NOT NULL,
  `club_id` int UNSIGNED DEFAULT NULL,
  `owner_email` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `saas_subscriptions`
--

CREATE TABLE `saas_subscriptions` (
  `id` int UNSIGNED NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `plan_id` tinyint UNSIGNED NOT NULL,
  `status` enum('trial','trial_expired','active','past_due','cancelled','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'trial',
  `trial_ends_at` datetime DEFAULT NULL,
  `current_period_start` datetime DEFAULT NULL,
  `current_period_end` datetime DEFAULT NULL,
  `auto_renew` tinyint(1) NOT NULL DEFAULT '1',
  `admin_notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `sale_orders`
--

CREATE TABLE `sale_orders` (
  `id` int NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `order_number` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED DEFAULT NULL,
  `client_name` varchar(191) DEFAULT NULL,
  `items_count` int UNSIGNED NOT NULL DEFAULT '0',
  `subtotal` decimal(10,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `payment_method` enum('cash','card','terminal','deposit','other') NOT NULL DEFAULT 'cash',
  `status` enum('completed','returned','pending_return','pending_cancel') NOT NULL DEFAULT 'completed',
  `shift_id` int UNSIGNED DEFAULT NULL,
  `admin_id` int UNSIGNED DEFAULT NULL,
  `admin_name` varchar(191) DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `return_reason` varchar(500) DEFAULT NULL,
  `return_requested_at` datetime DEFAULT NULL COMMENT 'Момент ІНІЦІАЦІЇ повернення (returned_at/returned_by_* — момент ПІДТВЕРДЖЕННЯ)',
  `return_requested_by_id` int UNSIGNED DEFAULT NULL,
  `return_requested_by_name` varchar(191) DEFAULT NULL,
  `returned_at` datetime DEFAULT NULL,
  `returned_by_id` int UNSIGNED DEFAULT NULL,
  `returned_by_name` varchar(191) DEFAULT NULL,
  `cancel_reason` varchar(500) DEFAULT NULL,
  `cancel_requested_at` datetime DEFAULT NULL,
  `cancel_requested_by_id` int UNSIGNED DEFAULT NULL,
  `cancel_requested_by_name` varchar(191) DEFAULT NULL,
  `cancel_confirmed_at` datetime DEFAULT NULL,
  `cancel_confirmed_by_id` int UNSIGNED DEFAULT NULL,
  `cancel_confirmed_by_name` varchar(191) DEFAULT NULL,
  `refund_method` enum('cash','deposit','other') DEFAULT NULL COMMENT 'Спосіб фактичного повернення коштів клієнту',
  `refund_location` varchar(20) DEFAULT NULL COMMENT 'register/safe — лише коли refund_method=cash',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `staff_payroll`
--

CREATE TABLE `staff_payroll` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `club_id` int NOT NULL,
  `period_month` char(7) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pay_month` decimal(10,2) NOT NULL DEFAULT '0.00',
  `pay_day` decimal(10,2) NOT NULL DEFAULT '0.00',
  `pay_hour` decimal(10,2) NOT NULL DEFAULT '0.00',
  `pct_tovar` decimal(10,2) NOT NULL DEFAULT '0.00',
  `pct_abon` decimal(10,2) NOT NULL DEFAULT '0.00',
  `plan_bonus` decimal(12,2) NOT NULL DEFAULT '0.00',
  `days_worked` decimal(6,1) NOT NULL DEFAULT '0.0',
  `hours_worked` decimal(8,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `paid_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('pending','partial','paid') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `staff_salary_settings`
--

CREATE TABLE `staff_salary_settings` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `club_id` int NOT NULL,
  `pay_month_on` tinyint(1) NOT NULL DEFAULT '0',
  `pay_month_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `pay_day_on` tinyint(1) NOT NULL DEFAULT '0',
  `pay_day_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `pay_hour_on` tinyint(1) NOT NULL DEFAULT '0',
  `pay_hour_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `pct_tovar_on` tinyint(1) NOT NULL DEFAULT '0',
  `pct_tovar_value` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `pct_tovar_min` decimal(12,2) NOT NULL DEFAULT '0.00',
  `pct_abon_on` tinyint(1) NOT NULL DEFAULT '0',
  `pct_abon_value` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `pct_abon_min` decimal(12,2) NOT NULL DEFAULT '0.00',
  `plan_on` tinyint(1) NOT NULL DEFAULT '0',
  `plan_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `plan_bonus_pct` decimal(6,4) NOT NULL DEFAULT '0.0000',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `staff_telegram_links`
--

CREATE TABLE `staff_telegram_links` (
  `id` int NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `token` char(32) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `support_messages`
--

CREATE TABLE `support_messages` (
  `id` int NOT NULL,
  `ticket_id` int NOT NULL,
  `sender_type` enum('club','admin') NOT NULL,
  `sender_user_id` int UNSIGNED NOT NULL,
  `message` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `support_telegram_messages`
--

CREATE TABLE `support_telegram_messages` (
  `id` int NOT NULL,
  `ticket_id` int NOT NULL,
  `chat_id` varchar(32) NOT NULL,
  `telegram_message_id` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `support_tickets`
--

CREATE TABLE `support_tickets` (
  `id` int NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `created_by` int UNSIGNED NOT NULL,
  `subject` varchar(191) NOT NULL,
  `status` enum('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
  `unread_by_club` tinyint(1) NOT NULL DEFAULT '0',
  `unread_by_admin` tinyint(1) NOT NULL DEFAULT '1',
  `last_message_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `sys_clubs`
--

CREATE TABLE `sys_clubs` (
  `id` int UNSIGNED NOT NULL,
  `owner_id` int UNSIGNED NOT NULL COMMENT 'ID власника з sys_users',
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'URL-ідентифікатор, напр. drive-sport',
  `city` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(180) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timezone` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Europe/Kyiv',
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'UAH',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `subscription_status` enum('trial','trial_expired','active','past_due','cancelled','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'trial',
  `trial_ends_at` datetime DEFAULT NULL,
  `logo_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `settings` json DEFAULT NULL COMMENT 'Довільні налаштування клубу',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `cash_shift_auto_close_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Автоматично закривати відкриту зміну каси за розкладом (owner-only налаштування)',
  `cash_shift_auto_close_time` time DEFAULT NULL COMMENT 'Час автозакриття зміни (локальний час сервера, як і решта дат у застосунку)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `sys_login_log`
--

CREATE TABLE `sys_login_log` (
  `id` bigint UNSIGNED NOT NULL,
  `email` varchar(180) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT '0',
  `fail_reason` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'wrong_password | not_found | blocked',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `sys_permissions`
--

CREATE TABLE `sys_permissions` (
  `slug` varchar(60) NOT NULL,
  `label` varchar(120) NOT NULL,
  `category` varchar(60) NOT NULL,
  `sort_order` tinyint NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `sys_roles`
--

CREATE TABLE `sys_roles` (
  `id` tinyint UNSIGNED NOT NULL,
  `slug` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'superadmin | owner | manager | trainer',
  `name_ua` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Відображувана назва',
  `level` tinyint UNSIGNED NOT NULL COMMENT 'Числовий рівень: 100=super, 80=owner, 50=manager, 30=trainer'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `sys_role_permissions`
--

CREATE TABLE `sys_role_permissions` (
  `role_id` int NOT NULL,
  `permission_slug` varchar(60) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `sys_sessions`
--

CREATE TABLE `sys_sessions` (
  `session_token` char(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'SHA-256 hex токен',
  `user_id` int UNSIGNED NOT NULL,
  `active_club_id` int UNSIGNED DEFAULT NULL COMMENT 'Поточний активний клуб',
  `view_as_role_slug` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload` json DEFAULT NULL COMMENT 'Кешовані дані сесії',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  `last_activity` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `sys_users`
--

CREATE TABLE `sys_users` (
  `id` int UNSIGNED NOT NULL,
  `email` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verify_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verify_token_expires` datetime DEFAULT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'bcrypt, мін. cost=12',
  `full_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telegram_id` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `global_role_id` tinyint UNSIGNED DEFAULT NULL COMMENT 'NULL = не глобальна роль. 1=superadmin',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `password_reset_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_reset_token_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `sys_user_clubs`
--

CREATE TABLE `sys_user_clubs` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `role_id` tinyint UNSIGNED NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `granted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `granted_by` int UNSIGNED DEFAULT NULL COMMENT 'Хто надав доступ'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `tariffs`
--

CREATE TABLE `tariffs` (
  `id` int UNSIGNED NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Назва: "Безліміт", "10 відвідувань"',
  `category` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Групова, персональна, онлайн...',
  `coverage` enum('all','gym','group','personal') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all' COMMENT 'Що покриває: all=зал+групові, gym, group, personal',
  `duration_days` smallint NOT NULL DEFAULT '30' COMMENT 'Термін дії в днях',
  `visits_limit` smallint DEFAULT NULL COMMENT 'NULL = безліміт',
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `description` text COLLATE utf8mb4_unicode_ci,
  `color` char(7) COLLATE utf8mb4_unicode_ci DEFAULT '#4f9cf9' COMMENT 'HEX-колір для бейджа',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` smallint NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `prolong_sum` decimal(10,2) DEFAULT '0.00' COMMENT 'Вартість продовження за день',
  `freeze_days_max` smallint DEFAULT '0' COMMENT 'Максимум днів заморозки',
  `freeze_days_min` int NOT NULL DEFAULT '0',
  `earn_release_trigger` enum('on_sale','on_each_visit','on_visits_done','on_end_date') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'on_each_visit' COMMENT 'on_sale=одразу при продажу, on_each_visit=після кожного тренування, on_visits_done=всі заняття вичерпано, on_end_date=дата закінчення',
  `has_trainer` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Чи можна призначати тренера на абонементи цього тарифу'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `telegram_notifications_log`
--

CREATE TABLE `telegram_notifications_log` (
  `id` int NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `invoice_id` int UNSIGNED NOT NULL,
  `notif_type` varchar(32) NOT NULL,
  `sent_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `telegram_settings`
--

CREATE TABLE `telegram_settings` (
  `id` tinyint UNSIGNED NOT NULL DEFAULT '1',
  `reminders_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `reminder_days_before` int NOT NULL DEFAULT '1',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `trainer_earnings`
--

CREATE TABLE `trainer_earnings` (
  `id` bigint UNSIGNED NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `trainer_id` int UNSIGNED NOT NULL COMMENT 'club_trainers.id',
  `source` enum('invoice','visit') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'invoice=абонемент, visit=відвідування групи',
  `source_id` bigint UNSIGNED NOT NULL COMMENT 'client_invoices.id або visits.id',
  `invoice_id` int UNSIGNED DEFAULT NULL COMMENT 'Завжди зберігаємо invoice_id для розблокування',
  `earn_type` enum('personal_percent','personal_fixed','group_fixed','group_bonus') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'Повна сума нарахування',
  `available_amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'Зараз доступно до виплати',
  `paid_amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'Вже виплачено',
  `release_trigger` enum('on_sale','on_each_visit','on_visits_done','on_end_date') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'on_each_visit' COMMENT 'Копія з тарифу на момент створення',
  `available_at` datetime DEFAULT NULL COMMENT 'Коли стало повністю доступним (NULL = ще не повністю)',
  `status` enum('locked','available','partial','paid') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'locked' COMMENT 'locked=заблоковано, available=доступно, partial=частково виплачено, paid=повністю виплачено',
  `notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Журнал нарахувань тренерам';

-- --------------------------------------------------------

--
-- Структура таблиці `trainer_ledger`
--

CREATE TABLE `trainer_ledger` (
  `id` int NOT NULL,
  `club_id` int NOT NULL,
  `trainer_id` int NOT NULL,
  `transaction_type` enum('earn','deduction','payout','rent','refund') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'earn',
  `source_type` enum('invoice_payment','bonus','rent_deduction','correction','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'invoice_payment',
  `source_id` int DEFAULT NULL,
  `payment_id` int DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('locked','available','partial','paid') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'locked',
  `available_from` date DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `trainer_payouts`
--

CREATE TABLE `trainer_payouts` (
  `id` int NOT NULL,
  `club_id` int NOT NULL,
  `trainer_id` int NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `gross_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `rent_deduction` decimal(10,2) NOT NULL DEFAULT '0.00',
  `net_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('pending','processing','paid','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `paid_at` datetime DEFAULT NULL,
  `payment_method` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблиці `trainer_rent`
--

CREATE TABLE `trainer_rent` (
  `id` int UNSIGNED NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `trainer_id` int UNSIGNED NOT NULL COMMENT 'club_trainers.id',
  `rent_type` enum('manual','deduction') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual' COMMENT 'manual=тренер платить клубу, deduction=утримується з заробітку',
  `amount` decimal(10,2) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `status` enum('pending','paid') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int UNSIGNED DEFAULT NULL COMMENT 'sys_users.id',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Оренда залу тренерами';

-- --------------------------------------------------------

--
-- Структура таблиці `visits`
--

CREATE TABLE `visits` (
  `id` bigint UNSIGNED NOT NULL,
  `club_id` int UNSIGNED NOT NULL,
  `client_id` int UNSIGNED NOT NULL,
  `invoice_id` int UNSIGNED DEFAULT NULL,
  `visited_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `method` enum('barcode','manual','admin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual' COMMENT 'Як зафіксовано: barcode=сканування, manual=вручну, admin=адмін',
  `admin_id` int UNSIGNED DEFAULT NULL,
  `admin_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trainer_id` int UNSIGNED DEFAULT NULL,
  `trainer_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Індекси збережених таблиць
--

--
-- Індекси таблиці `cash_shifts`
--
ALTER TABLE `cash_shifts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_cash_shifts_one_open_per_club` (`open_marker`),
  ADD KEY `idx_club_status` (`club_id`,`status`),
  ADD KEY `idx_opened_by` (`opened_by`);

--
-- Індекси таблиці `certificates`
--
ALTER TABLE `certificates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cert_club_code` (`club_id`,`code`),
  ADD KEY `idx_cert_club_status` (`club_id`,`status`);

--
-- Індекси таблиці `certificate_sales`
--
ALTER TABLE `certificate_sales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_certsale_cert` (`certificate_id`),
  ADD KEY `idx_certsale_club_status` (`club_id`,`status`),
  ADD KEY `idx_certsale_club_sold` (`club_id`,`sold_at`),
  ADD KEY `fk_certsale_client` (`redeemed_client_id`),
  ADD KEY `fk_certsale_deposit` (`deposit_id`);

--
-- Індекси таблиці `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_club` (`club_id`),
  ADD KEY `idx_phone` (`phone_normalized`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_trainer` (`assigned_trainer_id`),
  ADD KEY `idx_barcode` (`club_id`,`barcode`);
ALTER TABLE `clients` ADD FULLTEXT KEY `ft_search` (`full_name`,`phone`,`email`) COMMENT 'Для швидкого пошуку';

--
-- Індекси таблиці `client_deposits`
--
ALTER TABLE `client_deposits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_club` (`club_id`),
  ADD KEY `idx_client` (`client_id`),
  ADD KEY `idx_date` (`created_at`);

--
-- Індекси таблиці `client_invoices`
--
ALTER TABLE `client_invoices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_client` (`client_id`),
  ADD KEY `idx_club` (`club_id`),
  ADD KEY `idx_dates` (`start_date`,`end_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `fk_ci_tariff` (`tariff_id`),
  ADD KEY `idx_active` (`club_id`,`status`,`end_date`);

--
-- Індекси таблиці `client_telegram_links`
--
ALTER TABLE `client_telegram_links`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ctl_token` (`token`),
  ADD KEY `idx_ctl_client` (`client_id`),
  ADD KEY `fk_ctl_club` (`club_id`);

--
-- Індекси таблиці `club_acquiring_settings`
--
ALTER TABLE `club_acquiring_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_acquiring_club` (`club_id`);

--
-- Індекси таблиці `club_cashflow`
--
ALTER TABLE `club_cashflow`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_cashflow_source` (`source`,`source_id`),
  ADD KEY `idx_club` (`club_id`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_date` (`created_at`),
  ADD KEY `idx_source` (`source`,`source_id`),
  ADD KEY `idx_payment_method` (`payment_method`),
  ADD KEY `idx_cashflow_shift` (`shift_id`),
  ADD KEY `idx_cashflow_location` (`club_id`,`location`);

--
-- Індекси таблиці `club_deletion_requests`
--
ALTER TABLE `club_deletion_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cdr_club` (`club_id`),
  ADD KEY `fk_cdr_user` (`requested_by`);

--
-- Індекси таблиці `club_equipment`
--
ALTER TABLE `club_equipment`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_equipment_club` (`club_id`);

--
-- Індекси таблиці `club_expenses`
--
ALTER TABLE `club_expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_club` (`club_id`),
  ADD KEY `idx_date` (`expense_date`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_source` (`source`,`source_id`);

--
-- Індекси таблиці `club_menu_settings`
--
ALTER TABLE `club_menu_settings`
  ADD PRIMARY KEY (`club_id`,`page_slug`);

--
-- Індекси таблиці `club_payments`
--
ALTER TABLE `club_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_club` (`club_id`),
  ADD KEY `idx_client` (`client_id`),
  ADD KEY `idx_invoice` (`invoice_id`),
  ADD KEY `idx_date` (`created_at`),
  ADD KEY `idx_club_payments_fiscal_retry` (`fiscal_status`,`fiscal_attempts`);

--
-- Індекси таблиці `club_prro_payment_methods`
--
ALTER TABLE `club_prro_payment_methods`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_prro_club_method` (`club_id`,`payment_method`);

--
-- Індекси таблиці `club_prro_settings`
--
ALTER TABLE `club_prro_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_prro_club` (`club_id`);

--
-- Індекси таблиці `club_role_permissions`
--
ALTER TABLE `club_role_permissions`
  ADD PRIMARY KEY (`club_id`,`role_id`,`permission_slug`),
  ADD KEY `permission_slug` (`permission_slug`);

--
-- Індекси таблиці `club_trainers`
--
ALTER TABLE `club_trainers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_trainer_club` (`club_id`,`user_id`),
  ADD KEY `idx_trainer_user` (`user_id`),
  ADD KEY `idx_trainer_club_active` (`club_id`,`is_active`);

--
-- Індекси таблиці `deleted_clubs_audit`
--
ALTER TABLE `deleted_clubs_audit`
  ADD PRIMARY KEY (`id`);

--
-- Індекси таблиці `group_sessions`
--
ALTER TABLE `group_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_group_sessions_club_date` (`club_id`,`session_date`),
  ADD KEY `idx_group_sessions_trainer` (`trainer_id`);

--
-- Індекси таблиці `group_session_clients`
--
ALTER TABLE `group_session_clients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_group_session_client` (`session_id`,`client_id`),
  ADD KEY `idx_group_session_clients_client` (`client_id`),
  ADD KEY `fk_group_session_clients_invoice` (`invoice_id`),
  ADD KEY `fk_group_session_clients_visit` (`visit_id`);

--
-- Індекси таблиці `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_club` (`club_id`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_barcode` (`barcode`);

--
-- Індекси таблиці `product_arrivals`
--
ALTER TABLE `product_arrivals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_club` (`club_id`),
  ADD KEY `idx_product` (`product_id`),
  ADD KEY `idx_date` (`created_at`);

--
-- Індекси таблиці `product_sales`
--
ALTER TABLE `product_sales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_club` (`club_id`),
  ADD KEY `idx_product` (`product_id`),
  ADD KEY `idx_client` (`client_id`),
  ADD KEY `idx_date` (`created_at`),
  ADD KEY `idx_ps_order` (`order_id`);

--
-- Індекси таблиці `saas_invoices`
--
ALTER TABLE `saas_invoices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_inv_club` (`club_id`),
  ADD KEY `fk_inv_sub` (`subscription_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_due` (`due_date`);

--
-- Індекси таблиці `saas_payments`
--
ALTER TABLE `saas_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_pay_invoice` (`invoice_id`),
  ADD KEY `fk_pay_club` (`club_id`),
  ADD KEY `idx_gateway_txn` (`gateway_txn_id`);

--
-- Індекси таблиці `saas_plans`
--
ALTER TABLE `saas_plans`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_slug` (`slug`);

--
-- Індекси таблиці `saas_promo_codes`
--
ALTER TABLE `saas_promo_codes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_promo_code` (`code`),
  ADD KEY `idx_promo_active` (`is_active`,`is_used`),
  ADD KEY `fk_promo_used_club` (`used_club_id`);

--
-- Індекси таблиці `saas_registrations`
--
ALTER TABLE `saas_registrations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email` (`owner_email`),
  ADD KEY `idx_ip` (`ip_address`,`created_at`);

--
-- Індекси таблиці `saas_subscriptions`
--
ALTER TABLE `saas_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_club` (`club_id`),
  ADD KEY `fk_sub_plan` (`plan_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_trial_end` (`trial_ends_at`);

--
-- Індекси таблиці `sale_orders`
--
ALTER TABLE `sale_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_so_club_number` (`club_id`,`order_number`),
  ADD KEY `idx_so_club_created` (`club_id`,`created_at`),
  ADD KEY `idx_so_club_status` (`club_id`,`status`),
  ADD KEY `fk_so_client` (`client_id`);

--
-- Індекси таблиці `staff_payroll`
--
ALTER TABLE `staff_payroll`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_club_period` (`user_id`,`club_id`,`period_month`),
  ADD KEY `idx_club_period` (`club_id`,`period_month`);

--
-- Індекси таблиці `staff_salary_settings`
--
ALTER TABLE `staff_salary_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_club` (`user_id`,`club_id`),
  ADD KEY `idx_club` (`club_id`);

--
-- Індекси таблиці `staff_telegram_links`
--
ALTER TABLE `staff_telegram_links`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_stl_token` (`token`),
  ADD KEY `idx_stl_user` (`user_id`);

--
-- Індекси таблиці `support_messages`
--
ALTER TABLE `support_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sm_ticket` (`ticket_id`,`created_at`),
  ADD KEY `fk_sm_sender` (`sender_user_id`);

--
-- Індекси таблиці `support_telegram_messages`
--
ALTER TABLE `support_telegram_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_stm_lookup` (`chat_id`,`telegram_message_id`),
  ADD KEY `idx_stm_ticket` (`ticket_id`);

--
-- Індекси таблиці `support_tickets`
--
ALTER TABLE `support_tickets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_st_club` (`club_id`,`status`),
  ADD KEY `idx_st_last_message` (`last_message_at`),
  ADD KEY `fk_st_creator` (`created_by`);

--
-- Індекси таблиці `sys_clubs`
--
ALTER TABLE `sys_clubs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_slug` (`slug`),
  ADD KEY `fk_club_owner` (`owner_id`),
  ADD KEY `idx_sub_status` (`subscription_status`);

--
-- Індекси таблиці `sys_login_log`
--
ALTER TABLE `sys_login_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ip_time` (`ip_address`,`created_at`),
  ADD KEY `idx_email` (`email`);

--
-- Індекси таблиці `sys_permissions`
--
ALTER TABLE `sys_permissions`
  ADD PRIMARY KEY (`slug`);

--
-- Індекси таблиці `sys_roles`
--
ALTER TABLE `sys_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_slug` (`slug`);

--
-- Індекси таблиці `sys_role_permissions`
--
ALTER TABLE `sys_role_permissions`
  ADD PRIMARY KEY (`role_id`,`permission_slug`),
  ADD KEY `permission_slug` (`permission_slug`);

--
-- Індекси таблиці `sys_sessions`
--
ALTER TABLE `sys_sessions`
  ADD PRIMARY KEY (`session_token`),
  ADD KEY `fk_sess_user` (`user_id`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Індекси таблиці `sys_users`
--
ALTER TABLE `sys_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_email` (`email`),
  ADD KEY `fk_global_role` (`global_role_id`);

--
-- Індекси таблиці `sys_user_clubs`
--
ALTER TABLE `sys_user_clubs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_club` (`user_id`,`club_id`),
  ADD KEY `fk_uc_user` (`user_id`),
  ADD KEY `fk_uc_club` (`club_id`),
  ADD KEY `fk_uc_role` (`role_id`);

--
-- Індекси таблиці `tariffs`
--
ALTER TABLE `tariffs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_club` (`club_id`);

--
-- Індекси таблиці `telegram_notifications_log`
--
ALTER TABLE `telegram_notifications_log`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tnl_dedupe` (`client_id`,`invoice_id`,`notif_type`),
  ADD KEY `fk_tnl_invoice` (`invoice_id`);

--
-- Індекси таблиці `telegram_settings`
--
ALTER TABLE `telegram_settings`
  ADD PRIMARY KEY (`id`);

--
-- Індекси таблиці `trainer_earnings`
--
ALTER TABLE `trainer_earnings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_earning_source` (`source`,`source_id`),
  ADD KEY `idx_te_trainer` (`trainer_id`,`status`),
  ADD KEY `idx_te_invoice` (`invoice_id`),
  ADD KEY `idx_te_club` (`club_id`),
  ADD KEY `idx_te_source` (`source`,`source_id`);

--
-- Індекси таблиці `trainer_ledger`
--
ALTER TABLE `trainer_ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_club_trainer` (`club_id`,`trainer_id`),
  ADD KEY `idx_status_available` (`status`,`available_from`),
  ADD KEY `idx_created` (`created_at`);

--
-- Індекси таблиці `trainer_payouts`
--
ALTER TABLE `trainer_payouts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_period` (`club_id`,`trainer_id`,`period_start`,`period_end`),
  ADD KEY `idx_club_trainer_status` (`club_id`,`trainer_id`,`status`),
  ADD KEY `idx_period` (`period_start`,`period_end`),
  ADD KEY `idx_created` (`created_at`);

--
-- Індекси таблиці `trainer_rent`
--
ALTER TABLE `trainer_rent`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rent_trainer` (`trainer_id`,`status`),
  ADD KEY `idx_rent_club` (`club_id`);

--
-- Індекси таблиці `visits`
--
ALTER TABLE `visits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_client` (`client_id`),
  ADD KEY `idx_club_dt` (`club_id`,`visited_at`),
  ADD KEY `fk_visits_invoice` (`invoice_id`),
  ADD KEY `idx_club_date` (`club_id`,`visited_at`);

--
-- AUTO_INCREMENT для збережених таблиць
--

--
-- AUTO_INCREMENT для таблиці `cash_shifts`
--
ALTER TABLE `cash_shifts`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `certificates`
--
ALTER TABLE `certificates`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `certificate_sales`
--
ALTER TABLE `certificate_sales`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `clients`
--
ALTER TABLE `clients`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `client_deposits`
--
ALTER TABLE `client_deposits`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `client_invoices`
--
ALTER TABLE `client_invoices`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `client_telegram_links`
--
ALTER TABLE `client_telegram_links`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `club_acquiring_settings`
--
ALTER TABLE `club_acquiring_settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `club_cashflow`
--
ALTER TABLE `club_cashflow`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `club_deletion_requests`
--
ALTER TABLE `club_deletion_requests`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `club_equipment`
--
ALTER TABLE `club_equipment`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `club_expenses`
--
ALTER TABLE `club_expenses`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `club_payments`
--
ALTER TABLE `club_payments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `club_prro_payment_methods`
--
ALTER TABLE `club_prro_payment_methods`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `club_prro_settings`
--
ALTER TABLE `club_prro_settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `club_trainers`
--
ALTER TABLE `club_trainers`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `deleted_clubs_audit`
--
ALTER TABLE `deleted_clubs_audit`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `group_sessions`
--
ALTER TABLE `group_sessions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `group_session_clients`
--
ALTER TABLE `group_session_clients`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `products`
--
ALTER TABLE `products`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `product_arrivals`
--
ALTER TABLE `product_arrivals`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `product_sales`
--
ALTER TABLE `product_sales`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `saas_invoices`
--
ALTER TABLE `saas_invoices`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `saas_payments`
--
ALTER TABLE `saas_payments`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `saas_plans`
--
ALTER TABLE `saas_plans`
  MODIFY `id` tinyint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `saas_promo_codes`
--
ALTER TABLE `saas_promo_codes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `saas_registrations`
--
ALTER TABLE `saas_registrations`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `saas_subscriptions`
--
ALTER TABLE `saas_subscriptions`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `sale_orders`
--
ALTER TABLE `sale_orders`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `staff_payroll`
--
ALTER TABLE `staff_payroll`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `staff_salary_settings`
--
ALTER TABLE `staff_salary_settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `staff_telegram_links`
--
ALTER TABLE `staff_telegram_links`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `support_messages`
--
ALTER TABLE `support_messages`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `support_telegram_messages`
--
ALTER TABLE `support_telegram_messages`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `support_tickets`
--
ALTER TABLE `support_tickets`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `sys_clubs`
--
ALTER TABLE `sys_clubs`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `sys_login_log`
--
ALTER TABLE `sys_login_log`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `sys_roles`
--
ALTER TABLE `sys_roles`
  MODIFY `id` tinyint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `sys_users`
--
ALTER TABLE `sys_users`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `sys_user_clubs`
--
ALTER TABLE `sys_user_clubs`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `tariffs`
--
ALTER TABLE `tariffs`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `telegram_notifications_log`
--
ALTER TABLE `telegram_notifications_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `trainer_earnings`
--
ALTER TABLE `trainer_earnings`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `trainer_ledger`
--
ALTER TABLE `trainer_ledger`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `trainer_payouts`
--
ALTER TABLE `trainer_payouts`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `trainer_rent`
--
ALTER TABLE `trainer_rent`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблиці `visits`
--
ALTER TABLE `visits`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Обмеження зовнішнього ключа збережених таблиць
--

--
-- Обмеження зовнішнього ключа таблиці `certificates`
--
ALTER TABLE `certificates`
  ADD CONSTRAINT `fk_cert_club` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE CASCADE;

--
-- Обмеження зовнішнього ключа таблиці `certificate_sales`
--
ALTER TABLE `certificate_sales`
  ADD CONSTRAINT `fk_certsale_cert` FOREIGN KEY (`certificate_id`) REFERENCES `certificates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_certsale_client` FOREIGN KEY (`redeemed_client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_certsale_club` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_certsale_deposit` FOREIGN KEY (`deposit_id`) REFERENCES `client_deposits` (`id`) ON DELETE SET NULL;

--
-- Обмеження зовнішнього ключа таблиці `clients`
--
ALTER TABLE `clients`
  ADD CONSTRAINT `fk_clients_club` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE RESTRICT;

--
-- Обмеження зовнішнього ключа таблиці `client_deposits`
--
ALTER TABLE `client_deposits`
  ADD CONSTRAINT `fk_cd_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE RESTRICT;

--
-- Обмеження зовнішнього ключа таблиці `client_invoices`
--
ALTER TABLE `client_invoices`
  ADD CONSTRAINT `fk_ci_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_ci_club` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_ci_tariff` FOREIGN KEY (`tariff_id`) REFERENCES `tariffs` (`id`) ON DELETE SET NULL;

--
-- Обмеження зовнішнього ключа таблиці `client_telegram_links`
--
ALTER TABLE `client_telegram_links`
  ADD CONSTRAINT `fk_ctl_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ctl_club` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE CASCADE;

--
-- Обмеження зовнішнього ключа таблиці `club_acquiring_settings`
--
ALTER TABLE `club_acquiring_settings`
  ADD CONSTRAINT `fk_acquiring_settings_club` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE CASCADE;

--
-- Обмеження зовнішнього ключа таблиці `club_deletion_requests`
--
ALTER TABLE `club_deletion_requests`
  ADD CONSTRAINT `fk_cdr_club` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cdr_user` FOREIGN KEY (`requested_by`) REFERENCES `sys_users` (`id`) ON DELETE CASCADE;

--
-- Обмеження зовнішнього ключа таблиці `club_equipment`
--
ALTER TABLE `club_equipment`
  ADD CONSTRAINT `fk_equipment_club` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE CASCADE;

--
-- Обмеження зовнішнього ключа таблиці `club_menu_settings`
--
ALTER TABLE `club_menu_settings`
  ADD CONSTRAINT `club_menu_settings_ibfk_1` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE CASCADE;

--
-- Обмеження зовнішнього ключа таблиці `club_payments`
--
ALTER TABLE `club_payments`
  ADD CONSTRAINT `fk_cp_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_cp_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `client_invoices` (`id`) ON DELETE SET NULL;

--
-- Обмеження зовнішнього ключа таблиці `club_prro_payment_methods`
--
ALTER TABLE `club_prro_payment_methods`
  ADD CONSTRAINT `fk_prro_methods_club` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE CASCADE;

--
-- Обмеження зовнішнього ключа таблиці `club_prro_settings`
--
ALTER TABLE `club_prro_settings`
  ADD CONSTRAINT `fk_prro_settings_club` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE CASCADE;

--
-- Обмеження зовнішнього ключа таблиці `club_role_permissions`
--
ALTER TABLE `club_role_permissions`
  ADD CONSTRAINT `club_role_permissions_ibfk_1` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `club_role_permissions_ibfk_2` FOREIGN KEY (`permission_slug`) REFERENCES `sys_permissions` (`slug`) ON DELETE CASCADE;

--
-- Обмеження зовнішнього ключа таблиці `club_trainers`
--
ALTER TABLE `club_trainers`
  ADD CONSTRAINT `fk_ct_club` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ct_user` FOREIGN KEY (`user_id`) REFERENCES `sys_users` (`id`) ON DELETE CASCADE;

--
-- Обмеження зовнішнього ключа таблиці `group_sessions`
--
ALTER TABLE `group_sessions`
  ADD CONSTRAINT `fk_group_sessions_club` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_group_sessions_trainer` FOREIGN KEY (`trainer_id`) REFERENCES `club_trainers` (`id`) ON DELETE RESTRICT;

--
-- Обмеження зовнішнього ключа таблиці `group_session_clients`
--
ALTER TABLE `group_session_clients`
  ADD CONSTRAINT `fk_group_session_clients_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_group_session_clients_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `client_invoices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_group_session_clients_session` FOREIGN KEY (`session_id`) REFERENCES `group_sessions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_group_session_clients_visit` FOREIGN KEY (`visit_id`) REFERENCES `visits` (`id`) ON DELETE SET NULL;

--
-- Обмеження зовнішнього ключа таблиці `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_prod_club` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE CASCADE;

--
-- Обмеження зовнішнього ключа таблиці `product_arrivals`
--
ALTER TABLE `product_arrivals`
  ADD CONSTRAINT `fk_arr_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT;

--
-- Обмеження зовнішнього ключа таблиці `product_sales`
--
ALTER TABLE `product_sales`
  ADD CONSTRAINT `fk_ps_order` FOREIGN KEY (`order_id`) REFERENCES `sale_orders` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sale_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sale_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL;

--
-- Обмеження зовнішнього ключа таблиці `saas_invoices`
--
ALTER TABLE `saas_invoices`
  ADD CONSTRAINT `fk_inv_club` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_inv_sub` FOREIGN KEY (`subscription_id`) REFERENCES `saas_subscriptions` (`id`) ON DELETE RESTRICT;

--
-- Обмеження зовнішнього ключа таблиці `saas_payments`
--
ALTER TABLE `saas_payments`
  ADD CONSTRAINT `fk_pay_club` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_pay_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `saas_invoices` (`id`) ON DELETE RESTRICT;

--
-- Обмеження зовнішнього ключа таблиці `saas_promo_codes`
--
ALTER TABLE `saas_promo_codes`
  ADD CONSTRAINT `fk_promo_used_club` FOREIGN KEY (`used_club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE SET NULL;

--
-- Обмеження зовнішнього ключа таблиці `saas_subscriptions`
--
ALTER TABLE `saas_subscriptions`
  ADD CONSTRAINT `fk_sub_club` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sub_plan` FOREIGN KEY (`plan_id`) REFERENCES `saas_plans` (`id`);

--
-- Обмеження зовнішнього ключа таблиці `sale_orders`
--
ALTER TABLE `sale_orders`
  ADD CONSTRAINT `fk_so_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_so_club` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE CASCADE;

--
-- Обмеження зовнішнього ключа таблиці `staff_telegram_links`
--
ALTER TABLE `staff_telegram_links`
  ADD CONSTRAINT `fk_stl_user` FOREIGN KEY (`user_id`) REFERENCES `sys_users` (`id`) ON DELETE CASCADE;

--
-- Обмеження зовнішнього ключа таблиці `support_messages`
--
ALTER TABLE `support_messages`
  ADD CONSTRAINT `fk_sm_sender` FOREIGN KEY (`sender_user_id`) REFERENCES `sys_users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sm_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE;

--
-- Обмеження зовнішнього ключа таблиці `support_telegram_messages`
--
ALTER TABLE `support_telegram_messages`
  ADD CONSTRAINT `fk_stm_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE;

--
-- Обмеження зовнішнього ключа таблиці `support_tickets`
--
ALTER TABLE `support_tickets`
  ADD CONSTRAINT `fk_st_club` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_st_creator` FOREIGN KEY (`created_by`) REFERENCES `sys_users` (`id`) ON DELETE CASCADE;

--
-- Обмеження зовнішнього ключа таблиці `sys_clubs`
--
ALTER TABLE `sys_clubs`
  ADD CONSTRAINT `fk_clubs_owner` FOREIGN KEY (`owner_id`) REFERENCES `sys_users` (`id`) ON DELETE RESTRICT;

--
-- Обмеження зовнішнього ключа таблиці `sys_role_permissions`
--
ALTER TABLE `sys_role_permissions`
  ADD CONSTRAINT `sys_role_permissions_ibfk_1` FOREIGN KEY (`permission_slug`) REFERENCES `sys_permissions` (`slug`) ON DELETE CASCADE;

--
-- Обмеження зовнішнього ключа таблиці `sys_sessions`
--
ALTER TABLE `sys_sessions`
  ADD CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `sys_users` (`id`) ON DELETE CASCADE;

--
-- Обмеження зовнішнього ключа таблиці `sys_users`
--
ALTER TABLE `sys_users`
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`global_role_id`) REFERENCES `sys_roles` (`id`) ON DELETE SET NULL;

--
-- Обмеження зовнішнього ключа таблиці `sys_user_clubs`
--
ALTER TABLE `sys_user_clubs`
  ADD CONSTRAINT `fk_uc_club` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_uc_role` FOREIGN KEY (`role_id`) REFERENCES `sys_roles` (`id`),
  ADD CONSTRAINT `fk_uc_user` FOREIGN KEY (`user_id`) REFERENCES `sys_users` (`id`) ON DELETE CASCADE;

--
-- Обмеження зовнішнього ключа таблиці `tariffs`
--
ALTER TABLE `tariffs`
  ADD CONSTRAINT `fk_tariffs_club` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE CASCADE;

--
-- Обмеження зовнішнього ключа таблиці `telegram_notifications_log`
--
ALTER TABLE `telegram_notifications_log`
  ADD CONSTRAINT `fk_tnl_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_tnl_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `client_invoices` (`id`) ON DELETE CASCADE;

--
-- Обмеження зовнішнього ключа таблиці `trainer_earnings`
--
ALTER TABLE `trainer_earnings`
  ADD CONSTRAINT `fk_te_club` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_te_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `client_invoices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_te_trainer` FOREIGN KEY (`trainer_id`) REFERENCES `club_trainers` (`id`) ON DELETE CASCADE;

--
-- Обмеження зовнішнього ключа таблиці `trainer_rent`
--
ALTER TABLE `trainer_rent`
  ADD CONSTRAINT `fk_tr_club` FOREIGN KEY (`club_id`) REFERENCES `sys_clubs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_tr_trainer` FOREIGN KEY (`trainer_id`) REFERENCES `club_trainers` (`id`) ON DELETE CASCADE;

--
-- Обмеження зовнішнього ключа таблиці `visits`
--
ALTER TABLE `visits`
  ADD CONSTRAINT `fk_visits_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_visits_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `client_invoices` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
