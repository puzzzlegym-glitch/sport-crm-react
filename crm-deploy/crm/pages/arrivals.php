<?php
$pageTitle = 'Прихід товарів';
$pageCss   = 'arrivals';
require __DIR__ . '/../partials/head.php';
?>
<body>
<div class="app-layout">
  <main class="app-main">

    <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
      <div>
        <h1 class="page-title">Прихід товарів</h1>
        <p class="page-subtitle" id="page-subtitle">Завантаження...</p>
      </div>
      <button class="btn btn-primary" id="btn-add-arrival" onclick="openArrivalModal()" style="display:none">
        + Операція
      </button>
    </div>

    <!-- Статистика -->
    <div class="arr-stats">
      <div class="arr-stat green">
        <div class="as-label">Приходів</div>
        <div class="as-value" id="st-arrivals"><span class="spinner"></span></div>
        <div class="as-sub"   id="st-cost">—</div>
      </div>
      <div class="arr-stat orange">
        <div class="as-label">Очікується</div>
        <div class="as-value" id="st-pending">—</div>
        <div class="as-sub">замовлено, не доставлено</div>
      </div>
      <div class="arr-stat red">
        <div class="as-label">Борг постачальнику</div>
        <div class="as-value" id="st-unpaid">—</div>
        <div class="as-sub"   id="st-unpaid-cost">—</div>
      </div>
      <div class="arr-stat purple">
        <div class="as-label">Операцій мінус</div>
        <div class="as-value" id="st-minus">—</div>
        <div class="as-sub">прострочка / розфас. / перенесення</div>
      </div>
    </div>

    <!-- Тулбар -->
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
        <table>
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
  <div class="modal" style="max-width:500px">
    <button class="modal-close" onclick="closeModal('modal-edit-arrival')">&#10005;</button>
    <h2 class="modal-title">Редагування запису</h2>
    <div id="ea-error" class="alert alert-error" style="display:none"></div>
    <input type="hidden" id="ea-id">

    <!-- Рядок 1: Товар — Постачальник -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
      <div style="background:var(--bg-elevated);border-radius:var(--radius-sm);padding:10px 12px;font-size:13px">
        <div style="color:var(--text-muted);font-size:11px;margin-bottom:3px">Товар</div>
        <div style="font-weight:600" id="ea-name-view">—</div>
      </div>
      <div style="background:var(--bg-elevated);border-radius:var(--radius-sm);padding:10px 12px;font-size:13px">
        <div style="color:var(--text-muted);font-size:11px;margin-bottom:3px">Постачальник</div>
        <div id="ea-supplier-view">—</div>
      </div>
    </div>

    <!-- Рядок 2: Тип операції — Статус оплати -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div class="form-group">
        <label>Тип операції *</label>
        <select id="ea-operation" onchange="eaToggleStatus()">
          <option value="arrival">📥 Прихід</option>
          <option value="overdue">🔴 Прострочка</option>
          <option value="repack">🟡 Розфасування</option>
          <option value="transfer">⬛ Перенесення</option>
        </select>
      </div>
      <div class="form-group" id="ea-status-wrap">
        <label>Статус оплати</label>
        <select id="ea-status">
          <option value="paid">✅ Оплачено</option>
          <option value="unpaid">🔴 Не оплачено</option>
          <option value="pending">⏳ Замовлено</option>
        </select>
      </div>
    </div>

    <!-- Рядок 3: Кількість — Ціна закупки -->
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

    <!-- Рядок 4: Сума — Toggle примітка -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;align-items:end">
      <div class="form-group">
        <label>Сума <span style="color:var(--text-muted);font-size:11px">(авто)</span></label>
        <input type="number" id="ea-total" readonly
               style="background:var(--bg-elevated);color:var(--text-secondary)">
      </div>
      <div class="form-group" style="display:flex;align-items:center;gap:8px;padding-bottom:2px">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin:0;font-size:13px;color:var(--text-secondary)">
          <input type="checkbox" id="ea-notes-toggle" onchange="eaToggleNotes()"
                 style="width:16px;height:16px;cursor:pointer;accent-color:var(--accent)">
          Додати примітку
        </label>
      </div>
    </div>

    <!-- Примітка — лише якщо toggle увімкнено -->
    <div class="form-group" id="ea-notes-wrap" style="display:none">
      <label>Примітка</label>
      <input type="text" id="ea-notes" placeholder="Необов'язково...">
    </div>

    <!-- Кнопки -->
    <div style="display:flex;gap:10px;margin-top:4px">
      <button class="btn btn-primary" id="btn-ea-submit" onclick="submitEdit()">Зберегти</button>
      <button class="btn btn-ghost"   onclick="closeModal('modal-edit-arrival')">Скасувати</button>
      <button class="btn" id="btn-ea-delete" onclick="confirmDelete()"
              style="margin-left:auto;background:rgba(248,113,113,.1);color:var(--danger);border:1px solid rgba(248,113,113,.3)">
        🗑 Видалити
      </button>
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
  const ctx = await initPage();
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

