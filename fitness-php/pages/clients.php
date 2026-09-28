<?php
$pageTitle = 'Клієнти';
$pageCss   = 'clients';
require __DIR__ . '/../partials/head.php';
?>
<body>

<div class="app-layout">

  <!-- aside і header НЕ пишемо — sidebar.js вставить їх сам -->

  <!-- ── MAIN ── -->
  <main class="app-main">

    <!-- Toolbar: пошук + фільтри + кнопка -->
    <div class="toolbar">
      <div class="search-wrap">
        <span class="search-icon">&#128269;</span>
        <input type="text" id="search-input" placeholder="Ім'я, телефон, email..."
               oninput="onSearchInput()" autocomplete="off">
      </div>
      <select class="filter-select" id="status-filter" onchange="loadClients(1)">
        <option value="">Всі статуси</option>
        <option value="active">Активні</option>
        <option value="inactive">Неактивні</option>
        <option value="frozen">Заморожені</option>
      </select>
      <select class="filter-select" id="order-select" onchange="loadClients(1)">
        <option value="created_at|desc">Спочатку нові</option>
        <option value="created_at|asc">Спочатку старі</option>
        <option value="full_name|asc">А → Я</option>
        <option value="full_name|desc">Я → А</option>
      </select>
      <button class="btn btn-primary" id="btn-add" onclick="openAddModal()"
              data-shift-action style="display:none">+ Додати клієнта</button>
    </div>

    <!-- Таблиця -->
    <div class="card" style="padding:0;overflow:hidden">
      <!-- Мобільні картки -->
      <div id="clients-cards" style="display:none"></div>

      <!-- Таблиця (ПК) -->
      <div id="clients-table-wrap" class="table-wrap">
        <table>
          <thead class="mob-hide">
            <tr>
              <th>Клієнт</th>
              <th>Статус</th>
              <th class="mob-hide">Абонемент</th>
              <th class="mob-hide">Останнє відвідування</th>
              <th>Баланс</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="clients-tbody">
            <tr><td colspan="6"><div class="loader"><div class="spinner"></div> Завантаження...</div></td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <div class="pagination" id="pagination" style="padding:8px 0"></div>

  </main>
</div>

<!-- ════════════════════════════════════════
     МОДАЛКА: ДОДАТИ / РЕДАГУВАТИ КЛІЄНТА
════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-client">
  <div class="modal" style="max-width:560px">
    <button class="modal-close" onclick="closeModal('modal-client')">&#10005;</button>
    <h2 class="modal-title" id="modal-client-title">Новий клієнт</h2>

    <div id="modal-error" class="alert alert-error" style="display:none"></div>

    <div class="form-row">
      <div class="form-group" style="grid-column:1/-1">
        <label>ПІБ *</label>
        <input type="text" id="f-name" placeholder="Іван Петренко" maxlength="120">
      </div>
      <div class="form-group">
        <label>Телефон</label>
        <input type="tel" id="f-phone" placeholder="+38 067 123 45 67">
      </div>
      <div class="form-group">
        <label>Email</label>
        <input type="email" id="f-email" placeholder="ivan@example.com">
      </div>
      <div class="form-group">
        <label>Дата народження</label>
        <input type="date" id="f-birthday">
      </div>
      <div class="form-group">
        <label>Стать</label>
        <select id="f-gender">
          <option value="">Не вказано</option>
          <option value="M">Чоловік</option>
          <option value="F">Жінка</option>
        </select>
      </div>
      <div class="form-group" style="grid-column:1/-1">
        <label>Адреса</label>
        <input type="text" id="f-address" placeholder="м. Київ, вул. Хрещатик 1">
      </div>
      <div class="form-group">
        <label>Статус</label>
        <select id="f-status">
          <option value="active">Активний</option>
          <option value="inactive">Неактивний</option>
          <option value="frozen">Заморожений</option>
        </select>
      </div>
      <div class="form-group">
        <label>Звідки дізнався</label>
        <select id="f-source">
          <option value="">Не вказано</option>
          <option value="Реклама">Реклама</option>
          <option value="Instagram">Instagram</option>
          <option value="Рекомендація">Рекомендація</option>
          <option value="Вивіска">Вивіска</option>
          <option value="Google">Google</option>
          <option value="Інше">Інше</option>
        </select>
      </div>
      <div class="form-group" style="grid-column:1/-1">
        <label>Нотатки</label>
        <textarea id="f-notes" placeholder="Внутрішні нотатки (клієнт не бачить)"></textarea>
      </div>
    </div>

    <div style="display:flex;gap:10px;margin-top:8px">
      <button class="btn btn-primary" id="btn-save-client" onclick="saveClient()">Зберегти</button>
      <button class="btn btn-ghost"   onclick="closeModal('modal-client')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════
     МОДАЛКА: ПЕРЕГЛЯД КЛІЄНТА
