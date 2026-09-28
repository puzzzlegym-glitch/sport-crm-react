import { api } from './client';

export const getPayrollTeam = () => api('get_team', {}, 'payroll');
export const getPayroll = (params) => api('get_payroll', params, 'payroll');
export const getPayrollSummary = (params) => api('get_summary', params, 'payroll');
export const getSalarySettings = (userId) => api('get_settings', { user_id: userId }, 'payroll');
export const getWorkedShifts = (params) => api('get_worked_shifts', params, 'payroll');
export const saveSalarySettings = (payload) => api('save_settings', payload, 'payroll');
export const calcPayrollPreview = (payload) => api('calc_preview', payload, 'payroll');
export const createPayroll = (payload) => api('create_payroll', payload, 'payroll');
export const payPayroll = (payload) => api('pay_payroll', payload, 'payroll');
