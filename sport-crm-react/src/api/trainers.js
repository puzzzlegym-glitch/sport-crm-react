import { api } from './client';

export const getTrainers = () => api('get_list', {}, 'trainers');

export const getTrainer = (trainerId) => api('get_one', { trainer_id: trainerId }, 'trainers');
export const saveTrainerProfile = (payload) => api('save', payload, 'trainers');
export const toggleTrainerActive = (trainerId) => api('toggle', { trainer_id: trainerId }, 'trainers');
export const getTrainerEarnings = (trainerId, status) => api('get_earnings', { trainer_id: trainerId, status }, 'trainers');
export const payTrainerEarning = (earningId, amount, paymentMethod) => api('pay_earning', { earning_id: earningId, amount, payment_method: paymentMethod }, 'trainers');
export const getTrainerRent = (trainerId) => api('get_rent', { trainer_id: trainerId }, 'trainers');
export const saveTrainerRent = (payload) => api('save_rent', payload, 'trainers');
export const deleteTrainerRent = (rentId) => api('delete_rent', { rent_id: rentId }, 'trainers');
export const getTrainerSummary = (trainerId) => api('get_summary', { trainer_id: trainerId }, 'trainers');
export const getTrainerUsers = () => api('get_trainer_users', {}, 'trainers');

export const getMyTrainerProfile = () => api('my_profile', {}, 'trainers');
export const getMyTrainerEarnings = () => api('my_earnings', {}, 'trainers');
export const getMyTrainerSummary = () => api('my_summary', {}, 'trainers');
