<?php
$pageTitle = 'Фінанси';
$pageCss   = 'finance';
require __DIR__ . '/../partials/head.php';
?>
<body>
<div class="app-layout">

  <!-- aside і header НЕ пишемо — sidebar.js вставить їх сам -->

    <main class="app-main">

    <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
      <div>
        <h1 class="page-title">Фінанси</h1>
        <p class="page-subtitle" id="period-subtitle">—</p>
      </div>
      <div style="display:flex;gap:10px">
        <button class="btn btn-ghost"   id="btn-expense" onclick="openExpenseModal(null)" style="display:none">+ Витрата</button>
        <button class="btn btn-primary" id="btn-deposit" onclick="openDepositModal()"    style="display:none">+ Депозит</button>
      </div>
    </div>

    <!-- Вибір периоду -->
    <div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;align-items:flex-end">
      <div class="form-group" style="margin:0">
        <label style="font-size:12px">Від</label>
        <input type="date" id="date-from" onchange="loadAll()">
      </div>
      <div class="form-group" style="margin:0">
        <label style="font-size:12px">До</label>
        <input type="date" id="date-to" onchange="loadAll()">
      </div>
    </div>
    <div class="period-btns">
      <button class="period-btn active" onclick="setPeriod('month',this)">Цей місяць</button>
      <button class="period-btn" onclick="setPeriod('prev_month',this)">Минулий місяць</button>
      <button class="period-btn" onclick="setPeriod('week',this)">Цей тиждень</button>
      <button class="period-btn" onclick="setPeriod('today',this)">Сьогодні</button>
      <button class="period-btn" onclick="setPeriod('year',this)">Цей рік</button>
    </div>

    <!-- Зведені картки -->
    <div class="fin-summary">
      <div class="fin-card income">
        <div class="fc-label">Надходження</div>
        <div class="fc-value positive" id="fc-income"><span class="spinner"></span></div>
        <div class="fc-sub" id="fc-income-sub">—</div>
      </div>
      <div class="fin-card expense">
        <div class="fc-label">Витрати</div>
        <div class="fc-value negative" id="fc-expense">—</div>
        <div class="fc-sub" id="fc-expense-sub">—</div>
      </div>
      <div class="fin-card profit">
        <div class="fc-label">Чистий прибуток</div>
        <div class="fc-value" id="fc-profit">—</div>
        <div class="fc-sub">надходження − витрати</div>
      </div>
      <div class="fin-card neutral">
        <div class="fc-label">Поповнено депозитів</div>
        <div class="fc-value" id="fc-deposits">—</div>
        <div class="fc-sub">клієнтські баланси</div>
      </div>
    </div>

    <!-- По методах оплати -->
    <div class="methods-grid" id="methods-grid" style="display:none">
      <div class="method-pill">
        <span class="method-pill-label">&#128181; Готівка</span>
        <span class="method-pill-val" id="mp-cash">—</span>
      </div>
      <div class="method-pill">
        <span class="method-pill-label">&#128179; Карта</span>
        <span class="method-pill-val" id="mp-card">—</span>
      </div>
      <div class="method-pill">
        <span class="method-pill-label">&#128507; Термінал</span>
        <span class="method-pill-val" id="mp-terminal">—</span>
      </div>
    </div>

    <!-- Вкладки -->
    <div class="fin-tabs">
      <button class="fin-tab-btn active" onclick="switchTab('overview',this)">&#128200; Огляд</button>
      <button class="fin-tab-btn"        onclick="switchTab('income',this)">&#128176; Надходження</button>
      <button class="fin-tab-btn"        onclick="switchTab('expenses',this)">&#128394; Витрати</button>
      <button class="fin-tab-btn"        onclick="switchTab('deposits',this)">&#128179; Депозити</button>
    </div>

    <!-- ═══ ОГЛЯД ═══ -->
    <div class="fin-tab-content active" id="tab-overview">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px" id="overview-grid">

        <!-- Структура надходжень -->
        <div class="card">
          <div class="card-title">Надходження</div>
          <div id="income-breakdown">
            <div class="loader"><div class="spinner"></div></div>
          </div>
        </div>

        <!-- Витрати по категоріях -->
        <div class="card">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
            <div class="card-title" style="margin:0">Витрати по категоріях</div>
            <button class="btn btn-ghost btn-sm" id="btn-add-exp"
                    onclick="openExpenseModal(null)" style="display:none">+ Додати</button>
          </div>
          <div id="expense-breakdown">
            <div class="loader"><div class="spinner"></div></div>
          </div>
        </div>

      </div>
    </div>

    <!-- ═══ НАДХОДЖЕННЯ ═══ -->
    <div class="fin-tab-content" id="tab-income">
      <div id="income-list">
        <div class="loader"><div class="spinner"></div></div>
      </div>
    </div>

    <!-- ═══ ВИТРАТИ ═══ -->
    <div class="fin-tab-content" id="tab-expenses">
      <div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;align-items:center">
        <select id="exp-cat-filter" onchange="loadExpenses()"
                style="width:auto;min-width:160px">
          <option value="">Всі категорії</option>
        </select>
        <button class="btn btn-ghost btn-sm" id="btn-exp-list"
                onclick="openExpenseModal(null)" style="display:none">+ Витрата</button>
      </div>
      <div id="expenses-list">
        <div class="loader"><div class="spinner"></div></div>
      </div>
      <div id="expenses-total" style="font-size:14px;font-weight:600;padding:12px 0;
           border-top:1px solid var(--border);margin-top:4px;display:none"></div>
    </div>

    <!-- ═══ ДЕПОЗИТИ ═══ -->
    <div class="fin-tab-content" id="tab-deposits">
      <div id="dep-summary" style="margin-bottom:14px;display:none"></div>
      <div id="deposits-list">
        <div class="loader"><div class="spinner"></div></div>
      </div>
    </div>

  </main>
