import { api } from './client';

export const getInvoices = (params) => api('get_list', params, 'invoices');
export const getInvoice = (id) => api('get_one', { id }, 'invoices');
export const getInvoiceTariffs = () => api('get_tariffs', {}, 'invoices');
export const createInvoice = (payload) => api('create', payload, 'invoices');
export const updateInvoice = (payload) => api('update', payload, 'invoices');
export const addInvoicePayment = (payload) => api('add_payment', payload, 'invoices');
export const freezeInvoice = (payload) => api('freeze', payload, 'invoices');
export const cancelFreezeInvoice = (id) => api('cancel_freeze', { id }, 'invoices');
export const updateFreezeDays = (id, days) => api('update_freeze_days', { id, days }, 'invoices');
export const cancelInvoice = (id, reason = '') => api('cancel', { id, reason }, 'invoices');
export const restoreInvoice = (id) => api('restore', { id }, 'invoices');
