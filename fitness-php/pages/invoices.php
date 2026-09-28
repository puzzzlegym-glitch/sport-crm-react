<?php
$pageTitle = 'Абонементи';
$pageCss   = 'invoices';
require __DIR__ . '/../partials/head.php';
?>
<body>
<div class="app-layout">

  <!-- aside і header НЕ пишемо — sidebar.js вставить їх сам -->

    <main class="app-main">

    <!-- Фільтри + кнопка -->
    <div class="inv-toolbar">
      <div class="search-wrap">
        <span class="search-icon">&#128269;</span>
        <input type="text" id="search-input"
               placeholder="Ім'я або телефон клієнта..."
               oninput="scheduleSearch()">
      </div>
      <button class="inv-filter-btn active" data-status="" onclick="setFilter(this,'')">Всі</button>
      <button class="inv-filter-btn" data-status="active"    onclick="setFilter(this,'active')">Активні</button>
      <button class="inv-filter-btn" data-status="frozen"    onclick="setFilter(this,'frozen')">Заморожені</button>
      <button class="inv-filter-btn" data-status="expired"   onclick="setFilter(this,'expired')">Прострочені</button>
      <button class="inv-filter-btn" data-status="cancelled" onclick="setFilter(this,'cancelled')">Скасовані</button>
      <button class="btn btn-primary" id="btn-sell" data-shift-action onclick="openSellModal()" style="display:none;margin-left:auto">
        + Продати абонемент
      </button>
    </div>

    <!-- Мобільні картки -->
    <div id="inv-cards" style="display:none"></div>

    <!-- Таблиця (ПК) -->
    <div class="card" id="inv-table-card" style="padding:0;overflow:hidden">
      <div class="table-wrap">
        <table>
          <thead class="mob-hide">
            <tr>
              <th>Клієнт</th>
              <th>Тариф</th>
              <th>Статус</th>
              <th class="mob-hide">Залишилось</th>
              <th>Оплата</th>
              <th class="mob-hide">Відвідування</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="inv-tbody">
            <tr><td colspan="7">
              <div class="loader"><div class="spinner"></div> Завантаження...</div>
            </td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <div class="pagination" id="pagination" style="padding:8px 0"></div>

  </main>
</div>

<!-- ════════════════════════════════════════════
     МОДАЛКА: ПРОДАТИ АБОНЕМЕНТ
════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-sell">
  <div class="modal" style="max-width:520px">
    <button class="modal-close" onclick="closeModal('modal-sell')">&#10005;</button>
    <h2 class="modal-title">Продати абонемент</h2>
    <div id="sell-error" class="alert alert-error" style="display:none"></div>

    <!-- Клієнт -->
    <div class="form-group">
      <label>Клієнт *</label>
      <select id="sell-client-select" onchange="onSellClientChange()" style="width:100%">
        <option value="">— Завантаження... —</option>
      </select>
    </div>

    <!-- Тариф -->
    <div class="form-group">
      <label>Тариф *</label>
      <select id="sell-tariff-select" onchange="sellSelectTariff(this.value)" style="width:100%">
        <option value="">— Оберіть тариф —</option>
      </select>
      <div id="sell-tariff-info" style="display:none;margin-top:8px;padding:10px 12px;
           background:var(--bg-elevated);border:1px solid var(--border);
           border-radius:var(--radius-sm);font-size:13px;color:var(--text-secondary)">
      </div>
    </div>

    <!-- Дата, знижка, оплата -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px">
      <div class="form-group">
        <label>Дата початку *</label>
        <input type="date" id="sell-start-date">
      </div>
      <div class="form-group">
        <label>Знижка (%)</label>
        <input type="number" id="sell-discount" value="0" min="0" max="100" step="1">
      </div>
    </div>
    <div class="form-group">
      <label>Оплата зараз (грн)</label>
      <input type="number" id="sell-paid-now" value="0" min="0" step="1">
    </div>
    <div class="form-group">
      <label>Примітка</label>
      <textarea id="sell-notes" placeholder="Необов'язково..." rows="2"></textarea>
    </div>

    <div style="display:flex;gap:10px;margin-top:8px">
      <button class="btn btn-primary" id="btn-sell-submit" onclick="submitSell()">Продати</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-sell')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════
     МОДАЛКА: ДЕТАЛІ АБОНЕМЕНТУ
