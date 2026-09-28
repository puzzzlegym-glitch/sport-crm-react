<?php
$pageTitle = 'Прихід товарів';
$pageCss   = 'arrivals';
require __DIR__ . '/../partials/head.php';
?>
<body>
<div class="app-layout">
  <main class="app-main">

    <!-- Тулбар + кнопка -->
    <div class="arr-toolbar">
      <div class="form-group">
        <label>Від</label>
        <input type="date" id="date-from" onchange="reload()">
      </div>
      <div class="form-group">
        <label>До</label>
        <input type="date" id="date-to" onchange="reload()">
      </div>
      <div class="form-group" style="flex:1;min-width:180px">
        <label>Пошук</label>
        <input type="text" id="search-input" placeholder="Назва товару..." oninput="scheduleSearch()">
      </div>
      <button class="btn btn-primary" id="btn-add-arrival" onclick="openArrivalModal()" style="display:none;align-self:flex-end">
        + Прихід
      </button>
    </div>

    <!-- Фільтр операцій -->
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px">
      <button class="op-filter-btn active" data-op="" onclick="setOpFilter(this,'')">Всі</button>
      <button class="op-filter-btn" data-op="arrival"  onclick="setOpFilter(this,'arrival')">📥 Прихід</button>
      <button class="op-filter-btn" data-op="overdue"  onclick="setOpFilter(this,'overdue')">🔴 Прострочка</button>
      <button class="op-filter-btn" data-op="repack"   onclick="setOpFilter(this,'repack')">🟡 Розфасування</button>
      <button class="op-filter-btn" data-op="transfer" onclick="setOpFilter(this,'transfer')">⬛ Перенесення</button>
      <button class="op-filter-btn" data-op="pending"  onclick="setStatusFilter(this,'pending')" style="margin-left:8px;border-style:dashed">⏳ Очікується</button>
      <button class="op-filter-btn" data-op="unpaid"   onclick="setStatusFilter(this,'unpaid')" style="border-style:dashed">🔴 Не оплачено</button>
    </div>

    <!-- Таблиця -->
    <div class="card" style="padding:0;overflow:hidden">
      <div class="table-wrap">
        <table class="compact-card-table">
          <thead>
            <tr>
              <th>Товар</th>
              <th>Операція</th>
              <th>Статус</th>
              <th>К-сть</th>
              <th>Ціна закупки</th>
              <th>Сума</th>
              <th>Постачальник</th>
              <th>Поточний залишок</th>
              <th>Менеджер</th>
              <th>Дата</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="arrivals-tbody">
            <tr><td colspan="11"><div class="loader"><div class="spinner"></div> Завантаження...</div></td></tr>
          </tbody>
        </table>
      </div>
      <div id="pagination" style="padding:12px 20px"></div>
    </div>

  </main>
</div>