// ── Статистика ────────────────────────────────────────────
async function loadStats() {
  const res = await api('get_stats', {
    date_from: document.getElementById('date-from').value,
    date_to:   document.getElementById('date-to').value,
  }, 'arrivals');
  if (!res.success) return;
  const s = res.stats;
  document.getElementById('st-arrivals').textContent  = s.arrivals_count || 0;
  document.getElementById('st-cost').textContent      = 'на ' + formatMoney(s.total_cost || 0);
  document.getElementById('st-pending').textContent   = s.pending_count  || 0;
  document.getElementById('st-unpaid').textContent    = s.unpaid_count   || 0;
  document.getElementById('st-unpaid-cost').textContent = formatMoney(s.unpaid_cost || 0);
  document.getElementById('st-minus').textContent     = s.minus_count    || 0;
}

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
  document.getElementById('page-subtitle').textContent = `${pagination.total} записів`;

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

    return `
      <tr>
        <td>
          <div class="arr-product-name">${esc(a.product_name)}</div>
          <div class="arr-product-meta">${esc(a.category||'—')}</div>
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
        <td style="white-space:nowrap">${confirmBtn}${editBtn}</td>
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
  document.getElementById('ea-id').value                   = id;
  document.getElementById('ea-name-view').textContent      = name;
  document.getElementById('ea-supplier-view').textContent  = supplier || '—';
  document.getElementById('ea-qty').value                  = qty;
  document.getElementById('ea-price').value                = price;
  document.getElementById('ea-total').value                = total;
  document.getElementById('ea-operation').value            = operation;
  document.getElementById('ea-status').value               = status;
  document.getElementById('ea-notes').value                = notes;
  // Toggle примітки: показуємо якщо є текст
  const hasNotes = notes && notes.trim().length > 0;
  document.getElementById('ea-notes-toggle').checked       = hasNotes;
  document.getElementById('ea-notes-wrap').style.display   = hasNotes ? 'block' : 'none';
  document.getElementById('ea-error').style.display        = 'none';
  eaToggleStatus();
  openModal('modal-edit-arrival');
}

function eaCalcTotal() {
  const qty   = parseFloat(document.getElementById('ea-qty').value)   || 0;
  const price = parseFloat(document.getElementById('ea-price').value) || 0;
  document.getElementById('ea-total').value = (qty * price).toFixed(2);
}

function eaToggleStatus() {
  const op = document.getElementById('ea-operation').value;
  document.getElementById('ea-status-wrap').style.display = op === 'arrival' ? '' : 'none';
}

function eaToggleNotes() {
  const show = document.getElementById('ea-notes-toggle').checked;
  document.getElementById('ea-notes-wrap').style.display = show ? 'block' : 'none';
  if (!show) document.getElementById('ea-notes').value = '';
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
    notes:          document.getElementById('ea-notes-toggle').checked
                      ? document.getElementById('ea-notes').value.trim() : '',
  }, 'arrivals');

  btn.disabled = false; btn.textContent = 'Зберегти';

  if (res.success) {
    closeModal('modal-edit-arrival');
    toast(res.message, 'success');
    loadArrivals(state.page); loadStats();
  } else {
    errEl.textContent = res.error; errEl.style.display = 'block';
  }
}

// ── Видалення запису ──────────────────────────────────────
function confirmDelete() {
  const name = document.getElementById('ea-name-view').textContent;
  if (!confirm(`Видалити запис "${name}"?\nСклад буде перераховано автоматично.`)) return;
  submitDelete();
}

async function submitDelete() {
  const btn = document.getElementById('btn-ea-delete');
  btn.disabled = true; btn.textContent = 'Видалення...';

  const res = await api('delete', {
    id: parseInt(document.getElementById('ea-id').value),
  }, 'arrivals');

  btn.disabled = false; btn.textContent = '🗑 Видалити';

  if (res.success) {
    closeModal('modal-edit-arrival');
    toast(res.message, 'success');
    loadArrivals(state.page); loadStats();
  } else {
    document.getElementById('ea-error').textContent = res.error;
    document.getElementById('ea-error').style.display = 'block';
  }
}

// ── Нова операція — переходимо на товари ─────────────────
function openArrivalModal() {
  window.location.href = '/products';
}

// ── Утиліти ───────────────────────────────────────────────
function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
