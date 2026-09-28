import { api } from './client';

export const getBillingPlans = () => api('get_plans', {}, 'billing');
export const getMyBilling = () => api('get_my_billing', {}, 'billing');
export const activateTrial = () => api('activate_trial', {}, 'billing');
export const switchToFree = (planId) => api('switch_to_free', planId ? { plan_id: planId } : {}, 'billing');
export const createPayment = (payload) => api('create_payment', payload, 'billing');
export const checkPromoCode = (payload) => api('check_promo', payload, 'billing');
