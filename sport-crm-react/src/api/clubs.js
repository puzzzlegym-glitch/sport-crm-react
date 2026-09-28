import { api } from './client';

export const getClubStats = () => api('get_stats', {}, 'clubs');
export const getClubs = (params) => api('get_list', params, 'clubs');
export const getClub = (clubId) => api('get_one', { club_id: clubId }, 'clubs');
export const toggleClubActive = (clubId) => api('toggle_active', { club_id: clubId }, 'clubs');
export const extendClubTrial = (payload) => api('extend_trial', payload, 'clubs');
export const cancelClubTrial = (clubId) => api('cancel_trial', { club_id: clubId }, 'clubs');
export const recordClubPayment = (payload) => api('record_payment', payload, 'clubs');
export const getClubPayments = (clubId) => api('get_payments', { club_id: clubId }, 'clubs');
export const requestDeleteClub = (clubId, reason) => api('request_delete', { club_id: clubId, reason }, 'clubs');
export const confirmDeleteClub = (clubId, code) => api('confirm_delete', { club_id: clubId, code }, 'clubs');
