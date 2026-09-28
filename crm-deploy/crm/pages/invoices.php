<?php
$pageTitle = 'Абонементи';
$pageCss   = 'invoices';
require __DIR__ . '/../partials/head.php';
?>
<body>
<div class="app-layout">

  <!-- aside і header НЕ пишемо — sidebar.js вставить їх сам -->

    <main class="app-main">

    <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
      <div>
        <h1 class="page-title">Абонементи</h1>
        <p class="page-subtitle" id="inv-subtitle">Завантаження...</p>
      </div>
      <button class="btn btn-primary" id="btn-sell" onclick="openSellModal()" style="display:none">
        + Продати абонемент
      </button>
    </div>

    <!-- Статистика -->
    <div class="inv-stats">
      <div class="inv-stat">
        <div class="is-label">Активних</div>
        <div class="is-value" id="st-active"><span class="spinner"></span></div>
        <div class="is-sub" id="st-active-sub">—</div>
      </div>
      <div class="inv-stat orange">
        <div class="is-label">Закінчуються</div>
        <div class="is-value" id="st-expiring">—</div>
        <div class="is-sub">протягом 7 днів</div>
      </div>
      <div class="inv-stat red">
        <div class="is-label">Боргів</div>
        <div class="is-value" id="st-debt">—</div>
        <div class="is-sub" id="st-debt-sum">—</div>
      </div>
      <div class="inv-stat green">
        <div class="is-label">Продано цього місяця</div>
        <div class="is-value" id="st-sold">—</div>
        <div class="is-sub" id="st-income">—</div>
      </div>
    </div>

    <!-- Фільтри і пошук -->
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
    </div>

    <!-- Таблиця -->
    <div class="card" style="padding:0;overflow:hidden">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Клієнт</th>
              <th>Тариф</th>
              <th>Статус</th>
              <th>Залишилось</th>
              <th>Оплата</th>
              <th>Відвідування</th>
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
      <div class="pagination" id="pagination" style="padding:16px 20px"></div>
    </div>

  </main>
</div>

<!-- ════════════════════════════════════════════
     МОДАЛКА: ПРОДАТИ АБОНЕМЕНТ
════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-sell">
  <div class="modal" style="max-width:620px">
    <button class="modal-close" onclick="closeModal('modal-sell')">&#10005;</button>
    <h2 class="modal-title">Продати абонемент</h2>
    <div id="sell-error" class="alert alert-error" style="display:none"></div>

    <!-- Крок 1: Вибір клієнта -->
    <div id="sell-step-1">
      <div class="form-group">
        <label>Клієнт *</label>
        <div style="position:relative">
          <input type="text" id="client-search-input"
                 placeholder="Введіть ім'я або телефон..."
                 oninput="searchClients()" autocomplete="off">
          <div id="client-dropdown" style="
            display:none;position:absolute;top:100%;left:0;right:0;
            background:var(--bg-elevated);border:1px solid var(--border-light);
            border-radius:var(--radius-sm);z-index:100;max-height:200px;overflow-y:auto;
            box-shadow:var(--shadow-md);margin-top:4px;
          "></div>
        </div>
        <div id="selected-client-badge" style="display:none;margin-top:8px">
          <div style="display:flex;align-items:center;gap:10px;padding:10px 12px;
                      background:var(--accent-dim);border:1px solid rgba(79,156,249,.3);
                      border-radius:var(--radius-sm)">
            <div style="font-size:14px;font-weight:500" id="sel-client-name">—</div>
            <div style="font-size:12px;color:var(--text-secondary)" id="sel-client-phone">—</div>
            <div style="margin-left:auto;font-size:12px;color:var(--text-secondary)">
              Баланс: <strong id="sel-client-balance">—</strong>
            </div>
            <button style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:16px"
                    onclick="clearClient()">&#10005;</button>
          </div>
        </div>
      </div>

      <!-- Вибір тарифу -->
      <div class="form-group">
        <label>Тариф *</label>
        <div class="tariff-picker" id="tariff-picker">
          <div class="loader"><div class="spinner"></div></div>
        </div>
      </div>

      <!-- Дата початку -->
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0 14px">
        <div class="form-group">
          <label>Дата початку *</label>
          <input type="date" id="sell-start-date" onchange="recalcSummary()">
        </div>
        <div class="form-group">
          <label>Знижка (%)</label>
          <input type="number" id="sell-discount" value="0" min="0" max="100"
                 step="1" oninput="recalcSummary()">
        </div>
        <div class="form-group">
          <label>Оплата зараз (грн)</label>
          <input type="number" id="sell-paid-now" value="0" min="0" step="1"
                 oninput="recalcSummary()">
        </div>
      </div>

      <div class="form-group">
        <label>Примітка</label>
        <textarea id="sell-notes" placeholder="Необов'язково..." rows="2"></textarea>
      </div>

      <div style="display:flex;gap:10px;margin-top:8px">
        <button class="btn btn-primary" id="btn-sell-submit" onclick="submitSell()">
          Продати абонемент
        </button>
        <button class="btn btn-ghost" onclick="closeModal('modal-sell')">Скасувати</button>
      </div>
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
  const ctx = await initPage();
  if (!ctx) return;
  guardBlockedClub(ctx.isBlocked);
  const { u, isSuperAdmin, inClubMode, club, clubRole } = ctx;
  // Права визначаємо з clubRole або global_level (SuperAdmin)
  const _level = isSuperAdmin ? 100 : (clubRole?.level ?? 0);
  state.canWrite = !ctx.isBlocked && _level >= 50;
  state.isOwner  = _level >= 80;

  if (state.canWrite) document.getElementById('btn-sell').style.display = 'inline-flex';

  // Дата за замовчуванням
  document.getElementById('sell-start-date').value = new Date().toISOString().split('T')[0];

  loadStats();
  loadInvoices(1);
  loadTariffs();
});

