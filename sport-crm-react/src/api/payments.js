import { api } from './client';

export const getPayments = (params) => api('get_list', params, 'payments');
export const getPaymentTariffs = () => api('get_tariffs', {}, 'payments');
export const updatePayment = (payload) => api('update', payload, 'payments');
export const deletePayment = (id) => api('delete', { id }, 'payments');
export const refundPayment = (payload) => api('refund', payload, 'payments');
