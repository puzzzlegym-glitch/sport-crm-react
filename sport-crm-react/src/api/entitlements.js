import { api } from './client';

/**
 * Централізований entitlement-стан клубу: план, usage, ліміти,
 * похідні permissions (canAddClient/canAddMember) і рекомендований план.
 * Джерело істини — Billing::getEntitlements() на бекенді, тут лише читаємо.
 */
export const getEntitlements = () => api('get_entitlements', {}, 'billing');
