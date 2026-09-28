import { api } from './client';

export const getPrroSettings = () => api('get_settings', {}, 'prro');
export const savePrroSettings = (payload) => api('save_settings', payload, 'prro');
export const syncPrroCashier = () => api('sync_cashier', {}, 'prro');
export const getPrroPaymentMethods = () => api('get_payment_methods', {}, 'prro');
export const savePrroPaymentMethods = (methods) => api('save_payment_methods', { methods }, 'prro');