<!-- ════ МОДАЛКА: НОВИЙ ПРИХІД ════ -->
<div class="modal-overlay" id="modal-arrival">
  <div class="modal" style="max-width:480px">
    <button class="modal-close" onclick="closeModal('modal-arrival')">&#10005;</button>
    <h2 class="modal-title">Прихід товару</h2>
    <div id="arr-error" class="alert alert-error" style="display:none"></div>
    <div class="form-group">
      <label>Товар *</label>
      <select id="arr-product" onchange="onArrProductChange()">
        <option value="">— Оберіть товар —</option>
      </select>
    </div>
    <div class="form-group">
      <label>Операція *</label>
      <div style="display:flex;gap:8px;flex-wrap:wrap" id="arr-operation-btns">
        <button type="button" class="arr-op-btn active" data-op="arrival"  onclick="selectArrOp(this)">&#10145;&#65039; Прихід</button>
        <button type="button" class="arr-op-btn"        data-op="overdue"  onclick="selectArrOp(this)">&#128993; Прострочка</button>
        <button type="button" class="arr-op-btn"        data-op="repack"   onclick="selectArrOp(this)">&#129516; Розфасування</button>
        <button type="button" class="arr-op-btn"        data-op="transfer" onclick="selectArrOp(this)">&#128683; Перенесення</button>
      </div>
      <input type="hidden" id="arr-operation" value="arrival">
    </div>
    <div class="form-group" id="arr-status-wrap">
      <label>Статус *</label>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="button" class="arr-status-btn active" data-status="paid"    onclick="selectArrStatus(this)">&#9989; Оплачено</button>
        <button type="button" class="arr-status-btn"        data-status="unpaid"  onclick="selectArrStatus(this)">&#128308; Не оплачено</button>
        <button type="button" class="arr-status-btn"        data-status="pending" onclick="selectArrStatus(this)">&#9201; Замовлено</button>
      </div>
      <input type="hidden" id="arr-status" value="paid">
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px">
      <div class="form-group">
        <label id="arr-qty-label">Кількість *</label>
        <input type="number" id="arr-qty" value="1" min="1" step="1" oninput="calcArrTotal()">
      </div>
      <div class="form-group">
        <label>Постачальник</label>
        <input type="text" id="arr-supplier" placeholder="">
      </div>
      <div class="form-group" id="arr-purchase-wrap">
        <label>Ціна закупки (грн)</label>
        <input type="number" id="arr-purchase" placeholder="0" min="0" step="0.01" oninput="calcArrTotal()">
      </div>
      <div class="form-group" id="arr-sale-wrap">
        <label>Ціна продажу (грн)</label>
        <input type="number" id="arr-sale" placeholder="0" min="0" step="0.01">
      </div>
    </div>
    <div class="form-group">
      <label>Примітка</label>
      <input type="text" id="arr-notes" placeholder="Необов'язково">
    </div>
    <div id="arr-total" style="font-size:13px;color:var(--text-secondary);margin-bottom:16px"></div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" id="btn-arr-submit" onclick="submitArrival()">Підтвердити</button>
      <button class="btn btn-ghost"   onclick="closeModal('modal-arrival')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: ПІДТВЕРДИТИ PENDING ════ -->
<div class="modal-overlay" id="modal-confirm-arrival">
  <div class="modal" style="max-width:420px">
    <button class="modal-close" onclick="closeModal('modal-confirm-arrival')">&#10005;</button>
    <h2 class="modal-title">Підтвердити прихід</h2>
    <div id="ca-error" class="alert alert-error" style="display:none"></div>
    <div style="padding:10px 14px;background:var(--bg-elevated);border-radius:var(--radius-sm);margin-bottom:16px">
      <div style="font-size:13px;color:var(--text-muted)">Товар</div>
      <div style="font-size:15px;font-weight:600;margin-top:2px" id="ca-product-name">—</div>
      <div style="font-size:13px;color:var(--text-muted);margin-top:4px">Очікувалось: <strong id="ca-expected">—</strong> шт.</div>
    </div>
    <div class="form-group">
      <label>Фактична кількість *</label>
      <input type="number" id="ca-qty" min="1" step="1">
    </div>
    <div class="form-group">
      <label>Статус оплати *</label>
      <div class="confirm-status-btns">
        <button type="button" class="csb active" data-s="paid"   onclick="selectCaStatus(this)">&#9989; Оплачено</button>
        <button type="button" class="csb"        data-s="unpaid" onclick="selectCaStatus(this)">&#128308; Не оплачено</button>
      </div>
      <input type="hidden" id="ca-status" value="paid">
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" id="btn-ca-submit" onclick="submitConfirm()">Підтвердити</button>
      <button class="btn btn-ghost"   onclick="closeModal('modal-confirm-arrival')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: РЕДАГУВАННЯ ЗАПИСУ ════ -->