════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-view">
  <div class="modal modal-view-card" style="max-width:580px">
    <button class="modal-close" onclick="closeModal('modal-view')">&#10005;</button>

    <div id="view-loading" class="loader"><div class="spinner"></div> Завантаження...</div>
    <div id="view-content" class="view-card-body" style="display:none">

      <!-- Шапка -->
      <div class="client-detail-header">
        <div class="client-detail-avatar" id="view-avatar">?</div>
        <div>
          <div class="view-client-name" id="view-name">—</div>
          <div style="margin-top:4px" id="view-status-badge"></div>
        </div>
      </div>

      <!-- Вкладки -->
      <div class="modal-tabs">
        <button class="modal-tab-btn active" onclick="switchViewTab('info',this)">Інформація</button>
        <button class="modal-tab-btn" onclick="switchViewTab('invoices',this)">Абонементи</button>
        <button class="modal-tab-btn" onclick="switchViewTab('visits',this)">Відвідування</button>
      </div>

      <!-- Вкладка: Інформація -->
      <div class="modal-tab-content active view-tab-scroll" id="view-tab-info">
        <div class="detail-fields-grid" id="view-fields"></div>
      </div>

      <!-- Вкладка: Абонементи -->
      <div class="modal-tab-content view-tab-scroll" id="view-tab-invoices">
        <div id="view-invoices-list">
          <div class="empty-state"><div class="icon">&#128196;</div><p>Абонементів ще немає</p></div>
        </div>
      </div>

      <!-- Вкладка: Відвідування -->
      <div class="modal-tab-content view-tab-scroll" id="view-tab-visits">
        <div id="view-visits-list">
          <div class="empty-state"><div class="icon">&#128197;</div><p>Відвідувань ще немає</p></div>
        </div>
      </div>

    </div><!-- /view-content -->

    <!-- Фіксований футер з кнопками -->
    <div class="view-card-footer" id="view-actions" style="display:none"></div>

  </div>
</div>

