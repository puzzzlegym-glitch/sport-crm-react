<?php
$pageTitle = 'Каса';
$pageCss   = 'cash';
require __DIR__ . '/../partials/head.php';
?>
<body>
<div class="app-layout">
  <main class="app-main">

    <!-- ══ ПАНЕЛЬ ЗМІНИ ══ -->
    <div id="shift-panel" class="shift-panel shift-closed">
      <div class="shift-panel-info">
        <span id="shift-status-icon">🔴</span>
        <span id="shift-status-text">Зміна не відкрита</span>
        <span id="shift-admin-info" style="color:var(--text-muted);font-size:13px"></span>
      </div>
      <div class="shift-panel-balances" id="shift-balances" style="display:none">
        <span>Початок: <strong id="shift-balance-open">—</strong></span>
        <span>Поточний залишок: <strong id="shift-balance-now">—</strong></span>
      </div>
      <div class="shift-panel-actions">
        <button class="btn btn-success btn-sm" id="btn-open-shift"  onclick="openShiftModal()"  style="display:none">▶ Відкрити зміну</button>
        <button class="btn btn-warning btn-sm" id="btn-close-shift" onclick="closeShiftModal()" style="display:none">■ Закрити зміну</button>
      </div>
    </div>

    <!-- ══ КАРТКИ БАЛАНСУ ══ -->
    <div class="cash-summary">
      <div class="cash-card balance">
        <div class="cash-card-label">💰 Залишок в касі</div>
        <div class="cash-card-value" id="s-balance">—</div>
        <div class="cash-card-sub">За весь час</div>
      </div>
      <div class="cash-card safe">
        <div class="cash-card-label">🔒 Сейф</div>
        <div class="cash-card-value" id="s-safe">—</div>
        <div class="cash-card-sub">За весь час</div>
      </div>
      <div class="cash-card income">
        <div class="cash-card-label">📈 Надходження</div>
        <div class="cash-card-value" id="s-income">—</div>
        <div class="cash-card-sub" id="s-period">За період</div>
      </div>
      <div class="cash-card expense">
        <div class="cash-card-label">📉 Витрати + інкасації</div>
        <div class="cash-card-value" id="s-expense">—</div>
        <div class="cash-card-sub">За період</div>
      </div>
      <div class="cash-card profit">
        <div class="cash-card-label">⚖️ Різниця за період</div>
        <div class="cash-card-value" id="s-profit">—</div>
        <div class="cash-card-sub">Надходження − Витрати</div>
      </div>
    </div>

    <!-- ══ ТУЛБАР ══ -->
    <div class="cash-toolbar">
      <div class="form-group">
        <label>Від</label>
        <input type="date" id="date-from" onchange="reload()">
      </div>
      <div class="form-group">
        <label>До</label>
        <input type="date" id="date-to" onchange="reload()">
      </div>
      <div class="cash-toolbar-actions">
        <button class="btn btn-ghost"  id="btn-expense"    data-shift-action onclick="openExpenseModal()"    style="display:none">+ Витрата</button>
        <button class="btn btn-ghost"  id="btn-encashment" data-shift-action onclick="openEncashmentModal()" style="display:none">⬇ Інкасація в сейф</button>
        <button class="btn btn-ghost"  id="btn-refill"     data-shift-action onclick="openRefillModal()"     style="display:none">⬆ Поповнити з сейфа</button>
        <button class="btn btn-ghost"  id="btn-adjust"     onclick="openAdjustModal()"                       style="display:none">⚖ Коригування</button>
      </div>
    </div>

    <!-- ══ ФІЛЬТР ТИПУ ══ -->
    <div class="cash-filter-bar">
      <button class="cash-filter-btn active" onclick="setFilter(this,'')">Всі</button>
      <button class="cash-filter-btn" onclick="setFilter(this,'income')">📈 Надходження</button>
      <button class="cash-filter-btn" onclick="setFilter(this,'expense')">📉 Витрати</button>
      <button class="cash-filter-btn" onclick="setFilter(this,'transfer')">🔁 Перекази</button>
      <button class="cash-filter-btn" onclick="setFilter(this,'adjustment')">⚖ Коригування</button>
    </div>

    <!-- ══ ТАБЛИЦЯ ══ -->
    <div class="card" style="padding:0;overflow:hidden">
      <div class="table-wrap">
        <table class="compact-card-table">
          <thead>
            <tr>
              <th>Тип</th>
              <th>Опис</th>
              <th>Категорія</th>
              <th>Сума</th>
              <th>Залишок</th>
              <th>Менеджер</th>
              <th>Дата</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="cash-tbody">
            <tr><td colspan="7"><div class="loader"><div class="spinner"></div></div></td></tr>
          </tbody>
        </table>
      </div>
      <div id="pagination" style="padding:12px 20px"></div>
    </div>

  </main>