</div>

<!-- ════ МОДАЛКА: ВИТРАТА ════ -->
<div class="modal-overlay" id="modal-expense">
  <div class="modal" style="max-width:460px">
    <button class="modal-close" onclick="closeModal('modal-expense')">&#10005;</button>
    <h2 class="modal-title" id="exp-modal-title">Нова витрата</h2>
    <div id="exp-error" class="alert alert-error" style="display:none"></div>

    <div class="form-group">
      <label>Категорія</label>
      <input type="text" id="exp-cat" list="exp-cat-list"
             placeholder="Оренда, Комунальні, Зарплата...">
      <datalist id="exp-cat-list"></datalist>
    </div>
    <div class="form-group">
      <label>Опис *</label>
      <input type="text" id="exp-desc" placeholder="Оплата оренди за вересень" maxlength="255">
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px">
      <div class="form-group">
        <label>Сума (грн) *</label>
        <input type="number" id="exp-amount" placeholder="0" min="0.01" step="0.01">
      </div>
      <div class="form-group">
        <label>Дата</label>
        <input type="date" id="exp-date">
      </div>
    </div>
    <div class="form-group">
      <label>Спосіб оплати</label>
      <select id="exp-method">
        <option value="cash">Готівка</option>
        <option value="card">Карта</option>
        <option value="terminal">Термінал</option>
        <option value="transfer">Переказ</option>
      </select>
    </div>
    <div class="form-group">
      <label>Примітка</label>
      <textarea id="exp-notes" rows="2" placeholder="Необов'язково"></textarea>
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" id="btn-exp-save" onclick="saveExpense()">Зберегти</button>
      <button class="btn btn-ghost"   onclick="closeModal('modal-expense')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: ДЕПОЗИТ ════ -->
