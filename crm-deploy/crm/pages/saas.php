<?php
$pageTitle = 'Білінг платформи';
$pageCss   = 'finance';
require __DIR__ . '/../partials/head.php';
?>
<body>
<div class="app-layout">

  <!-- aside і header НЕ пишемо — sidebar.js вставить їх сам -->

  <main class="app-main">

    <div class="page-header">
      <h1 class="page-title">Білінг платформи</h1>
      <p class="page-subtitle">Тарифи, підписки, рахунки, платежі</p>
    </div>

    <!-- Зведена статистика -->
    <div class="fin-summary" id="overview-stats" style="margin-bottom:24px">
      <div class="fin-card income">
        <div class="fc-label">Дохід цього місяця</div>
        <div class="fc-value positive" id="ov-month"><span class="spinner"></span></div>
        <div class="fc-sub">від платних підписок</div>
      </div>
      <div class="fin-card neutral">
        <div class="fc-label">Загальний дохід</div>
        <div class="fc-value" id="ov-total">—</div>
        <div class="fc-sub">за весь час</div>
      </div>
      <div class="fin-card" style="--fc-accent:#c4b5fd">
        <div class="fc-label">Активних підписок</div>
        <div class="fc-value" id="ov-active">—</div>
        <div class="fc-sub" id="ov-trial">—</div>
      </div>
    </div>

    <!-- Вкладки -->
    <div class="fin-tabs">
      <button class="fin-tab-btn active" onclick="switchTab('plans',this)">&#128179; Тарифні плани</button>
      <button class="fin-tab-btn" onclick="switchTab('subs',this)">&#128196; Підписки клубів</button>
      <button class="fin-tab-btn" onclick="switchTab('invoices',this)">&#128221; Рахунки</button>
      <button class="fin-tab-btn" onclick="switchTab('payments',this)">&#128176; Платежі</button>
    </div>

    <!-- ════ ПЛАНИ ════ -->
    <div class="fin-tab-content active" id="tab-plans">
      <div id="plans-list">
        <div class="loader"><div class="spinner"></div></div>
      </div>
    </div>

    <!-- ════ ПІДПИСКИ ════ -->
    <div class="fin-tab-content" id="tab-subs">
      <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
        <button class="period-btn active" data-status="" onclick="setSF(this,'')">Всі</button>
        <button class="period-btn" data-status="trial"         onclick="setSF(this,'trial')">Тріал</button>
        <button class="period-btn" data-status="active"        onclick="setSF(this,'active')">Активні</button>
        <button class="period-btn" data-status="trial_expired" onclick="setSF(this,'trial_expired')">Прострочені</button>
        <button class="period-btn" data-status="cancelled"     onclick="setSF(this,'cancelled')">Скасовані</button>
      </div>
      <div class="card" style="padding:0;overflow:hidden">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Клуб</th>
                <th>Власник</th>
                <th>План</th>
                <th>Статус</th>
                <th>Тріал / Кінець</th>
                <th>Сплачено</th>
                <th>Оновлено</th>
              </tr>
            </thead>
            <tbody id="subs-tbody">
              <tr><td colspan="7"><div class="loader"><div class="spinner"></div></div></td></tr>
            </tbody>
          </table>
        </div>
        <div id="subs-pagination" style="padding:14px 20px"></div>
      </div>
    </div>

    <!-- ════ РАХУНКИ ════ -->
    <div class="fin-tab-content" id="tab-invoices">
      <div class="card" style="padding:0;overflow:hidden">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Клуб</th>
                <th>План</th>
                <th>Сума</th>
                <th>Статус</th>
                <th>Період</th>
                <th>Сплачено</th>
              </tr>
            </thead>
            <tbody id="inv-tbody">
              <tr><td colspan="7"><div class="loader"><div class="spinner"></div></div></td></tr>
            </tbody>
          </table>
        </div>
        <div id="inv-pagination" style="padding:14px 20px"></div>
      </div>
    </div>

    <!-- ════ ПЛАТЕЖІ ════ -->
    <div class="fin-tab-content" id="tab-payments">
      <div id="pay-summary" style="margin-bottom:14px"></div>
      <div class="card" style="padding:0;overflow:hidden">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Клуб</th>
                <th>Сума</th>
                <th>Метод</th>
                <th>Статус</th>
                <th>Дата</th>
                <th>Примітка</th>
              </tr>
            </thead>
            <tbody id="pay-tbody">
              <tr><td colspan="7"><div class="loader"><div class="spinner"></div></div></td></tr>
            </tbody>
          </table>
        </div>
        <div id="pay-pagination" style="padding:14px 20px"></div>
      </div>
    </div>

  </main>
