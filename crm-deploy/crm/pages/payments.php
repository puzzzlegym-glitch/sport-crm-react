<?php
$pageTitle = 'Оплати';
$pageCss   = 'payments';
require __DIR__ . '/../partials/head.php';
?>
<body>
<div class="app-layout">

  <main class="app-main">

    <div class="page-header">
      <div>
        <h1 class="page-title">Оплати за абонементи</h1>
        <p class="page-subtitle" id="page-subtitle">Завантаження...</p>
      </div>
    </div>

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

    <!-- Таблиця -->
    <div class="card" style="padding:0;overflow:hidden">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Дата</th>
              <th>Клієнт</th>
              <th>Тариф / Абонемент</th>
              <th>Спосіб</th>
              <th>Менеджер</th>
              <th style="text-align:right">Сума</th>
            </tr>
          </thead>
          <tbody id="pay-tbody">
            <tr><td colspan="6"><div class="loader"><div class="spinner"></div></div></td></tr>
          </tbody>
        </table>
      </div>
      <div class="pagination" id="pagination" style="padding:16px 20px"></div>
    </div>

  </main>
</div>

<?php require __DIR__ . '/../partials/scripts.php'; ?>
<script>
let state = { searchTimer: null, page: 1 };

const PAY_METHODS = {
  cash: 'Готівка', card: 'Карта', terminal: 'Термінал',
  deposit: 'Депозит', transfer: 'Переказ', free: 'Безкоштовно', other: 'Інше',
};

window.addEventListener('DOMContentLoaded', async () => {
  const ctx = await initPage({ title: 'Оплати' });
  if (!ctx) return;
  guardBlockedClub(ctx.isBlocked);

  // Дати за замовчуванням — поточний місяць
  const now   = new Date();
  const y     = now.getFullYear();
  const m     = String(now.getMonth() + 1).padStart(2, '0');
  const today = now.toISOString().split('T')[0];
  document.getElementById('f-from').value = `${y}-${m}-01`;
  document.getElementById('f-to').value   = today;

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
    '<tr><td colspan="6"><div class="loader"><div class="spinner"></div></div></td></tr>';

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
      `<tr><td colspan="6"><div class="alert alert-error" style="margin:16px">${res.error}</div></td></tr>`;
    return;
  }

  const { payments, summary, pagination } = res;
  document.getElementById('page-subtitle').textContent = `Всього: ${pagination.total}`;

  // Підсумок по методах
  renderSummary(summary);

  if (!payments.length) {
    tbody.innerHTML =
      '<tr><td colspan="6"><div class="empty-state" style="padding:40px;text-align:center">' +
      '<div style="font-size:36px;margin-bottom:12px">💳</div><h3>Оплат не знайдено</h3></div></td></tr>';
    renderPagination(pagination);
    return;
  }

  tbody.innerHTML = payments.map(p => `
    <tr>
      <td style="white-space:nowrap;font-size:13px">
        ${formatDate(p.created_at)}
        <div style="font-size:11px;color:var(--text-muted)">
          ${new Date(p.created_at).toLocaleTimeString('uk-UA',{hour:'2-digit',minute:'2-digit'})}
        </div>
      </td>
      <td>
        <div style="font-weight:500;font-size:14px">${esc(p.client_name)}</div>
        <div style="font-size:12px;color:var(--text-muted)">${p.client_phone || '—'}</div>
      </td>
      <td>
        <div style="font-size:13px">${esc(p.tariff_name || '—')}</div>
        ${p.invoice_id
          ? `<a href="/invoices" onclick="event.stopPropagation()"
               style="font-size:11px;color:var(--accent)">#${p.invoice_id}</a>`
          : ''}
      </td>
      <td>
        <span class="pay-method-badge pay-method-${p.payment_method}">
          ${PAY_METHODS[p.payment_method] || p.payment_method}
        </span>
      </td>
      <td style="font-size:13px;color:var(--text-secondary)">${esc(p.admin_name || '—')}</td>
      <td style="text-align:right;font-weight:600;color:var(--success);white-space:nowrap">
        ${formatMoney(p.amount)}
      </td>
    </tr>`).join('');

  renderPagination(pagination);
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
</script>
</body>