// ── Статистика ────────────────────────────────────────────
async function loadStats() {
  const res = await api('get_stats', {}, 'invoices');
  if (!res.success) return;
  const s = res.stats;
  document.getElementById('st-active').textContent    = s.active;
  document.getElementById('st-active-sub').textContent= 'заморожених: ' + s.frozen;
  document.getElementById('st-expiring').textContent  = s.expiring_soon;
  document.getElementById('st-debt').textContent      = s.with_debt;
  document.getElementById('st-debt-sum').textContent  =
    s.total_debt > 0 ? formatMoney(s.total_debt) + ' борг' : '—';
  document.getElementById('st-sold').textContent      = s.sold_count;
  document.getElementById('st-income').textContent    = formatMoney(s.income_month);
}

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
  document.getElementById('inv-subtitle').textContent = `Всього: ${pagination.total}`;

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

  tbody.innerHTML = invoices.map(inv => renderRow(inv)).join('');
  renderPagination(pagination);
}

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
      <td>
        <div class="inv-client-info">
          <div class="inv-client-name">${esc(inv.client_name)}</div>
          <div class="inv-client-phone">${inv.client_phone || '—'}</div>
        </div>
      </td>
      <td>
        <div class="inv-tariff-name" title="${esc(inv.tariff_name)}">${esc(inv.tariff_name)}</div>
        <div class="inv-tariff-dates">
          ${formatDate(inv.start_date)} — ${formatDate(inv.end_date)}
        </div>
      </td>
      <td><span class="badge ${sBadge}">${sLabel}</span></td>
      <td>${daysHtml}</td>
      <td>${payHtml}</td>
      <td>${visitsHtml}</td>
      <td onclick="event.stopPropagation()">
        <button class="btn btn-ghost btn-sm" onclick="openDetail(${inv.id})"
                title="Деталі">&#128269;</button>
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

  const picker = document.getElementById('tariff-picker');
  if (!res.tariffs.length) {
    picker.innerHTML =
      '<div style="font-size:13px;color:var(--text-muted);grid-column:1/-1">' +
      'Тарифів ще немає. <a href="/settings">Додайте тарифи</a> у налаштуваннях.</div>';
    return;
  }

  picker.innerHTML = res.tariffs.map(t => `
    <div class="tariff-card" data-id="${t.id}" onclick="selectTariff(${t.id})">
      <div class="tc-name">${esc(t.name)}</div>
      <div class="tc-price">${t.price} грн</div>
      <div class="tc-meta">
        ${t.duration_days} дн.
        ${t.visits_limit ? ' · ' + t.visits_limit + ' відвід.' : ' · безліміт'}
        ${t.category ? ' · ' + t.category : ''}
      </div>
    </div>
  `).join('');

  // Також завантажуємо тренерів
  const staffRes = await api('get_list', {}, 'users');
  if (staffRes.success) {
    const trainers = (staffRes.staff || []).filter(u => u.role_slug === 'trainer');
    const sel = document.getElementById('sell-trainer');
    trainers.forEach(t => {
      const opt = document.createElement('option');
      opt.value = t.id;
      opt.textContent = t.full_name;
      sel.appendChild(opt);
    });
  }
}