</div>

<!-- Модалка редагування плану -->
<div class="modal-overlay" id="modal-plan">
  <div class="modal" style="max-width:420px">
    <button class="modal-close" onclick="closeModal('modal-plan')">&#10005;</button>
    <h2 class="modal-title">Редагувати план</h2>
    <div class="form-group">
      <label>Назва</label>
      <input type="text" id="plan-name" placeholder="Business">
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px">
      <div class="form-group">
        <label>Ціна (грн/міс)</label>
        <input type="number" id="plan-price" min="0" step="1">
      </div>
      <div class="form-group">
        <label>Ліміт клієнтів</label>
        <input type="number" id="plan-clients" placeholder="порожньо = ∞" min="0">
      </div>
      <div class="form-group">
        <label>Ліміт користувачів</label>
        <input type="number" id="plan-users" placeholder="порожньо = ∞" min="0">
      </div>
      <div class="form-group">
        <label>Активний</label>
        <select id="plan-active">
          <option value="1">Так</option>
          <option value="0">Ні</option>
        </select>
      </div>
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" onclick="savePlan()">Зберегти</button>
      <button class="btn btn-ghost"   onclick="closeModal('modal-plan')">Скасувати</button>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../partials/scripts.php'; ?>
<script>
let editPlanId = null;
let subsFilter = '';
let subsPage   = 1;

window.addEventListener('DOMContentLoaded', async () => {
  const ctx = await initPage();
  if (!ctx) return;
  guardBlockedClub(ctx.isBlocked);
  if (!ctx.isSuperAdmin) { window.location.href = '/dashboard'; return; }

  loadOverview();
  loadPlans();
});

// ── Огляд ─────────────────────────────────────────────────
async function loadOverview() {
  const res = await api('get_overview', {}, 'saas');
  if (!res.success) return;
  document.getElementById('ov-month').textContent = formatMoney(res.revenue_month);
  document.getElementById('ov-total').textContent = formatMoney(res.revenue_total);

  const subs = res.subs_by_status || {};
  document.getElementById('ov-active').textContent = (subs.active||0) + ' активних';
  document.getElementById('ov-trial').textContent  = (subs.trial||0)  + ' на тріалі · ' +
    (subs.trial_expired||0) + ' прострочених';
}

