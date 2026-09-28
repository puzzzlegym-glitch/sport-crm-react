import { api } from './client';

export const getClientServiceStats = () => api('get_stats', {}, 'client_service');
export const getClientHubStatus = (clientId) => api('get_client_status', { client_id: clientId }, 'client_service');
