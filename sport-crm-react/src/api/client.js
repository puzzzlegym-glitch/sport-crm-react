/**
 * api/client.js — заміна api() з helpers.js
 * Той самий контракт з backend, credentials: 'include'
 */

import { isDemoActive, demoApi } from '../demo';

const API = {
  auth:      '/api/auth_api.php',
  clients:   '/api/clients_api.php',
  invoices:  '/api/invoices_api.php',
  products:  '/api/products_api.php',
  finance:   '/api/finance_api.php',
  users:     '/api/users_api.php',
  clubs:     '/api/clubs_api.php',
  billing:   '/api/billing_api.php',
  register:  '/api/register_api.php',
  settings:  '/api/settings_api.php',
  trainers:  '/api/trainers_api.php',
  visits:    '/api/visits_api.php',
  saas:      '/api/saas_api.php',
  tariffs:   '/api/tariffs_api.php',
  payments:  '/api/payments_api.php',
  arrivals:  '/api/arrivals_api.php',
  sales:     '/api/sales_api.php',
  cash:      '/api/cash_api.php',
  payroll:   '/api/payroll_api.php',
  sell:      '/api/sell_api.php',
  permissions: '/api/permissions_api.php',
  telegram:    '/api/telegram_api.php',
  support:     '/api/support_api.php',
  client_service: '/api/client_service_api.php',
  certificates:   '/api/certificates_api.php',
  prro:           '/api/prro_api.php',
  acquiring:      '/api/acquiring_api.php',
  groupSessions:  '/api/group_sessions_api.php',
  booking:        '/api/booking_api.php',
  equipment:      '/api/equipment_api.php',
};

const isDev = ['localhost', '127.0.0.1'].includes(window.location.hostname);

/**
 * @param {string} action
 * @param {object} body
 * @param {keyof API} apiType
 */
export async function api(action, body = {}, apiType = 'auth') {
  if (isDemoActive()) return demoApi(action, body, apiType);

  const baseUrl = API[apiType] ?? API.auth;

  try {
    const response = await fetch(`${baseUrl}?action=${action}`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });

    if (response.status === 401) {
      if (isDev) console.warn('[API] Сесія закінчилась');
      window.location.href = '/login';
      return { success: false };
    }

    const text = await response.text();
    try {
      const data = JSON.parse(text);
      if (isDev && !data.success && data.error) {
        console.warn(`[API] ${apiType}:${action} →`, data.error);
      }
      return data;
    } catch {
      console.error(`[API] Не-JSON від ${action}:`, text.substring(0, 300));
      return { success: false, error: 'Невірна відповідь сервера' };
    }
  } catch (err) {
    console.error(`[API] Мережева помилка (${action}):`, err.message);
    return { success: false, error: 'Немає підключення до сервера' };
  }
}
