import { api } from './client';

export const authCheck   = () => api('check', {}, 'auth');
export const login       = (email, password) => api('login', { email, password }, 'auth');
export const logout      = () => api('logout', {}, 'auth');
export const switchClub  = (club_id) => api('switch_club', { club_id }, 'auth');
export const exitClub    = () => api('exit_club', {}, 'auth');
export const myClubs     = () => api('my_clubs', {}, 'auth');
export const setViewRole = (role_slug) => api('set_view_role', { role_slug }, 'auth');
export const forgotPassword = (email) => api('forgot_password', { email }, 'auth');
export const resetPassword  = (token, password) => api('reset_password', { token, password }, 'auth');
