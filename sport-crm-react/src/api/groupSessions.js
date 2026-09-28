import { api } from './client';

export const getGroupSessions = (params) => api('get_list', params, 'groupSessions');
export const getGroupSessionTrainers = () => api('get_trainers', {}, 'groupSessions');
export const getGroupSession = (sessionId) => api('get_one', { session_id: sessionId }, 'groupSessions');
export const createGroupSession = (payload) => api('create', payload, 'groupSessions');
export const updateGroupSession = (payload) => api('update', payload, 'groupSessions');
export const cancelGroupSession = (sessionId) => api('cancel', { session_id: sessionId }, 'groupSessions');
export const addGroupSessionClient = (sessionId, clientId) => api('add_client', { session_id: sessionId, client_id: clientId }, 'groupSessions');
export const removeGroupSessionClient = (rosterId) => api('remove_client', { roster_id: rosterId }, 'groupSessions');
export const markGroupSessionAttendance = (rosterId, status) => api('mark_attendance', { roster_id: rosterId, status }, 'groupSessions');
export const completeGroupSession = (sessionId) => api('complete_session', { session_id: sessionId }, 'groupSessions');
export const getMyGroupSchedule = () => api('my_schedule', {}, 'groupSessions');
