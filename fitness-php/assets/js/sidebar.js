/**
 * sidebar.js — Централізований сайдбар Sport CRM
 *
 * Використання на кожній сторінці:
 *   1. В <body> має бути <div class="app-layout"> з <main class="app-main">
 *   2. Підключити скрипти перед закриваючим </body>:
 *        <script src="/assets/js/helpers.js"></script>
 *        <script src="/assets/js/sidebar.js"></script>
 *   3. У своєму скрипті викликати:
 *        const ctx = await initPage({ title: 'Назва сторінки' });
 *        if (!ctx) return;
 */

// ── Конфігурація навігації (мета-дані пунктів) ──────────
// Порядок і іконки — тут. Видимість — з auth.menu (сервер).
const NAV_ITEMS = {
  // Клуб — головне
  dashboard: { href: '/dashboard', icon: '⊞',  label: 'Дашборд',      section: 'main' },
  clients:   { href: '/clients',   icon: '👤', label: 'Клієнти',       section: 'main' },
  invoices:  { href: '/invoices',  icon: '📄', label: 'Абонементи',    section: 'main' },
  payments:  { href: '/payments',  icon: '💵', label: 'Оплати',         section: 'main' },
  tariffs:   { href: '/tariffs',   icon: '🏷', label: 'Тарифи',         section: 'main' },
  visits:    { href: '/visits',    icon: '📅', label: 'Відвідування',   section: 'main' },
  products:  { href: '/products',  icon: '🛒', label: 'Товари',         section: 'main' },
  sales:     { href: '/sales',     icon: '🧾', label: 'Продажі',        section: 'main' },
  arrivals:  { href: '/arrivals',  icon: '📦', label: 'Прихід',          section: 'main' },
  finance:   { href: '/finance',   icon: '💰', label: 'Фінанси',        section: 'main' },
  cash:      { href: '/cash',      icon: '🏧', label: 'Каса',            section: 'main' },
  trainers:  { href: '/trainers',  icon: '🏋', label: 'Тренери',         section: 'main' },
  // Клуб — управління
  users:     { href: '/users',     icon: '👥', label: 'Команда',        section: 'manage' },
  billing:   { href: '/billing',   icon: '💳', label: 'Підписка',       section: 'manage' },
  settings:  { href: '/settings',  icon: '⚙',  label: 'Налаштування',   section: 'manage' },
  access:    { href: '/access',    icon: '🔑', label: 'Доступ і ролі',  section: 'manage', comingSoon: true },
  // SuperAdmin без клубу
  clubs:     { href: '/clubs',     icon: '🏙', label: 'Всі клуби',      section: 'clubs' },
  saas:      { href: '/saas',      icon: '💳', label: 'Білінг платформи',section: 'clubs' },
  saas_payments: { href: '/saas-payments', icon: '💰', label: 'Платежі',        section: 'clubs' },
};

// ── Вставка CSS для сайдбару (мобільні стилі) ───────────
(function injectSidebarStyles() {
  if (document.getElementById('_sidebar-mobile-css')) return;
  const style = document.createElement('style');
  style.id = '_sidebar-mobile-css';
  style.textContent = `
    /* Overlay для мобільного */
    .sidebar-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.5);
      z-index: 199;
      backdrop-filter: blur(2px);
    }
    .sidebar-overlay.open {
      display: block;
    }

    /* Гамбургер кнопка */
    #burger-btn {
      display: none;
      background: none;
      border: none;
      color: var(--text-secondary, #94a3b8);
      font-size: 22px;
      cursor: pointer;
      padding: 6px 10px;
      border-radius: 8px;
      line-height: 1;
      order: -1;
      flex-shrink: 0;
    }
    #burger-btn:hover {
      background: var(--bg-hover, rgba(255,255,255,0.05));
    }

    @media (max-width: 768px) {
      #burger-btn {
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
      }

      .app-sidebar {
        position: fixed !important;
        left: -260px !important;
        top: 0 !important;
        height: 100vh !important;
        z-index: 200 !important;
        transition: left 0.25s ease !important;
        width: 240px !important;
      }

      .app-sidebar.mobile-open {
        left: 0 !important;
      }

      .app-main {
        margin-left: 0 !important;
      }

      .app-header {
        padding-left: 8px !important;
      }
    }

    /* Банер режиму клубу */
    #club-mode-banner {
      position: fixed;
      bottom: 0; left: 0; right: 0;
      z-index: 500;
      background: rgba(167,139,250,.15);
      border-top: 1px solid rgba(167,139,250,.4);
      padding: 10px 20px;
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 13px;
      color: #c4b5fd;
    }

  `;
  document.head.appendChild(style);
})();