<!-- ════ МОДАЛКА: ПРОДАТИ АБОНЕМЕНТ (з карти клієнта) ════ -->
<div class="modal-overlay" id="modal-sell-client">
  <div class="modal" style="max-width:560px">
    <button class="modal-close" onclick="closeModal('modal-sell-client')">&#10005;</button>
    <h2 class="modal-title">Продати абонемент</h2>
    <div id="sc-error" class="alert alert-error" style="display:none"></div>
    <div id="sc-client-badge" style="padding:10px 12px;background:var(--accent-dim);
         border:1px solid rgba(79,156,249,.3);border-radius:var(--radius-sm);margin-bottom:16px">
      <span id="sc-client-name" style="font-weight:500"></span>
    </div>
    <div class="form-group">
      <label>Тариф *</label>
      <div class="tariff-picker" id="sc-tariff-picker">
        <select id="sc-tariff-select" onchange="scSelectTariff(this.value)"
                style="width:100%">
          <option value="">— Оберіть тариф —</option>
        </select>
        <div id="sc-tariff-info" style="display:none;margin-top:8px;padding:10px 12px;
             background:var(--bg-elevated);border:1px solid var(--border);
             border-radius:var(--radius-sm);font-size:13px;color:var(--text-secondary)">
        </div>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px">
      <div class="form-group">
        <label>Дата початку *</label>
        <input type="date" id="sc-start-date">
      </div>
      <div class="form-group">
        <label>Знижка (%)</label>
        <input type="number" id="sc-discount" value="0" min="0" max="100" step="1">
      </div>
    </div>
    <div class="form-group">
      <label>Примітка</label>
      <textarea id="sc-notes" rows="2" placeholder="Необов'язково..."></textarea>
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" id="sc-submit" onclick="submitSellClient()">Продати</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-sell-client')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: ДОДАТИ ОПЛАТУ ════ -->
<div class="modal-overlay" id="modal-add-payment">
  <div class="modal" style="max-width:420px">
    <button class="modal-close" onclick="closeModal('modal-add-payment')">&#10005;</button>
    <h2 class="modal-title">Додати оплату</h2>
    <div id="ap-client-badge" style="padding:8px 12px;background:var(--accent-dim);
         border:1px solid rgba(79,156,249,.3);border-radius:var(--radius-sm);margin-bottom:14px">
      <span id="ap-client-name" style="font-weight:500"></span>
    </div>
    <div class="form-group">
      <label>Абонемент *</label>
      <select id="ap-invoice"></select>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px">
      <div class="form-group">
        <label>Сума *</label>
        <input type="number" id="ap-amount" min="1" step="1" placeholder="0">
      </div>
      <div class="form-group">
        <label>Спосіб</label>
        <select id="ap-method">
          <option value="cash">Готівка</option>
          <option value="card">Карта</option>
          <option value="terminal">Термінал</option>
          <option value="deposit">Депозит</option>
          <option value="transfer">Переказ</option>
        </select>
      </div>
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" id="ap-submit" onclick="submitAddPayment()">Записати</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-add-payment')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: ПОПОВНИТИ ДЕПОЗИТ ════ -->
<div class="modal-overlay" id="modal-deposit">
  <div class="modal" style="max-width:380px">
    <button class="modal-close" onclick="closeModal('modal-deposit')">&#10005;</button>
    <h2 class="modal-title">Поповнити депозит</h2>
    <div id="dep-client-badge" style="padding:8px 12px;background:var(--accent-dim);
         border:1px solid rgba(79,156,249,.3);border-radius:var(--radius-sm);margin-bottom:14px">
      <span id="dep-client-name" style="font-weight:500"></span>
      <span style="margin-left:10px;font-size:12px;color:var(--text-muted)">
        Баланс: <strong id="dep-balance">—</strong>
      </span>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px">
      <div class="form-group">
        <label>Сума *</label>
        <input type="number" id="dep-amount" min="1" step="1" placeholder="0">
      </div>
      <div class="form-group">
        <label>Спосіб</label>
        <select id="dep-method">
          <option value="cash">Готівка</option>
          <option value="card">Карта</option>
          <option value="terminal">Термінал</option>
          <option value="transfer">Переказ</option>
        </select>
      </div>
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" id="dep-submit" onclick="submitDeposit()">Поповнити</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-deposit')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: ВІДВІДУВАННЯ ════ -->
<div class="modal-overlay" id="modal-checkin">
  <div class="modal" style="max-width:380px">
    <button class="modal-close" onclick="closeModal('modal-checkin')">&#10005;</button>
    <h2 class="modal-title">Відмітити відвідування</h2>
    <div id="ci-client-badge" style="padding:8px 12px;background:var(--accent-dim);
         border:1px solid rgba(79,156,249,.3);border-radius:var(--radius-sm);margin-bottom:14px">
      <span id="ci-client-name" style="font-weight:500"></span>
    </div>
    <div class="form-group">
      <label>Примітка</label>
      <input type="text" id="ci-notes" placeholder="Необов'язково...">
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" id="ci-submit" onclick="submitCheckin()">Відмітити</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-checkin')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ Підтвердження видалення ════ -->
<div class="modal-overlay" id="modal-confirm">
  <div class="modal" style="max-width:380px">
    <h2 class="modal-title">Архівувати клієнта?</h2>
    <p style="font-size:14px;color:var(--text-secondary);margin-bottom:24px">
      Клієнта буде переведено в статус "архів". Всі дані збережуться.
    </p>
    <div style="display:flex;gap:10px">
      <button class="btn btn-danger" id="btn-confirm-delete" onclick="confirmDelete()">Архівувати</button>
      <button class="btn btn-ghost"  onclick="closeModal('modal-confirm')">Скасувати</button>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../partials/scripts.php'; ?>
<script>
// ── Стан ────────────────────────────────────────────────────
let state = {
  page: 1,
  totalPages: 1,
  editId: null,
  deleteId: null,
  canWrite: false,
  searchTimer: null,
  currentClubId: null,
};

// ── Ініціалізація ─────────────────────────────────────────
window.addEventListener('DOMContentLoaded', async () => {
  const ctx = await initPage({ title: 'Клієнти' });
  if (!ctx) return;
  guardBlockedClub(ctx.isBlocked);
  window._shiftCtx = ctx;
  applyShiftLock(ctx);
  applyPlanLock(ctx);

  const { isSuperAdmin, inClubMode, clubRole, club } = ctx;

  if (club) {
    state.currentClubId = club.id;
  }

  // Визначаємо права — повторює логіку getPermissions з sidebar.js
  if (isSuperAdmin && inClubMode) {
    state.canWrite = true;
  } else if (isSuperAdmin && !inClubMode) {
    state.canWrite = false;
  } else {
    const level = clubRole?.level ?? 0;
    state.canWrite = level >= 50;
  }

  if (state.canWrite) {
    document.getElementById('btn-add').style.display = 'inline-flex';
  }

  loadClients(1);
});

