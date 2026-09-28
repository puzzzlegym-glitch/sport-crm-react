-- ============================================================================
-- Прибрати старий (доміграційний) чорновий набір дій sys_permissions,
-- що дублює нову послідовну матрицю з 2026-08-08_permissions_matrix.sql
-- (view/create/edit/delete на кожен модуль, sort_order 10/20/30/40).
--
-- Старий набір мав грубу деталізацію (одне "manage" на модуль,
-- sort_order 0-4) і жодним реальним функціоналом не використовується —
-- перевірено: Auth::can() у всьому бекенді викликається лише для 'access.manage'.
--
-- club_role_permissions / sys_role_permissions чиститься каскадно
-- (FK ... ON DELETE CASCADE), окремих DELETE для них не потрібно.
-- ============================================================================

DELETE FROM sys_permissions WHERE slug IN (
    'access.view', 'arrivals.manage', 'billing.view', 'cash.manage',
    'finance.delete', 'invoices.create', 'invoices.edit', 'invoices.payment',
    'products.sell', 'products.manage', 'sales.edit', 'settings.view',
    'tariffs.manage', 'trainers.own', 'users.view'
);
