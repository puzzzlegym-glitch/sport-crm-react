import { api } from './client';

export const getArrivalsList = (params) => api('get_list', params, 'arrivals');
export const confirmArrival = (payload) => api('confirm', payload, 'arrivals');
export const updateArrivalEntry = (payload) => api('update', payload, 'arrivals');
export const deleteArrivalEntry = (id) => api('delete', { id }, 'arrivals');
