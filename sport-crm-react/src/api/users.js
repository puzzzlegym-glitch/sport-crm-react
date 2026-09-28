import { api } from './client';

export const getUsers = (showHidden = false) => api('get_list', { show_hidden: showHidden }, 'users');
export const getRoles = () => api('get_roles', {}, 'users');
export const inviteUser = (payload) => api('invite', payload, 'users');
export const updateUserRole = (payload) => api('update_role', payload, 'users');
export const toggleUserAccess = (userId, enabled) => api('toggle_access', { user_id: userId, enabled }, 'users');
export const removeUser = (userId) => api('remove', { user_id: userId }, 'users');
// Бекенд читає confirm_password (не new_password2, як помилково слав оригінальний фронтенд —
// це робило зміну пароля непрацюючою: завжди "Паролі не збігаються").
export const changePassword = (currentPassword, newPassword) =>
  api('change_password', { current_password: currentPassword, new_password: newPassword, confirm_password: newPassword }, 'users');
