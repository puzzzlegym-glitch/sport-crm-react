<?php
$pageTitle = 'Платежі';
$pageCss   = 'finance';
require __DIR__ . '/../partials/head.php';
?>
<body>
<div class="app-layout">
  <main class="app-main">

    <div class="card" style="padding:0;overflow:hidden;margin-top:8px">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border)">
        <div style="font-size:15px;font-weight:600">Платежі платформи</div>
        <div id="pay-summary-inline" style="font-size:13px;color:var(--text-secondary)"></div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Клуб</th>
              <th>План</th>
              <th>Сума</th>
              <th>Метод</th>
              <th>Статус</th>
              <th>Дата</th>
              <th>Примітка</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="pay-tbody">
            <tr><td colspan="9"><div class="loader"><div class="spinner"></div></div></td></tr>
          </tbody>
        </table>
      </div>
      <div id="pay-pagination" style="padding:14px 20px"></div>
    </div>

  </main>
</div>

<!-- Модалка редагування платежу -->
<div class="modal-overlay" id="modal-payment">
  <div class="modal" style="max-width:420px">
    <button class="modal-close" onclick="closeModal('modal-payment')">&#10005;</button>
    <h2 class="modal-title">Редагувати платіж</h2>
    <input type="hidden" id="pay-edit-id">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px">
      <div class="form-group">
        <label>Сума (грн)</label>
        <input type="number" id="pay-edit-amount" min="0" step="0.01">
      </div>
      <div class="form-group">
        <label>Метод</label>
        <select id="pay-edit-gateway">
          <option value="manual">manual</option>
          <option value="wayforpay">wayforpay</option>
          <option value="liqpay">liqpay</option>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label>Статус</label>
      <select id="pay-edit-status">
        <option value="success">success</option>
        <option value="pending">pending</option>
        <option value="failed">failed</option>
        <option value="refunded">refunded</option>
      </select>
    </div>
    <div class="form-group">
      <label>Примітка</label>
      <input type="text" id="pay-edit-note" placeholder="Необов'язково">
    </div>
    <div id="pay-edit-error" class="alert alert-error" style="display:none"></div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" onclick="savePayment()">Зберегти</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-payment')">Скасувати</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../partials/scripts.php'; ?>
<script>
window.addEventListener('DOMContentLoaded', async () => {
  const ctx = await initPage({ title: 'Платежі' });
  if (!ctx) return;
  if (!ctx.isSuperAdmin) { window.location.href = '/dashboard'; return; }
  loadPayments();
});

async function loadPayments(page) {
  page = page || 1;
  const tbody = document.getElementById('pay-tbody');
  tbody.innerHTML = '<tr><td colspan="9"><div class="loader"><div class="spinner"></div></div></td></tr>';

  const res = await api('get_payments', { page: page }, 'saas');
  if (!res.success) {
    tbody.innerHTML = '<tr><td colspan="9" style="color:var(--danger);padding:20px">' + (res.error || 'Помилка') + '</td></tr>';
    return;
  }

  // Підсумок
  if (res.summary) {
    document.getElementById('pay-summary-inline').textContent =
      'Успішних: ' + formatMoney(res.summary.success || 0) +
      ' · Ручних: ' + formatMoney(res.summary.manual || 0);
  }

  const sMap = { success:'badge-active', failed:'badge-inactive', pending:'badge-info', refunded:'badge-pending' };

  if (!res.payments || !res.payments.length) {
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:30px;color:var(--text-muted)">Платежів немає</td></tr>';
    return;
  }

  tbody.innerHTML = res.payments.map(function(p) {
    return '<tr>' +
      '<td style="font-size:12px;color:var(--text-muted)">#' + p.id + '</td>' +
      '<td style="font-weight:500">' + esc(p.club_name || '') + '</td>' +
      '<td style="font-size:13px;color:var(--text-secondary)">' + esc(p.plan_name || '') + '</td>' +
      '<td style="font-weight:600;color:var(--success)">' + formatMoney(p.amount) + '</td>' +
      '<td><span style="font-size:12px;text-transform:uppercase;color:var(--text-secondary)">' + esc(p.gateway) + '</span></td>' +
      '<td><span class="badge ' + (sMap[p.status] || 'badge-info') + '">' + p.status + '</span></td>' +
      '<td style="font-size:13px;color:var(--text-secondary)">' + formatDate(p.created_at) + '</td>' +
      '<td style="font-size:12px;color:var(--text-muted)">' + esc(p.recorded_note || '') + '</td>' +
      '<td style="white-space:nowrap">' +
        '<button class="btn btn-ghost btn-sm" onclick="openPaymentModal(' + p.id + ',' + p.amount + ',\'' + esc(p.gateway) + '\',\'' + p.status + '\',\'' + esc(p.recorded_note || '') + '\')">&#9998;</button> ' +
        '<button class="btn btn-ghost btn-sm" style="color:var(--danger)" onclick="deletePayment(' + p.id + ')">&#128465;</button>' +
      '</td>' +
    '</tr>';
  }).join('');

  renderPagination('pay-pagination', res.pagination, loadPayments);
}

function openPaymentModal(id, amount, gateway, status, note) {
  document.getElementById('pay-edit-id').value      = id;
  document.getElementById('pay-edit-amount').value  = amount;
  document.getElementById('pay-edit-gateway').value = gateway;
  document.getElementById('pay-edit-status').value  = status;
  document.getElementById('pay-edit-note').value    = note;
  document.getElementById('pay-edit-error').style.display = 'none';
  openModal('modal-payment');
}

async function savePayment() {
  const errEl = document.getElementById('pay-edit-error');
  errEl.style.display = 'none';
  const res = await api('update_payment', {
    id:      parseInt(document.getElementById('pay-edit-id').value),
    amount:  parseFloat(document.getElementById('pay-edit-amount').value),
    gateway: document.getElementById('pay-edit-gateway').value,
    status:  document.getElementById('pay-edit-status').value,
    note:    document.getElementById('pay-edit-note').value,
  }, 'saas');
  if (res.success) { closeModal('modal-payment'); toast('Збережено', 'success'); loadPayments(); }
  else { errEl.textContent = res.error; errEl.style.display = 'block'; }
}

async function deletePayment(id) {
  if (!confirm('Видалити платіж #' + id + '?')) return;
  const res = await api('delete_payment', { id: id }, 'saas');
  if (res.success) { toast('Видалено', 'success'); loadPayments(); }
  else toast(res.error, 'error');
}

function renderPagination(elId, pg, loadFn) {
  var el = document.getElementById(elId);
  if (!pg || pg.pages <= 1) { el.innerHTML = ''; return; }
  el.innerHTML = '<div class="pagination"><span>' + pg.total + ' записів</span>' +
    '<div class="pagination-btns">' +
    '<button class="page-btn" onclick="loadPayments(' + (pg.page-1) + ')" ' + (pg.page<=1?'disabled':'') + '>&#8249;</button>' +
    '<button class="page-btn active">' + pg.page + ' / ' + pg.pages + '</button>' +
    '<button class="page-btn" onclick="loadPayments(' + (pg.page+1) + ')" ' + (pg.page>=pg.pages?'disabled':'') + '>&#8250;</button>' +
    '</div></div>';
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
</script>
</body>
</html>