<div class="modal-overlay" id="modal-deposit">
  <div class="modal" style="max-width:420px">
    <button class="modal-close" onclick="closeModal('modal-deposit')">&#10005;</button>
    <h2 class="modal-title">Поповнення депозиту</h2>
    <div id="dep-error" class="alert alert-error" style="display:none"></div>

    <div class="form-group">
      <label>Клієнт *</label>
      <div style="position:relative">
        <input type="text" id="dep-client-input"
               placeholder="Введіть ім'я або телефон..."
               oninput="searchDepClient()" autocomplete="off">
        <div id="dep-client-dd" style="
          display:none;position:absolute;top:100%;left:0;right:0;
          background:var(--bg-elevated);border:1px solid var(--border-light);
          border-radius:var(--radius-sm);z-index:100;max-height:180px;
          overflow-y:auto;box-shadow:var(--shadow-md);margin-top:4px;
        "></div>
      </div>
      <div id="dep-client-badge" style="display:none;margin-top:8px;padding:10px 12px;
           background:var(--accent-dim);border-radius:var(--radius-sm);font-size:14px">
        <strong id="dep-client-name">—</strong>
        <span style="margin-left:8px;color:var(--text-secondary);font-size:12px">
          Баланс: <span id="dep-client-balance">—</span>
        </span>
        <button onclick="clearDepClient()" style="float:right;background:none;border:none;
                cursor:pointer;color:var(--text-muted)">&#10005;</button>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px">
      <div class="form-group">
        <label>Сума поповнення (грн) *</label>
        <input type="number" id="dep-amount" placeholder="0" min="1" step="1">
      </div>
      <div class="form-group">
        <label>Спосіб оплати</label>
        <select id="dep-method">
          <option value="cash">Готівка</option>
          <option value="card">Карта</option>
          <option value="terminal">Термінал</option>
          <option value="transfer">Переказ</option>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label>Примітка</label>
      <input type="text" id="dep-notes" placeholder="Необов'язково">
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" id="btn-dep-save" onclick="saveDeposit()">Поповнити</button>
      <button class="btn btn-ghost"   onclick="closeModal('modal-deposit')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ Підтвердження видалення витрати ════ -->
<div class="modal-overlay" id="modal-del-exp">
  <div class="modal" style="max-width:360px">
    <h2 class="modal-title">Видалити витрату?</h2>
    <p style="font-size:14px;color:var(--text-secondary);margin-bottom:24px" id="del-exp-desc">—</p>
    <div style="display:flex;gap:10px">
      <button class="btn btn-danger" onclick="confirmDeleteExpense()">Видалити</button>
      <button class="btn btn-ghost"  onclick="closeModal('modal-del-exp')">Скасувати</button>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../partials/scripts.php'; ?>
<script>
let state = {
  canWrite: false, isOwner: false,
  editExpenseId: null, deleteExpenseId: null,
  depClientId: null, depClientTimer: null,
  summaryData: null,
};

// ── Ініціалізація ─────────────────────────────────────────
window.addEventListener('DOMContentLoaded', async () => {
  const ctx = await initPage();
  if (!ctx) return;
  guardBlockedClub(ctx.isBlocked);
  const { u, isSuperAdmin, inClubMode, club, clubRole } = ctx;
  const perms = getPermissions(ctx);
  state.canWrite = perms.canWrite;
  state.isOwner  = perms.isOwner;
  if (state.canWrite) {
    document.getElementById('btn-expense').style.display = 'inline-flex';
    document.getElementById('btn-deposit').style.display = 'inline-flex';
    document.getElementById('btn-add-exp').style.display = 'inline-flex';
    document.getElementById('btn-exp-list').style.display = 'inline-flex';
  }

  // Встановлюємо поточний місяць
  const today  = new Date();
  const month1 = today.toISOString().substring(0,8) + '01';
  const todayS = today.toISOString().split('T')[0];
  document.getElementById('date-from').value = month1;
  document.getElementById('date-to').value   = todayS;
  document.getElementById('exp-date').value  = todayS;

  // Категорії витрат
  loadExpenseCategories();
  loadAll();
});

// ── Управління периодом ───────────────────────────────────
function setPeriod(period, btn) {
  document.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');

  const now = new Date();
  let from, to = now.toISOString().split('T')[0];

  if (period === 'today') {
    from = to;
  } else if (period === 'week') {
    const d = new Date(now);
    d.setDate(d.getDate() - d.getDay() + 1);
    from = d.toISOString().split('T')[0];
  } else if (period === 'month') {
    from = now.toISOString().substring(0,8) + '01';
  } else if (period === 'prev_month') {
    const d = new Date(now.getFullYear(), now.getMonth() - 1, 1);
    from = d.toISOString().split('T')[0];
    const last = new Date(now.getFullYear(), now.getMonth(), 0);
    to   = last.toISOString().split('T')[0];
  } else if (period === 'year') {
    from = now.getFullYear() + '-01-01';
  }

  document.getElementById('date-from').value = from;
  document.getElementById('date-to').value   = to;
  loadAll();
}

