import { api } from './client';

export const getCertificates = (params) => api('get_certificates', params, 'certificates');
export const sellCertificate = (payload) => api('sell_certificate', payload, 'certificates');
export const redeemCertificate = (payload) => api('redeem_certificate', payload, 'certificates');
export const cancelCertificateSale = (payload) => api('cancel_certificate_sale', payload, 'certificates');
export const cancelCertificateRedemption = (payload) => api('cancel_redemption', payload, 'certificates');
export const updateCertificate = (payload) => api('update_certificate', payload, 'certificates');
export const deleteCertificate = (id) => api('delete_certificate', { id }, 'certificates');