// ── Вставка структури layout ─────────────────────────────
(function injectLayout() {
  const run = () => {
    // Якщо aside вже є — нічого не вставляємо
    if (document.querySelector('.app-sidebar')) return;

    const layout = document.querySelector('.app-layout');
    if (!layout) return;

    // 1. Вставляємо aside
    const aside = document.createElement('aside');
    aside.className = 'app-sidebar';
    aside.innerHTML = `
      <a href="/dashboard" class="sidebar-logo">Sport<span>CRM</span></a>
      <nav id="sidebar-nav"></nav>
      <div class="sidebar-bottom">
        <button class="nav-item" id="sidebar-logout"
          style="color:var(--danger);width:100%;text-align:left;
                 border:none;background:none;cursor:pointer;
                 font-family:inherit;font-size:inherit">
          <span class="nav-icon">→</span> Вийти
        </button>
      </div>
    `;
    layout.insertBefore(aside, layout.firstChild);

    // 2. Вставляємо header якщо немає
    if (!document.querySelector('.app-header')) {
      const header = document.createElement('header');
      header.className = 'app-header';
      header.innerHTML = `
        <div class="header-title" id="header-title"></div>
        <div class="header-user">
          <div class="avatar" id="user-avatar">?</div>
          <span id="user-name">...</span>
        </div>
      `;
      aside.insertAdjacentElement('afterend', header);
    }

    // 3. Overlay для мобільного
    if (!document.getElementById('sidebar-overlay')) {
      const ov = document.createElement('div');
      ov.id = 'sidebar-overlay';
      ov.className = 'sidebar-overlay';
      ov.onclick = closeSidebar;
      document.body.appendChild(ov);
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();

// ── Головна функція ініціалізації сторінки ───────────────
async function initPage({ title = '', requireSuperAdmin = false } = {}) {
  const auth = await api('check', {}, 'auth');
  if (!auth.authenticated) {
    window.location.href = '/login';
    return null;
  }

  const u            = auth.user;
  const isSuperAdmin = !!(u.is_superadmin || u.global_level >= 100);
  const inClubMode   = !!auth.in_club_mode;
  const club         = auth.active_club  || null;
  const clubRole     = auth.club_role    || null;
  const permissions  = auth.permissions  || {};
  const menu         = auth.menu         || {};
  const allowedPages = auth.allowed_pages ?? null;  // null = всі
  const planIsFree   = !!auth.plan_is_free;
  const limitsExceeded = !!auth.limits_exceeded;

  // Сторінки не блокуються — лише дії в межах плану
  if (requireSuperAdmin && !isSuperAdmin) {
    window.location.href = '/dashboard';
    return null;
  }

  _fillHeader(u);

  if (title) {
    const el = document.getElementById('header-title');
    if (el) el.textContent = title;
    document.title = `${title} — Sport CRM`;
  }

  // Будуємо меню з даних сервера
  _buildSidebar(isSuperAdmin, inClubMode, club, clubRole, menu);

  const logoutBtn = document.getElementById('sidebar-logout');
  if (logoutBtn) {
    logoutBtn.onclick = doLogout;
    if (isSuperAdmin && inClubMode) {
      logoutBtn.closest('.sidebar-bottom').style.display = 'none';
    }
  }

  if (isSuperAdmin && inClubMode && club) {
    // Банер прибрано — кнопка виходу є в сайдбарі
  }

  const blockedStatuses = ['trial_expired', 'past_due', 'cancelled', 'deleted'];
  const isBlocked = club && blockedStatuses.includes(club.subscription_status);
  if (isBlocked) _showBlockedBanner(club.subscription_status);

  _initMobileMenu();

  const activeShiftId = auth.active_shift_id ?? null;
  return { auth, u, isSuperAdmin, inClubMode, club, clubRole, permissions, menu, isBlocked, activeShiftId };
}

// ── Заповнення шапки ─────────────────────────────────────
function _fillHeader(u) {
  const nameEl   = document.getElementById('user-name');
  const avatarEl = document.getElementById('user-avatar');
  if (nameEl)   nameEl.textContent   = u.full_name || '';
  if (avatarEl) avatarEl.textContent = (typeof getInitials === 'function')
    ? getInitials(u.full_name)
    : (u.full_name || '?').charAt(0).toUpperCase();
}

// ── Побудова сайдбару ────────────────────────────────────
function _buildSidebar(isSuperAdmin, inClubMode, club, clubRole, menu) {
  const nav = document.getElementById('sidebar-nav');
  if (!nav) {
    setTimeout(() => _buildSidebar(isSuperAdmin, inClubMode, club, clubRole, menu), 50);
    return;
  }

  const path = window.location.pathname;

  const renderLink = (slug) => {
    const item = NAV_ITEMS[slug];
    if (!item) return '';
    const isActive = path === item.href || path.startsWith(item.href + '/');
    return `<a href="${item.href}" class="nav-item ${isActive ? 'active' : ''}">
      <span class="nav-icon">${item.icon}</span>${item.label}
    </a>`;
  };

  const renderSection = (label, slugs) => {
    const html = slugs.map(renderLink).join('');
    if (!html.trim()) return '';
    return `<div class="sidebar-section">
      <div class="sidebar-section-label">${label}</div>
      ${html}
    </div>`;
  };

  // ── SuperAdmin без клубу ──────────────────────────────
  if (isSuperAdmin && !inClubMode) {
    nav.innerHTML =
      renderSection('Клуби',   ['clubs', 'saas', 'saas_payments']) +
      renderSection('Система', ['settings']) +
      `<div class="sidebar-section">
        <div class="sidebar-section-label">Перейти до клубу</div>
        <div style="padding:0 8px 4px">
          <input id="sa-club-search" type="text" placeholder="Пошук клубу..."
            style="width:100%;box-sizing:border-box;padding:6px 10px;
                   background:var(--bg-elevated);border:1px solid var(--border);
                   border-radius:6px;color:var(--text-primary);font-size:12px;
                   font-family:inherit;outline:none;margin-bottom:4px"
            oninput="filterSAClubs(this.value)">
          <div id="sa-club-list" style="max-height:240px;overflow-y:auto">
            <div style="font-size:12px;color:var(--text-muted);padding:4px 2px">Завантаження...</div>
          </div>
        </div>
      </div>`;
    _loadSAClubs();
    return;
  }

  // ── Клуб (owner / manager / trainer / superadmin у клубі) ──
  // menu — об'єкт {dashboard: true, clients: true, ...} з сервера
  const mainSlugs   = ['dashboard','clients','invoices','payments','tariffs','visits','products','sales','arrivals','finance','cash','trainers'];
  const manageSlugs = ['users','billing','settings'];

  // Фільтруємо по menu (якщо menu порожній — показуємо все для сумісності)
  const hasMenu = Object.keys(menu).length > 0;
  const visible  = (slug) => !hasMenu || menu[slug] === true;

  const mainHtml   = mainSlugs.filter(visible).map(renderLink).join('');
  const manageHtml = manageSlugs.filter(visible).map(renderLink).join('');

  const clubLabel = club ? `📍 ${_esc(club.name)}` : 'Головне';

  nav.innerHTML =
    `<div class="sidebar-section">
      <div class="sidebar-section-label">${clubLabel}</div>
      ${mainHtml}
    </div>` +
    (manageHtml ? `<div class="sidebar-section">
      <div class="sidebar-section-label">Управління</div>
      ${manageHtml}
    </div>` : '') +
    // Кнопка виходу + перемикач клубу для SuperAdmin
    (isSuperAdmin && inClubMode ? `<div class="sidebar-section">
      <div class="sidebar-section-label">SuperAdmin</div>
      <button class="nav-item" onclick="exitClubMode()"
        style="color:rgba(167,139,250,.9);width:100%;text-align:left;
               border:none;background:none;cursor:pointer;
               font-family:inherit;font-size:inherit">
        <span class="nav-icon">←</span> Вийти з клубу
      </button>
      <button class="nav-item" onclick="toggleSAClubPicker()"
        style="color:rgba(167,139,250,.9);width:100%;text-align:left;
               border:none;background:none;cursor:pointer;
               font-family:inherit;font-size:inherit">
        <span class="nav-icon">🔀</span> Змінити клуб
      </button>
      <div id="sa-club-picker" style="display:none;padding:4px 8px">
        <input id="sa-club-search" type="text" placeholder="Пошук клубу..."
          style="width:100%;box-sizing:border-box;padding:6px 10px;
                 background:var(--bg-elevated);border:1px solid var(--border);
                 border-radius:6px;color:var(--text-primary);font-size:12px;
                 font-family:inherit;outline:none;margin-bottom:4px"
          oninput="filterSAClubs(this.value)">
        <div id="sa-club-list" style="max-height:200px;overflow-y:auto"></div>
      </div>
    </div>` : '');
}

// ── Клуби в сайдбарі SuperAdmin ──────────────────────────
let _saClubsCache = null;

async function _loadSAClubs() {
  const list = document.getElementById('sa-club-list');
  if (!list) return;
  if (_saClubsCache) { _renderSAClubs(_saClubsCache); return; }
  const res = await api('my_clubs', {}, 'auth');
  if (!res.success || !res.clubs?.length) {
    list.innerHTML = '<div style="font-size:12px;color:var(--text-muted);padding:4px 2px">Клубів немає</div>';
    return;
  }
  _saClubsCache = res.clubs;
  _renderSAClubs(_saClubsCache);
}

function _renderSAClubs(clubs) {
  const list = document.getElementById('sa-club-list');
  if (!list) return;
  list.innerHTML = clubs.map(c =>
    `<div class="nav-item" style="cursor:pointer;font-size:12px;padding:7px 10px"
          onclick="switchClub(${c.id})">${_esc(c.name)}</div>`
  ).join('');
}

function filterSAClubs(q) {
  if (!_saClubsCache) return;
  const v = q.toLowerCase();
  _renderSAClubs(_saClubsCache.filter(c => c.name.toLowerCase().includes(v)));
}

function toggleSAClubPicker() {
  const picker = document.getElementById('sa-club-picker');
  if (!picker) return;
  const open = picker.style.display === 'none';
  picker.style.display = open ? 'block' : 'none';
  if (open) {
    _loadSAClubs();
    setTimeout(() => document.getElementById('sa-club-search')?.focus(), 50);
  }
}

async function switchClub(id) {
  const picker = document.getElementById('sa-club-picker');
  if (picker) picker.style.display = 'none';
  const res = await api('switch_club', { club_id: id }, 'auth');
  if (res.success) {
    location.reload();
  } else if (typeof toast === 'function') {
    toast(res.error, 'error');
  }
}

// ── Банер режиму клубу ───────────────────────────────────
function _showClubModeBanner(club) {
  if (document.getElementById('club-mode-banner')) return;
  const banner = document.createElement('div');
  banner.id = 'club-mode-banner';
  banner.innerHTML = `
    <span>🛡 SuperAdmin · Перегляд клубу
      <strong style="color:#e2e8f0">${_esc(club.name)}</strong>
    </span>
    <button onclick="exitClubMode()"
      style="margin-left:auto;background:rgba(167,139,250,.2);
             border:1px solid rgba(167,139,250,.4);color:#c4b5fd;
             padding:5px 14px;border-radius:20px;cursor:pointer;
             font-size:12px;font-family:inherit;">
      ← Вийти з клубу
    </button>`;
  document.body.appendChild(banner);
  const main = document.querySelector('.app-main');
  if (main) main.style.paddingBottom = '52px';
}

function _showBlockedBanner(status) {
  if (document.getElementById('club-blocked-banner')) return;

  const labels = {
    trial_expired: '⏰ Тріал закінчився',
    past_due:      '⚠️ Підписка прострочена',
    cancelled:     '🚫 Підписку скасовано',
    deleted:       '🗑 Клуб видалено',
  };
  const label = labels[status] || '⚠️ Клуб неактивний';

  const banner = document.createElement('div');
  banner.id = 'club-blocked-banner';
  banner.style.cssText = `
    position: sticky; top: 0; z-index: 150;
    background: rgba(220,38,38,.12);
    border-bottom: 1px solid rgba(220,38,38,.35);
    color: #fca5a5;
    padding: 10px 20px;
    display: flex; align-items: center; gap: 12px;
    font-size: 14px; font-weight: 500;
  `;
  banner.innerHTML = `
    <span style="flex:1">${label} — дані доступні лише для перегляду</span>
    <a href="/billing"
       style="background:#dc2626;color:#fff;padding:5px 16px;
              border-radius:20px;font-size:12px;font-weight:600;
              text-decoration:none;white-space:nowrap;">
      💳 Оплатити підписку
    </a>`;

  const main = document.querySelector('.app-main');
  if (main) main.insertBefore(banner, main.firstChild);
}

// ── Мобільний гамбургер ──────────────────────────────────
function _initMobileMenu() {
  const header = document.querySelector('.app-header');
  if (!header || document.getElementById('burger-btn')) return;

  const btn = document.createElement('button');
  btn.id = 'burger-btn';
  btn.setAttribute('aria-label', 'Відкрити меню');
  btn.textContent = '☰';
  btn.onclick = toggleSidebar;
  header.insertBefore(btn, header.firstChild);
}

function toggleSidebar() {
  const sb = document.querySelector('.app-sidebar');
  if (!sb) return;
  sb.classList.contains('mobile-open') ? closeSidebar() : openSidebar();
}

function openSidebar() {
  document.querySelector('.app-sidebar')?.classList.add('mobile-open');
  document.getElementById('sidebar-overlay')?.classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeSidebar() {
  document.querySelector('.app-sidebar')?.classList.remove('mobile-open');
  document.getElementById('sidebar-overlay')?.classList.remove('open');
  document.body.style.overflow = '';
}

// ── Блокування кнопок при закритій зміні ────────────────
/**
 * Викликати після initPage на кожній сторінці де є дії з касою.
 * Блокує [data-shift-action] якщо роль <= manager (level < 80) і зміна закрита.
 *
 * @param {object} ctx — результат initPage()
 */
function applyShiftLock(ctx) {
  if (!ctx) return;
  const level = ctx.clubRole?.level ?? 0;
  if (level >= 80 || ctx.isSuperAdmin) return;

  const shiftOpen = !!ctx.activeShiftId;
  const ATTR = 'data-shift-locked';
  const MSG  = 'Відкрийте зміну в розділі Каса 🔒';

  document.querySelectorAll('[data-shift-action]').forEach(btn => {
    if (shiftOpen) {
      // Розблоковуємо
      if (btn.hasAttribute(ATTR)) {
        btn.removeAttribute(ATTR);
        btn.style.opacity = '';
        btn.style.cursor  = '';
        btn.title = btn.dataset.origTitle || '';
      }
    } else {
      // Блокуємо через перехоплення кліку (не disabled — щоб title працював)
      if (!btn.hasAttribute(ATTR)) {
        btn.dataset.origTitle = btn.title || '';
        btn.setAttribute(ATTR, '1');
        btn.style.opacity = '0.45';
        btn.style.cursor  = 'not-allowed';
        btn.title = MSG;
        btn.addEventListener('click', _shiftLockClick, true);
      }
    }
  });
}

function _shiftLockClick(e) {
  if (!this.hasAttribute('data-shift-locked')) return;
  e.preventDefault();
  e.stopImmediatePropagation();
  if (typeof toast === 'function') toast('Відкрийте зміну в розділі Каса', 'warning');
}


/**
 * Повертає { canWrite, isOwner, isSuperAdmin, level, permissions }
 *
 * Пріоритет:
 *   1. ctx.permissions — об'єкт з сервера (slug => bool)
 *   2. Fallback — рівень ролі (level >= 50 / 80)
 *
 * Використання: const perms = getPermissions(ctx);
 */
function getPermissions(ctx) {
  if (!ctx) return { canWrite: false, isOwner: false, isSuperAdmin: false, level: 0, permissions: {} };

  const { isSuperAdmin, inClubMode, clubRole, permissions = {} } = ctx;
  const level = isSuperAdmin
    ? (inClubMode ? 100 : 100)
    : (clubRole?.level ?? 0);

  // SuperAdmin без клубу — лише перегляд платформи
  if (isSuperAdmin && !inClubMode) {
    return { canWrite: false, isOwner: false, isSuperAdmin: true, level: 100, permissions };
  }

  // Якщо сервер повернув permissions — використовуємо їх
  const hasServerPerms = Object.keys(permissions).length > 0;
  const canWrite = hasServerPerms
    ? !!(permissions['clients.create'] ?? permissions['invoices.create'] ?? level >= 50)
    : level >= 50;
  const isOwner = hasServerPerms
    ? !!(permissions['finance.delete'] ?? permissions['users.invite'] ?? level >= 80)
    : level >= 80;

  return { canWrite, isOwner, isSuperAdmin, level, permissions };
}

// ── Вихід з режиму клубу ─────────────────────────────────
async function exitClubMode() {
  const res = await api('exit_club', {}, 'auth');
  if (res.success) {
    window.location.href = '/clubs';
  } else if (typeof toast === 'function') {
    toast(res.error, 'error');
  }
}

// ── Логаут ───────────────────────────────────────────────
async function doLogout() {
  await api('logout', {}, 'auth');
  window.location.href = '/login';
}

// ── Утиліта екранування HTML ─────────────────────────────
function _esc(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}
// Робимо доступною глобально якщо esc ще не визначено
if (typeof esc === 'undefined') window.esc = _esc;

// ── Блокування дій за планом підписки ───────────────────
/**
 * Викликати після initPage() на кожній сторінці.
 * Блокує кнопки [data-plan-action] якщо canWrite=false.
 * @param {object} ctx — результат initPage()
 */
function applyPlanLock(ctx) {
  if (!ctx) return;
  const perms = getPermissions(ctx);
  if (perms.canWrite) return; // доступ є — нічого не блокуємо

  document.querySelectorAll('[data-plan-action]').forEach(btn => {
    btn.style.opacity = '0.45';
    btn.style.cursor  = 'not-allowed';
    btn.title = 'Недоступно на поточному плані підписки';
    btn.addEventListener('click', e => {
      e.preventDefault();
      e.stopImmediatePropagation();
      if (typeof toast === 'function') toast('Недоступно на поточному плані підписки', 'warning');
    }, true);
  });
}

// ── Перевірка заблокованого клубу ───────────────────────
/**
 * Викликається на кожній сторінці після initPage().
 * Якщо клуб заблоковано — показує банер, але НЕ зупиняє роботу.
 * @param {boolean} isBlocked
 */
function guardBlockedClub(isBlocked) {
  if (!isBlocked) return;
  if (typeof _showBlockedBanner === 'function') _showBlockedBanner(isBlocked);
}