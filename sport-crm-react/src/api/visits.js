import { api } from './client';

export const checkIn = (payload) => api('check_in', payload, 'visits');
export const scanVisit = (query, force) => api('scan', { query, force: force ? 1 : 0 }, 'visits');
export const getVisitStats = () => api('get_stats', {}, 'visits');
export const getVisitsList = (params) => api('get_list', params, 'visits');
export const deleteVisitEntry = (id, confirmReversal) => api('delete', { id, confirm_reversal: confirmReversal ? 1 : 0 }, 'visits');
export const getActiveInvoiceForClient = (clientId) => api('get_active_invoice', { client_id: clientId }, 'visits');
export const getCheckinTrainers = () => api('get_checkin_trainers', {}, 'visits');
