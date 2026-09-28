<?php
$pageTitle = 'Продажі товарів';
$pageCss   = 'sales';
require __DIR__ . '/../partials/head.php';
?>
<body>
<div class="app-layout">

  <main class="app-main">

    <!-- ═══ ФІЛЬТРИ + КНОПКА ═══ -->
    <div class="card" style="padding:16px;margin-bottom:20px">
      <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
        <div class="form-group" style="margin:0;min-width:130px">
          <label>Від</label>
          <input type="date" id="f-from" onchange="loadSales()">
        </div>
        <div class="form-group" style="margin:0;min-width:130px">
          <label>До</label>
          <input type="date" id="f-to" onchange="loadSales()">
        </div>
        <div class="form-group" style="margin:0;min-width:150px">
          <label>Спосіб оплати</label>
          <select id="f-method" onchange="loadSales()">
            <option value="">Всі</option>
            <option value="cash">Готівка</option>
            <option value="card">Карта</option>
            <option value="terminal">Термінал</option>
            <option value="deposit">Депозит</option>
            <option value="other">Інше</option>
          </select>
        </div>
        <button class="btn btn-primary" id="btn-sell" data-shift-action onclick="window.location.href='/products'"
                style="display:none;margin-left:auto">&#128722; Новий продаж</button>
      </div>
    </div>

    <!-- ═══ ТАБЛИЦЯ ═══ -->
    <div id="sales-wrap"></div>

  </main>
</div>

<!-- ════ МОДАЛКА: РЕДАГУВАННЯ ════ -->
<div class="modal-overlay" id="modal-edit">
  <div class="modal" style="max-width:460px">
    <button class="modal-close" onclick="closeModal('modal-edit')">&#10005;</button>
    <h2 class="modal-title">Редагувати продаж</h2>
    <input type="hidden" id="e-id">
    <div id="e-error" class="alert alert-error" style="display:none"></div>

    <div style="background:var(--bg-elevated);border-radius:var(--radius-md);padding:12px;margin-bottom:16px;font-size:13px">
      <div style="display:flex;justify-content:space-between;margin-bottom:4px">
        <span style="color:var(--text-muted)">Товар</span>
        <span id="e-product-name" style="font-weight:500"></span>
      </div>
      <div style="display:flex;justify-content:space-between">
        <span style="color:var(--text-muted)">Клієнт</span>
        <span id="e-client-name"></span>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div class="form-group">
        <label>Кількість</label>
        <input type="number" id="e-qty" min="1" step="1" oninput="recalcEdit()">
      </div>
      <div class="form-group">
        <label>Ціна продажу</label>
        <input type="number" id="e-price" min="0" step="0.01" oninput="recalcEdit()">
      </div>
      <div class="form-group">
        <label>Знижка (грн)</label>
        <input type="number" id="e-discount" min="0" step="1" oninput="recalcEdit()">
      </div>
      <div class="form-group">
        <label>Сума</label>
        <input type="number" id="e-total" readonly
               style="background:var(--bg-elevated);color:var(--text-secondary)">
      </div>
    </div>
    <div class="form-group">
      <label>Спосіб оплати</label>
      <select id="e-method">
        <option value="cash">Готівка</option>
        <option value="card">Карта</option>
        <option value="terminal">Термінал</option>
        <option value="deposit">Депозит</option>
        <option value="other">Інше</option>
      </select>
    </div>
    <div class="form-group">
      <label>Примітка</label>
      <input type="text" id="e-notes" placeholder="Необов'язково">
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" id="btn-e-save" onclick="submitEdit()">Зберегти</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-edit')">Скасувати</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../partials/scripts.php'; ?>
<script>
const state = { canWrite: false, isOwner: false };

const METHOD_LABELS = {
  cash: 'Готівка', card: 'Карта', terminal: 'Термінал',
  deposit: 'Депозит', other: 'Інше'
};

window.addEventListener('DOMContentLoaded', async () => {
  const ctx = await initPage({ title: 'Продажі товарів' });
  if (!ctx) return;
  guardBlockedClub(ctx.isBlocked);
  window._shiftCtx = ctx;
  applyShiftLock(ctx);
  applyPlanLock(ctx);

  const perms = getPermissions(ctx);
  state.canWrite = perms.canWrite;
  state.isOwner  = perms.isOwner;

  if (state.canWrite) document.getElementById('btn-sell').style.display = 'inline-flex';

  const today  = new Date().toISOString().split('T')[0];
  const month1 = today.substring(0, 8) + '01';
  document.getElementById('f-from').value = month1;
  document.getElementById('f-to').value   = today;

  loadSales();
});

