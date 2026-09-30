import { api } from './client';

// Налаштування запису на тренування (зали, типи занять, розклад, графік тренерів, правила)
export const getBookingSetup = () => api('get_setup', {}, 'booking');
export const saveBookingSettings = (settings) => api('save_settings', { settings }, 'booking');
export const saveRoom = (payload) => api('room_save', payload, 'booking');
export const saveClassType = (payload) => api('type_save', payload, 'booking');
export const saveScheduleTemplate = (payload) => api('template_save', payload, 'booking');
export const deleteScheduleTemplate = (id) => api('template_delete', { id }, 'booking');
export const saveTrainerAvailability = (trainerId, rows) => api('availability_save', { trainer_id: trainerId, rows }, 'booking');
export const generateSchedule = () => api('generate', {}, 'booking');
export const getTrainerSlots = (payload) => api('slots', payload, 'booking');
export const bookPersonal = (payload) => api('book_personal', payload, 'booking');