════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-detail">
  <div class="modal" style="max-width:540px">
    <button class="modal-close" onclick="closeModal('modal-detail')">&#10005;</button>

    <div id="detail-loading" class="loader"><div class="spinner"></div></div>
    <div id="detail-content" style="display:none">

      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;flex-wrap:wrap;gap:8px">
        <div>
          <h2 class="modal-title" id="det-tariff" style="margin-bottom:4px">—</h2>
          <div id="det-client" style="font-size:14px;color:var(--text-secondary)">—</div>
        </div>
        <div id="det-status-badge"></div>
      </div>

      <!-- Вкладки -->
      <div class="inv-modal-tabs">
        <button class="inv-modal-tab-btn active" onclick="switchDetTab('info',this)">Деталі</button>
        <button class="inv-modal-tab-btn" onclick="switchDetTab('payments',this)">Платежі</button>
        <button class="inv-modal-tab-btn" onclick="switchDetTab('actions',this)">Дії</button>
      </div>

      <!-- Вкладка: Деталі -->
      <div id="det-tab-info">
        <div id="det-rows"></div>
      </div>

      <!-- Вкладка: Платежі -->
      <div id="det-tab-payments" style="display:none">
        <div id="det-payments-list"></div>

        <!-- Форма додавання платежу -->
        <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border)" id="add-pay-form">
          <div style="font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:10px">
            Додати платіж
          </div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <div class="form-group" style="margin:0;flex:1;min-width:100px">
              <label>Сума</label>
              <input type="number" id="pay-amount" min="1" step="1" placeholder="0">
            </div>
            <div class="form-group" style="margin:0;flex:1;min-width:120px">
              <label>Спосіб</label>
              <select id="pay-method">
                <option value="cash">Готівка</option>
                <option value="card">Карта</option>
                <option value="terminal">Термінал</option>
                <option value="deposit">З депозиту</option>
              </select>
            </div>
            <button class="btn btn-primary btn-sm" onclick="addPayment()" style="margin-bottom:2px">
              Записати
            </button>
          </div>
        </div>
      </div>

      <!-- Вкладка: Дії (рендериться динамічно в openDetail) -->
      <div id="det-tab-actions" style="display:none"></div>

    </div>
  </div>
</div>
<?php require __DIR__ . '/../partials/scripts.php'; ?>
<script>
// ── Стан ────────────────────────────────────────────────────
let state = {
  filter: '', page: 1, searchTimer: null,
  canWrite: false, isOwner: false,
  tariffs: [], trainers: [],
  selectedTariff: null, selectedClientId: null,
  currentInvoiceId: null,
  clientSearchTimer: null,
};

// ── Ініціалізація ─────────────────────────────────────────
window.addEventListener('DOMContentLoaded', async () => {
  const ctx = await initPage({ title: 'Абонементи' });
  if (!ctx) return;
  guardBlockedClub(ctx.isBlocked);
  window._shiftCtx = ctx;
  applyShiftLock(ctx);
  applyPlanLock(ctx);
  const { u, isSuperAdmin, inClubMode, club, clubRole } = ctx;
  // Права визначаємо з clubRole або global_level (SuperAdmin)
  const _level = isSuperAdmin ? 100 : (clubRole?.level ?? 0);
  state.canWrite = !ctx.isBlocked && _level >= 50;
  state.isOwner  = _level >= 80;

  if (state.canWrite) document.getElementById('btn-sell').style.display = 'inline-flex';
  // Завантажуємо тарифи і тренерів для форми редагування
  api('get_tariffs', {}, 'invoices').then(r => { if(r.success) state.tariffs = r.tariffs; });
  api('get_list', {}, 'trainers').then(r => { if(r.success) state.trainers = r.trainers || []; });

  // Дата за замовчуванням
  document.getElementById('sell-start-date').value = new Date().toISOString().split('T')[0];

  loadStats();
  loadInvoices(1);
  loadTariffs();
});

// ── Статистика ────────────────────────────────────────────
async function loadStats() {}

// ── Список абонементів ────────────────────────────────────
async function loadInvoices(page = 1) {
  state.page = page;
  const tbody = document.getElementById('inv-tbody');
  tbody.innerHTML =
    '<tr><td colspan="7"><div class="loader"><div class="spinner"></div></div></td></tr>';

  const res = await api('get_list', {
    search: document.getElementById('search-input').value.trim(),
    status: state.filter,
    page,
  }, 'invoices');

  if (!res.success) {
    tbody.innerHTML =
      `<tr><td colspan="7"><div class="alert alert-error" style="margin:16px">${res.error}</div></td></tr>`;
    return;
  }

  const { invoices, pagination } = res;

  if (!invoices.length) {
    tbody.innerHTML = `<tr><td colspan="7">
      <div class="empty-state" style="padding:40px">
        <div style="font-size:36px;margin-bottom:12px">&#128196;</div>
        <h3>Абонементів не знайдено</h3>
        ${state.canWrite ? '<p>Натисніть "+ Продати абонемент"</p>' : ''}
      </div>
    </td></tr>`;
    renderPagination(pagination);
    return;
  }

  const isMobile  = window.innerWidth <= 768;
  const cardsEl   = document.getElementById('inv-cards');
  const tableCard = document.getElementById('inv-table-card');
  const emptyHtml = `<div class="empty-state" style="padding:40px;text-align:center">
    <div style="font-size:36px;margin-bottom:12px">&#128196;</div>
    <h3>Абонементів не знайдено</h3>
    ${state.canWrite ? '<p>Натисніть "+ Продати абонемент"</p>' : ''}
  </div>`;

  if (isMobile) {
    tableCard.style.display = 'none';
    cardsEl.style.display   = 'block';
    cardsEl.innerHTML = invoices.length
      ? invoices.map(inv => renderInvCard(inv)).join('')
      : emptyHtml;
  } else {
    cardsEl.style.display   = 'none';
    tableCard.style.display = '';
    tbody.innerHTML = invoices.length
      ? invoices.map(inv => renderRow(inv)).join('')
      : `<tr><td colspan="7">${emptyHtml}</td></tr>`;
  }
  renderPagination(pagination);
  applyShiftLock(window._shiftCtx);
  applyPlanLock(window._shiftCtx);
}

