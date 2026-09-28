<?php
$pageTitle = 'Білінг платформи';
$pageCss   = 'finance';
require __DIR__ . '/../partials/head.php';
?>
<body>
<div class="app-layout">

  <!-- aside і header НЕ пишемо — sidebar.js вставить їх сам -->

  <main class="app-main">


    <!-- Вкладки -->
    <div class="fin-tabs">
      <button class="fin-tab-btn active" onclick="switchTab('plans',this)">&#128179; Тарифні плани</button>
      <button class="fin-tab-btn" onclick="switchTab('subs',this)">&#128196; Підписки клубів</button>
      <button class="fin-tab-btn" onclick="switchTab('invoices',this)">&#128221; Рахунки</button>
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

  </main>
</div>

<!-- Модалка редагування плану -->
<div class="modal-overlay" id="modal-plan">
  <div class="modal" style="max-width:480px">
    <button class="modal-close" onclick="closeModal('modal-plan')">&#10005;</button>
    <h2 class="modal-title" id="plan-modal-title">Редагувати план</h2>
    <input type="hidden" id="plan-id">
    <div class="form-group">
      <label>Назва *</label>
      <input type="text" id="plan-name" placeholder="Business">
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px">
      <div class="form-group">
        <label>Ціна (грн/міс)</label>
        <input type="number" id="plan-price" min="0" step="1" placeholder="0 = безкоштовно">
      </div>
      <div class="form-group">
        <label>Тріал (днів)</label>
        <input type="number" id="plan-trial-days" min="0" step="1" placeholder="0 = без тріалу">
      </div>
      <div class="form-group">
        <label>Ліміт клієнтів</label>
        <input type="number" id="plan-clients" placeholder="порожньо = ∞" min="1">
      </div>
      <div class="form-group">
        <label>Ліміт користувачів</label>
        <input type="number" id="plan-users" placeholder="порожньо = ∞" min="1">
      </div>
      <div class="form-group">
        <label>Ліміт абонементів</label>
        <input type="number" id="plan-invoices" placeholder="порожньо = ∞" min="1">
      </div>
      <div class="form-group">
        <label>Активний</label>
        <select id="plan-active">
          <option value="1">Так</option>
          <option value="0">Ні</option>
        </select>
      </div>
    </div>
    <!-- is_free -->
    <div class="form-group">
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
        <input type="checkbox" id="plan-is-free"
               style="width:16px;height:16px;accent-color:var(--success)">
        <span>
          Free план
          <span style="font-size:12px;color:var(--text-muted);display:block;font-weight:400">
            Клуби автоматично переходять на цей план після закінчення тріалу
          </span>
        </span>
      </label>
    </div>
    <!-- allowed_pages -->
    <div class="form-group">
      <label style="font-size:13px;font-weight:600;margin-bottom:8px;display:block">
        Дозволені сторінки
        <span style="font-size:11px;color:var(--text-muted);font-weight:400;margin-left:6px">(порожньо = всі)</span>
      </label>
      <div id="plan-pages-wrap" style="display:grid;grid-template-columns:1fr 1fr;gap:4px 14px;padding:10px;background:var(--bg-elevated);border-radius:var(--radius-sm)"></div>
    </div>
    <div id="plan-error" class="alert alert-error" style="display:none"></div>
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
let _plansCache = {};

