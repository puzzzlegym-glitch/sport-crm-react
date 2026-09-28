import { api } from './client';

export const getTariffs = (archived = false) => api('get_list', { archived: archived ? 1 : 0 }, 'tariffs');
export const createTariff = (payload) => api('create', payload, 'tariffs');
export const updateTariff = (payload) => api('update', payload, 'tariffs');
export const archiveTariff = (id) => api('archive', { id }, 'tariffs');