// ── Завантаження списку ───────────────────────────────────
async function loadClients(page = 1) {
  state.page = page;

  const tbody = document.getElementById('clients-tbody');
  tbody.innerHTML = `<tr><td colspan="6"><div class="loader"><div class="spinner"></div> Завантаження...</div></td></tr>`;

  const [orderField, orderDir] = (document.getElementById('order-select').value || 'created_at|desc').split('|');

  const res = await api('get_list', {
    search:   document.getElementById('search-input').value.trim(),
    status:   document.getElementById('status-filter').value,
    page:     page,
    per_page: 25,
    order:    orderField,
    dir:      orderDir,
  }, 'clients');

  if (!res.success) {
    tbody.innerHTML = `<tr><td colspan="6"><div class="alert alert-error">${res.error}</div></td></tr>`;
    return;
  }

  const { clients, pagination } = res;
  state.totalPages = pagination.pages;

  const isMobile    = window.innerWidth <= 768;
  const cardsEl     = document.getElementById('clients-cards');
  const tableWrapEl = document.getElementById('clients-table-wrap');
  const emptyHtml   = '<div class="empty-state"><div class="icon">&#128100;</div>' +
    '<h3>Клієнтів не знайдено</h3><p>Змініть параметри пошуку або додайте першого клієнта</p></div>';

  if (isMobile) {
    tableWrapEl.style.display = 'none';
    cardsEl.style.display = 'block';
    cardsEl.innerHTML = clients.length
      ? clients.map(cl => renderCard(cl)).join('')
      : emptyHtml;
  } else {
    cardsEl.style.display = 'none';
    tableWrapEl.style.display = '';
    tbody.innerHTML = clients.length
      ? clients.map(cl => renderRow(cl)).join('')
      : `<tr><td colspan="6">${emptyHtml}</td></tr>`;
  }

  renderPagination(pagination);
  applyShiftLock(window._shiftCtx);
  applyPlanLock(window._shiftCtx);
}

function renderCard(c) {
  const initials = getInitials(c.full_name);
  const statusMap = {
    active:   ['badge-active',   'Активний'],
    inactive: ['badge-inactive', 'Неактивний'],
    frozen:   ['badge-pending',  'Заморожений'],
    banned:   ['badge-inactive', 'Архів'],
  };
  const [badgeClass, badgeLabel] = statusMap[c.status] || ['badge-info', c.status];
  const actions = state.canWrite ? `
    <button class="btn btn-ghost btn-sm" data-shift-action onclick="event.stopPropagation();openEditModal(${c.id})">&#9998;</button>
    <button class="btn btn-ghost btn-sm" data-shift-action onclick="event.stopPropagation();openDeleteConfirm(${c.id})" style="color:var(--danger)">&#128465;</button>` : '';

  return `
  <div class="client-card" onclick="openViewModal(${c.id})">
    <div class="client-card-main">
      <div class="client-avatar-sm">${initials}</div>
      <div class="client-card-body">
        <div class="client-card-name">${escHtml(c.full_name)}</div>
        <div class="client-card-phone">${c.phone || '—'}</div>
      </div>
      <div class="client-card-right">
        <span class="badge ${badgeClass}">${badgeLabel}</span>
        ${c.balance != 0 ? `<div class="client-card-balance">${formatMoney(c.balance)}</div>` : ''}
        <div class="client-card-actions" onclick="event.stopPropagation()">${actions}</div>
      </div>
    </div>
  </div>`;
}

function renderRow(c) {
  const initials = getInitials(c.full_name);
  const statusMap = {
    active:   ['badge-active',   'Активний'],
    inactive: ['badge-inactive', 'Неактивний'],
    frozen:   ['badge-pending',  'Заморожений'],
    banned:   ['badge-inactive', 'Архів'],
  };
  const [badgeClass, badgeLabel] = statusMap[c.status] || ['badge-info', c.status];

  let tariffHtml = '<span class="text-muted" style="font-size:12px">—</span>';
  if (c.active_tariff) {
    const endDate  = new Date(c.tariff_end_date);
    const now      = new Date();
    const daysLeft = Math.ceil((endDate - now) / 86400000);
    const expClass = daysLeft <= 0 ? 'expired' : daysLeft <= 7 ? 'soon' : '';
    const expLabel = daysLeft <= 0 ? 'Прострочено' : `до ${formatDate(c.tariff_end_date)}`;
    tariffHtml = `
      <div class="tariff-badge" title="${c.active_tariff}">${c.active_tariff}</div>
      <div class="tariff-expire ${expClass}">${expLabel}</div>
    `;
  }

  const editBtn = state.canWrite
    ? `<button class="btn btn-ghost btn-sm" data-shift-action onclick="openEditModal(${c.id})" title="Редагувати">&#9998;</button>
       <button class="btn btn-ghost btn-sm" data-shift-action onclick="openDeleteConfirm(${c.id})" title="Архівувати" style="color:var(--danger)">&#128465;</button>`
    : '';

  return `
    <tr onclick="openViewModal(${c.id})" style="cursor:pointer">
      <td class="mob-primary">
        <div class="client-info">
          <div class="client-avatar">${initials}</div>
          <div>
            <div class="client-name">${escHtml(c.full_name)}</div>
            <div class="client-phone">${c.phone || '—'}</div>
          </div>
        </div>
      </td>
      <td data-label="Статус"><span class="badge ${badgeClass}">${badgeLabel}</span></td>
      <td data-label="Абонемент" class="mob-hide">${tariffHtml}</td>
      <td data-label="Останній візит" class="mob-hide"><span style="font-size:13px">${c.last_visit ? formatDate(c.last_visit) : '—'}</span></td>
      <td data-label="Баланс" style="font-size:13px">${c.balance != 0 ? formatMoney(c.balance) : '—'}</td>
      <td class="mob-actions" onclick="event.stopPropagation()" style="white-space:nowrap">
        ${editBtn}
      </td>
    </tr>
  `;
}

