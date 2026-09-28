<?php
$pageTitle = 'Оплати';
$pageCss   = 'payments';
require __DIR__ . '/../partials/head.php';
?>
<body>
<div class="app-layout">

  <main class="app-main">

    <!-- Фільтри -->
    <div class="pay-toolbar">
      <div class="form-group" style="margin:0">
        <label>Від</label>
        <input type="date" id="f-from" onchange="loadPayments(1)">
      </div>
      <div class="form-group" style="margin:0">
        <label>До</label>
        <input type="date" id="f-to" onchange="loadPayments(1)">
      </div>
      <div class="form-group" style="margin:0">
        <label>Спосіб</label>
        <select id="f-method" onchange="loadPayments(1)">
          <option value="">Всі способи</option>
          <option value="cash">Готівка</option>
          <option value="card">Карта</option>
          <option value="terminal">Термінал</option>
          <option value="deposit">Депозит</option>
          <option value="transfer">Переказ</option>
          <option value="free">Безкоштовно</option>
        </select>
      </div>
      <div class="form-group" style="margin:0">
        <label>Тариф</label>
        <select id="f-tariff" onchange="loadPayments(1)">
          <option value="">Всі тарифи</option>
        </select>
      </div>
      <div class="form-group" style="margin:0;flex:1;min-width:160px">
        <label>Клієнт</label>
        <input type="text" id="f-search" placeholder="Ім'я клієнта..." oninput="scheduleSearch()">
      </div>
    </div>

    <!-- Підсумок -->
    <div id="pay-summary" style="display:none" class="pay-summary-row"></div>

    <!-- Мобільні картки (динамічно) -->
    <div id="pay-cards"></div>

    <!-- Таблиця (ПК) -->
    <div class="card" id="pay-table-card" style="padding:0;overflow:hidden">
      <div class="table-wrap">
        <table>
          <thead class="mob-hide">
            <tr>
              <th>Клієнт</th>
              <th>Абонемент</th>
              <th>Спосіб</th>
              <th>Тренер</th>
              <th>Менеджер</th>
              <th style="text-align:right">Сума</th>
              <th>Дата</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="pay-tbody">
            <tr><td colspan="8"><div class="loader"><div class="spinner"></div></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="pagination" id="pagination" style="padding:8px 0"></div>

  </main>
</div>

<?php require __DIR__ . '/../partials/scripts.php'; ?>
<script>
let state = { searchTimer: null, page: 1, canWrite: false, isOwner: false };

const PAY_METHODS = {
  cash: 'Готівка', card: 'Карта', terminal: 'Термінал',
  deposit: 'Депозит', transfer: 'Переказ', free: 'Безкоштовно', other: 'Інше',
};

window.addEventListener('DOMContentLoaded', async () => {
  const ctx = await initPage({ title: 'Оплати' });
  if (!ctx) return;
  guardBlockedClub(ctx.isBlocked);
  window._shiftCtx = ctx;
  applyShiftLock(ctx);
  applyPlanLock(ctx);

  // Дати за замовчуванням — поточний місяць
  const now   = new Date();
  const y     = now.getFullYear();
  const m     = String(now.getMonth() + 1).padStart(2, '0');
  const today = now.toISOString().split('T')[0];
  document.getElementById('f-from').value = `${y}-${m}-01`;
  document.getElementById('f-to').value   = today;

  const lvl = ctx.isSuperAdmin ? 100 : (ctx.clubRole?.level ?? 0);
  state.canWrite = lvl >= 50;
  state.isOwner  = lvl >= 80;
  loadTariffFilter();
  loadPayments(1);
});

async function loadTariffFilter() {
  const res = await api('get_tariffs', {}, 'payments');
  if (!res.success) return;
  const sel = document.getElementById('f-tariff');
  res.tariffs.forEach(t => {
    const opt = document.createElement('option');
    opt.value       = t.id;
    opt.textContent = t.name;
    sel.appendChild(opt);
  });
}

function scheduleSearch() {
  clearTimeout(state.searchTimer);
  state.searchTimer = setTimeout(() => loadPayments(1), 350);
}

