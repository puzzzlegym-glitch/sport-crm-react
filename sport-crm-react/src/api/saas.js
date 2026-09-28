import { api } from './client';

export const getPlans = () => api('get_plans', {}, 'saas');
export const savePlan = (payload) => api('update_plan', payload, 'saas');
export const getSubscriptions = (params) => api('get_subscriptions', params, 'saas');
export const updateSubscription = (payload) => api('update_subscription', payload, 'saas');
export const getSaasInvoices = (params) => api('get_invoices', params, 'saas');
export const updateSaasInvoice = (payload) => api('update_invoice', payload, 'saas');
export const deleteSaasInvoice = (id) => api('delete_invoice', { id }, 'saas');
export const getSaasPayments = (params) => api('get_payments', params, 'saas');
export const updateSaasPayment = (payload) => api('update_payment', payload, 'saas');
export const deleteSaasPayment = (id) => api('delete_payment', { id }, 'saas');
export const getPromoCodes = () => api('get_promo_codes', {}, 'saas');
export const savePromoCode = (payload) => api('save_promo_code', payload, 'saas');
export const resetPromoCode = (id) => api('reset_promo_code', { id }, 'saas');
export const deletePromoCode = (id) => api('delete_promo_code', { id }, 'saas');