// ── Пагінація ─────────────────────────────────────────────
function renderPagination({ total, page, pages, per_page }) {
  const el = document.getElementById('pagination');
  if (pages <= 1) { el.innerHTML = ''; return; }

  const from = (page - 1) * per_page + 1;
  const to   = Math.min(page * per_page, total);

  const delta = 2;
  let btns = '';
  for (let i = 1; i <= pages; i++) {
    if (i === 1 || i === pages || Math.abs(i - page) <= delta) {
      btns += `<button class="page-btn ${i === page ? 'active' : ''}"
                onclick="loadClients(${i})">${i}</button>`;
    } else if (Math.abs(i - page) === delta + 1) {
      btns += `<button class="page-btn" disabled>…</button>`;
    }
  }

  el.innerHTML = `
    <span>${from}–${to} з ${total}</span>
    <div class="pagination-btns">
      <button class="page-btn" onclick="loadClients(${page-1})" ${page <= 1 ? 'disabled' : ''}>&#8249;</button>
      ${btns}
      <button class="page-btn" onclick="loadClients(${page+1})" ${page >= pages ? 'disabled' : ''}>&#8250;</button>
    </div>
  `;
}

// ── Пошук з затримкою ─────────────────────────────────────
function onSearchInput() {
  clearTimeout(state.searchTimer);
  state.searchTimer = setTimeout(() => loadClients(1), 350);
}

// ── МОДАЛКА ДОДАТИ/РЕДАГУВАТИ ────────────────────────────
function openAddModal() {
  state.editId = null;
  document.getElementById('modal-client-title').textContent = 'Новий клієнт';
  clearClientForm();
  hideModalError();
  openModal('modal-client');
  document.getElementById('f-name').focus();
}

async function openEditModal(id) {
  event.stopPropagation();
  state.editId = id;
  document.getElementById('modal-client-title').textContent = 'Редагування клієнта';
  clearClientForm();
  hideModalError();
  openModal('modal-client');

  const res = await api('get_one', { id }, 'clients');
  if (!res.success) { showModalError(res.error); return; }

  const c = res.client;
  document.getElementById('f-name').value     = c.full_name || '';
  document.getElementById('f-phone').value    = c.phone     || '';
  document.getElementById('f-email').value    = c.email     || '';
  document.getElementById('f-birthday').value = c.birthday  || '';
  document.getElementById('f-gender').value   = c.gender    || '';
  document.getElementById('f-address').value  = c.address   || '';
  document.getElementById('f-status').value   = c.status    || 'active';
  document.getElementById('f-source').value   = c.source    || '';
  document.getElementById('f-notes').value    = c.notes     || '';
}

async function saveClient() {
  hideModalError();
  const btn = document.getElementById('btn-save-client');
  btn.disabled = true;
  btn.textContent = 'Збереження...';

  const payload = {
    id:        state.editId,
    full_name: document.getElementById('f-name').value.trim(),
    phone:     document.getElementById('f-phone').value.trim(),
    email:     document.getElementById('f-email').value.trim(),
    birthday:  document.getElementById('f-birthday').value,
    gender:    document.getElementById('f-gender').value,
    address:   document.getElementById('f-address').value.trim(),
    status:    document.getElementById('f-status').value,
    source:    document.getElementById('f-source').value,
    notes:     document.getElementById('f-notes').value.trim(),
  };

  const action = state.editId ? 'update' : 'create';
  const res    = await api(action, payload, 'clients');

  btn.disabled = false;
  btn.textContent = 'Зберегти';

  if (!res.success) { showModalError(res.error); return; }

  closeModal('modal-client');
  toast(state.editId ? 'Зміни збережено' : 'Клієнта додано', 'success');
  loadClients(state.page);
}