async function loadPayments(page) {
  state.page = page;
  const tbody = document.getElementById('pay-tbody');
  tbody.innerHTML =
    '<tr><td colspan="8"><div class="loader"><div class="spinner"></div></div></td></tr>';

  const res = await api('get_list', {
    date_from: document.getElementById('f-from').value,
    date_to:   document.getElementById('f-to').value,
    method:    document.getElementById('f-method').value,
    tariff_id: document.getElementById('f-tariff').value || 0,
    search:    document.getElementById('f-search').value.trim(),
    page,
  }, 'payments');

  if (!res.success) {
    tbody.innerHTML =
      `<tr><td colspan="8"><div class="alert alert-error" style="margin:16px">${res.error}</div></td></tr>`;
    return;
  }

  const { payments, summary, pagination } = res;
  // Підсумок по методах
  renderSummary(summary);

  const isMobile = window.innerWidth <= 768;
  const tableCard = document.getElementById('pay-table-card');
  const cards     = document.getElementById('pay-cards');

  if (!payments.length) {
    const emptyHtml = '<div class="empty-state" style="padding:40px;text-align:center">' +
      '<div style="font-size:36px;margin-bottom:12px">💳</div><h3>Оплат не знайдено</h3></div>';
    if (isMobile) {
      tableCard.style.display = 'none';
      cards.innerHTML = emptyHtml;
      cards.style.display = 'block';
    } else {
      cards.style.display = 'none';
      tableCard.style.display = '';
      tbody.innerHTML = `<tr><td colspan="8">${emptyHtml}</td></tr>`;
    }
    renderPagination(pagination);
    return;
  }

  if (isMobile) {
    // Мобільні картки
    tableCard.style.display = 'none';
    cards.style.display = 'block';
    cards.innerHTML = payments.map(p => `
      <div class="pay-card">
        <div class="pay-card-row">
          <div class="pay-card-client">
            <div class="pay-card-name">${esc(p.client_name)}</div>
            <div class="pay-card-sub">${p.client_phone || ''} · ${formatDate(p.created_at)}</div>
          </div>
          <div class="pay-card-right">
            <div class="pay-card-amount">${formatMoney(p.amount)}</div>
          </div>
        </div>
        <div class="pay-card-row pay-card-meta">
          <span class="pay-method-badge pay-method-${p.payment_method}">${PAY_METHODS[p.payment_method] || p.payment_method}</span>
          ${p.tariff_name ? `<span class="pay-card-tariff">${esc(p.tariff_name)}</span>` : ''}
          ${p.trainer_name ? `<span class="pay-card-trainer">🏋 ${esc(p.trainer_name)}</span>` : ''}
        </div>
        <div class="pay-card-footer">
          <span class="pay-card-time">${new Date(p.created_at).toLocaleTimeString('uk-UA',{hour:'2-digit',minute:'2-digit'})}</span>
          <div class="pay-card-actions">
            ${state.canWrite ? `<button class="btn btn-ghost btn-sm" data-shift-action onclick="openPayEdit(${p.id},${p.amount},'${p.payment_method}','${(p.notes||'').replace(/'/g,"\'")}')">&#9998;</button>` : ''}
            ${state.isOwner  ? `<button class="btn btn-ghost btn-sm" data-shift-action style="color:var(--danger)" onclick="doDeletePay(${p.id})">&#128465;</button>` : ''}
          </div>
        </div>
      </div>`).join('');
  } else {
    // Десктоп таблиця
    cards.style.display = 'none';
    tableCard.style.display = '';
    tbody.innerHTML = payments.map(p => `
      <tr>
        <td class="mob-primary">
          <div style="font-weight:500;font-size:14px">${esc(p.client_name)}</div>
          <div style="font-size:12px;color:var(--text-muted)">${p.client_phone || '—'}</div>
        </td>
        <td>
          <div style="font-size:13px">${esc(p.tariff_name || '—')}</div>
          ${p.invoice_id ? `<a href="/invoices" style="font-size:11px;color:var(--accent)">#${p.invoice_id}</a>` : ''}
        </td>
        <td><span class="pay-method-badge pay-method-${p.payment_method}">${PAY_METHODS[p.payment_method] || p.payment_method}</span></td>
        <td style="font-size:13px;color:var(--text-secondary)">${esc(p.trainer_name || '—')}</td>
        <td style="font-size:13px;color:var(--text-secondary)">${esc(p.admin_name || '—')}</td>
        <td style="text-align:right;font-weight:600;color:var(--success);white-space:nowrap">${formatMoney(p.amount)}</td>
        <td style="font-size:12px;color:var(--text-muted);white-space:nowrap">
          ${formatDate(p.created_at)}<br>
          <span style="font-size:11px">${new Date(p.created_at).toLocaleTimeString('uk-UA',{hour:'2-digit',minute:'2-digit'})}</span>
        </td>
        <td onclick="event.stopPropagation()" style="white-space:nowrap">
          ${state.canWrite ? `<button class="btn btn-ghost btn-sm" data-shift-action onclick="openPayEdit(${p.id},${p.amount},'${p.payment_method}','${(p.notes||'').replace(/'/g,"\'")}')">&#9998;</button>` : ''}
          ${state.isOwner  ? `<button class="btn btn-ghost btn-sm" data-shift-action style="color:var(--danger)" onclick="doDeletePay(${p.id})">&#128465;</button>` : ''}
        </td>
      </tr>`).join('');
  }

  renderPagination(pagination);
  applyShiftLock(window._shiftCtx);
  applyPlanLock(window._shiftCtx);
}

function renderSummary(summary) {
  const el = document.getElementById('pay-summary');
  if (!summary?.length) { el.style.display = 'none'; return; }

  const total = summary.reduce((a, s) => a + parseFloat(s.total || 0), 0);
  const items = summary.map(s =>
    `<span class="pay-sum-chip">
      <span class="pay-method-badge pay-method-${s.payment_method}">
        ${PAY_METHODS[s.payment_method] || s.payment_method}
      </span>
      <strong>${formatMoney(s.total)}</strong>
      <span style="color:var(--text-muted);font-size:11px">(${s.cnt})</span>
    </span>`
  ).join('');

  el.innerHTML = `
    <div class="pay-sum-total">Разом: <strong>${formatMoney(total)}</strong></div>
    <div class="pay-sum-chips">${items}</div>`;
  el.style.display = 'flex';
}

function renderPagination({ total, page, pages, per_page }) {
  const el = document.getElementById('pagination');
  if (pages <= 1) { el.innerHTML = ''; return; }
  const from = (page - 1) * per_page + 1;
  const to   = Math.min(page * per_page, total);
  let btns = '';
  for (let i = 1; i <= pages; i++) {
    if (i === 1 || i === pages || Math.abs(i - page) <= 2)
      btns += `<button class="page-btn ${i === page ? 'active' : ''}"
                       onclick="loadPayments(${i})">${i}</button>`;
    else if (Math.abs(i - page) === 3)
      btns += '<button class="page-btn" disabled>…</button>';
  }
  el.innerHTML = `
    <span>${from}–${to} з ${total}</span>
    <div class="pagination-btns">
      <button class="page-btn" onclick="loadPayments(${page-1})" ${page<=1?'disabled':''}>&#8249;</button>
      ${btns}
      <button class="page-btn" onclick="loadPayments(${page+1})" ${page>=pages?'disabled':''}>&#8250;</button>
    </div>`;
}

function esc(s) {
  return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Редагування оплати ────────────────────────────────────
function openPayEdit(id, amount, method, notes) {
  document.getElementById('pe-id').value     = id;
  document.getElementById('pe-amount').value = amount;
  document.getElementById('pe-method').value = method;
  document.getElementById('pe-notes').value  = notes || '';
  document.getElementById('pe-error').style.display = 'none';
  openModal('modal-pay-edit');
}

async function submitPayEdit() {
  const btn   = document.getElementById('pe-submit');
  const errEl = document.getElementById('pe-error');
  errEl.style.display = 'none';
  btn.disabled = true; btn.textContent = '...';

  const res = await api('update', {
    id:             +document.getElementById('pe-id').value,
    amount:         +document.getElementById('pe-amount').value,
    payment_method:  document.getElementById('pe-method').value,
    notes:           document.getElementById('pe-notes').value.trim(),
  }, 'payments');

  btn.disabled = false; btn.textContent = 'Зберегти';
  if (!res.success) { errEl.textContent = res.error; errEl.style.display = 'block'; return; }
  toast('Оплату оновлено', 'success');
  closeModal('modal-pay-edit');
  loadPayments(state.page);
}

async function doDeletePay(id) {
  if (!confirm('Видалити цю оплату?')) return;
  const res = await api('delete', { id }, 'payments');
  if (!res.success) { toast(res.error, 'error'); return; }
  toast('Оплату видалено', 'success');
  loadPayments(state.page);
}
</script>

<!-- Модалка редагування оплати -->
<div class="modal-overlay" id="modal-pay-edit">
  <div class="modal" style="max-width:400px">
    <button class="modal-close" onclick="closeModal('modal-pay-edit')">&#10005;</button>
    <h2 class="modal-title">Редагувати оплату</h2>
    <input type="hidden" id="pe-id">
    <div id="pe-error" class="alert alert-error" style="display:none"></div>

    <div class="form-group">
      <label>Сума (грн)</label>
      <input type="number" id="pe-amount" min="0.01" step="0.01">
    </div>
    <div class="form-group">
      <label>Спосіб оплати</label>
      <select id="pe-method">
        <option value="cash">Готівка</option>
        <option value="card">Карта</option>
        <option value="terminal">Термінал</option>
        <option value="deposit">Депозит</option>
        <option value="transfer">Переказ</option>
        <option value="free">Безкоштовно</option>
        <option value="other">Інше</option>
      </select>
    </div>
    <div class="form-group">
      <label>Нотатки</label>
      <input type="text" id="pe-notes" placeholder="Необов'язково">
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
      <button class="btn btn-ghost" onclick="closeModal('modal-pay-edit')">Скасувати</button>
      <button class="btn btn-primary" id="pe-submit" onclick="submitPayEdit()">Зберегти</button>
    </div>
  </div>
</div>
</body>
