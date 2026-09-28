/**
 * helpers.js — Спільні утиліти для всіх сторінок
 * Підключати ПЕРШИМ скриптом на кожній сторінці
 */

// ── Режим розробки ───────────────────────────────────────────
const _isDev = ['localhost', '127.0.0.1'].includes(location.hostname);

/** Виводить у консоль лише в dev-режимі */
function debug(...args) {
  if (_isDev) console.debug(...args);
}

// ── API endpoints ────────────────────────────────────────────
const API = {
  auth:     '/api/auth_api.php',
  clients:  '/api/clients_api.php',
  invoices: '/api/invoices_api.php',
  products: '/api/products_api.php',
  finance:  '/api/finance_api.php',
  users:    '/api/users_api.php',
  clubs:    '/api/clubs_api.php',
  billing:  '/api/billing_api.php',
  register: '/api/register_api.php',
  settings: '/api/settings_api.php',
  trainers: '/api/trainers_api.php',
  visits:   '/api/visits_api.php',
  saas:     '/api/saas_api.php',
  tariffs:  '/api/tariffs_api.php',
  payments:  '/api/payments_api.php',
  arrivals:  '/api/arrivals_api.php',
};

/**
 * Універсальна функція для запитів до API
 *
 * @param {string} action  — назва дії
 * @param {object} body    — дані (POST)
 * @param {string} apiType — ключ з об'єкту API вище
 */
async function api(action, body = {}, apiType = 'auth') {
  const baseUrl = API[apiType] ?? API.auth;

  debug(`[API] → ${apiType}:${action}`, Object.keys(body).length ? body : '');

  try {
    const response = await fetch(`${baseUrl}?action=${action}`, {
      method:      'POST',
      credentials: 'include',
      headers:     { 'Content-Type': 'application/json' },
      body:        JSON.stringify(body),
    });

    const text = await response.text();

    if (response.status === 401) {
      console.warn('[API] Сесія закінчилась — редирект на /login');
      window.location.href = '/login';
      return { success: false };
    }

    try {
      const data = JSON.parse(text);
      if (!data.success && data.error) {
        console.warn(`[API] ← ${action}: ${data.error}`);
      } else {
        debug(`[API] ← ${action}:`, data);
      }
      return data;
    } catch {
      console.error(`[API] Не-JSON від ${action}:`, text.substring(0, 400));
      return { success: false, error: 'Невірна відповідь сервера' };
    }

  } catch (err) {
    console.error(`[API] Мережева помилка для ${action}:`, err.message);
    return { success: false, error: 'Немає підключення до сервера' };
  }
}

// ── Форматування ─────────────────────────────────────────────

function formatMoney(val) {
  const n = parseFloat(val) || 0;
  return n.toLocaleString('uk-UA', { minimumFractionDigits: 0 }) + ' грн';
}

function formatDate(d) {
  if (!d) return '—';
  try {
    return new Date(d).toLocaleDateString('uk-UA');
  } catch {
    return d;
  }
}

function formatRelativeDate(d) {
  if (!d) return '—';
  const date = new Date(d);
  const now  = new Date();
  const days = Math.floor((now - date) / 86400000);
  if (days === 0) return 'сьогодні';
  if (days === 1) return 'вчора';
  if (days < 7)  return `${days} дн. тому`;
  if (days < 30) return `${Math.floor(days/7)} тиж. тому`;
  return formatDate(d);
}

function getInitials(name) {
  return (name || '')
    .split(' ')
    .slice(0, 2)
    .map(w => w.charAt(0).toUpperCase())
    .join('');
}

// ── Сповіщення (Toast) ───────────────────────────────────────

function toast(msg, type = 'info', ms = 3000, html = false) {
  const colors = {
    success: 'var(--success)',
    error:   'var(--danger)',
    warning: 'var(--warning)',
    info:    'var(--accent)',
  };

  const el = document.createElement('div');
  el.style.cssText = `
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 9999;
    background: var(--bg-elevated);
    border: 1px solid var(--border-light);
    border-left: 3px solid ${colors[type] ?? colors.info};
    border-radius: var(--radius-sm);
    padding: 12px 18px;
    font-size: 14px;
    color: var(--text-primary);
    box-shadow: var(--shadow-md);
    max-width: 340px;
    display: flex; flex-direction: column; gap: 6px;
    animation: slideIn .2s ease;
  `;
  if (html) el.innerHTML = msg;
  else el.textContent = msg;

  if (!document.getElementById('toast-styles')) {
    const s = document.createElement('style');
    s.id = 'toast-styles';
    s.textContent = '@keyframes slideIn{from{transform:translateX(20px);opacity:0}to{transform:translateX(0);opacity:1}}';
    document.head.appendChild(s);
  }

  document.body.appendChild(el);
  setTimeout(() => el.remove(), ms);
  return el;
}

