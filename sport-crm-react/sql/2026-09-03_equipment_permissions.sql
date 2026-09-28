-- ============================================================================
-- Права доступу для модуля "Обладнання".
-- Використовується: api/equipment_api.php, src/pages/EquipmentPage.jsx
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-09-03
-- ============================================================================

INSERT INTO sys_permissions (slug, label, category, sort_order) VALUES
    ('equipment.view',   'Перегляд обладнання',   'equipment', 10),
    ('equipment.manage', 'Керування обладнанням', 'equipment', 20)
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category), sort_order = VALUES(sort_order);

-- view — усьому персоналу (тренеру корисно бачити, що зламано), manage — тільки manager+owner
INSERT IGNORE INTO sys_role_permissions (role_id, permission_slug)
SELECT r.id, p.slug
FROM sys_roles r
JOIN sys_permissions p ON p.slug = 'equipment.view'
WHERE r.slug IN ('trainer', 'manager', 'owner');

INSERT IGNORE INTO sys_role_permissions (role_id, permission_slug)
SELECT r.id, p.slug
FROM sys_roles r
JOIN sys_permissions p ON p.slug = 'equipment.manage'
WHERE r.slug IN ('manager', 'owner');
