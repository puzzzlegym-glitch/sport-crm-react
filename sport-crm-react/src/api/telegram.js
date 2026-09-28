import { api } from './client';

export const generateTelegramLink = (clientId) => api('generate_link', { client_id: clientId }, 'telegram');
export const unlinkTelegram = (clientId) => api('unlink', { client_id: clientId }, 'telegram');

export const generateStaffLink = () => api('generate_staff_link', {}, 'telegram');
export const unlinkStaff = () => api('unlink_staff', {}, 'telegram');
export const broadcastClients = (text) => api('broadcast_clients', { text }, 'telegram');
export const broadcastOwners = (text) => api('broadcast_owners', { text }, 'telegram');
export const getTelegramSettings = () => api('get_settings', {}, 'telegram');
export const saveTelegramSettings = (payload) => api('save_settings', payload, 'telegram');
export const getTelegramStats = () => api('get_stats', {}, 'telegram');