// ── Модалки ──────────────────────────────────────────────────

function openModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.add('open');
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.remove('open');
}

document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.classList.remove('open');
  }
});

// ════════════════════════════════════════════════════════════
// PWA — реєстрація Service Worker
// Автоматично запускається при завантаженні helpers.js
// ════════════════════════════════════════════════════════════
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js', { scope: '/' })
      .then(reg => {
        reg.addEventListener('updatefound', () => {
          const newWorker = reg.installing;
          newWorker?.addEventListener('statechange', () => {
            if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
              // Є оновлення — показуємо тост
              if (typeof toast === 'function') {
                toast('Доступне оновлення. Оновіть сторінку.', 'info');
              }
            }
          });
        });
      })
      .catch(() => {}); // Тихо ігноруємо якщо SW не підтримується
  });
}

// ── Блокування дій у неактивному клубі ───────────────────────
// Викликати після initPage: guardBlockedClub(ctx.isBlocked);
function guardBlockedClub(isBlocked) {
  if (!isBlocked) return;

  // На сторінці білінгу нічого не блокуємо
  if (window.location.pathname === '/billing') return;

  // Кнопки що дозволені навіть у заблокованому клубі
  // (системні, навігаційні, кнопки завантаження/фільтрації)
  const ALLOWED_IDS = new Set([
    'btn-sell', // буде сховано нижче окремо
  ]);

  // Атрибути що дозволяють кнопку явно
  const isAllowed = (el) => {
    if (el.closest('.app-sidebar'))              return true;
    if (el.closest('.app-header'))               return true;
    if (el.closest('#club-blocked-banner'))      return true;
    if (el.closest('#club-mode-banner'))         return true;
    if (el.dataset.billingOk !== undefined)      return true;
    // Дозволяємо кнопки фільтрів, пошуку, пагінації, перемикання вкладок
    if (el.classList.contains('period-btn'))     return true;
    if (el.classList.contains('page-tab-btn'))   return true;
    if (el.classList.contains('fin-tab-btn'))    return true;
    if (el.classList.contains('modal-tab-btn'))  return true;
    if (el.classList.contains('modal-close'))    return true;
    if (el.type === 'submit' && el.closest('form[data-search]')) return true;
    // Кнопки з текстом що містять лише навігаційні дії
    const txt = (el.textContent || '').trim().toLowerCase();
    if (txt === 'закрити' || txt === 'скасувати' || txt === '✕' || txt === '×') return true;
    return false;
  };

  const _block = () => {
    // Блокуємо кнопки що змінюють дані
    document.querySelectorAll('button, input[type="submit"], input[type="button"]').forEach(el => {
      if (isAllowed(el)) return;
      if (el.dataset.blockedGuard) return;
      el.dataset.blockedGuard = '1';

      // Перехоплюємо клік
      el.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopImmediatePropagation();
        _showBillingToast();
      }, true);

      el.style.opacity = '0.45';
      el.style.cursor  = 'not-allowed';
      el.title = 'Клуб неактивний — оплатіть підписку';
    });
  };

  // Запускаємо після рендеру сторінки
  setTimeout(_block, 300);
  setTimeout(_block, 1000);
  setTimeout(_block, 2500);
}

function _showBillingToast() {
  document.getElementById('_billing-toast')?.remove();
  const el = toast(
    `🔒 Клуб неактивний — дія заблокована<br>
     <a href="/billing" style="color:#f87171;font-weight:600;font-size:13px;text-decoration:none;">
       💳 Перейти до оплати підписки →
     </a>`,
    'error', 4000, true
  );
  if (el) el.id = '_billing-toast';
}
let _deferredInstallPrompt = null;
window.addEventListener('beforeinstallprompt', e => {
  e.preventDefault();
  _deferredInstallPrompt = e;
  // Показуємо кнопку встановлення якщо є
  const btn = document.getElementById('pwa-install-btn');
  if (btn) btn.style.display = 'block';
});

window.addEventListener('appinstalled', () => {
  _deferredInstallPrompt = null;
  const btn = document.getElementById('pwa-install-btn');
  if (btn) btn.style.display = 'none';
  if (typeof toast === 'function') toast('Додаток встановлено!', 'success');
});

async function installPWA() {
  if (!_deferredInstallPrompt) return;
  _deferredInstallPrompt.prompt();
  const { outcome } = await _deferredInstallPrompt.userChoice;
  _deferredInstallPrompt = null;
}