function selectTariff(id) {
  state.selectedTariff = state.tariffs.find(t => t.id == id);
  document.querySelectorAll('.tariff-card').forEach(c => c.classList.remove('selected'));
  document.querySelector(`.tariff-card[data-id="${id}"]`)?.classList.add('selected');
  recalcSummary();
}

function openSellModal() {
  state.selectedTariff   = null;
  state.selectedClientId = null;
  document.getElementById('client-search-input').value = '';
  document.getElementById('selected-client-badge').style.display = 'none';
  document.getElementById('client-dropdown').style.display = 'none';
  document.getElementById('sell-discount').value  = '0';
  document.getElementById('sell-paid-now').value  = '0';
  document.getElementById('sell-notes').value     = '';
  document.getElementById('sell-error').style.display = 'none';
  document.getElementById('sale-summary').style.display = 'none';
  document.querySelectorAll('.tariff-card').forEach(c => c.classList.remove('selected'));
  openModal('modal-sell');
}

// Пошук клієнта
let clientTimer = null;
function searchClients() {
  clearTimeout(clientTimer);
  const q = document.getElementById('client-search-input').value.trim();
  if (q.length < 2) {
    document.getElementById('client-dropdown').style.display = 'none';
    return;
  }
  clientTimer = setTimeout(async () => {
    const res = await api('search', { q }, 'clients');
    const dd  = document.getElementById('client-dropdown');
    if (!res.success || !res.results?.length) {
      dd.style.display = 'none'; return;
    }
    dd.innerHTML = res.results.map(c => `
      <div onclick="selectClient(${c.id},'${esc(c.full_name)}','${c.phone||''}',0)"
           style="padding:9px 12px;cursor:pointer;font-size:14px;border-bottom:1px solid var(--border)"
           onmouseover="this.style.background='var(--bg-hover)'"
           onmouseout="this.style.background=''">
        <strong>${esc(c.full_name)}</strong>
        <span style="color:var(--text-muted);margin-left:8px;font-size:12px">${c.phone||''}</span>
      </div>`).join('');
    dd.style.display = 'block';
  }, 300);
}

async function selectClient(id, name, phone, balance) {
  // Завантажуємо актуальний баланс
  const res = await api('get_one', { id }, 'clients');
  const bal = res.success ? parseFloat(res.client.balance || 0) : balance;

  state.selectedClientId = id;
  document.getElementById('client-search-input').value   = '';
  document.getElementById('client-dropdown').style.display = 'none';
  document.getElementById('selected-client-badge').style.display = 'flex';
  document.getElementById('sel-client-name').textContent    = name;
  document.getElementById('sel-client-phone').textContent   = phone;
  document.getElementById('sel-client-balance').textContent = formatMoney(bal);
  recalcSummary();
}

function clearClient() {
  state.selectedClientId = null;
  document.getElementById('selected-client-badge').style.display = 'none';
}

// Перерахунок підсумку
function recalcSummary() {
  const t = state.selectedTariff;
  if (!t) { document.getElementById('sale-summary').style.display = 'none'; return; }

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

  document.getElementById('sale-summary').style.display = 'block';
}

async function submitSell() {
  const errEl = document.getElementById('sell-error');
  errEl.style.display = 'none';

  if (!state.selectedClientId) { errEl.textContent = 'Оберіть клієнта'; errEl.style.display='block'; return; }
  if (!state.selectedTariff)   { errEl.textContent = 'Оберіть тариф';   errEl.style.display='block'; return; }

  const btn = document.getElementById('btn-sell-submit');
  btn.disabled = true; btn.textContent = 'Зберігаємо...';

  const trainerId  = parseInt(document.getElementById('sell-trainer').value) || 0;
  const trainerSel = document.getElementById('sell-trainer');
  const trainerName = trainerId
    ? trainerSel.options[trainerSel.selectedIndex]?.textContent || null
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
        <div style="font-size:13px;font-weight:600;color:var(--danger);margin-bottom:6px">Скасувати абонемент</div>
        <div style="font-size:12px;color:var(--text-secondary);margin-bottom:10px">
          Дія незворотна. Кошти не повертаються автоматично.
        </div>
        <button class="btn btn-danger btn-sm" onclick="doCancel()">Скасувати абонемент</button>
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
</body>
