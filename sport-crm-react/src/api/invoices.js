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

// Групові абонементи
export const getInvoiceGroups = (params) => api('group_list', params, 'invoices');
export const getInvoiceGroup = (id) => api('group_get', { id }, 'invoices');
export const createInvoiceGroup = (payload) => api('group_create', payload, 'invoices');
export const updateInvoiceGroup = (payload) => api('group_update', payload, 'invoices');
export const addInvoiceGroupMembers = (payload) => api('group_add_members', payload, 'invoices');
export const removeInvoiceGroupMember = (invoiceId) => api('group_remove_member', { invoice_id: invoiceId }, 'invoices');
export const payInvoiceGroup = (payload) => api('group_pay', payload, 'invoices');