async function loadSales() {
  const wrap = document.getElementById('sales-wrap');
  wrap.innerHTML = '<div class="loader"><div class="spinner"></div></div>';

  const res = await api('get_list', {
    date_from : document.getElementById('f-from').value,
    date_to   : document.getElementById('f-to').value,
    method    : document.getElementById('f-method').value,
  }, 'sales');

  if (!res.success) {
    wrap.innerHTML = `<div class="alert alert-error">${res.error}</div>`;
    return;
  }

  if (!res.sales.length) {
    wrap.innerHTML = `<div class="empty-state" style="padding:40px;text-align:center">
      <div style="font-size:36px;margin-bottom:12px">🧾</div>
      <h3>Продажів не знайдено</h3></div>`;
    return;
  }

  const groups = {};
  res.sales.forEach(r => {
    if (!groups[r.sale_date]) groups[r.sale_date] = [];
    groups[r.sale_date].push(r);
  });

  let html = '';
  for (const [date, rows] of Object.entries(groups)) {
    const dayTotal = rows.reduce((s, r) => s + parseFloat(r.total_amount), 0);
    html += `
      <div class="sales-day-header">
        <span>${formatDate(date)}</span>
        <span style="color:var(--text-muted);font-size:13px">${rows.length} шт. · ${formatMoney(dayTotal)}</span>
      </div>
      <div class="table-wrap card" style="padding:0;overflow:hidden;margin-bottom:4px">
      <table class="compact-card-table">
        <thead><tr>
          <th>Товар</th>
          <th>Клієнт</th>
          <th class="num">Кіл-ть</th>
          <th class="num">Ціна</th>
          <th class="num">Знижка</th>
          <th class="num">Сума</th>
          <th>Оплата</th>
          <th>Адмін</th>
          <th>Час</th>
          <th></th>
        </tr></thead>
        <tbody>`;

    rows.forEach(r => {
      const photo = r.photo_url
        ? `<img src="${esc(r.photo_url)}" class="cc-photo" onerror="this.outerHTML='<div class=cc-photo-placeholder>🛒</div>'">`
        : `<div class="cc-photo-placeholder">🛒</div>`;
      const actions = [
        state.canWrite
          ? `<button class="btn btn-sm btn-ghost" data-shift-action onclick='openEdit(${JSON.stringify(JSON.stringify(r))})'>✏</button>`
          : '',
        state.isOwner
          ? `<button class="btn btn-sm btn-ghost" data-shift-action style="color:var(--danger)" onclick="deleteSale(${r.id})">🗑</button>`
          : '',
      ].join('');

      html += `<tr data-color="${r.payment_method}">
        <td data-label="Товар">
          <div class="cc-card">${photo}
            <div class="cc-body">
              <div class="cc-title">${esc(r.product_name)}</div>
              <div class="cc-sub">${r.quantity} шт. · ${r.created_at.split(' ')[1].substring(0,5)}${r.client_name ? ' · ' + esc(r.client_name) : ''}</div>
            </div>
            <div class="cc-right">
              <div class="cc-amount">${formatMoney(r.total_amount)}</div>
              <div class="cc-badge"><span class="badge badge-${r.payment_method}">${METHOD_LABELS[r.payment_method]||r.payment_method}</span></div>
            </div>
            <div class="cc-actions">${actions}</div>
          </div>
          <span class="cc-desktop-name">${esc(r.product_name)}</span>
        </td>
        <td data-label="Клієнт">${esc(r.client_name || '—')}</td>
        <td data-label="Кіл-ть" class="num">${r.quantity}</td>
        <td data-label="Ціна" class="num">${formatMoney(r.sale_price)}</td>
        <td data-label="Знижка" class="num">${r.discount > 0 ? '−'+formatMoney(r.discount) : '—'}</td>
        <td data-label="Сума" class="num"><strong>${formatMoney(r.total_amount)}</strong></td>
        <td data-label="Оплата"><span class="badge badge-${r.payment_method}">${METHOD_LABELS[r.payment_method]||r.payment_method}</span></td>
        <td data-label="Адмін">${esc(r.admin_name||'—')}</td>
        <td data-label="Час" style="color:var(--text-muted);font-size:12px">${r.created_at.split(' ')[1].substring(0,5)}</td>
        <td style="white-space:nowrap">${actions}</td>
      </tr>`;
    });

    html += `</tbody></table></div>`;
  }

  wrap.innerHTML = html;
  applyShiftLock(window._shiftCtx);
  applyPlanLock(window._shiftCtx);
}

function openEdit(jsonStr) {
  const r = JSON.parse(jsonStr);
  document.getElementById('e-id').value                 = r.id;
  document.getElementById('e-product-name').textContent = r.product_name;
  document.getElementById('e-client-name').textContent  = r.client_name || '—';
  document.getElementById('e-qty').value                = r.quantity;
  document.getElementById('e-price').value              = r.sale_price;
  document.getElementById('e-discount').value           = r.discount;
  document.getElementById('e-method').value             = r.payment_method;
  document.getElementById('e-notes').value              = r.notes || '';
  document.getElementById('e-error').style.display      = 'none';
  recalcEdit();
  openModal('modal-edit');
}

function recalcEdit() {
  const qty      = parseFloat(document.getElementById('e-qty').value)      || 0;
  const price    = parseFloat(document.getElementById('e-price').value)    || 0;
  const discount = parseFloat(document.getElementById('e-discount').value) || 0;
  document.getElementById('e-total').value = Math.max(0, qty * price - discount).toFixed(2);
}

async function submitEdit() {
  const errEl = document.getElementById('e-error');
  errEl.style.display = 'none';
  const btn = document.getElementById('btn-e-save');
  btn.disabled = true; btn.textContent = 'Збереження...';

  const res = await api('update', {
    id            : parseInt(document.getElementById('e-id').value),
    quantity      : parseInt(document.getElementById('e-qty').value)       || 1,
    sale_price    : parseFloat(document.getElementById('e-price').value)   || 0,
    discount      : parseFloat(document.getElementById('e-discount').value)|| 0,
    payment_method: document.getElementById('e-method').value,
    notes         : document.getElementById('e-notes').value.trim(),
  }, 'sales');

  btn.disabled = false; btn.textContent = 'Зберегти';

  if (res.success) { closeModal('modal-edit'); toast(res.message, 'success'); loadSales(); }
  else { errEl.textContent = res.error; errEl.style.display = 'block'; }
}

async function deleteSale(id) {
  if (!confirm('Видалити цей продаж? Товар буде повернуто на склад.')) return;
  const res = await api('delete', { id }, 'sales');
  if (res.success) { toast(res.message, 'success'); loadSales(); }
  else toast(res.error, 'error');
}

function formatDate(iso) {
  return new Date(iso + 'T00:00:00').toLocaleDateString('uk-UA', { day:'numeric', month:'long', year:'numeric' });
}
function esc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>
</body>
</html>