</div>

<!-- ════ МОДАЛКА: ВІДКРИТИ ЗМІНУ ════ -->
<div class="modal-overlay" id="modal-open-shift">
  <div class="modal" style="max-width:400px">
    <button class="modal-close" onclick="closeModal('modal-open-shift')">&#10005;</button>
    <h2 class="modal-title">▶ Відкрити зміну</h2>
    <div id="os-error" class="alert alert-error" style="display:none"></div>
    <p style="font-size:13px;color:var(--text-secondary);margin-bottom:12px">
      Залишок на початок зміни: <strong id="os-balance">—</strong>
    </p>
    <div class="form-group">
      <label>Коментар (необов'язково)</label>
      <input type="text" id="os-notes" placeholder="Відкриття зміни...">
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-success" id="btn-os-submit" onclick="submitOpenShift()">Відкрити</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-open-shift')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: ЗАКРИТИ ЗМІНУ ════ -->
<div class="modal-overlay" id="modal-close-shift">
  <div class="modal" style="max-width:400px">
    <button class="modal-close" onclick="closeModal('modal-close-shift')">&#10005;</button>
    <h2 class="modal-title">■ Закрити зміну</h2>
    <div id="cs-error" class="alert alert-error" style="display:none"></div>
    <div id="cs-summary" style="background:var(--bg-surface);border-radius:var(--radius);padding:14px;margin-bottom:16px;font-size:13px;display:grid;gap:6px">
      <div>Відкрив: <strong id="cs-opened-name">—</strong></div>
      <div>Час відкриття: <strong id="cs-opened-at">—</strong></div>
      <div>На початок: <strong id="cs-balance-open">—</strong></div>
      <div>На кінець (зараз): <strong id="cs-balance-now">—</strong></div>
    </div>
    <div class="form-group">
      <label>Коментар (необов'язково)</label>
      <input type="text" id="cs-notes" placeholder="Підсумок зміни...">
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-danger" id="btn-cs-submit" onclick="submitCloseShift()">Закрити зміну</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-close-shift')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: ВИТРАТА ════ -->
<div class="modal-overlay" id="modal-expense">
  <div class="modal" style="max-width:420px">
    <button class="modal-close" onclick="closeModal('modal-expense')">&#10005;</button>
    <h2 class="modal-title">Витрата з каси</h2>
    <div id="exp-error" class="alert alert-error" style="display:none"></div>
    <div class="form-group">
      <label>Сума (грн) *</label>
      <input type="number" id="exp-amount" min="0.01" step="0.01" placeholder="0.00">
    </div>
    <div class="form-group">
      <label>Категорія</label>
      <select id="exp-category">
        <option value="">— Оберіть —</option>
        <option>Оренда</option>
        <option>Комунальні</option>
        <option>Зарплата</option>
        <option>Закупка товарів</option>
        <option>Господарські</option>
        <option>Реклама</option>
        <option>Інше</option>
      </select>
    </div>
    <div class="form-group">
      <label>Опис *</label>
      <input type="text" id="exp-desc" placeholder="Оплата оренди за квітень...">
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" id="btn-exp-submit" onclick="submitExpense()">Записати</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-expense')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: ІНКАСАЦІЯ ════ -->
<div class="modal-overlay" id="modal-encashment">
  <div class="modal" style="max-width:400px">
    <button class="modal-close" onclick="closeModal('modal-encashment')">&#10005;</button>
    <h2 class="modal-title">⬇ Інкасація в сейф</h2>
    <div id="enc-error" class="alert alert-error" style="display:none"></div>
    <p style="font-size:13px;color:var(--text-secondary);margin-bottom:16px">
      Залишок у касі: <strong id="enc-balance">—</strong>
    </p>
    <div class="form-group">
      <label>Сума виїмки (грн) *</label>
      <input type="number" id="enc-amount" min="0.01" step="0.01" placeholder="0.00">
    </div>
    <div class="form-group">
      <label>Коментар</label>
      <input type="text" id="enc-desc" placeholder="Інкасація за день...">
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" id="btn-enc-submit" onclick="submitEncashment()">Провести</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-encashment')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: ПОПОВНЕННЯ КАСИ З СЕЙФА ════ -->
<div class="modal-overlay" id="modal-refill">
  <div class="modal" style="max-width:400px">
    <button class="modal-close" onclick="closeModal('modal-refill')">&#10005;</button>
    <h2 class="modal-title">⬆ Поповнити касу з сейфа</h2>
    <div id="refill-error" class="alert alert-error" style="display:none"></div>
    <p style="font-size:13px;color:var(--text-secondary);margin-bottom:16px">
      Залишок у сейфі: <strong id="refill-balance">—</strong>
    </p>
    <div class="form-group">
      <label>Сума поповнення (грн) *</label>
      <input type="number" id="refill-amount" min="0.01" step="0.01" placeholder="0.00">
    </div>
    <div class="form-group">
      <label>Коментар</label>
      <input type="text" id="refill-desc" placeholder="Поповнення каси...">
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" id="btn-refill-submit" onclick="submitRefill()">Провести</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-refill')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: КОРИГУВАННЯ БАЛАНСУ ════ -->
<div class="modal-overlay" id="modal-adjust">
  <div class="modal" style="max-width:420px">
    <button class="modal-close" onclick="closeModal('modal-adjust')">&#10005;</button>
    <h2 class="modal-title">⚖ Коригування балансу</h2>
    <div id="adj-error" class="alert alert-error" style="display:none"></div>
    <p style="font-size:13px;color:var(--text-secondary);margin-bottom:16px">
      Використовується для стартового залишку або при розбіжності з фактичним перерахунком готівки.
      Сума додається до балансу — від'ємне число зменшить його.
    </p>
    <div class="form-group">
      <label>Локація</label>
      <select id="adj-location">
        <option value="register">Каса</option>
        <option value="safe">Сейф</option>
      </select>
    </div>
    <div class="form-group">
      <label>Сума (грн, зі знаком) *</label>
      <input type="number" id="adj-amount" step="0.01" placeholder="напр. 500 або -120">
    </div>
    <div class="form-group">
      <label>Коментар</label>
      <input type="text" id="adj-notes" placeholder="Стартовий залишок / перерахунок готівки...">
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" id="btn-adj-submit" onclick="submitAdjust()">Скоригувати</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-adjust')">Скасувати</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../partials/scripts.php'; ?>
<script>
let state = { typeFilter: '', page: 1, canWrite: false, isOwner: false, shiftId: null };

window.addEventListener('DOMContentLoaded', async () => {
  const ctx = await initPage({ title: 'Каса' });
  if (!ctx) return;
  guardBlockedClub(ctx.isBlocked);

  const perms = getPermissions(ctx);
  state.canWrite = perms.canWrite;
  state.isOwner  = perms.isOwner;
  window._shiftCtx = ctx;

  if (state.canWrite) {
    document.querySelectorAll('#btn-expense, #btn-encashment, #btn-refill, #btn-adjust').forEach(b => b.style.display = 'inline-flex');
  }

  const today  = new Date().toISOString().split('T')[0];
  const month1 = today.substring(0, 8) + '01';
  document.getElementById('date-from').value = month1;
  document.getElementById('date-to').value   = today;

  await loadSummary();
  loadList(1);
});

// ── Підсумок + зміна ─────────────────────────────────────
async function loadSummary() {
  const res = await api('get_summary', {
    date_from: document.getElementById('date-from').value,
    date_to:   document.getElementById('date-to').value,
  }, 'cash');
  if (!res.success) return;

  document.getElementById('s-balance').textContent  = formatMoney(res.balance);
  document.getElementById('s-safe').textContent     = formatMoney(res.safe_balance);
  document.getElementById('s-income').textContent   = formatMoney(res.period_income);
  document.getElementById('s-expense').textContent  = formatMoney(res.period_expenses);
  const profEl = document.getElementById('s-profit');
  profEl.textContent = formatMoney(res.period_profit);
  profEl.style.color = res.period_profit >= 0 ? 'var(--success)' : 'var(--danger)';

  renderShiftPanel(res.shift, res.balance);
}

// ── Панель зміни ─────────────────────────────────────────
function renderShiftPanel(shift, balanceNow) {
  const panel    = document.getElementById('shift-panel');
  const icon     = document.getElementById('shift-status-icon');
  const text     = document.getElementById('shift-status-text');
  const adminInf = document.getElementById('shift-admin-info');
  const balances = document.getElementById('shift-balances');
  const btnOpen  = document.getElementById('btn-open-shift');
  const btnClose = document.getElementById('btn-close-shift');

  if (shift) {
    state.shiftId = shift.id;
    panel.className = 'shift-panel shift-open';
    icon.textContent = '🟢';
    text.textContent = 'Зміна відкрита';
    adminInf.textContent = `${esc(shift.opened_name)} · з ${formatDate(shift.opened_at)}`;
    document.getElementById('shift-balance-open').textContent = formatMoney(shift.balance_open);
    document.getElementById('shift-balance-now').textContent  = formatMoney(balanceNow);
    balances.style.display = 'flex';
    if (state.canWrite) { btnOpen.style.display = 'none'; btnClose.style.display = 'inline-flex'; }
  } else {
    state.shiftId = null;
    panel.className = 'shift-panel shift-closed';
    icon.textContent = '🔴';
    text.textContent = 'Зміна не відкрита';
    adminInf.textContent = '';
    balances.style.display = 'none';
    if (state.canWrite) { btnOpen.style.display = 'inline-flex'; btnClose.style.display = 'none'; }
  }

  // Оновлюємо глобальний ctx і застосовуємо lock на кнопки витрати/інкасації
  if (window._shiftCtx) window._shiftCtx.activeShiftId = shift ? shift.id : null;
  applyShiftLock(window._shiftCtx);
  applyPlanLock(window._shiftCtx);
}

// ── Відкрити зміну ────────────────────────────────────────
async function openShiftModal() {
  document.getElementById('os-notes').value = '';
  document.getElementById('os-error').style.display = 'none';
  // Отримуємо актуальний залишок
  const res = await api('get_shift', {}, 'cash');
  document.getElementById('os-balance').textContent = res.success ? formatMoney(res.balance) : '—';
  openModal('modal-open-shift');
}

async function submitOpenShift() {
  const errEl = document.getElementById('os-error');
  errEl.style.display = 'none';
  const btn = document.getElementById('btn-os-submit');
  btn.disabled = true; btn.textContent = 'Відкриваємо...';

  const res = await api('open_shift', { notes: document.getElementById('os-notes').value.trim() }, 'cash');

  btn.disabled = false; btn.textContent = 'Відкрити';

  if (res.success) {
    closeModal('modal-open-shift');
    toast('Зміну відкрито', 'success');
    await loadSummary();
  } else {
    errEl.textContent = res.error; errEl.style.display = 'block';
  }
}

// ── Закрити зміну ─────────────────────────────────────────
async function closeShiftModal() {
  if (!state.shiftId) return;
  document.getElementById('cs-notes').value = '';
  document.getElementById('cs-error').style.display = 'none';

  const res = await api('get_shift', {}, 'cash');
  if (res.success && res.shift) {
    document.getElementById('cs-opened-name').textContent  = esc(res.shift.opened_name);
    document.getElementById('cs-opened-at').textContent    = formatDate(res.shift.opened_at);
    document.getElementById('cs-balance-open').textContent = formatMoney(res.shift.balance_open);
    document.getElementById('cs-balance-now').textContent  = formatMoney(res.balance);
  }
  openModal('modal-close-shift');
}

async function submitCloseShift() {
  const errEl = document.getElementById('cs-error');
  errEl.style.display = 'none';
  const btn = document.getElementById('btn-cs-submit');
  btn.disabled = true; btn.textContent = 'Закриваємо...';

  const res = await api('close_shift', {
    shift_id: state.shiftId,
    notes:    document.getElementById('cs-notes').value.trim(),
  }, 'cash');

  btn.disabled = false; btn.textContent = 'Закрити зміну';

  if (res.success) {
    closeModal('modal-close-shift');
    toast(`Зміну закрито. Початок: ${formatMoney(res.balance_open)} → Кінець: ${formatMoney(res.balance_close)}`, 'success');
    await loadSummary();
  } else {
    errEl.textContent = res.error; errEl.style.display = 'block';
  }
}

// ── Журнал ───────────────────────────────────────────────
async function loadList(page = 1) {
  state.page = page;
  const tbody = document.getElementById('cash-tbody');
  tbody.innerHTML = '<tr><td colspan="8"><div class="loader"><div class="spinner"></div></div></td></tr>';

  const res = await api('get_list', {
    date_from: document.getElementById('date-from').value,
    date_to:   document.getElementById('date-to').value,
    type:      state.typeFilter,
    page,
  }, 'cash');

  if (!res.success) {
    tbody.innerHTML = `<tr><td colspan="7"><div class="alert alert-error" style="margin:16px">${res.error}</div></td></tr>`;
    return;
  }

  const { rows, pagination } = res;
  const typeLabel = {
    income:'📈 Надходження', expense:'📉 Витрата', encashment:'⬇ Інкасація',
    transfer_in:'⬆ Переказ', transfer_out:'⬇ Переказ', adjustment:'⚖ Коригування',
  };

    tbody.innerHTML = rows.length ? rows.map(r => {
    const amountHtml = r.type === 'adjustment'
      ? `<span class="cash-amount-${r.type}">${formatMoney(r.amount)}</span>`
      : `<span class="cash-amount-${r.type}">${['income','transfer_in'].includes(r.type) ? '+' : '−'}${formatMoney(r.amount)}</span>`;
    const balHtml = `<span style="font-size:13px;font-weight:600;color:var(--text-primary)">${formatMoney(r.running_balance)}</span>`;
    const delBtn = state.isOwner && ['manual','expense','transfer','adjustment'].includes(r.source)
      ? `<button class="confirm-btn" style="color:var(--danger)" onclick="deleteRow(${r.id},'${esc(r.description)}')">🗑</button>`
      : '';
    return `<tr class="cash-row-${r.type}">
      <td><span class="cash-type-badge ${r.type}">${typeLabel[r.type] || r.type}</span></td>
      <td style="font-size:13px">${esc(r.description)}</td>
      <td style="font-size:13px;color:var(--text-secondary)">${esc(r.category||'—')}</td>
      <td>${amountHtml}</td>
      <td>${balHtml}</td>
      <td style="font-size:13px;color:var(--text-secondary)">${esc(r.admin_name||'—')}</td>
      <td style="font-size:13px;color:var(--text-secondary);white-space:nowrap">${formatDate(r.created_at)}</td>
      <td>${delBtn}</td>
    </tr>`;
  }).join('') : '<tr><td colspan="8"><div class="empty-state" style="padding:30px"><div style="font-size:36px">💰</div><p>Операцій за цей період немає</p></div></td></tr>';

  renderPagination(pagination);
}

function renderPagination({ total, page, pages, per_page }) {
  const el = document.getElementById('pagination');
  if (pages <= 1) { el.innerHTML = ''; return; }
  const from = (page - 1) * per_page + 1;
  const to   = Math.min(page * per_page, total);
  let btns = '';
  for (let i = 1; i <= pages; i++) {
    if (i === 1 || i === pages || Math.abs(i - page) <= 2)
      btns += `<button class="page-btn ${i === page ? 'active' : ''}" onclick="loadList(${i})">${i}</button>`;
    else if (Math.abs(i - page) === 3)
      btns += `<span style="color:var(--text-muted);padding:0 4px">…</span>`;
  }
  el.innerHTML = `<div class="pagination">
    <span>${from}–${to} з ${total}</span>
    <div class="pagination-btns">
      <button class="page-btn" onclick="loadList(${page-1})" ${page<=1?'disabled':''}>‹</button>
      ${btns}
      <button class="page-btn" onclick="loadList(${page+1})" ${page>=pages?'disabled':''}>›</button>
    </div>
  </div>`;
}

// ── Фільтр ───────────────────────────────────────────────
function setFilter(btn, type) {
  document.querySelectorAll('.cash-filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  state.typeFilter = type;
  reload();
}

function reload() { loadSummary(); loadList(1); }

// ── Витрата ───────────────────────────────────────────────
function openExpenseModal() {
  document.getElementById('exp-amount').value   = '';
  document.getElementById('exp-desc').value     = '';
  document.getElementById('exp-category').value = '';
  document.getElementById('exp-error').style.display = 'none';
  openModal('modal-expense');
}

async function submitExpense() {
  const errEl = document.getElementById('exp-error');
  errEl.style.display = 'none';
  const btn = document.getElementById('btn-exp-submit');
  btn.disabled = true; btn.textContent = 'Збереження...';

  const res = await api('add_expense', {
    amount:      parseFloat(document.getElementById('exp-amount').value) || 0,
    description: document.getElementById('exp-desc').value.trim(),
    category:    document.getElementById('exp-category').value,
  }, 'cash');

  btn.disabled = false; btn.textContent = 'Записати';
  if (res.success) { closeModal('modal-expense'); toast(res.message, 'success'); reload(); }
  else { errEl.textContent = res.error; errEl.style.display = 'block'; }
}

// ── Інкасація ─────────────────────────────────────────────
async function openEncashmentModal() {
  document.getElementById('enc-amount').value = '';
  document.getElementById('enc-desc').value   = '';
  document.getElementById('enc-error').style.display = 'none';
  const res = await api('get_shift', {}, 'cash');
  document.getElementById('enc-balance').textContent = res.success ? formatMoney(res.balance) : '—';
  openModal('modal-encashment');
}

async function submitEncashment() {
  const errEl = document.getElementById('enc-error');
  errEl.style.display = 'none';
  const btn = document.getElementById('btn-enc-submit');
  btn.disabled = true; btn.textContent = 'Проводимо...';

  const res = await api('encashment', {
    amount:      parseFloat(document.getElementById('enc-amount').value) || 0,
    description: document.getElementById('enc-desc').value.trim() || 'Інкасація',
  }, 'cash');

  btn.disabled = false; btn.textContent = 'Провести';
  if (res.success) { closeModal('modal-encashment'); toast(res.message, 'success'); reload(); }
  else { errEl.textContent = res.error; errEl.style.display = 'block'; }
}

// ── Поповнення каси з сейфа ─────────────────────────────────
async function openRefillModal() {
  document.getElementById('refill-amount').value = '';
  document.getElementById('refill-desc').value   = '';
  document.getElementById('refill-error').style.display = 'none';
  const res = await api('get_shift', {}, 'cash');
  document.getElementById('refill-balance').textContent = res.success ? formatMoney(res.safe_balance) : '—';
  openModal('modal-refill');
}

async function submitRefill() {
  const errEl = document.getElementById('refill-error');
  errEl.style.display = 'none';
  const btn = document.getElementById('btn-refill-submit');
  btn.disabled = true; btn.textContent = 'Проводимо...';

  const res = await api('refill_from_safe', {
    amount:      parseFloat(document.getElementById('refill-amount').value) || 0,
    description: document.getElementById('refill-desc').value.trim() || 'Поповнення каси з сейфа',
  }, 'cash');

  btn.disabled = false; btn.textContent = 'Провести';
  if (res.success) { closeModal('modal-refill'); toast(res.message, 'success'); reload(); }
  else { errEl.textContent = res.error; errEl.style.display = 'block'; }
}

// ── Коригування балансу ──────────────────────────────────────
function openAdjustModal() {
  document.getElementById('adj-location').value = 'register';
  document.getElementById('adj-amount').value    = '';
  document.getElementById('adj-notes').value     = '';
  document.getElementById('adj-error').style.display = 'none';
  openModal('modal-adjust');
}

async function submitAdjust() {
  const errEl = document.getElementById('adj-error');
  errEl.style.display = 'none';
  const btn = document.getElementById('btn-adj-submit');
  btn.disabled = true; btn.textContent = 'Зберігаємо...';

  const res = await api('adjust_balance', {
    location: document.getElementById('adj-location').value,
    amount:   parseFloat(document.getElementById('adj-amount').value) || 0,
    notes:    document.getElementById('adj-notes').value.trim(),
  }, 'cash');

  btn.disabled = false; btn.textContent = 'Скоригувати';
  if (res.success) { closeModal('modal-adjust'); toast(res.message, 'success'); reload(); }
  else { errEl.textContent = res.error; errEl.style.display = 'block'; }
}

// ── Видалення ─────────────────────────────────────────────
async function deleteRow(id, desc) {
  if (!confirm(`Видалити запис "${desc}"?`)) return;
  const res = await api('delete', { id }, 'cash');
  if (res.success) { toast(res.message, 'success'); reload(); }
  else toast(res.error, 'error');
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