// ── Плани ─────────────────────────────────────────────────
async function loadPlans() {
  const res = await api('get_plans', {}, 'saas');
  if (!res.success) return;

  document.getElementById('plans-list').innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px">
      ${res.plans.map(p => `
        <div class="card" style="position:relative;overflow:hidden">
          <div style="position:absolute;top:0;left:0;width:3px;height:100%;
               background:${p.is_active ? 'var(--accent)' : 'var(--border)'}"></div>
          <div style="padding-left:8px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
              <div style="font-size:16px;font-weight:700">${esc(p.name)}</div>
              ${!p.is_active ? '<span class="badge badge-inactive">Вимкнено</span>' : ''}
            </div>
            <div style="font-size:24px;font-weight:700;color:var(--accent);margin-bottom:8px">
              ${p.price_monthly} <span style="font-size:13px;font-weight:400;color:var(--text-muted)">грн/міс</span>
            </div>
            <div style="font-size:13px;color:var(--text-secondary);margin-bottom:4px">
              &#128100; ${p.clients_limit ? 'до ' + p.clients_limit + ' клієнтів' : 'Необмежено клієнтів'}
            </div>
            <div style="font-size:13px;color:var(--text-secondary);margin-bottom:12px">
              &#128101; ${p.users_limit ? 'до ' + p.users_limit + ' користувачів' : 'Необмежено корист.'}
            </div>
            <div style="font-size:12px;color:var(--text-muted)">
              ${(p.features||[]).map(f => '· ' + f).join('<br>')}
            </div>
            <button class="btn btn-ghost btn-sm" style="margin-top:12px"
                    onclick="openPlanModal(${p.id},'${esc(p.name)}',${p.price_monthly},
                             ${p.clients_limit||''},${p.users_limit||''},${p.is_active})">
              &#9998; Редагувати
            </button>
          </div>
        </div>
      `).join('')}
    </div>`;
}

function openPlanModal(id, name, price, clients, users, isActive) {
  editPlanId = id;
  document.getElementById('plan-name').value    = name;
  document.getElementById('plan-price').value   = price;
  document.getElementById('plan-clients').value = clients || '';
  document.getElementById('plan-users').value   = users   || '';
  document.getElementById('plan-active').value  = isActive ? '1' : '0';
  openModal('modal-plan');
}

async function savePlan() {
  const res = await api('update_plan', {
    id:            editPlanId,
    name:          document.getElementById('plan-name').value.trim(),
    price_monthly: parseFloat(document.getElementById('plan-price').value)   || 0,
    clients_limit: document.getElementById('plan-clients').value || null,
    users_limit:   document.getElementById('plan-users').value   || null,
    is_active:     parseInt(document.getElementById('plan-active').value),
  }, 'saas');
  if (res.success) { closeModal('modal-plan'); toast('Збережено', 'success'); loadPlans(); }
  else toast(res.error, 'error');
}

// ── Підписки ───────────────────────────────────────────────
function setSF(btn, status) {
  document.querySelectorAll('[data-status]').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  subsFilter = status; subsPage = 1;
  loadSubs();
}

async function loadSubs(page=1) {
  subsPage = page;
  const tbody = document.getElementById('subs-tbody');
  tbody.innerHTML = '<tr><td colspan="7"><div class="loader"><div class="spinner"></div></div></td></tr>';

  const res = await api('get_subscriptions', { status: subsFilter, page }, 'saas');
  if (!res.success) return;

  const statusMap = {
    trial:'badge-info', active:'badge-active',
    trial_expired:'badge-inactive', past_due:'badge-pending',
    cancelled:'badge-inactive', deleted:'badge-inactive'
  };
  const statusLabel = {
    trial:'Тріал', active:'Активна',
    trial_expired:'Закінчився', past_due:'Не оплачено',
    cancelled:'Скасовано', deleted:'Видалено'
  };

  const { subscriptions, pagination } = res;

  tbody.innerHTML = subscriptions.map(s => `
    <tr>
      <td>
        <div style="font-weight:500">${esc(s.club_name)}</div>
        <div style="font-size:12px;color:var(--text-muted)">${s.city||'—'}</div>
      </td>
      <td>
        <div style="font-size:13px">${esc(s.owner_name)}</div>
        <div style="font-size:12px;color:var(--text-muted)">${s.owner_email}</div>
      </td>
      <td style="font-size:13px">${esc(s.plan_name)}</td>
      <td><span class="badge ${statusMap[s.status]||'badge-info'}">${statusLabel[s.status]||s.status}</span></td>
      <td style="font-size:13px">
        ${s.status==='trial' && s.trial_ends_at
          ? formatDate(s.trial_ends_at) + ' (' + s.trial_days_left + ' дн.)'
          : s.current_period_end ? formatDate(s.current_period_end) : '—'}
      </td>
      <td style="font-size:13px;color:var(--success);font-weight:500">
        ${s.total_paid ? formatMoney(s.total_paid) : '—'}
      </td>
      <td style="font-size:13px;color:var(--text-secondary)">${formatDate(s.updated_at)}</td>
    </tr>`).join('');

  renderPagination('subs-pagination', pagination, loadSubs);
}

// ── Рахунки ────────────────────────────────────────────────
async function loadInvoices(page=1) {
  const tbody = document.getElementById('inv-tbody');
  tbody.innerHTML = '<tr><td colspan="7"><div class="loader"><div class="spinner"></div></div></td></tr>';

  const res = await api('get_invoices', { page }, 'saas');
  if (!res.success) return;

  const sMap = {paid:'badge-active', draft:'badge-info', void:'badge-inactive'};
  tbody.innerHTML = res.invoices.map(i => `
    <tr>
      <td style="font-size:12px;color:var(--text-muted)">#${i.id}</td>
      <td style="font-weight:500">${esc(i.club_name)}</td>
      <td style="font-size:13px">${esc(i.plan_name)}</td>
      <td style="font-weight:600;color:var(--accent)">${formatMoney(i.amount)}</td>
      <td><span class="badge ${sMap[i.status]||'badge-info'}">${i.status}</span></td>
      <td style="font-size:12px;color:var(--text-secondary)">${formatDate(i.period_start)} — ${formatDate(i.period_end)}</td>
      <td style="font-size:13px;color:var(--text-secondary)">${i.paid_at ? formatDate(i.paid_at) : '—'}</td>
    </tr>`).join('');

  renderPagination('inv-pagination', res.pagination, loadInvoices);
}

// ── Платежі ────────────────────────────────────────────────
async function loadPayments(page=1) {
  const tbody = document.getElementById('pay-tbody');
  tbody.innerHTML = '<tr><td colspan="7"><div class="loader"><div class="spinner"></div></div></td></tr>';

  const res = await api('get_payments', { page }, 'saas');
  if (!res.success) return;

  // Підсумок
  if (res.summary) {
    document.getElementById('pay-summary').innerHTML = `
      <div class="methods-grid">
        <div class="method-pill">
          <span class="method-pill-label">Успішних</span>
          <span class="method-pill-val" style="color:var(--success)">${formatMoney(res.summary.success||0)}</span>
        </div>
        <div class="method-pill">
          <span class="method-pill-label">Ручних</span>
          <span class="method-pill-val">${formatMoney(res.summary.manual||0)}</span>
        </div>
      </div>`;
  }

  const sMap = {success:'badge-active', failed:'badge-inactive', pending:'badge-info', refunded:'badge-pending'};
  tbody.innerHTML = res.payments.map(p => `
    <tr>
      <td style="font-size:12px;color:var(--text-muted)">#${p.id}</td>
      <td style="font-weight:500">${esc(p.club_name)}</td>
      <td style="font-weight:600;color:var(--success)">${formatMoney(p.amount)}</td>
      <td>
        <span style="font-size:12px;text-transform:uppercase;color:var(--text-secondary)">${p.gateway}</span>
      </td>
      <td><span class="badge ${sMap[p.status]||'badge-info'}">${p.status}</span></td>
      <td style="font-size:13px;color:var(--text-secondary)">${formatDate(p.created_at)}</td>
      <td style="font-size:12px;color:var(--text-muted)">${p.recorded_note||'—'}</td>
    </tr>`).join('');

  renderPagination('pay-pagination', res.pagination, loadPayments);
}

// ── Вкладки ────────────────────────────────────────────────
function switchTab(tab, btn) {
  document.querySelectorAll('.fin-tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.fin-tab-content').forEach(c => c.style.display='none');
  btn.classList.add('active');
  document.getElementById('tab-'+tab).style.display = 'block';
  if (tab==='subs')     loadSubs();
  if (tab==='invoices') loadInvoices();
  if (tab==='payments') loadPayments();
}

// ── Пагінація ──────────────────────────────────────────────
function renderPagination(elId, pg, loadFn) {
  const el = document.getElementById(elId);
  if (!pg || pg.pages <= 1) { el.innerHTML=''; return; }
  el.innerHTML = `
    <div class="pagination">
      <span>${pg.total} записів</span>
      <div class="pagination-btns">
        <button class="page-btn" onclick="(${loadFn.name})(${pg.page-1})" ${pg.page<=1?'disabled':''}>&#8249;</button>
        <button class="page-btn active">${pg.page} / ${pg.pages}</button>
        <button class="page-btn" onclick="(${loadFn.name})(${pg.page+1})" ${pg.page>=pg.pages?'disabled':''}>&#8250;</button>
      </div>
    </div>`;
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>

</html>