function getDates() {
  return {
    date_from: document.getElementById('date-from').value,
    date_to:   document.getElementById('date-to').value,
  };
}

async function loadAll() {
  const d = getDates();
  document.getElementById('period-subtitle').textContent =
    formatDate(d.date_from) + ' — ' + formatDate(d.date_to);

  loadSummary();

  // Завантажуємо активну вкладку
  const activeTab = document.querySelector('.fin-tab-content.active')?.id?.replace('tab-','');
  if (activeTab === 'income')   loadIncome();
  if (activeTab === 'expenses') loadExpenses();
  if (activeTab === 'deposits') loadDeposits();
}

// ── Зведений звіт ─────────────────────────────────────────
async function loadSummary() {
  document.getElementById('fc-income').innerHTML = '<span class="spinner"></span>';

  const res = await api('get_summary', getDates(), 'finance');
  if (!res.success) return;

  state.summaryData = res.summary;
  const s = res.summary;

  document.getElementById('fc-income').textContent   = formatMoney(s.total_income);
  document.getElementById('fc-income-sub').textContent=
    `абонементи ${formatMoney(s.invoices_income)} · товари ${formatMoney(s.products_income)}`;
  document.getElementById('fc-expense').textContent  = formatMoney(s.total_expense);
  document.getElementById('fc-expense-sub').textContent = s.expenses_count + ' записів';
  document.getElementById('fc-deposits').textContent = formatMoney(s.deposits_top_up);

  const profit = s.net_profit;
  const profEl = document.getElementById('fc-profit');
  profEl.textContent = (profit < 0 ? '−' : '') + formatMoney(Math.abs(profit));
  profEl.className   = 'fc-value ' + (profit >= 0 ? 'positive' : 'negative');

  // По методах
  document.getElementById('mp-cash').textContent     = formatMoney(s.by_method.cash);
  document.getElementById('mp-card').textContent     = formatMoney(s.by_method.card);
  document.getElementById('mp-terminal').textContent = formatMoney(s.by_method.terminal);
  document.getElementById('methods-grid').style.display = 'flex';

  // Огляд — структура надходжень
  document.getElementById('income-breakdown').innerHTML = `
    <div class="txn-row">
      <div class="txn-icon income">&#128196;</div>
      <div class="txn-desc">
        <div class="txn-desc-main">Абонементи</div>
        <div class="txn-desc-sub">${s.invoices_count} платежів</div>
      </div>
      <div class="txn-amount pos">${formatMoney(s.invoices_income)}</div>
    </div>
    <div class="txn-row">
      <div class="txn-icon income">&#128722;</div>
      <div class="txn-desc">
        <div class="txn-desc-main">Продаж товарів</div>
        <div class="txn-desc-sub">${s.products_count} продажів · прибуток ${formatMoney(s.products_profit)}</div>
      </div>
      <div class="txn-amount pos">${formatMoney(s.products_income)}</div>
    </div>
    <div class="txn-row" style="border-bottom:none">
      <div class="txn-icon deposit">&#128179;</div>
      <div class="txn-desc">
        <div class="txn-desc-main">Поповнення депозитів</div>
        <div class="txn-desc-sub">кошти на рахунках клієнтів</div>
      </div>
      <div class="txn-amount pos">${formatMoney(s.deposits_top_up)}</div>
    </div>
  `;

  // Витрати по категоріях
  const cats = res.expenses_by_category || [];
  if (!cats.length) {
    document.getElementById('expense-breakdown').innerHTML =
      '<div style="font-size:13px;color:var(--text-muted);padding:16px 0">Витрат за цей період немає</div>';
  } else {
    const maxVal = Math.max(...cats.map(c => parseFloat(c.total)));
    document.getElementById('expense-breakdown').innerHTML =
      cats.map(c => {
        const pct = maxVal > 0 ? Math.round(parseFloat(c.total) / maxVal * 100) : 0;
        return `
          <div class="cat-bar-row">
            <div class="cat-bar-name">${esc(c.category || 'Без категорії')}</div>
            <div class="cat-bar-track">
              <div class="cat-bar-fill" style="width:${pct}%"></div>
            </div>
            <div class="cat-bar-val">${formatMoney(c.total)}</div>
          </div>`;
      }).join('');
  }
}