async function loadPlans() {
  const res = await api('get_plans', {}, 'saas');
  if (!res.success) return;
  _plansCache = {};
  res.plans.forEach(p => { _plansCache[p.id] = p; });

  document.getElementById('plans-list').innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px">
      ${res.plans.map(p => `
        <div class="card" style="position:relative;overflow:hidden">
          <div style="position:absolute;top:0;left:0;width:3px;height:100%;
               background:${p.is_free ? 'var(--success)' : p.is_active ? 'var(--accent)' : 'var(--border)'}"></div>
          <div style="padding-left:8px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;gap:6px;flex-wrap:wrap">
              <div style="font-size:16px;font-weight:700">${esc(p.name)}</div>
              <div style="display:flex;gap:4px;flex-wrap:wrap">
                ${p.is_free    ? '<span class="badge badge-active">Free</span>' : ''}
                ${p.trial_days ? `<span class="badge badge-info">Тріал ${p.trial_days}д</span>` : ''}
                ${!p.is_active ? '<span class="badge badge-inactive">Вимкнено</span>' : ''}
              </div>
            </div>
            <div style="font-size:24px;font-weight:700;color:var(--accent);margin-bottom:8px">
              ${p.price_monthly > 0 ? p.price_monthly + ' <span style="font-size:13px;font-weight:400;color:var(--text-muted)">грн/міс</span>' : '<span style="color:var(--success)">Безкоштовно</span>'}
            </div>
            <div style="font-size:13px;color:var(--text-secondary);margin-bottom:4px">
              &#128100; ${p.clients_limit   ? 'до ' + p.clients_limit   + ' клієнтів'      : 'Необмежено клієнтів'}
            </div>
            <div style="font-size:13px;color:var(--text-secondary);margin-bottom:4px">
              &#128101; ${p.users_limit     ? 'до ' + p.users_limit     + ' користувачів'  : 'Необмежено корист.'}
            </div>
            <div style="font-size:13px;color:var(--text-secondary);margin-bottom:12px">
              &#128196; ${p.invoices_limit  ? 'до ' + p.invoices_limit  + ' абонементів'   : 'Необмежено абон.'}
            </div>
            <div style="font-size:12px;color:var(--text-muted)">
              ${(p.features||[]).map(f => '· ' + f).join('<br>')}
            </div>
            <div style="display:flex;gap:6px;margin-top:12px;align-items:center">
              <button class="btn btn-ghost btn-sm"
                      onclick="openPlanModalById(${p.id})">
                &#9998; Редагувати
              </button>
              <span style="font-size:12px;color:var(--text-muted)">${p.clubs_count} клубів</span>
            </div>
          </div>
        </div>
      `).join('')}
      <div class="card" style="display:flex;align-items:center;justify-content:center;min-height:160px;border:2px dashed var(--border);background:none;cursor:pointer"
           onclick="openPlanModalById(0)">
        <div style="text-align:center;color:var(--text-muted)">
          <div style="font-size:28px">+</div>
          <div style="font-size:13px;margin-top:4px">Новий план</div>
        </div>
      </div>
    </div>`;
}

function openPlanModalById(id) {
  var p = _plansCache[id];
  if (id === 0) { openPlanModal(0,'',0,null,null,null,1,0,0,null); return; }
  if (!p) return;
  openPlanModal(p.id,p.name,p.price_monthly,p.clients_limit,p.users_limit,p.invoices_limit,p.is_active,p.trial_days,p.is_free,p.allowed_pages);
}

const ALL_PAGES = [
  {slug:'clients',label:'Клієнти'},{slug:'invoices',label:'Абонементи'},
  {slug:'payments',label:'Оплати'},{slug:'tariffs',label:'Тарифи'},
  {slug:'visits',label:'Відвідування'},{slug:'arrivals',label:'Прихід'},
  {slug:'finance',label:'Фінанси'},{slug:'cash',label:'Каса'},
  {slug:'products',label:'Товари'},{slug:'sales',label:'Продажі'},
  {slug:'trainers',label:'Тренери'},{slug:'users',label:'Команда'},
  {slug:'settings',label:'Налаштування'},
];

function openPlanModal(id, name, price, clients, users, invoices, isActive, trialDays, isFree, allowedPages) {
  editPlanId = id;
  document.getElementById('plan-id').value            = id;
  document.getElementById('plan-modal-title').textContent = id ? 'Редагувати план' : 'Новий план';
  document.getElementById('plan-name').value          = name;
  document.getElementById('plan-price').value         = price;
  document.getElementById('plan-clients').value       = clients  != null ? clients  : '';
  document.getElementById('plan-users').value         = users    != null ? users    : '';
  document.getElementById('plan-invoices').value      = invoices != null ? invoices : '';
  document.getElementById('plan-active').value        = isActive ? '1' : '0';
  document.getElementById('plan-trial-days').value    = trialDays || 0;
  document.getElementById('plan-is-free').checked     = !!isFree;
  document.getElementById('plan-error').style.display = 'none';
  var wrap = document.getElementById('plan-pages-wrap');
  wrap.innerHTML = ALL_PAGES.map(function(p) {
    var checked = Array.isArray(allowedPages) && allowedPages.includes(p.slug);
    return '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">' +
      '<input type="checkbox" class="plan-page-cb" value="' + p.slug + '"' + (checked?' checked':'') +
      ' style="width:14px;height:14px;accent-color:var(--accent)">' + p.label + '</label>';
  }).join('');
  openModal('modal-plan');
}

async function savePlan() {
  const name = document.getElementById('plan-name').value.trim();
  if (!name) {
    document.getElementById('plan-error').textContent = 'Введіть назву плану';
    document.getElementById('plan-error').style.display = 'block';
    return;
  }
  const checkedPages = [...document.querySelectorAll('.plan-page-cb:checked')].map(cb => cb.value);
  const res = await api('update_plan', {
    id:             editPlanId || 0,
    name,
    price_monthly:  parseFloat(document.getElementById('plan-price').value)    || 0,
    clients_limit:  document.getElementById('plan-clients').value  || null,
    users_limit:    document.getElementById('plan-users').value    || null,
    invoices_limit: document.getElementById('plan-invoices').value || null,
    trial_days:     parseInt(document.getElementById('plan-trial-days').value) || 0,
    is_free:        document.getElementById('plan-is-free').checked ? 1 : 0,
    is_active:      parseInt(document.getElementById('plan-active').value),
    allowed_pages:  checkedPages.length ? checkedPages : null,
  }, 'saas');
  if (res.success) { closeModal('modal-plan'); toast('Збережено', 'success'); loadPlans(); }
  else {
    document.getElementById('plan-error').textContent = res.error;
    document.getElementById('plan-error').style.display = 'block';
  }
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

// ── Вкладки ────────────────────────────────────────────────
function switchTab(tab, btn) {
  document.querySelectorAll('.fin-tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.fin-tab-content').forEach(c => c.style.display='none');
  btn.classList.add('active');
  document.getElementById('tab-'+tab).style.display = 'block';
  if (tab==='subs')     loadSubs();
  if (tab==='invoices') loadInvoices();
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