// ── МОДАЛКА ПЕРЕГЛЯДУ ────────────────────────────────────
async function openViewModal(id) {
  document.getElementById('view-loading').style.display = 'flex';
  document.getElementById('view-content').style.display = 'none';
  openModal('modal-view');

  const res = await api('get_one', { id }, 'clients');
  if (!res.success) {
    closeModal('modal-view');
    toast(res.error, 'error');
    return;
  }

  const { client: c, invoices, visits } = res;

  document.getElementById('view-avatar').textContent = getInitials(c.full_name);
  document.getElementById('view-name').textContent   = c.full_name;

  const statusMap = {
    active:   'badge-active',
    inactive: 'badge-inactive',
    frozen:   'badge-pending',
    banned:   'badge-inactive',
  };
  const statusLabel = {
    active:   'Активний',
    inactive: 'Неактивний',
    frozen:   'Заморожений',
    banned:   'Архів',
  };
  document.getElementById('view-status-badge').innerHTML =
    `<span class="badge ${statusMap[c.status]}">${statusLabel[c.status] || c.status}</span>`;

  // Баланс абонементів = paid - price (може бути від'ємним = борг)
  const totalDebt   = res.total_debt ?? 0;
  const deposit     = parseFloat(c.balance || 0); // депозитний рахунок
  const abonBalance = -totalDebt;                  // від'ємний = борг

  const abonBalStr  = totalDebt > 0.01
    ? `<span style="color:var(--danger)">${formatMoney(abonBalance)}</span>`
    : `<span style="color:var(--success)">0 грн</span>`;
  const depositStr  = deposit !== 0
    ? formatMoney(deposit)
    : '—';

  const fields = [
    ['Телефон',         c.phone    || '—'],
    ['Email',           c.email    || '—'],
    ['Дата народження', c.birthday ? formatDate(c.birthday) : '—'],
    ['Стать',           c.gender === 'M' ? 'Чоловік' : c.gender === 'F' ? 'Жінка' : '—'],
    ['Адреса',          c.address  || '—'],
    ['Джерело',         c.source   || '—'],
    ['Нотатки',         c.notes    || '—'],
    ['Доданий',         formatDate(c.created_at)],
  ];
  document.getElementById('view-fields').innerHTML =
    `<div class="detail-field">
       <span class="detail-label">Баланс абонементів</span>
       <span class="detail-value">${abonBalStr}</span>
     </div>
     <div class="detail-field">
       <span class="detail-label">Депозит</span>
       <span class="detail-value">${escHtml(depositStr)}</span>
     </div>` +
    fields.map(([l, v]) =>
      `<div class="detail-field">
         <span class="detail-label">${l}</span>
         <span class="detail-value">${escHtml(String(v))}</span>
       </div>`
    ).join('');

  const cid = c.id;
  document.getElementById('view-actions').innerHTML = state.canWrite ? `
    <div class="client-actions-grid">
      <button class="btn btn-primary" onclick="openSellForClient(${cid})">📄 Абонемент</button>
      <button class="btn btn-success" onclick="openAddPaymentModal(${cid})">💳 Оплата</button>
      <button class="btn btn-ghost"   onclick="openDepositModal(${cid})">💰 Депозит</button>
      <button class="btn btn-ghost"   onclick="openCheckinModal(${cid})">✅ Відвідування</button>
      <button class="btn btn-ghost"   onclick="closeModal('modal-view');openEditModal(${cid})">✏️ Редагувати</button>
      <button class="btn btn-danger"  onclick="closeModal('modal-view');openDeleteConfirm(${cid})">🗄 Архівувати</button>
    </div>` : '';
  document.getElementById('view-actions').style.display = state.canWrite ? 'block' : 'none';

  document.getElementById('view-invoices-list').innerHTML = invoices.length
    ? invoices.map(inv => `
        <div style="padding:12px 0;border-bottom:1px solid var(--border)">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <div>
              <div style="font-weight:500;font-size:14px">${escHtml(inv.tariff_name)}</div>
              <div style="font-size:12px;color:var(--text-muted);margin-top:2px">
                ${formatDate(inv.start_date)} — ${formatDate(inv.end_date)}
              </div>
            </div>
            <div style="text-align:right">
              <div style="font-size:14px;font-weight:500">${formatMoney(inv.price)}</div>
              <span class="badge ${inv.status === 'active' ? 'badge-active' : 'badge-inactive'}">${inv.status}</span>
            </div>
          </div>
        </div>
      `).join('')
    : '<div class="empty-state"><div class="icon">&#128196;</div><p>Абонементів ще немає</p></div>';

  document.getElementById('view-visits-list').innerHTML = visits.length
    ? visits.map(v => `
        <div style="padding:10px 0;border-bottom:1px solid var(--border);font-size:14px">
          ${formatDate(v.visited_at)}
          ${v.notes ? `<span style="color:var(--text-muted);margin-left:8px;font-size:12px">${escHtml(v.notes)}</span>` : ''}
        </div>
      `).join('')
    : '<div class="empty-state"><div class="icon">&#128197;</div><p>Відвідувань ще немає</p></div>';

  document.getElementById('view-loading').style.display = 'none';
  document.getElementById('view-content').style.display = 'block';
}