<div class="modal-overlay" id="modal-edit-arrival">
  <div class="modal" style="max-width:460px">
    <button class="modal-close" onclick="closeModal('modal-edit-arrival')">&#10005;</button>
    <h2 class="modal-title">Редагування запису</h2>
    <div id="ea-error" class="alert alert-error" style="display:none"></div>
    <input type="hidden" id="ea-id">
    <!-- Інфо (лише перегляд) -->
    <div style="background:var(--bg-elevated);border-radius:var(--radius-sm);padding:10px 14px;margin-bottom:16px;font-size:13px">
      <div style="color:var(--text-muted);margin-bottom:2px">Товар</div>
      <div style="font-weight:600;font-size:15px" id="ea-name-view">—</div>
      <div style="color:var(--text-muted);margin-top:6px;margin-bottom:2px">Постачальник</div>
      <div id="ea-supplier-view">—</div>
    </div>
    <!-- Тип операції -->
    <div class="form-group">
      <label>Тип операції *</label>
      <select id="ea-operation" onchange="eaToggleStatus()">
        <option value="arrival">📥 Прихід</option>
        <option value="overdue">🔴 Прострочка</option>
        <option value="repack">🟡 Розфасування</option>
        <option value="transfer">⬛ Перенесення</option>
      </select>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div class="form-group">
        <label>Кількість *</label>
        <input type="number" id="ea-qty" min="1" step="1" oninput="eaCalcTotal()">
      </div>
      <div class="form-group">
        <label>Ціна закупки</label>
        <input type="number" id="ea-price" min="0" step="0.01" oninput="eaCalcTotal()">
      </div>
    </div>
    <div class="form-group">
      <label>Сума <span style="color:var(--text-muted);font-size:11px">(авто)</span></label>
      <input type="number" id="ea-total" min="0" step="0.01" readonly
             style="background:var(--bg-elevated);color:var(--text-secondary)">
    </div>
    <div class="form-group" id="ea-status-wrap">
      <label>Статус оплати</label>
      <select id="ea-status">
        <option value="paid">✅ Оплачено</option>
        <option value="unpaid">🔴 Не оплачено</option>
        <option value="pending">⏳ Замовлено</option>
      </select>
    </div>
    <div class="form-group">
      <label>Примітка</label>
      <input type="text" id="ea-notes">
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" id="btn-ea-submit" onclick="submitEdit()">Зберегти</button>
      <button class="btn btn-ghost"   onclick="closeModal('modal-edit-arrival')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: ПЕРЕГЛЯД ЗАПИСУ ════ -->
<div class="modal-overlay" id="modal-view-arrival">
  <div class="modal" style="max-width:420px">
    <button class="modal-close" onclick="closeModal('modal-view-arrival')">&#10005;</button>
    <h2 class="modal-title">Деталі запису</h2>
    <div id="vw-content"></div>
    <div style="margin-top:16px">
      <button class="btn btn-ghost" onclick="closeModal('modal-view-arrival')">Закрити</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: НОВА ОПЕРАЦІЯ (редирект на products.php або вбудована) ════ -->
<?php require __DIR__ . '/../partials/scripts.php'; ?>
<script>
let state = {
  page: 1, opFilter: '', statusFilter: '', searchTimer: null,
  canWrite: false, isOwner: false,
  confirmId: null,
};

// ── Ініціалізація ─────────────────────────────────────────
window.addEventListener('DOMContentLoaded', async () => {
  const ctx = await initPage({ title: 'Журнал приходів' });
  if (!ctx) return;
  guardBlockedClub(ctx.isBlocked);
  const perms = getPermissions(ctx);
  state.canWrite = perms.canWrite;
  state.isOwner  = perms.isOwner;

  if (state.canWrite) document.getElementById('btn-add-arrival').style.display = 'inline-flex';

  const today  = new Date().toISOString().split('T')[0];
  const month1 = today.substring(0,8) + '01';
  document.getElementById('date-from').value = month1;
  document.getElementById('date-to').value   = today;

  loadStats();
  loadArrivals(1);
});

// ── Статистика — елементи видалено, функція-заглушка ─────
async function loadStats() {}

