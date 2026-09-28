<?php
$pageTitle = 'Всі клуби';
$pageCss   = 'clubs';
require __DIR__ . '/../partials/head.php';
?>
<body>
<div class="app-layout">

  <!-- aside і header НЕ пишемо — sidebar.js вставить їх сам -->

  <!-- ── SIDEBAR ── -->
    <!-- ── HEADER ── -->
  <!-- ── MAIN ── -->
  <main class="app-main">

    <div class="page-header">
      <h1 class="page-title">Всі клуби</h1>
      <p class="page-subtitle" id="page-subtitle">Завантаження...</p>
    </div>

    <!-- Статистика платформи -->
    <div class="platform-stats" id="platform-stats">
      <div class="platform-stat">
        <div class="ps-label">Всього клубів</div>
        <div class="ps-value" id="ps-total"><span class="spinner"></span></div>
        <div class="ps-sub" id="ps-active-sub">—</div>
      </div>
      <div class="platform-stat green">
        <div class="ps-label">Платних</div>
        <div class="ps-value" id="ps-paid">—</div>
        <div class="ps-sub" id="ps-revenue-month">—</div>
      </div>
      <div class="platform-stat orange">
        <div class="ps-label">На тріалі</div>
        <div class="ps-value" id="ps-trial">—</div>
        <div class="ps-sub" id="ps-expiring">—</div>
      </div>
      <div class="platform-stat red">
        <div class="ps-label">Тріал закінчився</div>
        <div class="ps-value" id="ps-expired">—</div>
        <div class="ps-sub">потребують оплати</div>
      </div>
      <div class="platform-stat purple">
        <div class="ps-label">Дохід всього</div>
        <div class="ps-value" id="ps-revenue-total">—</div>
        <div class="ps-sub">грн</div>
      </div>
    </div>

    <!-- Фільтри -->
    <div class="clubs-toolbar">
      <div style="position:relative;flex:1;min-width:200px;max-width:320px">
        <span style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:14px;pointer-events:none">&#128269;</span>
        <input type="text" id="search-input" placeholder="Назва, email власника..."
               style="padding-left:36px;width:100%"
               oninput="scheduleSearch()">
      </div>
      <button class="status-filter-btn active" data-status="" onclick="setFilter(this,'')">Всі</button>
      <button class="status-filter-btn" data-status="trial"        onclick="setFilter(this,'trial')">Тріал</button>
      <button class="status-filter-btn" data-status="active"       onclick="setFilter(this,'active')">Активні</button>
      <button class="status-filter-btn" data-status="trial_expired" onclick="setFilter(this,'trial_expired')">Прострочені</button>
    </div>

    <!-- Таблиця -->
    <div class="card" style="padding:0;overflow:hidden">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Клуб</th>
              <th>Власник</th>
              <th>Підписка</th>
              <th>Клієнти</th>
              <th>Зареєстровано</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="clubs-tbody">
            <tr><td colspan="6">
              <div class="loader"><div class="spinner"></div> Завантаження...</div>
            </td></tr>
          </tbody>
        </table>
      </div>
      <div class="pagination" id="pagination" style="padding:16px 20px"></div>
    </div>

  </main>
</div>

<!-- ════════════════════════════════════════
     МОДАЛКА: ДЕТАЛІ КЛУБУ