// ── Мобільна картка абонементу ───────────────────────────
function renderInvCard(inv) {
  const statusMap = {
    active:    ['badge-active',   'Активний'],
    expired:   ['badge-inactive', 'Прострочений'],
    frozen:    ['badge-pending',  'Заморожений'],
    cancelled: ['badge-inactive', 'Скасований'],
  };
  const [sBadge, sLabel] = statusMap[inv.status] || ['badge-info', inv.status];
  const paid  = parseFloat(inv.paid_amount || 0);
  const price = parseFloat(inv.price || 0);
  const debt  = parseFloat(inv.debt  || 0);

  const actions = `
    <button class="btn btn-ghost btn-sm" onclick="event.stopPropagation();openDetail(${inv.id})" title="Деталі">&#128269;</button>
    ${state.canWrite && inv.status !== 'cancelled' ? `<button class="btn btn-ghost btn-sm" data-shift-action onclick="event.stopPropagation();openDetailEdit(${inv.id})" title="Редагувати">&#9998;</button>` : ''}
    ${state.isOwner && inv.status !== 'cancelled' ? `<button class="btn btn-ghost btn-sm" data-shift-action style="color:var(--danger)" onclick="event.stopPropagation();state.currentInvoiceId=${inv.id};doCancel()" title="Скасувати">&#128465;</button>` : ''}`;

  return `
  <div class="inv-card" onclick="openDetail(${inv.id})">
    <div class="inv-card-main">
      <div class="inv-card-left">
        <div class="inv-card-client">${esc(inv.client_name)}</div>
        <div class="inv-card-sub">${esc(inv.tariff_name)} · ${formatDate(inv.end_date)}</div>
      </div>
      <div class="inv-card-right">
        <span class="badge ${sBadge}">${sLabel}</span>
        <div class="inv-card-pay">${formatMoney(paid)}${price > 0 ? ' / ' + formatMoney(price) : ''}</div>
        ${debt > 0.01 ? `<span class="debt-badge">борг ${formatMoney(debt)}</span>` : ''}
        <div class="inv-card-actions" onclick="event.stopPropagation()">${actions}</div>
      </div>
    </div>
  </div>`;
}

async function openDetailEdit(id) {
  const errEl = document.getElementById('inv-edit-error');
  const form  = document.getElementById('inv-edit-form');
  errEl.style.display = 'none';
  form.style.opacity  = '.4';
  document.getElementById('inv-edit-id').value = id;
  openModal('modal-inv-edit');

  const res = await api('get_one', { id }, 'invoices');
  form.style.opacity = '1';
  if (!res.success) { errEl.textContent = res.error; errEl.style.display = 'block'; return; }

  const inv = res.invoice;

  // Тарифи
  const tariffSel = document.getElementById('inv-edit-tariff-id');
  tariffSel.innerHTML = (state.tariffs || []).map(t =>
    `<option value="${t.id}" ${t.id == inv.tariff_id ? 'selected' : ''}>${t.name}</option>`
  ).join('');

  // Тренери
  const trainerSel = document.getElementById('inv-edit-trainer-id');
  trainerSel.innerHTML = '<option value="">— Без тренера —</option>' +
    (state.trainers || []).map(t =>
      `<option value="${t.id}" ${t.id == inv.trainer_id ? 'selected' : ''}>${t.full_name}</option>`
    ).join('');

  // Статус
  document.getElementById('inv-edit-status').value     = inv.status || 'active';
  document.getElementById('inv-edit-start-date').value = (inv.start_date || '').substring(0,10);
  document.getElementById('inv-edit-notes').value      = inv.notes || '';
}

async function submitInvEdit() {
  const errEl = document.getElementById('inv-edit-error');
  const btn   = document.getElementById('inv-edit-submit');
  errEl.style.display = 'none';
  btn.disabled = true; btn.textContent = '...';

  const res = await api('update', {
    id:         +document.getElementById('inv-edit-id').value,
    tariff_id:  +document.getElementById('inv-edit-tariff-id').value,
    start_date:  document.getElementById('inv-edit-start-date').value,
    status:      document.getElementById('inv-edit-status').value,
    trainer_id: +document.getElementById('inv-edit-trainer-id').value || 0,
    notes:       document.getElementById('inv-edit-notes').value.trim(),
  }, 'invoices');

  btn.disabled = false; btn.textContent = 'Зберегти';
  if (!res.success) { errEl.textContent = res.error; errEl.style.display = 'block'; return; }
  toast('Абонемент оновлено', 'success');
  closeModal('modal-inv-edit');
  loadInvoices(state.page);
}

// ── Рядок таблиці ────────────────────────────────────────
function renderRow(inv) {
  const statusMap = {
    active:    ['badge-active',   'Активний'],
    expired:   ['badge-inactive', 'Прострочений'],
    frozen:    ['badge-pending',  'Заморожений'],
    cancelled: ['badge-inactive', 'Скасований'],
  };
  const [sBadge, sLabel] = statusMap[inv.status] || ['badge-info', inv.status];

  // Дні залишились
  const days = parseInt(inv.days_left);
  let daysHtml = '—';
  if (inv.status === 'active') {
    const cls = days < 0 ? 'expired' : days <= 3 ? 'danger' : days <= 7 ? 'warn' : 'ok';
    daysHtml = `<div class="days-left ${cls}">
      ${days < 0 ? 'Прострочено' : days === 0 ? 'Сьогодні' : days + ' дн.'}
    </div>`;
  } else if (inv.status === 'frozen') {
    daysHtml = `<div style="font-size:12px;color:var(--warning)">&#10052; ${inv.freeze_days} дн.</div>`;
  }

  // Оплата
  const paid    = parseFloat(inv.paid_amount || 0);
  const price   = parseFloat(inv.price || 0);
  const debt    = parseFloat(inv.debt  || 0);
  const paidPct = price > 0 ? Math.min(100, Math.round(paid / price * 100)) : 100;
  let payHtml = `<div style="font-size:13px">${formatMoney(paid)}`;
  if (price > 0) payHtml += ` / ${formatMoney(price)}`;
  payHtml += '</div>';
  if (debt > 0.01) payHtml += `<span class="debt-badge">борг ${formatMoney(debt)}</span>`;

  // Відвідування
  let visitsHtml = '—';
  if (inv.visits_total) {
    const pct = Math.min(100, Math.round(inv.visits_used / inv.visits_total * 100));
    visitsHtml = `
      <div class="visits-progress">
        <span style="font-size:13px">${inv.visits_used}/${inv.visits_total}</span>
        <div class="visits-bar-track">
          <div class="visits-bar-fill" style="width:${pct}%"></div>
        </div>
      </div>`;
  } else {
    visitsHtml = '<span style="font-size:12px;color:var(--text-muted)">безліміт</span>';
  }

  return `
    <tr onclick="openDetail(${inv.id})" style="cursor:pointer">
      <td class="mob-primary">
        <div class="inv-client-info">
          <div class="inv-client-name">${esc(inv.client_name)}</div>
          <div class="inv-client-phone">${inv.client_phone || '—'}</div>
        </div>
      </td>
      <td data-label="Тариф">
        <div class="inv-tariff-name" title="${esc(inv.tariff_name)}">${esc(inv.tariff_name)}</div>
        <div class="inv-tariff-dates">${formatDate(inv.start_date)} — ${formatDate(inv.end_date)}</div>
      </td>
      <td data-label="Статус"><span class="badge ${sBadge}">${sLabel}</span></td>
      <td data-label="Залишилось" class="mob-hide">${daysHtml}</td>
      <td data-label="Оплата">${payHtml}</td>
      <td data-label="Відвідування" class="mob-hide">${visitsHtml}</td>
      <td class="mob-actions" onclick="event.stopPropagation()">
        <button class="btn btn-ghost btn-sm" onclick="openDetail(${inv.id})" title="Деталі">&#128269;</button>
        ${state.canWrite && inv.status !== 'cancelled' ? `<button class="btn btn-ghost btn-sm" data-shift-action onclick="event.stopPropagation();openDetailEdit(${inv.id})" title="Редагувати">&#9998;</button>` : ''}
        ${state.isOwner && inv.status !== 'cancelled' ? `<button class="btn btn-ghost btn-sm" data-shift-action onclick="event.stopPropagation();doDeleteInvoice(${inv.id})" title="Видалити" style="color:var(--danger)">&#128465;</button>` : ''}
      </td>
    </tr>`;
}

// ── Пагінація ─────────────────────────────────────────────
function renderPagination({ total, page, pages, per_page }) {
  const el = document.getElementById('pagination');
  if (pages <= 1) { el.innerHTML = ''; return; }
  const from = (page - 1) * per_page + 1;
  const to   = Math.min(page * per_page, total);
  let btns = '';
  for (let i = 1; i <= pages; i++) {
    if (i === 1 || i === pages || Math.abs(i - page) <= 2)
      btns += `<button class="page-btn ${i === page ? 'active' : ''}"
                       onclick="loadInvoices(${i})">${i}</button>`;
    else if (Math.abs(i - page) === 3)
      btns += '<button class="page-btn" disabled>…</button>';
  }
  el.innerHTML = `
    <span>${from}–${to} з ${total}</span>
    <div class="pagination-btns">
      <button class="page-btn" onclick="loadInvoices(${page-1})" ${page<=1?'disabled':''}>&#8249;</button>
      ${btns}
      <button class="page-btn" onclick="loadInvoices(${page+1})" ${page>=pages?'disabled':''}>&#8250;</button>
    </div>`;
}

// ── Фільтри ───────────────────────────────────────────────
function setFilter(btn, status) {
  state.filter = status;
  document.querySelectorAll('.inv-filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  loadInvoices(1);
}
function scheduleSearch() {
  clearTimeout(state.searchTimer);
  state.searchTimer = setTimeout(() => loadInvoices(1), 350);
}

// ════════════════════════════════════════════════════════════
// МОДАЛКА ПРОДАЖУ
// ════════════════════════════════════════════════════════════
async function loadTariffs() {
  const res = await api('get_tariffs', {}, 'invoices');
  if (!res.success) return;
  state.tariffs = res.tariffs;
  const sel = document.getElementById('sell-tariff-select');
  if (!sel) return;
  sel.innerHTML = '<option value="">— Оберіть тариф —</option>' +
    (res.tariffs.length
      ? res.tariffs.map(t =>
          `<option value="${t.id}">${esc(t.name)} · ${t.price} грн · ${t.duration_days} дн.` +
          `${t.visits_limit ? ' · ' + t.visits_limit + ' відвід.' : ' · безліміт'}</option>`
        ).join('')
      : '<option disabled>Тарифів немає</option>');
}

function sellSelectTariff(id) {
  state.selectedTariff = state.tariffs.find(t => t.id == id) || null;
  const info = document.getElementById('sell-tariff-info');
  if (!info) return;
  if (state.selectedTariff) {
    const t = state.selectedTariff;
    info.innerHTML =
      `<strong>${esc(t.name)}</strong> · <span style="color:var(--accent)">${t.price} грн</span>` +
      ` · ${t.duration_days} дн.` +
      (t.visits_limit ? ` · ${t.visits_limit} відвід.` : ' · безліміт') +
      (t.description ? `<div style="margin-top:4px;font-size:12px">${esc(t.description)}</div>` : '');
    info.style.display = 'block';
  } else {
    info.style.display = 'none';
  }
}

function openSellModal() {
  state.selectedTariff   = null;
  state.selectedClientId = null;
  const getEl = id => document.getElementById(id);
  if (getEl('sell-client-select')) getEl('sell-client-select').value = '';
  state.selectedClientId = null;
  loadClientsSelect();
  if (getEl('sell-discount'))         getEl('sell-discount').value  = '0';
  if (getEl('sell-paid-now'))         getEl('sell-paid-now').value  = '0';
  if (getEl('sell-notes'))            getEl('sell-notes').value     = '';
  if (getEl('sell-error'))            getEl('sell-error').style.display = 'none';
  if (getEl('sale-summary'))          getEl('sale-summary').style.display = 'none';
  const tSel = document.getElementById('sell-tariff-select');
  if (tSel) tSel.value = '';
  const tInfo = document.getElementById('sell-tariff-info');
  if (tInfo) tInfo.style.display = 'none';
  openModal('modal-sell');
}

// Завантаження клієнтів у select
async function loadClientsSelect() {
  const sel = document.getElementById('sell-client-select');
  if (!sel) return;
  const res = await api('get_list', { per_page: 200, order: 'full_name', dir: 'asc' }, 'clients');
  if (!res.success) return;
  sel.innerHTML = '<option value="">— Оберіть клієнта —</option>' +
    res.clients.map(c =>
      `<option value="${c.id}">${esc(c.full_name)}${c.phone ? ' · ' + c.phone : ''}</option>`
    ).join('');
}

function onSellClientChange() {
  const sel = document.getElementById('sell-client-select');
  state.selectedClientId = sel.value ? +sel.value : null;
}

// Перерахунок підсумку
function recalcSummary() {
  const t = state.selectedTariff;
  if (!t) { const ss = document.getElementById('sale-summary'); if(ss) ss.style.display = 'none'; return; }

  const discount   = Math.max(0, Math.min(100, parseFloat(document.getElementById('sell-discount').value) || 0));
  const finalPrice = Math.round(t.price * (1 - discount / 100) * 100) / 100;
  const paidNow    = Math.min(finalPrice, parseFloat(document.getElementById('sell-paid-now').value) || 0);
  const debt       = Math.max(0, finalPrice - paidNow);

  const startDate = document.getElementById('sell-start-date').value;
  let endDate = '';
  if (startDate) {
    const d = new Date(startDate);
    d.setDate(d.getDate() + parseInt(t.duration_days));
    endDate = d.toLocaleDateString('uk-UA');
  }

  document.getElementById('sum-tariff-price').textContent = formatMoney(t.price);
  document.getElementById('sum-final-price').textContent  = formatMoney(finalPrice);
  document.getElementById('sum-paid-now').textContent     = formatMoney(paidNow);
  document.getElementById('sum-debt').textContent         = formatMoney(debt);
  document.getElementById('sum-end-date').textContent     = endDate;
  document.getElementById('sum-debt-row').style.display   = debt > 0 ? 'flex' : 'none';

  if (discount > 0) {
    document.getElementById('sum-discount-row').style.display  = 'flex';
    document.getElementById('sum-discount-label').textContent  = `Знижка ${discount}%`;
    document.getElementById('sum-discount-val').textContent    = '−' + formatMoney(t.price - finalPrice);
  } else {
    document.getElementById('sum-discount-row').style.display  = 'none';
  }

  const ssum = document.getElementById('sale-summary'); if(ssum) ssum.style.display = 'block';
}

async function submitSell() {
  const errEl = document.getElementById('sell-error');
  errEl.style.display = 'none';

  if (!state.selectedClientId) { errEl.textContent = 'Оберіть клієнта'; errEl.style.display='block'; return; }
  if (!state.selectedTariff)   { errEl.textContent = 'Оберіть тариф';   errEl.style.display='block'; return; }

  const btn = document.getElementById('btn-sell-submit');
  btn.disabled = true; btn.textContent = 'Зберігаємо...';

  const trainerEl   = document.getElementById('sell-trainer');
  const trainerId   = trainerEl ? (parseInt(trainerEl.value) || 0) : 0;
  const trainerName = trainerId && trainerEl
    ? trainerEl.options[trainerEl.selectedIndex]?.textContent || null
    : null;

  const res = await api('create', {
    client_id:      state.selectedClientId,
    tariff_id:      state.selectedTariff.id,
    start_date:     document.getElementById('sell-start-date').value,
    discount:       parseFloat(document.getElementById('sell-discount').value) || 0,
    paid_amount:    parseFloat(document.getElementById('sell-paid-now').value) || 0,
    trainer_id:     trainerId || null,
    trainer_name:   trainerName,
    notes:          document.getElementById('sell-notes').value.trim(),
  }, 'invoices');

  btn.disabled = false; btn.textContent = 'Продати абонемент';

  if (res.success) {
    closeModal('modal-sell');
    toast('Абонемент продано', 'success');
    loadInvoices(state.page);
    loadStats();
  } else {
    errEl.textContent   = res.error;
    errEl.style.display = 'block';
  }
}

// ════════════════════════════════════════════════════════════
// МОДАЛКА ДЕТАЛЕЙ
// ════════════════════════════════════════════════════════════
async function openDetail(id) {
  state.currentInvoiceId = id;
  document.getElementById('detail-loading').style.display  = 'flex';
  document.getElementById('detail-content').style.display  = 'none';
  openModal('modal-detail');

  // Скидаємо вкладки
  document.querySelectorAll('.inv-modal-tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelector('.inv-modal-tab-btn').classList.add('active');
  document.getElementById('det-tab-info').style.display     = 'block';
  document.getElementById('det-tab-payments').style.display = 'none';
  document.getElementById('det-tab-actions').style.display  = 'none';

  const res = await api('get_one', { id }, 'invoices');
  if (!res.success) { closeModal('modal-detail'); toast(res.error, 'error'); return; }

  const inv = res.invoice;
  const pays = res.payments || [];

  // Шапка
  document.getElementById('det-tariff').textContent  = inv.tariff_name;
  document.getElementById('det-client').textContent  =
    inv.client_name + (inv.client_phone ? ' · ' + inv.client_phone : '');

  const statusMap = {
    active:    'badge-active',
    frozen:    'badge-pending',
    expired:   'badge-inactive',
    cancelled: 'badge-inactive',
  };
  const statusLabel = { active:'Активний', frozen:'Заморожений', expired:'Прострочений', cancelled:'Скасований' };
  document.getElementById('det-status-badge').innerHTML =
    `<span class="badge ${statusMap[inv.status] || 'badge-info'}">${statusLabel[inv.status] || inv.status}</span>`;

  // Деталі
  const paid  = parseFloat(inv.paid_amount || 0);
  const price = parseFloat(inv.price       || 0);
  const debt  = Math.max(0, price - paid);
  const rows  = [
    ['Дата початку',  formatDate(inv.start_date)],
    ['Дата кінця',    formatDate(inv.end_date)],
    ['Днів залишилось', parseInt(inv.days_left) >= 0
      ? parseInt(inv.days_left) + ' дн.' : 'Прострочено'],
    ['Ціна',          formatMoney(price)],
    ['Оплачено',      formatMoney(paid) + (debt > 0 ? ` · борг ${formatMoney(debt)}` : '')],
    ['Відвідувань',   inv.visits_total ? `${inv.visits_used} / ${inv.visits_total}` : 'Безліміт'],
    inv.trainer_name ? ['Тренер', inv.trainer_name] : null,
    inv.freeze_days > 0 ? ['Заморожено на', inv.freeze_days + ' дн. з ' + formatDate(inv.freeze_start)] : null,
    inv.admin_name ? ['Менеджер', inv.admin_name] : null,
    inv.notes ? ['Примітки', inv.notes] : null,
  ].filter(Boolean);

  document.getElementById('det-rows').innerHTML = rows.map(([l,v]) => `
    <div class="inv-detail-row">
      <span class="inv-detail-label">${l}</span>
      <span style="font-size:14px;text-align:right">${esc(String(v))}</span>
    </div>`).join('');

  // Платежі
  document.getElementById('det-payments-list').innerHTML = pays.length
    ? pays.map(p => `
        <div class="payment-item">
          <div>
            <span class="payment-method-badge">${payMethodLabel(p.payment_method)}</span>
            <span style="margin-left:8px;font-size:12px;color:var(--text-muted)">
              ${formatDate(p.created_at)} · ${p.admin_name || '—'}
            </span>
            ${p.notes ? `<div style="font-size:11px;color:var(--text-muted)">${esc(p.notes)}</div>` : ''}
          </div>
          <strong style="color:var(--success)">${formatMoney(p.amount)}</strong>
        </div>`).join('')
    : '<div style="font-size:13px;color:var(--text-muted);padding:16px 0">Платежів ще немає</div>';

  // Показуємо/ховаємо форму додавання платежу
  document.getElementById('add-pay-form').style.display =
    (state.canWrite && inv.status !== 'cancelled') ? 'block' : 'none';

  // Вкладка "Дії" — рендеримо залежно від статусу
  const actionsEl = document.getElementById('det-tab-actions');
  if (!state.canWrite) {
    actionsEl.innerHTML = '<div style="font-size:13px;color:var(--text-muted)">Немає доступних дій</div>';
  } else if (inv.status === 'cancelled') {
    actionsEl.innerHTML = state.isOwner ? `
      <div style="padding:14px;background:var(--bg-elevated);border-radius:var(--radius-sm)">
        <div style="font-size:13px;font-weight:600;margin-bottom:8px">Відновити абонемент</div>
        <div style="font-size:12px;color:var(--text-secondary);margin-bottom:10px">
          Статус зміниться на «Активний». Дата кінця залишиться незмінною.
        </div>
        <button class="btn btn-primary btn-sm" onclick="doRestore()">Відновити</button>
      </div>` : '<div style="font-size:13px;color:var(--text-muted)">Немає доступних дій</div>';
  } else {
    // Заморозка
    const freezeBlock = inv.status === 'frozen'
      ? `<div style="font-size:13px;font-weight:600;margin-bottom:10px">
           Розморозити (заморожено на ${inv.freeze_days} дн.)
         </div>
         <button class="btn btn-primary btn-sm" onclick="doFreeze()">Розморозити</button>`
      : `<div style="font-size:13px;font-weight:600;margin-bottom:10px">Заморозити абонемент</div>
         <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
           <div class="form-group" style="margin:0">
             <label>Дата початку *</label>
             <input type="date" id="freeze-start-date" required
                    min="${new Date().toISOString().slice(0,10)}"
                    oninput="onFreezeStartChange()">
           </div>
           <div class="form-group" style="margin:0;display:none" id="freeze-days-wrap">
             <label>Днів</label>
             <input type="number" id="freeze-days" value="7" min="1" max="90"
                    style="max-width:80px" oninput="onFreezeDaysChange()">
           </div>
           <div class="form-group" style="margin:0;display:none" id="freeze-end-wrap">
             <label>Дата кінця</label>
             <input type="date" id="freeze-end-date" disabled
                    style="opacity:.6;cursor:not-allowed">
           </div>
           <button class="btn btn-ghost btn-sm" onclick="doFreeze()" style="margin-bottom:2px;display:none"
                   id="freeze-submit-btn">
             Заморозити
           </button>
         </div>`;

    const cancelBlock = state.isOwner ? `
      <div id="cancel-section" style="padding:14px;background:rgba(248,113,113,.06);
           border:1px solid rgba(248,113,113,.2);border-radius:var(--radius-sm)">
        <div style="font-size:13px;font-weight:600;color:var(--danger);margin-bottom:6px">Небезпечна зона</div>
        <div style="font-size:12px;color:var(--text-secondary);margin-bottom:10px">
          Дія незворотна. Кошти не повертаються автоматично.
        </div>
        <button class="btn btn-danger btn-sm" onclick="doDeleteInvoice(state.currentInvoiceId)">&#128465; Видалити абонемент</button>
      </div>` : '';

    actionsEl.innerHTML = `
      <div style="display:flex;flex-direction:column;gap:12px">
        <div style="padding:14px;background:var(--bg-elevated);border-radius:var(--radius-sm)">
          ${freezeBlock}
        </div>
        ${cancelBlock}
      </div>`;
  }

  document.getElementById('detail-loading').style.display = 'none';
  document.getElementById('detail-content').style.display = 'block';
}

async function submitInvoiceEdit(id) {
  const errEl = document.getElementById('edit-inv-error');
  errEl.style.display = 'none';

  const res = await api('update', {
    id,
    tariff_id:  +document.getElementById('edit-tariff-id').value,
    start_date:  document.getElementById('edit-start-date').value,
    status:      document.getElementById('edit-status').value,
    trainer_id: +document.getElementById('edit-trainer-id').value || 0,
    notes:       document.getElementById('edit-notes').value.trim(),
  }, 'invoices');

  if (!res.success) { errEl.textContent = res.error; errEl.style.display = 'block'; return; }
  toast('Збережено', 'success');
  openDetail(id);
  loadInvoices(state.page);
}

function switchDetTab(tab, btn) {
  document.querySelectorAll('.inv-modal-tab-btn').forEach(b => b.classList.remove('active'));
  ['info','payments','actions'].forEach(t => {
    document.getElementById('det-tab-' + t).style.display = t === tab ? 'block' : 'none';
  });
  btn.classList.add('active');
}

async function addPayment() {
  const amount = parseFloat(document.getElementById('pay-amount').value);
  const method = document.getElementById('pay-method').value;
  if (!amount || amount <= 0) { toast('Введіть суму', 'error'); return; }

  const res = await api('add_payment', {
    invoice_id: state.currentInvoiceId,
    amount, payment_method: method,
  }, 'invoices');

  if (res.success) {
    toast('Платіж записано', 'success');
    document.getElementById('pay-amount').value = '';
    openDetail(state.currentInvoiceId);
    loadStats();
  } else {
    toast(res.error, 'error');
  }
}

function onFreezeStartChange() {
  const startVal = document.getElementById('freeze-start-date').value;
  const daysWrap = document.getElementById('freeze-days-wrap');
  const endWrap  = document.getElementById('freeze-end-wrap');
  const btn      = document.getElementById('freeze-submit-btn');

  if (!startVal) {
    daysWrap.style.display = 'none';
    endWrap.style.display  = 'none';
    btn.style.display      = 'none';
    return;
  }

  daysWrap.style.display = 'block';
  endWrap.style.display  = 'block';
  btn.style.display      = 'inline-flex';
  onFreezeDaysChange();
}

function onFreezeDaysChange() {
  const startVal = document.getElementById('freeze-start-date').value;
  const days     = parseInt(document.getElementById('freeze-days').value) || 0;
  const endInput = document.getElementById('freeze-end-date');
  if (!startVal || days < 1) { endInput.value = ''; return; }
  const end = new Date(startVal);
  end.setDate(end.getDate() + days);
  endInput.value = end.toISOString().slice(0, 10);
}

async function doFreeze() {
  const daysEl  = document.getElementById('freeze-days');
  const startEl = document.getElementById('freeze-start-date');
  const days    = daysEl  ? parseInt(daysEl.value)  : 0;
  const freezeStart = startEl ? startEl.value : '';

  const res = await api('freeze', {
    id: state.currentInvoiceId, days, freeze_start: freezeStart,
  }, 'invoices');

  if (res.success) {
    toast(res.message, 'success');
    closeModal('modal-detail');
    loadInvoices(state.page);
    loadStats();
  } else {
    toast(res.error, 'error');
  }
}

async function doDeleteInvoice(id) {
  if (!confirm('Видалити абонемент? Дія незворотна.')) return;
  const res = await api('delete', { id }, 'invoices');
  if (res.success) {
    toast('Абонемент видалено', 'success');
    closeModal('modal-detail');
    loadInvoices(state.page);
    loadStats();
  } else {
    toast(res.error, 'error');
  }
}

async function submitInvoiceEdit(id) {
  const errEl = document.getElementById('edit-inv-error');
  errEl.style.display = 'none';

  const payload = {
    id,
    start_date:   document.getElementById('edit-start-date')?.value   || '',
    tariff_id:    document.getElementById('edit-tariff-id')?.value    || 0,
    trainer_name: document.getElementById('edit-trainer-name')?.value || '',
    notes:        document.getElementById('edit-notes')?.value        || '',
  };

  const res = await api('update', payload, 'invoices');
  if (!res.success) {
    errEl.textContent = res.error;
    errEl.style.display = 'block';
    return;
  }
  toast('Збережено', 'success');
  closeModal('modal-detail');
  loadInvoices(state.page);
}

async function doCancel() {
  if (!confirm('Скасувати абонемент? Дія незворотна.')) return;
  const res = await api('cancel', { id: state.currentInvoiceId }, 'invoices');
  if (res.success) {
    toast('Абонемент скасовано', 'success');
    closeModal('modal-detail');
    loadInvoices(state.page);
    loadStats();
  } else {
    toast(res.error, 'error');
  }
}

async function doRestore() {
  const res = await api('restore', { id: state.currentInvoiceId }, 'invoices');
  if (res.success) {
    toast('Абонемент відновлено', 'success');
    closeModal('modal-detail');
    loadInvoices(state.page);
    loadStats();
  } else {
    toast(res.error, 'error');
  }
}

// ── Утиліти ───────────────────────────────────────────────
function payMethodLabel(m) {
  const map = {
    cash: 'Готівка', card: 'Карта', terminal: 'Термінал',
    deposit: 'Депозит', transfer: 'Переказ', free: 'Безкоштовно',
  };
  return map[m] || m || '—';
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<!-- Модалка редагування абонементу -->
<div class="modal-overlay" id="modal-inv-edit">
  <div class="modal" style="max-width:460px">
    <button class="modal-close" onclick="closeModal('modal-inv-edit')">&#10005;</button>
    <h2 class="modal-title">Редагувати абонемент</h2>
    <input type="hidden" id="inv-edit-id">
    <div id="inv-edit-error" class="alert alert-error" style="display:none"></div>
    <div id="inv-edit-form">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px">
        <div class="form-group" style="grid-column:1/-1">
          <label>Тариф</label>
          <select id="inv-edit-tariff-id"></select>
        </div>
        <div class="form-group">
          <label>Дата початку</label>
          <input type="date" id="inv-edit-start-date">
        </div>
        <div class="form-group">
          <label>Статус</label>
          <select id="inv-edit-status">
            <option value="active">Активний</option>
            <option value="expired">Прострочений</option>
            <option value="frozen">Заморожений</option>
            <option value="cancelled">Скасований</option>
          </select>
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label>Тренер</label>
          <select id="inv-edit-trainer-id"></select>
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label>Нотатки</label>
          <textarea id="inv-edit-notes" rows="2" placeholder="Необов'язково..."></textarea>
        </div>
      </div>
      <p style="font-size:11px;color:var(--text-muted);margin:4px 0 12px">
        ℹ️ Дата закінчення, ціна та ліміт відвідувань перераховуються автоматично з тарифу
      </p>
      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button class="btn btn-ghost" onclick="closeModal('modal-inv-edit')">Скасувати</button>
        <button class="btn btn-primary" id="inv-edit-submit" onclick="submitInvEdit()">Зберегти</button>
      </div>
    </div>
  </div>
</div>
</body>