// ── Список ────────────────────────────────────────────────
async function loadArrivals(page = 1) {
  state.page = page;
  const tbody = document.getElementById('arrivals-tbody');
  tbody.innerHTML = '<tr><td colspan="11"><div class="loader"><div class="spinner"></div></div></td></tr>';

  const res = await api('get_list', {
    date_from:  document.getElementById('date-from').value,
    date_to:    document.getElementById('date-to').value,
    operation:  state.opFilter,
    status:     state.statusFilter,
    search:     document.getElementById('search-input').value.trim(),
    page,
  }, 'arrivals');

  if (!res.success) {
    tbody.innerHTML = `<tr><td colspan="11"><div class="alert alert-error" style="margin:16px">${res.error}</div></td></tr>`;
    return;
  }

  const { arrivals, pagination } = res;

  const opLabel = { arrival:'Прихід', overdue:'Прострочка', repack:'Розфасування', transfer:'Перенесення' };
  const opClass = { arrival:'arrival', overdue:'overdue', repack:'repack', transfer:'transfer' };

  tbody.innerHTML = arrivals.length ? arrivals.map(a => {
    const isMinus   = ['overdue','repack','transfer'].includes(a.operation);
    const isPending = a.status === 'pending';

    const qtyHtml = isPending
      ? `<span class="qty-wait">⏳ ${a.expected_qty || a.quantity} (очік.)</span>`
      : `<span class="${isMinus ? 'qty-minus' : 'qty-plus'}">${isMinus ? '−' : '+'}${a.quantity}</span>`;

    const statusHtml = a.operation === 'arrival' ? {
      paid:    '<span class="status-badge paid">✅ Оплачено</span>',
      unpaid:  '<span class="status-badge unpaid">🔴 Не оплачено</span>',
      pending: '<span class="status-badge pending">⏳ Замовлено</span>',
    }[a.status] || '—' : '—';

    const sumHtml = isPending
      ? (a.total_cost > 0
          ? `<span style="color:var(--warning)">${formatMoney(Math.abs(a.total_cost))}</span>`
          : '<span style="color:var(--text-muted)">—</span>')
      : `<span class="${isMinus ? 'qty-minus' : ''}">${isMinus ? '−' : ''}${formatMoney(Math.abs(a.total_cost))}</span>`;

    const confirmBtn = isPending && state.canWrite
      ? `<button class="confirm-btn" onclick="openConfirm(${a.id},'${esc(a.product_name)}',${a.expected_qty||a.quantity})">✓ Підтвердити</button>`
      : '';
    const editBtn = state.canWrite
      ? `<button class="confirm-btn" onclick="openEdit(${a.id},'${esc(a.product_name)}','${esc(a.supplier||'')}',${a.quantity},${a.purchase_price},${a.total_cost},'${a.operation}','${a.status}','${esc(a.notes||'')}')" style="margin-left:4px">✏️</button>`
      : '';
    const delBtn = state.canWrite
      ? `<button class="confirm-btn" onclick="deleteArrival(event,${a.id},'${esc(a.product_name)}')" style="margin-left:4px;color:var(--danger)">🗑</button>`
      : '';
    const viewBtn = `<button class="confirm-btn" onclick="openView(${JSON.stringify({id:a.id,product_name:a.product_name,supplier:a.supplier,quantity:a.quantity,purchase_price:a.purchase_price,total_cost:a.total_cost,operation:a.operation,status:a.status,notes:a.notes,created_at:a.created_at,admin_name:a.admin_name,current_stock:a.current_stock}).replace(/'/g,'&apos;')})" style="margin-left:4px">👁</button>`;

    const photo = a.photo_url
      ? `<img src="${esc(a.photo_url)}" class="cc-photo" onerror="this.outerHTML='<div class=cc-photo-placeholder>📦</div>'">`
      : `<div class="cc-photo-placeholder">📦</div>`;

    return `
      <tr data-color="${a.operation}">
        <td data-label="Товар">
          <div class="cc-card">
            ${photo}
            <div class="cc-body">
              <div class="cc-title">${esc(a.product_name)}</div>
            </div>
            <div class="cc-sub">${qtyHtml} · ${formatDate(a.created_at)}${a.supplier ? ' · ' + esc(a.supplier) : ''}</div>
            <div class="cc-right">
              <div class="cc-amount">${sumHtml}</div>
            </div>
            <div class="cc-badge">${statusHtml !== '—' ? statusHtml : '<span class="op-badge '+opClass[a.operation]+'">'+opLabel[a.operation]+'</span>'}</div>
            <div class="cc-actions">${confirmBtn}${viewBtn}${editBtn}${delBtn}</div>
          </div>
          <span class="cc-desktop-name">${esc(a.product_name)}</span>
        </td>
        <td><span class="op-badge ${opClass[a.operation]||''}">${opLabel[a.operation]||a.operation}</span></td>
        <td>${statusHtml}</td>
        <td>${qtyHtml}</td>
        <td style="font-size:13px">${a.purchase_price > 0 ? formatMoney(a.purchase_price) : '—'}</td>
        <td style="font-weight:500">${sumHtml}</td>
        <td style="font-size:13px">${esc(a.supplier||'—')}</td>
        <td style="font-size:13px">${a.current_stock !== null ? a.current_stock + ' шт.' : '—'}</td>
        <td style="font-size:13px;color:var(--text-secondary)">${a.admin_name||'—'}</td>
        <td style="font-size:13px;color:var(--text-secondary);white-space:nowrap">${formatDate(a.created_at)}</td>
        <td style="white-space:nowrap">${confirmBtn}${viewBtn}${editBtn}${delBtn}</td>
      </tr>`;
  }).join('') : '<tr><td colspan="11"><div class="empty-state" style="padding:30px"><div style="font-size:36px">📦</div><p>Записів за цей період немає</p></div></td></tr>';

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
      btns += `<button class="page-btn ${i === page ? 'active' : ''}" onclick="loadArrivals(${i})">${i}</button>`;
    else if (Math.abs(i - page) === 3)
      btns += `<span style="color:var(--text-muted);padding:0 4px">…</span>`;
  }
  el.innerHTML = `
    <div class="pagination">
      <span>${from}–${to} з ${total}</span>
      <div class="pagination-btns">
        <button class="page-btn" onclick="loadArrivals(${page-1})" ${page<=1?'disabled':''}>‹</button>
        ${btns}
        <button class="page-btn" onclick="loadArrivals(${page+1})" ${page>=pages?'disabled':''}>›</button>
      </div>
    </div>`;
}

