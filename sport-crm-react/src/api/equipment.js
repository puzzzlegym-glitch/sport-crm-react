import { api } from './client';

export const getEquipmentList = () => api('get_list', {}, 'equipment');
export const createEquipment = (payload) => api('create', payload, 'equipment');
export const updateEquipment = (payload) => api('update', payload, 'equipment');
export const deleteEquipment = (id) => api('delete', { id }, 'equipment');
