-- ============================================================================
-- Матриця прав доступу: "Золотий стандарт" + override клубу
-- Використовується: api/permissions_api.php, src/pages/AccessPage.jsx
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-08-08
-- ============================================================================
-- ВАЖЛИВО: виконувати одним блоком, в такому порядку (через FK-залежності):
--   1. sys_permissions          — довідник усіх дій
--   2. sys_role_permissions     — золотий стандарт (fallback для ВСІХ клубів
--                                 без власного override, у т.ч. для нових клубів)
--   3. club_role_permissions    — порожня, per-club override заповнюється з UI
-- ============================================================================

-- 1) Довідник дій -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sys_permissions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    slug        VARCHAR(64)  NOT NULL,
    label       VARCHAR(191) NOT NULL,
    category    VARCHAR(32)  NOT NULL,
    sort_order  INT          NOT NULL DEFAULT 0,
    UNIQUE KEY uq_sys_permissions_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Золотий стандарт (system-wide fallback) -----------------------------------
CREATE TABLE IF NOT EXISTS sys_role_permissions (
    role_id          INT         NOT NULL,
    permission_slug  VARCHAR(64) NOT NULL,
    PRIMARY KEY (role_id, permission_slug),
    CONSTRAINT fk_srp_role FOREIGN KEY (role_id)         REFERENCES sys_roles(id)        ON DELETE CASCADE,
    CONSTRAINT fk_srp_perm FOREIGN KEY (permission_slug) REFERENCES sys_permissions(slug) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Override конкретного клубу (sparse — лише клітинки, що відрізняються) -----
CREATE TABLE IF NOT EXISTS club_role_permissions (
    club_id          INT         NOT NULL,
    role_id          INT         NOT NULL,
    permission_slug  VARCHAR(64) NOT NULL,
    is_allowed       TINYINT(1)  NOT NULL DEFAULT 0,
    PRIMARY KEY (club_id, role_id, permission_slug),
    CONSTRAINT fk_crp_club FOREIGN KEY (club_id)         REFERENCES sys_clubs(id)         ON DELETE CASCADE,
    CONSTRAINT fk_crp_role FOREIGN KEY (role_id)         REFERENCES sys_roles(id)         ON DELETE CASCADE,
    CONSTRAINT fk_crp_perm FOREIGN KEY (permission_slug) REFERENCES sys_permissions(slug) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SEED: довідник дій (category/sort_order відповідають CATEGORY_ORDER
-- в src/pages/AccessPage.jsx)
-- ============================================================================
INSERT INTO sys_permissions (slug, label, category, sort_order) VALUES
    ('dashboard.view',   'Перегляд дашборду',                     'dashboard', 10),

    ('clients.view',     'Перегляд клієнтів',                     'clients',   10),
    ('clients.create',   'Додавання клієнтів',                    'clients',   20),
    ('clients.edit',     'Редагування клієнтів',                  'clients',   30),
    ('clients.delete',   'Видалення клієнтів',                    'clients',   40),

    ('invoices.view',    'Перегляд абонементів',                  'invoices',  10),
    ('invoices.sell',    'Продаж абонементу',                     'invoices',  20),
    ('invoices.cancel',  'Заморозка / скасування абонементу',     'invoices',  30),
    ('invoices.delete',  'Видалення / повернення абонементу',     'invoices',  40),

    ('payments.view',    'Перегляд оплат',                        'payments',  10),
    ('payments.create',  'Створення оплати',                      'payments',  20),
    ('payments.edit',    'Редагування оплати',                    'payments',  30),
    ('payments.delete',  'Видалення оплати',                      'payments',  40),

    ('tariffs.view',     'Перегляд тарифів',                      'tariffs',   10),
    ('tariffs.create',   'Створення тарифу',                      'tariffs',   20),
    ('tariffs.edit',     'Редагування тарифу',                    'tariffs',   30),
    ('tariffs.delete',   'Архівування тарифу',                    'tariffs',   40),

    ('visits.view',      'Перегляд відвідувань',                  'visits',    10),
    ('visits.checkin',   'Відмітка приходу',                      'visits',    20),
    ('visits.delete',    'Видалення запису відвідування',         'visits',    30),

    ('products.view',    'Перегляд товарів',                      'products',  10),
    ('products.edit',    'Редагування товару',                    'products',  20),
    ('products.delete',  'Архівування / видалення товару',        'products',  30),

    ('arrivals.view',    'Перегляд приходів',                     'arrivals',  10),
    ('arrivals.create',  'Додавання приходу',                     'arrivals',  20),
    ('arrivals.edit',    'Редагування приходу',                   'arrivals',  30),
    ('arrivals.delete',  'Видалення приходу',                     'arrivals',  40),

    ('sales.view',       'Перегляд продажів',                     'sales',     10),
    ('sales.create',     'Продаж товару',                         'sales',     20),
    ('sales.delete',     'Видалення продажу',                     'sales',     30),

    ('warehouse.view',   'Перегляд складу',                       'warehouse', 10),

    ('finance.view',     'Перегляд фінансів',                     'finance',   10),
    ('finance.manage',   'Витрати, депозити',                     'finance',   20),

    ('cash.view',        'Перегляд каси',                         'cash',      10),
    ('cash.record',      'Витрата / інкасація',                   'cash',      20),
    ('cash.delete',      'Видалення запису каси',                 'cash',      30),

    ('trainers.view',    'Перегляд тренерів',                     'trainers',  10),
    ('trainers.manage',  'Керування тренерами, нарахування, оренда','trainers', 20),

    ('users.manage',     'Команда: запрошення, ролі, зарплата',   'users',     10),

    ('billing.manage',   'Підписка клубу',                        'billing',   10),

    ('settings.manage',  'Налаштування клубу',                    'settings',  10),

    ('access.manage',    'Редагування прав доступу клубу',        'access',    10)
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category), sort_order = VALUES(sort_order);

-- ============================================================================
-- SEED: золотий стандарт — повторює поточну зашиту в коді поведінку
-- (trainer=30, manager=50, owner=80), щоб деплой цієї функції нічого
-- не зламав для існуючих клубів.
-- ============================================================================

-- Дії, доступні trainer + manager + owner
INSERT IGNORE INTO sys_role_permissions (role_id, permission_slug)
SELECT r.id, p.slug
FROM sys_roles r
JOIN sys_permissions p ON p.slug IN (
    'dashboard.view',
    'clients.view', 'invoices.view', 'payments.view', 'tariffs.view',
    'visits.view', 'products.view', 'arrivals.view', 'sales.view',
    'warehouse.view', 'trainers.view'
)
WHERE r.slug IN ('trainer', 'manager', 'owner');

-- Дії, доступні manager + owner
INSERT IGNORE INTO sys_role_permissions (role_id, permission_slug)
SELECT r.id, p.slug
FROM sys_roles r
JOIN sys_permissions p ON p.slug IN (
    'clients.create', 'clients.edit',
    'invoices.sell', 'invoices.cancel',
    'payments.create',
    'visits.checkin',
    'products.edit',
    'arrivals.create', 'arrivals.edit',
    'sales.create',
    'finance.view',
    'cash.view', 'cash.record',
    'trainers.manage'
)
WHERE r.slug IN ('manager', 'owner');

-- Дії, доступні лише owner
INSERT IGNORE INTO sys_role_permissions (role_id, permission_slug)
SELECT r.id, p.slug
FROM sys_roles r
JOIN sys_permissions p ON p.slug IN (
    'clients.delete',
    'invoices.delete',
    'payments.edit', 'payments.delete',
    'tariffs.create', 'tariffs.edit', 'tariffs.delete',
    'visits.delete',
    'products.delete',
    'arrivals.delete',
    'sales.delete',
    'finance.manage',
    'cash.delete',
    'users.manage',
    'billing.manage',
    'settings.manage',
    'access.manage'
)
WHERE r.slug = 'owner';

-- SuperAdmin (level >= 100) не потребує рядків тут — Auth::isSuperAdmin()
-- завжди повертає true в Auth::can() і Auth::requireClubAccess() ще до
-- звернення до цих таблиць (див. app/core/Auth.php).