// ── Фільтри ───────────────────────────────────────────────
function setOpFilter(btn, op) {
  document.querySelectorAll('.op-filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  state.opFilter     = op;
  state.statusFilter = '';
  loadArrivals(1); loadStats();
}

function setStatusFilter(btn, st) {
  document.querySelectorAll('.op-filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  state.statusFilter = st;
  state.opFilter     = st === 'pending' || st === 'unpaid' ? 'arrival' : '';
  loadArrivals(1); loadStats();
}

function reload() { loadArrivals(1); loadStats(); }

function scheduleSearch() {
  clearTimeout(state.searchTimer);
  state.searchTimer = setTimeout(() => loadArrivals(1), 350);
}

// ── Підтвердження pending ─────────────────────────────────
function openConfirm(id, name, expected) {
  state.confirmId = id;
  document.getElementById('ca-product-name').textContent = name;
  document.getElementById('ca-expected').textContent     = expected;
  document.getElementById('ca-qty').value                = expected;
  document.getElementById('ca-status').value             = 'paid';
  document.getElementById('ca-error').style.display      = 'none';
  document.querySelectorAll('.csb').forEach(b => b.classList.remove('active'));
  document.querySelector('.csb[data-s="paid"]').classList.add('active');
  openModal('modal-confirm-arrival');
}

function selectCaStatus(btn) {
  document.querySelectorAll('.csb').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('ca-status').value = btn.dataset.s;
}

async function submitConfirm() {
  const errEl = document.getElementById('ca-error');
  errEl.style.display = 'none';
  const btn = document.getElementById('btn-ca-submit');
  btn.disabled = true; btn.textContent = 'Збереження...';

  const res = await api('confirm', {
    id:       state.confirmId,
    quantity: parseInt(document.getElementById('ca-qty').value) || 0,
    status:   document.getElementById('ca-status').value,
  }, 'arrivals');

  btn.disabled = false; btn.textContent = 'Підтвердити';

  if (res.success) {
    closeModal('modal-confirm-arrival');
    toast(res.message, 'success');
    loadArrivals(state.page);
    loadStats();
  } else {
    errEl.textContent = res.error; errEl.style.display = 'block';
  }
}

// ── Редагування запису ────────────────────────────────────
function openEdit(id, name, supplier, qty, price, total, operation, status, notes) {
  document.getElementById('ea-id').value          = id;
  document.getElementById('ea-name-view').textContent     = name;
  document.getElementById('ea-supplier-view').textContent = supplier || '—';
  document.getElementById('ea-qty').value         = qty;
  document.getElementById('ea-price').value       = price;
  document.getElementById('ea-total').value       = total;
  document.getElementById('ea-operation').value   = operation;
  document.getElementById('ea-status').value      = status;
  document.getElementById('ea-notes').value       = notes;
  document.getElementById('ea-error').style.display = 'none';
  eaToggleStatus();
  openModal('modal-edit-arrival');
}

function eaCalcTotal() {
  const qty   = parseFloat(document.getElementById('ea-qty').value)   || 0;
  const price = parseFloat(document.getElementById('ea-price').value) || 0;
  document.getElementById('ea-total').value = (qty * price).toFixed(2);
}

function eaToggleStatus() {
  const op   = document.getElementById('ea-operation').value;
  const wrap = document.getElementById('ea-status-wrap');
  // Статус оплати актуальний лише для приходу
  wrap.style.display = op === 'arrival' ? 'block' : 'none';
}

async function submitEdit() {
  const errEl = document.getElementById('ea-error');
  errEl.style.display = 'none';
  const btn = document.getElementById('btn-ea-submit');
  btn.disabled = true; btn.textContent = 'Збереження...';

  const op = document.getElementById('ea-operation').value;

  const res = await api('update', {
    id:             parseInt(document.getElementById('ea-id').value),
    quantity:       parseInt(document.getElementById('ea-qty').value)      || 0,
    purchase_price: parseFloat(document.getElementById('ea-price').value)  || 0,
    total_cost:     parseFloat(document.getElementById('ea-total').value)  || 0,
    operation:      op,
    status:         op === 'arrival' ? document.getElementById('ea-status').value : 'paid',
    notes:          document.getElementById('ea-notes').value.trim(),
  }, 'arrivals');

  btn.disabled = false; btn.textContent = 'Зберегти';

  if (res.success) {
    closeModal('modal-edit-arrival');
    toast(res.message, 'success');
    loadArrivals(state.page);
    loadStats();
  } else {
    errEl.textContent = res.error; errEl.style.display = 'block';
  }
}

// ── Нова операція — переходимо на товари ─────────────────
// ── Новий прихід ─────────────────────────────────────────
async function openArrivalModal() {
  // Завантажуємо список товарів
  const res = await api('get_list', { page: 1, per_page: 999 }, 'products');
  const sel = document.getElementById('arr-product');
  sel.innerHTML = '<option value="">— Оберіть товар —</option>';
  if (res.success) {
    res.products.forEach(p => {
      const o = new Option(p.name, p.id);
      o.dataset.purchase = p.purchase_price;
      o.dataset.sale     = p.sale_price;
      o.dataset.supplier = p.supplier || '';
      sel.appendChild(o);
    });
  }
  // Скидаємо поля
  document.getElementById('arr-error').style.display = 'none';
  document.getElementById('arr-qty').value     = '1';
  document.getElementById('arr-notes').value   = '';
  document.getElementById('arr-purchase').value = '';
  document.getElementById('arr-sale').value     = '';
  document.getElementById('arr-supplier').value = '';
  document.getElementById('arr-total').textContent = '';
  document.querySelectorAll('.arr-op-btn').forEach(b => b.classList.remove('active'));
  document.querySelector('.arr-op-btn[data-op="arrival"]').classList.add('active');
  document.getElementById('arr-operation').value = 'arrival';
  document.querySelectorAll('.arr-status-btn').forEach(b => b.classList.remove('active'));
  document.querySelector('.arr-status-btn[data-status="paid"]').classList.add('active');
  document.getElementById('arr-status').value = 'paid';
  document.getElementById('arr-status-wrap').style.display   = 'block';
  document.getElementById('arr-sale-wrap').style.display     = 'block';
  document.getElementById('arr-purchase-wrap').style.display = 'block';
  document.getElementById('arr-qty-label').textContent = 'Кількість *';
  openModal('modal-arrival');
}

function onArrProductChange() {
  const opt = document.getElementById('arr-product').options[document.getElementById('arr-product').selectedIndex];
  if (!opt?.value) return;
  document.getElementById('arr-purchase').value = opt.dataset.purchase || '';
  document.getElementById('arr-sale').value     = opt.dataset.sale     || '';
  document.getElementById('arr-supplier').value = opt.dataset.supplier || '';
  calcArrTotal();
}

function selectArrOp(btn) {
  document.querySelectorAll('.arr-op-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  const op = btn.dataset.op;
  document.getElementById('arr-operation').value = op;
  const isPlus   = op === 'arrival';
  const isPending = document.getElementById('arr-status').value === 'pending';
  document.getElementById('arr-status-wrap').style.display   = isPlus ? 'block' : 'none';
  document.getElementById('arr-purchase-wrap').style.display = (isPlus && !isPending) ? 'block' : 'none';
  document.getElementById('arr-sale-wrap').style.display     = (isPlus && !isPending) ? 'block' : 'none';
  document.getElementById('arr-qty-label').textContent = 'Кількість *';
  calcArrTotal();
}

function selectArrStatus(btn) {
  document.querySelectorAll('.arr-status-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  const st = btn.dataset.status;
  document.getElementById('arr-status').value = st;
  const isPending = st === 'pending';
  document.getElementById('arr-purchase-wrap').style.display = isPending ? 'none' : 'block';
  document.getElementById('arr-sale-wrap').style.display     = isPending ? 'none' : 'block';
  document.getElementById('arr-qty-label').textContent = isPending ? 'Очікувана кількість *' : 'Кількість *';
  calcArrTotal();
}

function calcArrTotal() {
  const qty   = parseFloat(document.getElementById('arr-qty').value)      || 0;
  const price = parseFloat(document.getElementById('arr-purchase').value) || 0;
  const total = qty * price;
  document.getElementById('arr-total').textContent = total > 0
    ? `Сума: ${formatMoney(total)}` : '';
}

async function submitArrival() {
  const errEl = document.getElementById('arr-error');
  errEl.style.display = 'none';
  const btn = document.getElementById('btn-arr-submit');
  btn.disabled = true; btn.textContent = 'Збереження...';

  const operation = document.getElementById('arr-operation').value;
  const status    = document.getElementById('arr-status').value;
  const qty       = parseInt(document.getElementById('arr-qty').value) || 0;

  const res = await api('add_arrival', {
    product_id:     parseInt(document.getElementById('arr-product').value) || 0,
    quantity:       qty,
    operation,
    status,
    expected_qty:   status === 'pending' ? qty : null,
    purchase_price: parseFloat(document.getElementById('arr-purchase').value) || 0,
    sale_price:     parseFloat(document.getElementById('arr-sale').value)     || 0,
    supplier:       document.getElementById('arr-supplier').value.trim(),
    notes:          document.getElementById('arr-notes').value.trim(),
  }, 'products');

  btn.disabled = false; btn.textContent = 'Підтвердити';

  if (res.success) {
    closeModal('modal-arrival');
    toast(res.message || 'Збережено', 'success');
    loadArrivals(state.page);
  } else {
    errEl.textContent = res.error; errEl.style.display = 'block';
  }
}

// ── Видалення запису ─────────────────────────────────────
async function deleteArrival(e, id, name) {
  e.stopPropagation();
  if (!confirm(`Видалити запис "${name}"?\nСклад буде перераховано автоматично.`)) return;
  const res = await api('delete', { id }, 'arrivals');
  if (res.success) { toast(res.message, 'success'); loadArrivals(state.page); }
  else toast(res.error, 'error');
}

// ── Перегляд запису ───────────────────────────────────────
function openView(data) {
  const d = typeof data === 'string' ? JSON.parse(data) : data;
  const opLabel = { arrival:'📥 Прихід', overdue:'🔴 Прострочка', repack:'🟡 Розфасування', transfer:'⬛ Перенесення' };
  const stLabel = { paid:'✅ Оплачено', unpaid:'🔴 Не оплачено', pending:'⏳ Замовлено' };
  document.getElementById('vw-content').innerHTML = `
    <div style="display:grid;gap:10px;font-size:14px">
      <div><span style="color:var(--text-muted)">Товар:</span> <strong>${esc(d.product_name)}</strong></div>
      <div><span style="color:var(--text-muted)">Постачальник:</span> ${esc(d.supplier||'—')}</div>
      <div><span style="color:var(--text-muted)">Операція:</span> ${opLabel[d.operation]||d.operation}</div>
      <div><span style="color:var(--text-muted)">Статус:</span> ${stLabel[d.status]||d.status}</div>
      <div><span style="color:var(--text-muted)">Кількість:</span> ${d.quantity} шт.</div>
      <div><span style="color:var(--text-muted)">Ціна закупки:</span> ${d.purchase_price > 0 ? formatMoney(d.purchase_price) : '—'}</div>
      <div><span style="color:var(--text-muted)">Сума:</span> ${formatMoney(Math.abs(d.total_cost))}</div>
      <div><span style="color:var(--text-muted)">Поточний залишок:</span> ${d.current_stock !== null ? d.current_stock + ' шт.' : '—'}</div>
      <div><span style="color:var(--text-muted)">Менеджер:</span> ${esc(d.admin_name||'—')}</div>
      <div><span style="color:var(--text-muted)">Дата:</span> ${formatDate(d.created_at)}</div>
      ${d.notes ? `<div><span style="color:var(--text-muted)">Примітка:</span> ${esc(d.notes)}</div>` : ''}
    </div>`;
  openModal('modal-view-arrival');
}

// ── Утиліти ───────────────────────────────────────────────
function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
