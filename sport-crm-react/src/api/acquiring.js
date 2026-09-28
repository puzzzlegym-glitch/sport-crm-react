import { api } from './client';

export const getAcquiringSettings = () => api('get_settings', {}, 'acquiring');
export const saveAcquiringSettings = (payload) => api('save_settings', payload, 'acquiring');
export const testAcquiringConnection = () => api('test_connection', {}, 'acquiring');