════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-club">
  <div class="modal" style="max-width:580px">
    <button class="modal-close" onclick="closeModal('modal-club')">&#10005;</button>

    <div id="club-detail-loading" class="loader"><div class="spinner"></div> Завантаження...</div>

    <div id="club-detail-content" style="display:none">

      <!-- Шапка -->
      <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px">
        <div>
          <h2 class="modal-title" id="detail-name" style="margin-bottom:4px">—</h2>
          <div id="detail-status-badge"></div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap" id="detail-actions"></div>
      </div>

      <!-- Вкладки -->
      <div class="modal-tabs">
        <button class="modal-tab-btn active" onclick="switchTab('info',this)">Інфо</button>
        <button class="modal-tab-btn" onclick="switchTab('billing',this)">Підписка</button>
        <button class="modal-tab-btn" onclick="switchTab('payments',this)">Платежі</button>
      </div>

      <!-- Вкладка: Інфо -->
      <div id="tab-info" class="modal-tab-content active">
        <div class="club-detail-label">Про клуб</div>
        <div id="detail-info-rows"></div>

        <div class="club-detail-label" style="margin-top:20px">Власник</div>
        <div id="detail-owner-rows"></div>
      </div>

      <!-- Вкладка: Підписка -->
      <div id="tab-billing" class="modal-tab-content" style="display:none">
        <div id="detail-billing-content"></div>

        <!-- Продовжити тріал -->
        <div style="margin-top:20px;padding-top:20px;border-top:1px solid var(--border)" id="extend-trial-section">
          <div class="club-detail-label">Продовжити тріал</div>
          <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
            <div class="form-group" style="margin:0;flex:1;min-width:120px">
              <label>Кількість днів</label>
              <input type="number" id="extend-days" value="14" min="1" max="90"
                     style="max-width:120px">
            </div>
            <div class="form-group" style="margin:0;flex:2;min-width:160px">
              <label>Причина (опційно)</label>
              <input type="text" id="extend-note" placeholder="напр. технічні проблеми">
            </div>
            <button class="btn btn-ghost" onclick="extendTrial()" style="margin-bottom:2px">
              Продовжити
            </button>
          </div>
        </div>

        <!-- Записати платіж -->
        <div style="margin-top:20px;padding-top:20px;border-top:1px solid var(--border)">
          <div class="club-detail-label">Записати ручний платіж</div>
          <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
            <div class="form-group" style="margin:0;flex:1;min-width:120px">
              <label>План</label>
              <select id="pay-plan-select" style="min-width:120px"></select>
            </div>
            <div class="form-group" style="margin:0;min-width:80px">
              <label>Місяців</label>
              <input type="number" id="pay-months" value="1" min="1" max="12" style="max-width:80px">
            </div>
            <div class="form-group" style="margin:0;flex:2;min-width:160px">
              <label>Примітка</label>
              <input type="text" id="pay-note" placeholder="напр. оплата готівкою">
            </div>
            <button class="btn btn-primary" onclick="recordPayment()" style="margin-bottom:2px">
              Записати
            </button>
          </div>
        </div>
      </div>

      <!-- Вкладка: Платежі -->
      <div id="tab-payments" class="modal-tab-content" style="display:none">
        <div id="detail-payments-list">
          <div class="loader"><div class="spinner"></div></div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Підтвердження блокування -->
<div class="modal-overlay" id="modal-block">
  <div class="modal" style="max-width:380px">
    <h2 class="modal-title" id="block-title">Заблокувати клуб?</h2>
    <p style="font-size:14px;color:var(--text-secondary);margin-bottom:24px" id="block-text">—</p>
    <div style="display:flex;gap:10px">
      <button class="btn btn-danger" id="btn-confirm-block" onclick="confirmBlock()">Підтвердити</button>
      <button class="btn btn-ghost"  onclick="closeModal('modal-block')">Скасувати</button>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../partials/scripts.php'; ?>
<script>
let state = {
  currentClubId: null,
  currentFilter: '',
  searchTimer: null,
  plans: [],
  page: 1,
};

// ── Ініціалізація ─────────────────────────────────────────
window.addEventListener('DOMContentLoaded', async () => {
  const ctx = await initPage();
  if (!ctx) return;
  guardBlockedClub(ctx.isBlocked);
  if (!ctx.isSuperAdmin) { window.location.href = '/dashboard'; return; }

  // Плани для форми платежу
  const plansRes = await api('get_plans', {}, 'billing');
  if (plansRes.success) {
    state.plans = plansRes.plans;
    const sel = document.getElementById('pay-plan-select');
    sel.innerHTML = plansRes.plans.map(p =>
      `<option value="${p.id}">${p.name} — ${p.price_monthly} грн</option>`
    ).join('');
  }

  loadStats();
  loadClubs(1);
});

// ── Статистика платформи ──────────────────────────────────
async function loadStats() {
  const res = await api('get_stats', {}, 'clubs');
  if (!res.success) return;
  const s = res.stats;

  document.getElementById('ps-total').textContent        = s.total_clubs;
  document.getElementById('ps-active-sub').textContent   = 'активних: ' + s.active_clubs;
  document.getElementById('ps-paid').textContent         = s.paid_clubs;
  document.getElementById('ps-revenue-month').textContent= formatMoney(s.revenue_month) + ' цього місяця';
  document.getElementById('ps-trial').textContent        = s.trial_clubs;
  document.getElementById('ps-expiring').textContent     = s.expiring_soon + ' закінчуються за 7 дн.';
  document.getElementById('ps-expired').textContent      = s.expired_clubs;
  document.getElementById('ps-revenue-total').textContent= formatMoney(s.revenue_total);
}

