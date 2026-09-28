import { api } from './client';

export const sellProduct = (payload) => api('sell', payload, 'sell');
