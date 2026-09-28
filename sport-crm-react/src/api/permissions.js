import { api } from './client';

export const getPermissionsCatalog = () => api('get_catalog', {}, 'permissions');
export const getClubPermissionMatrix = () => api('get_club_matrix', {}, 'permissions');
export const saveClubPermissionMatrix = (rows) => api('save_club_matrix', { rows }, 'permissions');
export const getSystemPermissionDefaults = () => api('get_system_defaults', {}, 'permissions');
export const saveSystemPermissionDefaults = (rows) => api('save_system_defaults', { rows }, 'permissions');