// ── Список клубів ─────────────────────────────────────────
async function loadClubs(page = 1) {
  state.page = page;
  const tbody = document.getElementById('clubs-tbody');
  tbody.innerHTML =
    '<tr><td colspan="6"><div class="loader"><div class="spinner"></div> Завантаження...</div></td></tr>';

  const res = await api('get_list', {
    search: document.getElementById('search-input').value.trim(),
    status: state.currentFilter,
    page,
  }, 'clubs');

  if (!res.success) {
    tbody.innerHTML = `<tr><td colspan="6">
      <div class="alert alert-error" style="margin:16px">${res.error}</div>
    </td></tr>`;
    return;
  }

  const { clubs, pagination } = res;
  document.getElementById('page-subtitle').textContent =
    `Всього: ${pagination.total} клубів`;

  if (!clubs.length) {
    tbody.innerHTML = `<tr><td colspan="6">
      <div class="empty-state" style="padding:40px 20px">
        <div style="font-size:36px;margin-bottom:12px">&#127963;</div>
        <h3>Клубів не знайдено</h3>
        <p>Зареєстровані клуби з'являться тут</p>
      </div>
    </td></tr>`;
    renderPagination(pagination);
    return;
  }

  tbody.innerHTML = clubs.map(c => renderClubRow(c)).join('');
  renderPagination(pagination);
}

function renderClubRow(c) {
  const subMap = {
    trial:         ['badge-trial',         'Тріал'],
    active:        ['badge-active',        'Активний'],
    trial_expired: ['badge-trial_expired', 'Прострочено'],
    past_due:      ['badge-past_due',      'Не оплачено'],
    cancelled:     ['badge-inactive',      'Скасовано'],
    deleted:       ['badge-deleted',       'Видалено'],
  };
  const [bClass, bLabel] = subMap[c.sub_status] || ['badge-info', c.sub_status || '—'];

  let trialInfo = '';
  if (c.sub_status === 'trial') {
    const d   = parseInt(c.trial_days_left);
    const cls = d <= 1 ? 'danger' : d <= 3 ? 'warn' : 'ok';
    trialInfo = `<div class="trial-days ${cls}">${d} дн. залишилось</div>`;
  }

  const isBlocked = !c.is_active;

  return `
    <tr class="${isBlocked ? 'club-blocked' : ''}"
        onclick="openClubDetail(${c.id})" style="cursor:pointer">
      <td>
        <div class="club-name">${esc(c.name)}</div>
        <div class="club-owner">${c.city || '—'}</div>
      </td>
      <td>
        <div style="font-size:14px">${esc(c.owner_name)}</div>
        <div style="font-size:12px;color:var(--text-muted)">${c.owner_email}</div>
      </td>
      <td>
        <span class="badge ${bClass}">${bLabel}</span>
        ${trialInfo}
      </td>
      <td style="font-size:14px">${c.clients_count}</td>
      <td style="font-size:13px;color:var(--text-secondary)">${formatDate(c.created_at)}</td>
      <td onclick="event.stopPropagation()" style="white-space:nowrap">
        <button class="btn btn-ghost btn-sm" onclick="openClubDetail(${c.id})" title="Деталі">&#128269;</button>
        <button class="btn btn-ghost btn-sm" onclick="openBlockModal(${c.id},'${esc(c.name)}',${c.is_active})"
                title="${isBlocked ? 'Розблокувати' : 'Заблокувати'}"
                style="${isBlocked ? 'color:var(--success)' : 'color:var(--danger)'}">
          ${isBlocked ? '&#9989;' : '&#128683;'}
        </button>
      </td>
    </tr>
  `;
}