function switchViewTab(tab, btn) {
  document.querySelectorAll('.modal-tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.modal-tab-content').forEach(c => c.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById(`view-tab-${tab}`).classList.add('active');
}

// ── ВИДАЛЕННЯ ─────────────────────────────────────────────
function openDeleteConfirm(id) {
  event.stopPropagation();
  state.deleteId = id;
  openModal('modal-confirm');
}

async function confirmDelete() {
  const res = await api('delete', { id: state.deleteId }, 'clients');
  closeModal('modal-confirm');
  if (res.success) {
    toast('Клієнта архівовано', 'success');
    loadClients(state.page);
  } else {
    toast(res.error, 'error');
  }
}

// ── Допоміжні ─────────────────────────────────────────────
function clearClientForm() {
  ['f-name', 'f-phone', 'f-email', 'f-birthday', 'f-address', 'f-notes'].forEach(id => {
    document.getElementById(id).value = '';
  });
  document.getElementById('f-gender').value = '';
  document.getElementById('f-status').value = 'active';
  document.getElementById('f-source').value = '';
}

function showModalError(msg) {
  const el = document.getElementById('modal-error');
  el.textContent = msg;
  el.style.display = 'block';
}

function hideModalError() {
  document.getElementById('modal-error').style.display = 'none';
}

// ── ПРОДАТИ АБОНЕМЕНТ (з карти клієнта) ─────────────────
let _sellClientId   = null;
let _sellTariffs    = [];
let _sellSelectedT  = null;

async function openSellForClient(clientId) {
  _sellClientId  = clientId;
  _sellSelectedT = null;
  document.getElementById('sc-tariff-info').style.display = 'none';

  const res = await api('get_one', { id: clientId }, 'clients');
  if (!res.success) { toast(res.error, 'error'); return; }

  document.getElementById('sc-client-name').textContent =
    res.client.full_name + (res.client.phone ? ' · ' + res.client.phone : '');
  document.getElementById('sc-start-date').value = new Date().toISOString().split('T')[0];
  document.getElementById('sc-discount').value   = '0';
  document.getElementById('sc-notes').value      = '';
  document.getElementById('sc-error').style.display = 'none';

  // Завантажити тарифи у select
  const sel = document.getElementById('sc-tariff-select');
  sel.innerHTML = '<option value="">Завантаження...</option>';
  sel.disabled = true;

  const tr = await api('get_tariffs', {}, 'invoices');
  _sellTariffs = tr.tariffs || [];

  if (!_sellTariffs.length) {
    sel.innerHTML = '<option value="">Тарифів немає</option>';
  } else {
    sel.innerHTML = '<option value="">— Оберіть тариф —</option>' +
      _sellTariffs.map(t =>
        `<option value="${t.id}">${escHtml(t.name)} · ${t.price} грн · ${t.duration_days} дн.` +
        `${t.visits_limit ? ' · ' + t.visits_limit + ' відвід.' : ' · безліміт'}` +
        `</option>`
      ).join('');
    sel.disabled = false;
  }

  openModal('modal-sell-client');
}

function scSelectTariff(id) {
  _sellSelectedT = _sellTariffs.find(t => t.id == id) || null;
  const info = document.getElementById('sc-tariff-info');
  if (_sellSelectedT) {
    const t = _sellSelectedT;
    info.innerHTML =
      `<strong>${escHtml(t.name)}</strong> · <span style="color:var(--accent)">${t.price} грн</span>` +
      ` · ${t.duration_days} дн.` +
      (t.visits_limit ? ` · ${t.visits_limit} відвід.` : ' · безліміт') +
      (t.description ? `<div style="margin-top:4px;font-size:12px">${escHtml(t.description)}</div>` : '');
    info.style.display = 'block';
  } else {
    info.style.display = 'none';
  }
}

async function submitSellClient() {
  const errEl = document.getElementById('sc-error');
  errEl.style.display = 'none';
  if (!_sellSelectedT) { errEl.textContent = 'Оберіть тариф'; errEl.style.display = 'block'; return; }

  const btn = document.getElementById('sc-submit');
  btn.disabled = true; btn.textContent = 'Збереження...';

  const res = await api('create', {
    client_id:    _sellClientId,
    tariff_id:    _sellSelectedT.id,
    start_date:   document.getElementById('sc-start-date').value,
    discount:     parseFloat(document.getElementById('sc-discount').value) || 0,
    paid_amount:  0,
    notes:        document.getElementById('sc-notes').value.trim(),
  }, 'invoices');

  btn.disabled = false; btn.textContent = 'Продати';

  if (res.success) {
    closeModal('modal-sell-client');
    toast('Абонемент продано', 'success');
    openViewModal(_sellClientId); // оновлюємо карту
  } else {
    errEl.textContent = res.error;
    errEl.style.display = 'block';
  }
}

// ── ДОДАТИ ОПЛАТУ ────────────────────────────────────────
let _apClientId = null;

async function openAddPaymentModal(clientId) {
  _apClientId = clientId;
  const res = await api('get_one', { id: clientId }, 'clients');
  if (!res.success) { toast(res.error, 'error'); return; }

  document.getElementById('ap-client-name').textContent = res.client.full_name;
  document.getElementById('ap-amount').value = '';

  // Заповнюємо активні абонементи
  const sel = document.getElementById('ap-invoice');
  const activeInvs = (res.invoices || []).filter(i => i.status === 'active');
  sel.innerHTML = activeInvs.length
    ? activeInvs.map(i => {
        const debt = Math.max(0, i.price - i.paid_amount);
        return `<option value="${i.id}">${escHtml(i.tariff_name)}` +
               (debt > 0 ? ` (борг ${formatMoney(debt)})` : ' (сплачено)') +
               `</option>`;
      }).join('')
    : '<option value="">— Немає активних абонементів —</option>';

  openModal('modal-add-payment');
}

async function submitAddPayment() {
  const invoiceId = parseInt(document.getElementById('ap-invoice').value);
  const amount    = parseFloat(document.getElementById('ap-amount').value);
  if (!invoiceId) { toast('Оберіть абонемент', 'error'); return; }
  if (!amount || amount <= 0) { toast('Введіть суму', 'error'); return; }

  const btn = document.getElementById('ap-submit');
  btn.disabled = true; btn.textContent = 'Збереження...';

  const res = await api('add_payment', {
    invoice_id:     invoiceId,
    amount,
    payment_method: document.getElementById('ap-method').value,
  }, 'invoices');

  btn.disabled = false; btn.textContent = 'Записати';

  if (res.success) {
    closeModal('modal-add-payment');
    toast('Платіж записано', 'success');
    openViewModal(_apClientId);
  } else {
    toast(res.error, 'error');
  }
}

// ── ДЕПОЗИТ ──────────────────────────────────────────────
let _depClientId = null;

async function openDepositModal(clientId) {
  _depClientId = clientId;
  const res = await api('get_one', { id: clientId }, 'clients');
  if (!res.success) { toast(res.error, 'error'); return; }

  document.getElementById('dep-client-name').textContent = res.client.full_name;
  document.getElementById('dep-balance').textContent     = formatMoney(res.client.balance);
  document.getElementById('dep-amount').value = '';
  openModal('modal-deposit');
}

async function submitDeposit() {
  const amount = parseFloat(document.getElementById('dep-amount').value);
  if (!amount || amount <= 0) { toast('Введіть суму', 'error'); return; }

  const btn = document.getElementById('dep-submit');
  btn.disabled = true; btn.textContent = 'Збереження...';

  const res = await api('add_deposit', {
    client_id:      _depClientId,
    amount,
    payment_method: document.getElementById('dep-method').value,
  }, 'finance');

  btn.disabled = false; btn.textContent = 'Поповнити';

  if (res.success) {
    closeModal('modal-deposit');
    toast('Депозит поповнено', 'success');
    openViewModal(_depClientId);
  } else {
    toast(res.error, 'error');
  }
}

// ── ВІДВІДУВАННЯ ─────────────────────────────────────────
let _ciClientId = null;

async function openCheckinModal(clientId) {
  _ciClientId = clientId;
  const res = await api('get_one', { id: clientId }, 'clients');
  if (!res.success) { toast(res.error, 'error'); return; }

  document.getElementById('ci-client-name').textContent = res.client.full_name;
  document.getElementById('ci-notes').value = '';
  openModal('modal-checkin');
}

async function submitCheckin() {
  const btn = document.getElementById('ci-submit');
  btn.disabled = true; btn.textContent = 'Збереження...';

  const res = await api('check_in', {
    client_id: _ciClientId,
    notes:     document.getElementById('ci-notes').value.trim(),
  }, 'visits');

  btn.disabled = false; btn.textContent = 'Відмітити';

  if (res.success) {
    closeModal('modal-checkin');
    toast('Відвідування записано', 'success');
    openViewModal(_ciClientId);
  } else {
    toast(res.error, 'error');
  }
}

function escHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}
</script>

</body>

</html>
