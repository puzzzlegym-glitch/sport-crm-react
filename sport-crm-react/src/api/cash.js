import { api } from './client';

export const getCashSummary = (params) => api('get_summary', params, 'cash');
export const getCashList = (params) => api('get_list', params, 'cash');
export const addCashExpense = (payload) => api('add_expense', payload, 'cash');
export const addEncashment = (payload) => api('encashment', payload, 'cash');
export const refillFromSafe = (payload) => api('refill_from_safe', payload, 'cash');
export const adjustCashBalance = (payload) => api('adjust_balance', payload, 'cash');
export const deleteCashRow = (id) => api('delete', { id }, 'cash');
export const getCashShift = () => api('get_shift', {}, 'cash');
export const openCashShift = (notes) => api('open_shift', { notes }, 'cash');
export const closeCashShift = (shiftId, notes, countedAmount) => api('close_shift', { shift_id: shiftId, notes, counted_amount: countedAmount }, 'cash');