// ── Деталі клубу ──────────────────────────────────────────
async function openClubDetail(clubId) {
  state.currentClubId = clubId;

  document.getElementById('club-detail-loading').style.display  = 'flex';
  document.getElementById('club-detail-content').style.display  = 'none';
  openModal('modal-club');

  // Скидаємо вкладки
  document.querySelectorAll('.modal-tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelector('.modal-tab-btn').classList.add('active');
  document.querySelectorAll('.modal-tab-content').forEach(t => t.style.display = 'none');
  document.getElementById('tab-info').style.display = 'block';
  document.getElementById('tab-info').classList.add('active');

  const res = await api('get_one', { club_id: clubId }, 'clubs');
  if (!res.success) { closeModal('modal-club'); toast(res.error, 'error'); return; }

  const c = res.club;

  // Шапка
  document.getElementById('detail-name').textContent = c.name;

  const subMap = { trial:'badge-trial', active:'badge-active', trial_expired:'badge-trial_expired' };
  const subLabel = { trial:'Тріал', active:'Активний', trial_expired:'Прострочено',
                     past_due:'Не оплачено', cancelled:'Скасовано' };
  document.getElementById('detail-status-badge').innerHTML =
    `<span class="badge ${subMap[c.sub_status] || 'badge-info'}">
      ${subLabel[c.sub_status] || c.sub_status || '—'}
    </span>
    ${!c.is_active ? '<span class="badge badge-inactive" style="margin-left:6px">Заблоковано</span>' : ''}`;

  // Кнопки дій
  document.getElementById('detail-actions').innerHTML = `
    <button class="btn btn-ghost btn-sm"
            onclick="openBlockModal(${c.id},'${esc(c.name)}',${c.is_active})"
            style="${c.is_active ? 'color:var(--danger)' : 'color:var(--success)'}">
      ${c.is_active ? '&#128683; Заблокувати' : '&#9989; Розблокувати'}
    </button>
  `;

  // Інфо про клуб
  const infoFields = [
    ['Місто',    c.city    || '—'],
    ['Адреса',   c.address || '—'],
    ['Телефон',  c.phone   || '—'],
    ['Email',    c.email   || '—'],
    ['Slug',     c.slug    || '—'],
    ['Клієнтів', '(завантаження)'],
    ['Зареєстровано', formatDate(c.created_at)],
  ];
  document.getElementById('detail-info-rows').innerHTML =
    infoFields.map(([l,v]) => `
      <div class="detail-row">
        <span class="detail-row-label">${l}</span>
        <span style="font-size:14px">${esc(String(v))}</span>
      </div>
    `).join('');

  // Власник
  const ownerFields = [
    ['Ім\'я',         c.owner_name  || '—'],
    ['Email',         c.owner_email || '—'],
    ['Телефон',       c.owner_phone || '—'],
    ['Останній вхід', c.owner_last_login ? formatDate(c.owner_last_login) : 'Ніколи'],
  ];
  document.getElementById('detail-owner-rows').innerHTML =
    ownerFields.map(([l,v]) => `
      <div class="detail-row">
        <span class="detail-row-label">${l}</span>
        <span style="font-size:14px">${esc(String(v))}</span>
      </div>
    `).join('');

  // Білінг
  const trialEnd = c.sub_trial_ends ? formatDate(c.sub_trial_ends) : '—';
  const periodEnd = c.current_period_end ? formatDate(c.current_period_end) : '—';
  document.getElementById('detail-billing-content').innerHTML = `
    <div class="detail-row"><span class="detail-row-label">План</span>
      <span>${c.plan_name || '—'} ${c.price_monthly ? '— ' + c.price_monthly + ' грн/міс' : ''}</span>
    </div>
    <div class="detail-row"><span class="detail-row-label">Статус</span>
      <span>${subLabel[c.sub_status] || c.sub_status || '—'}</span>
    </div>
    <div class="detail-row"><span class="detail-row-label">Тріал до</span>
      <span>${trialEnd}</span>
    </div>
    <div class="detail-row"><span class="detail-row-label">Підписка до</span>
      <span>${periodEnd}</span>
    </div>
    ${c.admin_notes ? `<div style="margin-top:12px;font-size:12px;color:var(--text-muted);
      background:var(--bg-elevated);padding:10px;border-radius:var(--radius-sm);white-space:pre-line">
      ${esc(c.admin_notes)}</div>` : ''}
  `;

  document.getElementById('club-detail-loading').style.display = 'none';
  document.getElementById('club-detail-content').style.display = 'block';
}

// Завантаження платежів (ліниво — тільки при відкритті вкладки)
async function loadPayments() {
  const res = await api('get_payments', { club_id: state.currentClubId }, 'clubs');
  const el  = document.getElementById('detail-payments-list');

  if (!res.success) { el.innerHTML = `<div class="alert alert-error">${res.error}</div>`; return; }

  if (!res.payments.length) {
    el.innerHTML = '<div class="empty-state" style="padding:30px 0"><p>Платежів ще немає</p></div>';
    return;
  }

  el.innerHTML = res.payments.map(p => `
    <div class="payment-row">
      <div>
        <div style="font-size:13px;font-weight:500">${p.plan_name}</div>
        <div style="font-size:12px;color:var(--text-muted)">
          ${formatDate(p.period_start)} — ${formatDate(p.period_end)}
          ${p.notes ? ' · ' + esc(p.notes) : ''}
        </div>
      </div>
      <div style="text-align:right">
        <div class="payment-amount">${formatMoney(p.amount)}</div>
        <div class="payment-gateway">${p.gateway || p.payment_gateway || '—'}</div>
      </div>
    </div>
  `).join('');
}

function switchTab(tab, btn) {
  document.querySelectorAll('.modal-tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.modal-tab-content').forEach(t => t.style.display = 'none');
  btn.classList.add('active');
  document.getElementById('tab-' + tab).style.display = 'block';
  if (tab === 'payments') loadPayments();
}

// ── Продовження тріалу ────────────────────────────────────
async function extendTrial() {
  const days = parseInt(document.getElementById('extend-days').value);
  const note = document.getElementById('extend-note').value.trim();
  if (!days || days < 1) { toast('Введіть кількість днів', 'error'); return; }

  const res = await api('extend_trial', {
    club_id: state.currentClubId, days, note,
  }, 'clubs');

  if (res.success) {
    toast(res.message, 'success');
    closeModal('modal-club');
    loadClubs(state.page);
    loadStats();
  } else {
    toast(res.error, 'error');
  }
}

// ── Ручний платіж ─────────────────────────────────────────
async function recordPayment() {
  const planId = parseInt(document.getElementById('pay-plan-select').value);
  const months = parseInt(document.getElementById('pay-months').value);
  const note   = document.getElementById('pay-note').value.trim() || 'Ручна оплата';

  if (!planId) { toast('Оберіть план', 'error'); return; }

  const res = await api('record_payment', {
    club_id: state.currentClubId, plan_id: planId, months, note,
  }, 'clubs');

  if (res.success) {
    toast(res.message, 'success');
    closeModal('modal-club');
    loadClubs(state.page);
    loadStats();
  } else {
    toast(res.error, 'error');
  }
}

// ── Блокування ────────────────────────────────────────────
let blockClubId = null;

function openBlockModal(clubId, name, isActive) {
  event.stopPropagation();
  blockClubId = clubId;
  document.getElementById('block-title').textContent =
    isActive ? 'Заблокувати клуб?' : 'Розблокувати клуб?';
  document.getElementById('block-text').innerHTML =
    isActive
      ? `Клуб <strong>${esc(name)}</strong> буде заблоковано. Власник не зможе входити.`
      : `Клуб <strong>${esc(name)}</strong> буде розблоковано.`;
  document.getElementById('btn-confirm-block').textContent =
    isActive ? 'Заблокувати' : 'Розблокувати';
  openModal('modal-block');
}

async function confirmBlock() {
  const res = await api('toggle_active', { club_id: blockClubId }, 'clubs');
  closeModal('modal-block');
  closeModal('modal-club');
  if (res.success) { toast(res.message, 'success'); loadClubs(state.page); }
  else toast(res.error, 'error');
}

// ── Фільтри і пошук ───────────────────────────────────────
function setFilter(btn, status) {
  state.currentFilter = status;
  document.querySelectorAll('.status-filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  loadClubs(1);
}

function scheduleSearch() {
  clearTimeout(state.searchTimer);
  state.searchTimer = setTimeout(() => loadClubs(1), 350);
}

// ── Пагінація ─────────────────────────────────────────────
function renderPagination({ total, page, pages, per_page }) {
  const el = document.getElementById('pagination');
  if (pages <= 1) { el.innerHTML = ''; return; }

  const from = (page - 1) * per_page + 1;
  const to   = Math.min(page * per_page, total);
  let btns = '';
  for (let i = 1; i <= pages; i++) {
    if (i === 1 || i === pages || Math.abs(i - page) <= 2) {
      btns += `<button class="page-btn ${i === page ? 'active' : ''}"
                       onclick="loadClubs(${i})">${i}</button>`;
    } else if (Math.abs(i - page) === 3) {
      btns += '<button class="page-btn" disabled>…</button>';
    }
  }
  el.innerHTML = `
    <span>${from}–${to} з ${total}</span>
    <div class="pagination-btns">
      <button class="page-btn" onclick="loadClubs(${page-1})" ${page<=1?'disabled':''}>&#8249;</button>
      ${btns}
      <button class="page-btn" onclick="loadClubs(${page+1})" ${page>=pages?'disabled':''}>&#8250;</button>
    </div>`;
}

// ── Утиліти ───────────────────────────────────────────────
function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
</script>
</body>
