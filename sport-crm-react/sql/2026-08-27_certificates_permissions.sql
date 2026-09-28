-- ============================================================================
-- Права доступу для окремого модуля "Сертифікати" (винесено з фінансів,
-- бо фінанси недоступні всім ролям, а сертифікати мають бути окремо
-- призначувані — див. sql/2026-08-08_permissions_matrix.sql для повної схеми).
-- Використовується: api/certificates_api.php, src/pages/CertificatesPage.jsx
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-08-27
-- ============================================================================

INSERT INTO sys_permissions (slug, label, category, sort_order) VALUES
    ('certificates.view',    'Перегляд сертифікатів',              'certificates', 10),
    ('certificates.manage',  'Продаж, активація сертифікатів',     'certificates', 20),
    ('certificates.cancel',  'Скасування продажу сертифіката',     'certificates', 30)
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category), sort_order = VALUES(sort_order);

-- Золотий стандарт: view+manage → manager+owner (як finance.manage мало бути
-- для повсякденних дій — на відміну від finance.manage, яке зашите
-- owner-only і саме тому було непридатне для сертифікатів); cancel → owner.
INSERT IGNORE INTO sys_role_permissions (role_id, permission_slug)
SELECT r.id, p.slug
FROM sys_roles r
JOIN sys_permissions p ON p.slug IN ('certificates.view', 'certificates.manage')
WHERE r.slug IN ('manager', 'owner');

INSERT IGNORE INTO sys_role_permissions (role_id, permission_slug)
SELECT r.id, p.slug
FROM sys_roles r
JOIN sys_permissions p ON p.slug = 'certificates.cancel'
WHERE r.slug = 'owner';
