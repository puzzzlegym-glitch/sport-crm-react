import { api } from './client';

export const getSaleOrders = (params) => api('get_orders', params, 'sales');
export const getSaleOrder = (id) => api('get_order', { id }, 'sales');
export const createSaleOrder = (payload) => api('create_order', payload, 'sales');
export const returnSaleOrder = (payload) => api('return_order', payload, 'sales');
export const confirmSaleReturn = (id) => api('confirm_return', { id }, 'sales');
export const cancelSaleReturn = (id, reason) => api('cancel_return', { id, reason }, 'sales');
export const confirmCancelSaleReturn = (id) => api('confirm_cancel_return', { id }, 'sales');
export const updateSaleItem = (payload) => api('update_item', payload, 'sales');
