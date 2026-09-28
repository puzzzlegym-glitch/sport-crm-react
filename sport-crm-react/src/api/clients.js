import { api } from './client';

export const getClients = (params) => api('get_list', params, 'clients');
export const getClient = (id) => api('get_one', { id }, 'clients');
export const getClientActivity = (id) => api('get_activity', { id }, 'clients');
export const createClient = (payload) => api('create', payload, 'clients');
export const updateClient = (payload) => api('update', payload, 'clients');
export const importClients = (rows) => api('import', { rows }, 'clients');
export const searchClients = (q) => api('search', { q }, 'clients');
