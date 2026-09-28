import { api } from './client';

export const getClubSettings = () => api('get_club', {}, 'settings');
export const updateClubSettings = (payload) => api('update_club', payload, 'settings');
export const updateProfile = (payload) => api('update_profile', payload, 'settings');

export const getSysRoles = () => api('get_sys_roles', {}, 'settings');
export const updateSysRole = (payload) => api('update_sys_role', payload, 'settings');

export const getSysUsers = (params) => api('get_sys_users', params, 'settings');
export const toggleSysUser = (id) => api('toggle_sys_user', { id }, 'settings');
export const updateSysUserProfile = (payload) => api('update_sys_user_profile', payload, 'settings');
export const createSysUser = (payload) => api('create_sys_user', payload, 'settings');

export const getSysUserClubs = (clubId) => api('get_sys_user_clubs', { club_id: clubId || 0 }, 'settings');
export const addSysUserClub = (payload) => api('add_sys_user_club', payload, 'settings');
export const updateSysUserClub = (payload) => api('update_sys_user_club', payload, 'settings');
export const removeSysUserClub = (id) => api('remove_sys_user_club', { id }, 'settings');
