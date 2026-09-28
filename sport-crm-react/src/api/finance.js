import { api } from './client';

export const addDeposit = (payload) => api('add_deposit', payload, 'finance');
export const updateDeposit = (payload) => api('update_deposit', payload, 'finance');
export const deleteDeposit = (id) => api('delete_deposit', { id }, 'finance');

export const getDashboard = (period) => api('get_dashboard', { period }, 'finance');
export const getDashboardTrend = (period) => api('get_dashboard_trend', { period }, 'finance');

export const getFinanceSummary = (params) => api('get_summary', params, 'finance');
export const getFinanceIncome = (params) => api('get_income', params, 'finance');
export const getFinanceExpenses = (params) => api('get_expenses', params, 'finance');
export const addFinanceExpense = (payload) => api('add_expense', payload, 'finance');
export const updateFinanceExpense = (payload) => api('update_expense', payload, 'finance');
export const deleteFinanceExpense = (id) => api('delete_expense', { id }, 'finance');
export const getFinanceDeposits = (params) => api('get_deposits', params, 'finance');
export const getExpenseCategories = () => api('get_expense_cats', {}, 'finance');