// ── Надходження ───────────────────────────────────────────
async function loadIncome() {
  document.getElementById('income-list').innerHTML =
    '<div class="loader"><div class="spinner"></div></div>';

  const res = await api('get_income', getDates(), 'finance');
  if (!res.success) return;

  const items = res.income || [];
  if (!items.length) {
    document.getElementById('income-list').innerHTML =
      '<div class="empty-state" style="padding:40px"><p>Надходжень за цей період немає</p></div>';
    return;
  }

  document.getElementById('income-list').innerHTML =
    '<div class="card" style="padding:0 20px">' +
    items.map(i => `
      <div class="txn-row">
        <div class="txn-icon income">
          ${i.source_type === 'invoice' ? '&#128196;' : '&#128722;'}
        </div>
        <div class="txn-desc">
          <div class="txn-desc-main">${esc(i.description || '—')}</div>
          <div class="txn-desc-sub">
            ${i.client_name ? esc(i.client_name) + ' · ' : ''}
            ${payLabel(i.payment_method)} · ${i.admin_name || '—'}
            · ${formatDate(i.created_at)}
          </div>
        </div>
        <div class="txn-amount pos">${formatMoney(i.amount)}</div>
      </div>`).join('') + '</div>';
}

// ── Витрати ───────────────────────────────────────────────
async function loadExpenses() {
  document.getElementById('expenses-list').innerHTML =
    '<div class="loader"><div class="spinner"></div></div>';

  const res = await api('get_expenses', {
    ...getDates(),
    category: document.getElementById('exp-cat-filter').value,
  }, 'finance');

  if (!res.success) return;

  const exps = res.expenses || [];
  const total = parseFloat(res.total || 0);

  document.getElementById('expenses-total').style.display = 'block';
  document.getElementById('expenses-total').innerHTML =
    `Разом за період: <span style="color:var(--danger)">${formatMoney(total)}</span>`;

  if (!exps.length) {
    document.getElementById('expenses-list').innerHTML =
      '<div class="empty-state" style="padding:40px"><p>Витрат за цей період немає</p></div>';
    return;
  }

  document.getElementById('expenses-list').innerHTML =
    '<div class="card" style="padding:0 20px">' +
    exps.map(e => {
      const actions = state.canWrite ? `
        <button class="btn btn-ghost btn-sm" onclick="openExpenseModal(${e.id})" title="Редагувати">&#9998;</button>
        ${state.isOwner ? `<button class="btn btn-ghost btn-sm" style="color:var(--danger)"
          onclick="openDeleteExpense(${e.id},'${esc(e.description)}')" title="Видалити">&#128465;</button>` : ''}
      ` : '';
      return `
        <div class="txn-row">
          <div class="txn-icon expense">&#128394;</div>
          <div class="txn-desc">
            <div class="txn-desc-main">${esc(e.description)}</div>
            <div class="txn-desc-sub">
              ${e.category ? esc(e.category) + ' · ' : ''}
              ${payLabel(e.payment_method)} · ${formatDate(e.expense_date)}
              ${e.notes ? ' · ' + esc(e.notes) : ''}
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:6px">
            <div class="txn-amount neg">${formatMoney(e.amount)}</div>
            <div onclick="event.stopPropagation()">${actions}</div>
          </div>
        </div>`;
    }).join('') + '</div>';
}

async function loadExpenseCategories() {
  const res = await api('get_expense_cats', {}, 'finance');
  if (!res.success) return;
  const cats = res.categories || [];

  const dl  = document.getElementById('exp-cat-list');
  const sel = document.getElementById('exp-cat-filter');
  cats.forEach(c => {
    const o1 = document.createElement('option'); o1.value = c; dl.appendChild(o1);
    const o2 = document.createElement('option'); o2.value = c; o2.textContent = c; sel.appendChild(o2);
  });
}

// ── Депозити ──────────────────────────────────────────────
async function loadDeposits() {
  document.getElementById('deposits-list').innerHTML =
    '<div class="loader"><div class="spinner"></div></div>';

  const res = await api('get_deposits', getDates(), 'finance');
  if (!res.success) return;

  const deps = res.deposits || [];
  const sum  = res.summary  || {};

  document.getElementById('dep-summary').style.display = 'flex';
  document.getElementById('dep-summary').innerHTML = `
    <div class="method-pill">
      <span class="method-pill-label">Поповнень</span>
      <span class="method-pill-val" style="color:var(--success)">${formatMoney(sum.top_up || 0)}</span>
    </div>
    <div class="method-pill">
      <span class="method-pill-label">Списань</span>
      <span class="method-pill-val" style="color:var(--danger)">${formatMoney(Math.abs(sum.write_off || 0))}</span>
    </div>`;

  if (!deps.length) {
    document.getElementById('deposits-list').innerHTML =
      '<div class="empty-state" style="padding:40px"><p>Депозитних операцій за цей період немає</p></div>';
    return;
  }

  const opLabel = { top_up:'Поповнення', pay_invoice:'Оплата абонементу',
    pay_product:'Оплата товару', refund:'Повернення', correction:'Коригування' };

  document.getElementById('deposits-list').innerHTML =
    '<div class="card" style="padding:0 20px">' +
    deps.map(d => `
      <div class="txn-row">
        <div class="txn-icon ${parseFloat(d.amount)>=0?'deposit':'expense'}">
          ${parseFloat(d.amount) >= 0 ? '&#128176;' : '&#128394;'}
        </div>
        <div class="txn-desc">
          <div class="txn-desc-main">
            ${esc(d.client_name)}
            <span class="dep-op ${d.operation}">${opLabel[d.operation]||d.operation}</span>
          </div>
          <div class="txn-desc-sub">
            ${d.client_phone ? d.client_phone + ' · ' : ''}
            ${payLabel(d.payment_method)} · ${d.admin_name||'—'} · ${formatDate(d.created_at)}
          </div>
        </div>
        <div class="txn-amount ${parseFloat(d.amount)>=0?'pos':'neg'}">
          ${parseFloat(d.amount)>=0?'+':''}${formatMoney(d.amount)}
        </div>
      </div>`).join('') + '</div>';
}

// ── Форма витрати ─────────────────────────────────────────
function openExpenseModal(id) {
  state.editExpenseId = id;
  document.getElementById('exp-modal-title').textContent = id ? 'Редагування витрати' : 'Нова витрата';
  document.getElementById('exp-error').style.display = 'none';

  if (!id) {
    document.getElementById('exp-cat').value    = '';
    document.getElementById('exp-desc').value   = '';
    document.getElementById('exp-amount').value = '';
    document.getElementById('exp-notes').value  = '';
    document.getElementById('exp-date').value   = new Date().toISOString().split('T')[0];
  }
  // При редагуванні — дані вже є у списку, але для простоти не заповнюємо автоматично
  openModal('modal-expense');
  document.getElementById('exp-desc').focus();
}

async function saveExpense() {
  const errEl = document.getElementById('exp-error');
  errEl.style.display = 'none';
  const btn = document.getElementById('btn-exp-save');
  btn.disabled = true; btn.textContent = 'Збереження...';

  const payload = {
    id:             state.editExpenseId,
    category:       document.getElementById('exp-cat').value.trim(),
    description:    document.getElementById('exp-desc').value.trim(),
    amount:         parseFloat(document.getElementById('exp-amount').value) || 0,
    expense_date:   document.getElementById('exp-date').value,
    payment_method: document.getElementById('exp-method').value,
    notes:          document.getElementById('exp-notes').value.trim(),
  };

  const action = state.editExpenseId ? 'update_expense' : 'add_expense';
  const res    = await api(action, payload, 'finance');
  btn.disabled = false; btn.textContent = 'Зберегти';

  if (res.success) {
    closeModal('modal-expense');
    toast(state.editExpenseId ? 'Збережено' : 'Витрату записано', 'success');
    loadSummary(); loadExpenses();
    loadExpenseCategories();
  } else {
    errEl.textContent = res.error; errEl.style.display = 'block';
  }
}

function openDeleteExpense(id, desc) {
  state.deleteExpenseId = id;
  document.getElementById('del-exp-desc').textContent = desc;
  openModal('modal-del-exp');
}

async function confirmDeleteExpense() {
  const res = await api('delete_expense', { id: state.deleteExpenseId }, 'finance');
  closeModal('modal-del-exp');
  if (res.success) { toast('Видалено', 'success'); loadSummary(); loadExpenses(); }
  else toast(res.error, 'error');
}

// ── Депозит ───────────────────────────────────────────────
function openDepositModal() {
  state.depClientId = null;
  document.getElementById('dep-error').style.display = 'none';
  document.getElementById('dep-client-input').value = '';
  document.getElementById('dep-client-badge').style.display = 'none';
  document.getElementById('dep-amount').value = '';
  document.getElementById('dep-notes').value  = '';
  openModal('modal-deposit');
}

let depClientTimer = null;
function searchDepClient() {
  clearTimeout(depClientTimer);
  const q = document.getElementById('dep-client-input').value.trim();
  if (q.length < 2) { document.getElementById('dep-client-dd').style.display='none'; return; }
  depClientTimer = setTimeout(async () => {
    const res = await api('search', { q }, 'clients');
    const dd = document.getElementById('dep-client-dd');
    if (!res.success || !res.results?.length) { dd.style.display='none'; return; }
    dd.innerHTML = res.results.map(c => `
      <div onclick="selectDepClient(${c.id},'${esc(c.full_name)}',${c.balance||0})"
           style="padding:9px 12px;cursor:pointer;font-size:14px;border-bottom:1px solid var(--border)"
           onmouseover="this.style.background='var(--bg-hover)'"
           onmouseout="this.style.background=''">
        <strong>${esc(c.full_name)}</strong>
        <span style="color:var(--text-muted);font-size:12px;margin-left:8px">${c.phone||''}</span>
      </div>`).join('');
    dd.style.display = 'block';
  }, 300);
}

function selectDepClient(id, name, balance) {
  state.depClientId = id;
  document.getElementById('dep-client-input').value = '';
  document.getElementById('dep-client-dd').style.display = 'none';
  document.getElementById('dep-client-badge').style.display = 'block';
  document.getElementById('dep-client-name').textContent    = name;
  document.getElementById('dep-client-balance').textContent = formatMoney(balance);
}

function clearDepClient() {
  state.depClientId = null;
  document.getElementById('dep-client-badge').style.display = 'none';
}

async function saveDeposit() {
  const errEl = document.getElementById('dep-error');
  errEl.style.display = 'none';
  const btn = document.getElementById('btn-dep-save');
  btn.disabled = true; btn.textContent = 'Поповнюємо...';

  const res = await api('add_deposit', {
    client_id:      state.depClientId,
    amount:         parseFloat(document.getElementById('dep-amount').value) || 0,
    payment_method: document.getElementById('dep-method').value,
    notes:          document.getElementById('dep-notes').value.trim(),
  }, 'finance');

  btn.disabled = false; btn.textContent = 'Поповнити';

  if (res.success) {
    closeModal('modal-deposit');
    toast(res.message, 'success');
    loadSummary(); loadDeposits();
  } else {
    errEl.textContent = res.error; errEl.style.display = 'block';
  }
}

// ── Вкладки ───────────────────────────────────────────────
function switchTab(tab, btn) {
  document.querySelectorAll('.fin-tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.fin-tab-content').forEach(c => {
    c.classList.remove('active'); c.style.display = 'none';
  });
  btn.classList.add('active');
  const el = document.getElementById('tab-' + tab);
  el.classList.add('active'); el.style.display = 'block';

  if (tab === 'income')   loadIncome();
  if (tab === 'expenses') loadExpenses();
  if (tab === 'deposits') loadDeposits();
}

// ── Утиліти ───────────────────────────────────────────────
function payLabel(m) {
  return { cash:'Готівка', card:'Карта', terminal:'Термінал',
           transfer:'Переказ', deposit:'Депозит', other:'Інше' }[m] || m || '—';
}
function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>

</html>
