import { api } from './client';

export const getCategories = () => api('get_categories', {}, 'products');
export const getProducts = (params) => api('get_list', params, 'products');
export const getProduct = (id) => api('get_one', { id }, 'products');
export const createProduct = (payload) => api('create', payload, 'products');
export const updateProduct = (payload) => api('update', payload, 'products');
export const toggleProductActive = (id) => api('toggle_active', { id }, 'products');
export const addArrival = (payload) => api('add_arrival', payload, 'products');
export const getArrivalsLog = (params) => api('get_arrivals', params, 'products');
export const getProductSalesLog = (params) => api('get_sales', params, 'products');
