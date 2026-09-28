<?php
$pageTitle = 'Тренери';
$pageCss   = 'trainers';
require __DIR__ . '/../partials/head.php';
?>
<body>
<div class="app-layout">

  <main class="app-main">

    <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
      <h1 class="page-title">Тренери</h1>
      <button class="btn btn-primary" id="btn-add-trainer" style="display:none"
              onclick="openTrainerModal(null)">+ Додати тренера</button>
    </div>

    <!-- Вкладки (для owner/manager) -->
    <div id="tab-bar" style="display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:20px"></div>

    <!-- ══ ВКЛАДКА: СПИСОК ══ -->
    <div class="tr-pane active" id="pane-list">
      <div id="trainers-grid" class="tr-grid">
        <div class="loader" style="padding:60px 0"><div class="spinner"></div> Завантаження...</div>
      </div>
    </div>

    <!-- ══ ВКЛАДКА: ДЕТАЛІ ТРЕНЕРА ══ -->
    <div class="tr-pane" id="pane-detail" style="display:none">

      <!-- Хлібні крихти -->
      <div style="margin-bottom:16px">
        <button class="btn btn-ghost btn-sm" onclick="backToList()">← До списку</button>
      </div>

      <!-- Шапка профілю -->
      <div class="tr-profile-header card" style="margin-bottom:20px">
        <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">
          <div id="tr-avatar" class="tr-avatar">?</div>
          <div style="flex:1;min-width:0">
            <div id="tr-name" style="font-size:18px;font-weight:600"></div>
            <div id="tr-spec" style="color:var(--text-secondary);font-size:13px;margin-top:2px"></div>
            <div id="tr-work-type" style="margin-top:6px"></div>
          </div>
          <div style="display:flex;gap:8px;align-items:center" id="tr-mgr-btns"></div>
        </div>

        <!-- Зведення всередині картки -->
        <div class="tr-summary-cards" id="tr-summary" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border)">
          <div class="loader"><div class="spinner"></div></div>
        </div>
      </div>

      <!-- Вкладки деталей -->
      <div class="tr-detail-tabs">
        <button class="tr-detail-tab active" onclick="switchDetailTab('earnings',this)">💰 Нарахування</button>
        <button class="tr-detail-tab" onclick="switchDetailTab('rent',this)">🏠 Оренда</button>
        <button class="tr-detail-tab" onclick="switchDetailTab('profile',this)">⚙ Налаштування</button>
      </div>

      <!-- Нарахування -->
      <div id="dtab-earnings">
        <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:center">
          <select id="earn-filter-status" onchange="loadEarnings()" style="width:auto">
            <option value="">Всі статуси</option>
            <option value="locked">🔒 Заблоковано</option>
            <option value="available">✅ Доступно</option>
            <option value="partial">⏳ Частково</option>
            <option value="paid">💚 Виплачено</option>
          </select>
        </div>
        <div class="card" style="padding:0;overflow:hidden">
          <div class="table-wrap">
            <table>
              <thead><tr>
                <th>Клієнт / Абонемент</th>
                <th>Тип</th>
                <th>Тригер</th>
                <th>Нараховано</th>
                <th>Доступно</th>
                <th>Виплачено</th>
                <th>Статус</th>
                <th></th>
              </tr></thead>
              <tbody id="earnings-tbody">
                <tr><td colspan="8"><div class="loader"><div class="spinner"></div></div></td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Оренда -->
      <div id="dtab-rent" style="display:none">
        <div style="display:flex;justify-content:flex-end;margin-bottom:12px" id="rent-add-wrap">
          <button class="btn btn-primary btn-sm" onclick="openRentModal()">+ Додати оренду</button>
        </div>
        <div class="card" style="padding:0;overflow:hidden">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Тип</th><th>Сума</th><th>Період</th><th>Статус</th><th>Нотатки</th><th></th></tr></thead>
              <tbody id="rent-tbody">
                <tr><td colspan="6"><div class="loader"><div class="spinner"></div></div></td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Налаштування профілю -->
      <div id="dtab-profile" style="display:none">
        <div class="card" style="max-width:560px">
          <div id="profile-form-wrap"></div>
        </div>
      </div>

    </div>

    <!-- ══ ВКЛАДКА: МОЇ ДАНІ (для тренера) ══ -->
    <div class="tr-pane" id="pane-my" style="display:none">

      <!-- Зведення -->
      <div class="tr-summary-cards" id="my-summary" style="margin-bottom:20px">
        <div class="loader"><div class="spinner"></div></div>
      </div>

      <!-- Вкладки кабінету тренера -->
      <div class="tr-detail-tabs">
        <button class="tr-detail-tab active" onclick="switchMyTab('earnings',this)">💰 Нарахування та виплати</button>
        <button class="tr-detail-tab" onclick="switchMyTab('settings',this)">⚙ Мої налаштування</button>
      </div>

      <!-- Нарахування -->
      <div id="my-tab-earnings">
        <div class="card" style="padding:0;overflow:hidden;margin-top:4px">
          <div class="table-wrap">
            <table>
              <thead><tr>
                <th>Клієнт</th><th>Тип</th><th>Тригер</th>
                <th>Нараховано</th><th>Доступно</th><th>Виплачено</th><th>Статус</th>
              </tr></thead>
              <tbody id="my-earnings-tbody">
                <tr><td colspan="7"><div class="loader"><div class="spinner"></div></div></td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Мої налаштування (read-only) -->
      <div id="my-tab-settings" style="display:none;margin-top:4px">
        <div id="my-settings-wrap" class="loader"><div class="spinner"></div></div>
      </div>

    </div>

  </main>
</div>

<!-- ════ МОДАЛКА: ПРОФІЛЬ ТРЕНЕРА ════ -->
<div class="modal-overlay" id="modal-trainer">
  <div class="modal tr-modal-wide">
    <button class="modal-close" onclick="closeModal('modal-trainer')">✕</button>
    <h2 class="modal-title" id="trainer-modal-title">Профіль тренера</h2>
    <div id="trainer-err" class="alert alert-error" style="display:none"></div>

    <!-- Рядок 1: тренер + спеціалізація + тип роботи -->
    <div class="tr-modal-grid">
      <div class="form-group tr-modal-col-2">
        <label>Тренер *</label>
        <select id="tm-user" style="display:none"></select>
        <div id="tm-user-name" style="font-weight:500;padding:8px 0"></div>
      </div>
      <div class="form-group tr-modal-col-1">
        <label>Спеціалізація</label>
        <input type="text" id="tm-spec" placeholder="Йога, Силові, Кардіо...">
      </div>
      <div class="form-group tr-modal-col-1">
        <label>Тип роботи</label>
        <select id="tm-work-type">
          <option value="employee">Найманий</option>
          <option value="rent">Орендар</option>
          <option value="both">Найм + Оренда</option>
        </select>
      </div>
    </div>

    <!-- Рядок 2: персональні (заголовок) -->
    <div class="tr-modal-group-label">Персональні тренування</div>
    <div class="tr-modal-grid" style="margin-bottom:14px">
      <div class="form-group">
        <label>Тип нарахування</label>
        <select id="tm-pers-type" onchange="updateEarnTypeHint()">
          <option value="percent">% від абонементу</option>
          <option value="fixed">Фіксована сума (грн)</option>
        </select>
      </div>
      <div class="form-group">
        <label id="tm-pers-value-label">Відсоток (%)</label>
        <input type="number" id="tm-pers-value" min="0" step="0.01" value="50">
      </div>
      <div class="form-group">
        <label title="Якщо тренер має більше N активних клієнтів — підвищений % або сума">Поріг клієнтів для підвищення</label>
        <input type="number" id="tm-pers-tier-threshold" min="0" step="1" value="0" placeholder="0 = вимкнено">
      </div>
      <div class="form-group">
        <label id="tm-pers-tier-label">Підвищений % (або сума)</label>
        <input type="number" id="tm-pers-tier-value" min="0" step="0.01" value="0" placeholder="0 = вимкнено">
      </div>
    </div>

    <!-- Рядок 3: групові (другий рядок) -->
    <div class="tr-modal-group-label">Групові тренування</div>
    <div class="tr-modal-grid" style="margin-bottom:20px">
      <div class="form-group">
        <label>Ставка за заняття (грн)</label>
        <input type="number" id="tm-group-rate" min="0" step="0.01" value="0">
      </div>
      <div class="form-group">
        <label>Бонус за учасника (грн)</label>
        <input type="number" id="tm-group-bonus" min="0" step="0.01" value="0">
      </div>
      <div class="form-group">
        <label title="Бонус за учасника нараховується лише якщо учасників більше N">Поріг учасників для бонусу</label>
        <input type="number" id="tm-group-threshold" min="0" step="1" value="0" placeholder="0 = з першого">
      </div>
      <div class="form-group" style="grid-column:span 1"></div>
    </div>
    <div class="tr-modal-group-label">Місячний бонус (групові)</div>
    <div class="tr-modal-grid" style="margin-bottom:20px">
      <div class="form-group">
        <label title="Якщо тренер проводить ≥ N занять на місяць — отримує бонус">Занять на місяць для бонусу</label>
        <input type="number" id="tm-group-monthly-sessions" min="0" step="1" value="0" placeholder="0 = вимкнено">
      </div>
      <div class="form-group">
        <label>Сума місячного бонусу (грн)</label>
        <input type="number" id="tm-group-monthly-amount" min="0" step="0.01" value="0">
      </div>
    </div>

    <div style="display:flex;gap:10px;margin-top:4px">
      <button class="btn btn-primary" onclick="saveTrainer()">Зберегти</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-trainer')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: ВИПЛАТА ════ -->
<div class="modal-overlay" id="modal-pay">
  <div class="modal" style="max-width:360px">
    <button class="modal-close" onclick="closeModal('modal-pay')">✕</button>
    <h2 class="modal-title">Виплата тренеру</h2>
    <div id="pay-err" class="alert alert-error" style="display:none"></div>
    <div id="pay-info" style="background:var(--bg-elevated);padding:10px 14px;border-radius:var(--radius-sm);font-size:13px;margin-bottom:14px"></div>
    <div class="form-group"><label>Сума виплати (грн) *</label>
      <input type="number" id="pay-amount" min="0.01" step="0.01"></div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" onclick="confirmPay()">Виплатити</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-pay')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: ОРЕНДА ════ -->
<div class="modal-overlay" id="modal-rent">
  <div class="modal" style="max-width:400px">
    <button class="modal-close" onclick="closeModal('modal-rent')">✕</button>
    <h2 class="modal-title">Оренда залу</h2>
    <div id="rent-err" class="alert alert-error" style="display:none"></div>
    <div class="form-group"><label>Тип</label>
      <select id="rm-type">
        <option value="manual">Тренер платить клубу</option>
        <option value="deduction">Утримується з заробітку</option>
      </select>
    </div>
    <div class="form-group"><label>Сума (грн) *</label>
      <input type="number" id="rm-amount" min="0.01" step="0.01"></div>
    <div class="settings-fields-2">
      <div class="form-group"><label>Від *</label><input type="date" id="rm-start"></div>
      <div class="form-group"><label>До *</label><input type="date" id="rm-end"></div>
    </div>
    <div class="form-group"><label>Нотатки</label>
      <input type="text" id="rm-notes" placeholder="Необов'язково"></div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" onclick="saveRent()">Зберегти</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-rent')">Скасувати</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../partials/scripts.php'; ?>
<script>
// ── Стан ─────────────────────────────────────────────────────
const T = {
  isManager: false, isTrainer: false,
  currentTrainerId: null, currentTrainerName: '',
  payEarnId: null, payMax: 0,
};

const EARN_TYPE_LABELS = {
  personal_percent: '% персональне',
  personal_fixed:   'Фікс. персональне',
  group_fixed:      'Фікс. групове',
  group_bonus:      'Бонус групового',
};
const TRIGGER_LABELS = {
  on_sale:       'При продажу',
  on_each_visit: 'Після кожного',
  on_visits_done:'Всі заняття',
  on_end_date:   'Кінець дати',
};
const STATUS_BADGE = {
  locked:    '<span class="badge badge-inactive">🔒 Заблок.</span>',
  available: '<span class="badge badge-active">✅ Доступно</span>',
  partial:   '<span class="badge badge-pending">⏳ Частково</span>',
  paid:      '<span class="badge" style="background:rgba(74,222,128,.15);color:#4ade80">💚 Виплачено</span>',
};
const WORK_TYPE_LABELS = {
  employee: '<span class="badge badge-info">Найманий</span>',
  rent:     '<span class="badge badge-pending">Орендар</span>',
  both:     '<span class="badge badge-active">Найм + Оренда</span>',
};

// ── Ініціалізація ─────────────────────────────────────────────
window.addEventListener('DOMContentLoaded', async () => {
  const ctx = await initPage();
  if (!ctx) return;
  guardBlockedClub(ctx.isBlocked);

  const { u, isSuperAdmin, club, clubRole } = ctx;
  const level = isSuperAdmin ? 100 : (clubRole?.level ?? 0);

  T.isManager = level >= 50;
  T.isTrainer = level === 30;

  if (T.isManager) {
    document.getElementById('tab-bar').style.display = 'none';
    document.getElementById('pane-list').style.display = 'block';
    document.getElementById('btn-add-trainer').style.display = 'inline-flex';
    loadTrainersList();
  } else if (T.isTrainer) {
    buildTrainerTabs();
    loadMyData();
  } else {
    document.getElementById('trainers-grid').innerHTML =
      '<div style="color:var(--text-muted);padding:40px 0;text-align:center">Доступ заборонено</div>';
  }
});

// ── Вкладки ───────────────────────────────────────────────────
function buildManagerTabs() {
  const bar = document.getElementById('tab-bar');
  bar.innerHTML = `
    <button class="fin-tab-btn active" onclick="showPane('list',this)">👥 Список</button>`;
  document.getElementById('pane-list').style.display = 'block';
}

function buildTrainerTabs() {
  document.getElementById('tab-bar').style.display = 'none';
  document.getElementById('pane-list').style.display = 'none';
  document.getElementById('pane-my').style.display   = 'block';
}

function showPane(id, btn) {
  document.querySelectorAll('.fin-tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tr-pane').forEach(p => p.style.display = 'none');
  btn.classList.add('active');
  document.getElementById('pane-' + id).style.display = 'block';
  if (id === 'list') loadTrainersList();
}

// ── Список тренерів ───────────────────────────────────────────
async function loadTrainersList() {
  const grid = document.getElementById('trainers-grid');
  grid.innerHTML = '<div class="loader" style="padding:60px 0"><div class="spinner"></div></div>';
  const res = await api('get_list', {}, 'trainers');
  if (!res.success) { grid.innerHTML = `<div style="color:var(--danger)">${res.error}</div>`; return; }
  if (!res.trainers.length) {
    grid.innerHTML = '<div style="color:var(--text-muted);padding:40px 0;text-align:center">Тренерів ще немає. Додайте першого!</div>';
    return;
  }
  grid.innerHTML = res.trainers.map(t => trainerCard(t)).join('');
}

function trainerCard(t) {
  const avail  = (parseFloat(t.total_available) - parseFloat(t.total_paid)).toFixed(2);
  const initials = (t.full_name||'?').split(' ').slice(0,2).map(w=>w[0]?.toUpperCase()).join('');
  return `
    <div class="tr-card ${t.is_active?'':'tr-card-inactive'}" onclick="openTrainerDetail(${t.id},'${esc(t.full_name)}')">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
        <div class="tr-avatar tr-avatar-sm">${initials}</div>
        <div style="flex:1;min-width:0">
          <div style="font-weight:600;font-size:15px">${esc(t.full_name)}</div>
          <div style="font-size:12px;color:var(--text-muted)">${esc(t.specialization||'—')}</div>
        </div>
        ${WORK_TYPE_LABELS[t.work_type]||''}
      </div>
      <div class="tr-card-stats">
        <div><div class="tr-stat-label">Нараховано</div><div class="tr-stat-value">${formatMoney(t.total_earned)}</div></div>
        <div><div class="tr-stat-label">Доступно</div><div class="tr-stat-value ${avail>0?'positive':''}">${formatMoney(avail)}</div></div>
        <div><div class="tr-stat-label">Виплачено</div><div class="tr-stat-value">${formatMoney(t.total_paid)}</div></div>
      </div>
      ${parseFloat(t.rent_pending)>0
        ? `<div style="margin-top:8px;font-size:12px;color:var(--warning)">🏠 Оренда до виплати: ${formatMoney(t.rent_pending)}</div>`
        : ''}
    </div>`;
}

// ── Деталі тренера ────────────────────────────────────────────
async function openTrainerDetail(trainerId, name) {
  T.currentTrainerId  = trainerId;
  T.currentTrainerName = name;

  document.querySelectorAll('.tr-pane').forEach(p => p.style.display = 'none');
  document.getElementById('pane-detail').style.display = 'block';
  document.querySelectorAll('#tab-bar .fin-tab-btn').forEach(b => b.classList.remove('active'));

  // Шапка
  const initials = name.split(' ').slice(0,2).map(w=>w[0]?.toUpperCase()).join('');
  document.getElementById('tr-avatar').textContent = initials;
  document.getElementById('tr-name').textContent   = name;

  // Завантажуємо профіль і зведення паралельно
  const [trRes, sumRes] = await Promise.all([
    api('get_one', { trainer_id: trainerId }, 'trainers'),
    api('get_summary', { trainer_id: trainerId }, 'trainers'),
  ]);

  if (trRes.success) renderTrainerHeader(trRes.trainer);
  if (sumRes.success) renderSummary('tr-summary', sumRes.summary);

  // Кнопки менеджера
  if (T.isManager) renderMgrBtns(trainerId, trRes.trainer?.is_active ?? 1);

  loadEarnings();
}

function renderMgrBtns(trainerId, isActive) {
  const btns = document.getElementById('tr-mgr-btns');
  const toggleBtn = isActive
    ? `<button class="btn btn-ghost btn-sm tr-btn-pause" title="Призупинити"
              onclick="toggleTrainer(${trainerId}, 0)">⏸ Призупинити</button>`
    : `<button class="btn btn-sm tr-btn-resume" title="Активувати"
              onclick="toggleTrainer(${trainerId}, 1)">▶ Активувати</button>`;
  btns.innerHTML = `
    <button class="btn btn-ghost btn-sm" onclick="openTrainerModal(${trainerId})">✎ Редагувати</button>
    ${toggleBtn}`;
}

function renderTrainerHeader(t) {
  document.getElementById('tr-spec').textContent    = t.specialization || '—';
  document.getElementById('tr-work-type').innerHTML = WORK_TYPE_LABELS[t.work_type] || '';
  renderProfileForm(t);
}

function renderSummary(elId, s) {
  const toPayOut = parseFloat(s.to_pay_out||0);
  document.getElementById(elId).innerHTML = `
    <div class="tr-sum-card">
      <div class="tr-sum-label">Всього нараховано</div>
      <div class="tr-sum-value">${formatMoney(s.total_earned)}</div>
    </div>
    <div class="tr-sum-card highlight">
      <div class="tr-sum-label">До виплати</div>
      <div class="tr-sum-value positive">${formatMoney(toPayOut)}</div>
    </div>
    <div class="tr-sum-card">
      <div class="tr-sum-label">Виплачено</div>
      <div class="tr-sum-value">${formatMoney(s.total_paid)}</div>
    </div>
    <div class="tr-sum-card">
      <div class="tr-sum-label">🔒 Заблоковано</div>
      <div class="tr-sum-value" style="font-size:14px">${s.cnt_locked||0} нарахувань</div>
    </div>
    ${parseFloat(s.rent_pending||0)>0?`
    <div class="tr-sum-card" style="border-color:var(--warning)">
      <div class="tr-sum-label">🏠 Оренда (борг)</div>
      <div class="tr-sum-value" style="color:var(--warning)">${formatMoney(s.rent_pending)}</div>
    </div>`:''}`;
}

function switchDetailTab(id, btn) {
  document.querySelectorAll('.tr-detail-tab').forEach(b => b.classList.remove('active'));
  ['earnings','rent','profile'].forEach(t => {
    document.getElementById('dtab-'+t).style.display = t===id?'block':'none';
  });
  btn.classList.add('active');
  if (id === 'rent') loadRent();
}

function backToList() {
  document.querySelectorAll('.tr-pane').forEach(p => p.style.display = 'none');
  document.getElementById('pane-list').style.display = 'block';
  document.querySelectorAll('#tab-bar .fin-tab-btn').forEach((b,i) => {
    if (i===0) b.classList.add('active');
  });
  loadTrainersList();
}

// ── Нарахування ───────────────────────────────────────────────
async function loadEarnings() {
  const tbody  = document.getElementById('earnings-tbody');
  const status = document.getElementById('earn-filter-status')?.value || '';
  tbody.innerHTML = '<tr><td colspan="8"><div class="loader"><div class="spinner"></div></div></td></tr>';

  const res = await api('get_earnings', { trainer_id: T.currentTrainerId, status }, 'trainers');
  if (!res.success) { tbody.innerHTML = `<tr><td colspan="8">${res.error}</td></tr>`; return; }
  if (!res.earnings.length) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--text-muted)">Нарахувань немає</td></tr>';
    return;
  }

  tbody.innerHTML = res.earnings.map(e => {
    const canPay = parseFloat(e.available_amount) - parseFloat(e.paid_amount);
    const payBtn = T.isManager && canPay > 0 && e.status !== 'locked'
      ? `<button class="btn btn-ghost btn-sm" onclick="openPayModal(${e.id},${canPay},'${esc(e.client_name||'')}')"
               style="color:var(--success)">Виплатити</button>`
      : '';
    return `<tr>
      <td>
        <div style="font-weight:500">${esc(e.client_name||'—')}</div>
        ${e.end_date?`<div style="font-size:11px;color:var(--text-muted)">до ${formatDate(e.end_date)}
          ${e.visits_total?` · ${e.visits_used}/${e.visits_total} занять`:''}</div>`:''}
      </td>
      <td>${EARN_TYPE_LABELS[e.earn_type]||e.earn_type}</td>
      <td style="font-size:12px;color:var(--text-secondary)">${TRIGGER_LABELS[e.release_trigger]||e.release_trigger}</td>
      <td><strong>${formatMoney(e.amount)}</strong></td>
      <td class="${parseFloat(e.available_amount)>0?'positive':''}">${formatMoney(e.available_amount)}</td>
      <td>${formatMoney(e.paid_amount)}</td>
      <td>${STATUS_BADGE[e.status]||e.status}</td>
      <td>${payBtn}</td>
    </tr>`;
  }).join('');
}

function openPayModal(earnId, max, clientName) {
  T.payEarnId = earnId;
  T.payMax    = max;
  document.getElementById('pay-err').style.display = 'none';
  document.getElementById('pay-info').innerHTML =
    `<div>Клієнт: <strong>${esc(clientName)}</strong></div>
     <div>Доступно до виплати: <strong style="color:var(--success)">${formatMoney(max)}</strong></div>`;
  document.getElementById('pay-amount').value = max;
  document.getElementById('pay-amount').max   = max;
  openModal('modal-pay');
}

async function confirmPay() {
  const errEl  = document.getElementById('pay-err');
  errEl.style.display = 'none';
  const amount = parseFloat(document.getElementById('pay-amount').value);
  if (!amount || amount <= 0) { errEl.textContent='Введіть суму'; errEl.style.display='block'; return; }
  if (amount > T.payMax)      { errEl.textContent=`Максимум ${T.payMax} грн`; errEl.style.display='block'; return; }

  const res = await api('pay_earning', { earning_id: T.payEarnId, amount }, 'trainers');
  if (res.success) {
    closeModal('modal-pay');
    toast('Виплату зафіксовано', 'success');
    loadEarnings();
    reloadSummary();
  } else { errEl.textContent = res.error; errEl.style.display='block'; }
}

// ── Оренда ────────────────────────────────────────────────────
async function loadRent() {
  const tbody = document.getElementById('rent-tbody');
  tbody.innerHTML = '<tr><td colspan="6"><div class="loader"><div class="spinner"></div></div></td></tr>';
  if (!T.isManager) document.getElementById('rent-add-wrap').style.display = 'none';

  const res = await api('get_rent', { trainer_id: T.currentTrainerId }, 'trainers');
  if (!res.success) { tbody.innerHTML = `<tr><td colspan="6">${res.error}</td></tr>`; return; }
  if (!res.rent.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--text-muted)">Записів немає</td></tr>';
    return;
  }

  const typeLabel = { manual:'Тренер платить', deduction:'З заробітку' };
  tbody.innerHTML = res.rent.map(r => `
    <tr>
      <td>${typeLabel[r.rent_type]||r.rent_type}</td>
      <td><strong>${formatMoney(r.amount)}</strong></td>
      <td style="font-size:13px">${formatDate(r.period_start)} — ${formatDate(r.period_end)}</td>
      <td><span class="badge ${r.status==='paid'?'badge-active':'badge-pending'}">${r.status==='paid'?'Оплачено':'Очікує'}</span></td>
      <td style="color:var(--text-secondary);font-size:13px">${esc(r.notes||'—')}</td>
      <td>${T.isManager?`<button class="btn btn-ghost btn-sm" style="color:var(--danger)"
               onclick="deleteRent(${r.id})">🗑</button>`:''}
      </td>
    </tr>`).join('');
}

function openRentModal() {
  document.getElementById('rent-err').style.display = 'none';
  ['rm-amount','rm-notes'].forEach(id => document.getElementById(id).value='');
  const today = new Date().toISOString().slice(0,10);
  document.getElementById('rm-start').value = today;
  document.getElementById('rm-end').value   = today;
  openModal('modal-rent');
}

async function saveRent() {
  const errEl = document.getElementById('rent-err');
  errEl.style.display = 'none';
  const res = await api('save_rent', {
    trainer_id:   T.currentTrainerId,
    rent_type:    document.getElementById('rm-type').value,
    amount:       parseFloat(document.getElementById('rm-amount').value)||0,
    period_start: document.getElementById('rm-start').value,
    period_end:   document.getElementById('rm-end').value,
    notes:        document.getElementById('rm-notes').value.trim(),
  }, 'trainers');
  if (res.success) { closeModal('modal-rent'); toast('Оренду додано','success'); loadRent(); reloadSummary(); }
  else { errEl.textContent=res.error; errEl.style.display='block'; }
}

async function deleteRent(rentId) {
  if (!confirm('Видалити запис оренди?')) return;
  const res = await api('delete_rent', { rent_id: rentId }, 'trainers');
  if (res.success) { toast('Видалено','success'); loadRent(); reloadSummary(); }
  else toast(res.error,'error');
}

// ── Профіль (форма в деталях) ─────────────────────────────────
function renderProfileForm(t) {
  if (!T.isManager) { document.getElementById('profile-form-wrap').innerHTML=''; return; }
  document.getElementById('profile-form-wrap').innerHTML = `
    <div style="font-weight:600;margin-bottom:14px">Ставки нарахувань</div>
    <div class="form-group"><label>Спеціалізація</label>
      <input type="text" id="pf-spec" value="${esc(t.specialization||'')}"></div>
    <div class="form-group"><label>Тип роботи</label>
      <select id="pf-work">
        <option value="employee" ${t.work_type==='employee'?'selected':''}>Найманий</option>
        <option value="rent"     ${t.work_type==='rent'    ?'selected':''}>Орендар</option>
        <option value="both"     ${t.work_type==='both'    ?'selected':''}>Обидва</option>
      </select>
    </div>
    <div style="background:var(--bg-elevated);border-radius:var(--radius-sm);padding:12px;margin-bottom:12px">
      <div style="font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:8px">Персональні</div>
      <div class="settings-fields-2">
        <div class="form-group" style="margin-bottom:8px"><label>Тип</label>
          <select id="pf-pers-type">
            <option value="percent" ${t.personal_earn_type==='percent'?'selected':''}>%</option>
            <option value="fixed"   ${t.personal_earn_type==='fixed'  ?'selected':''}>Фіксована</option>
          </select></div>
        <div class="form-group" style="margin-bottom:8px"><label>Значення</label>
          <input type="number" id="pf-pers-val" value="${t.personal_earn_value||50}" min="0" step="0.01"></div>
        <div class="form-group" style="margin-bottom:0"><label title="0 = вимкнено">Поріг клієнтів (тир)</label>
          <input type="number" id="pf-pers-tier-thr" value="${t.personal_tier_threshold||0}" min="0" step="1"></div>
        <div class="form-group" style="margin-bottom:0"><label>Підвищений % / сума</label>
          <input type="number" id="pf-pers-tier-val" value="${t.personal_tier_value||0}" min="0" step="0.01"></div>
      </div>
    </div>
    <div style="background:var(--bg-elevated);border-radius:var(--radius-sm);padding:12px;margin-bottom:16px">
      <div style="font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:8px">Групові</div>
      <div class="settings-fields-2">
        <div class="form-group" style="margin-bottom:8px"><label>Ставка за заняття</label>
          <input type="number" id="pf-group-rate" value="${t.group_earn_rate||0}" min="0" step="0.01"></div>
        <div class="form-group" style="margin-bottom:8px"><label>Бонус за учасника</label>
          <input type="number" id="pf-group-bonus" value="${t.group_earn_bonus_per_client||0}" min="0" step="0.01"></div>
        <div class="form-group" style="margin-bottom:8px"><label title="0 = з першого">Поріг учасників</label>
          <input type="number" id="pf-group-threshold" value="${t.group_bonus_threshold||0}" min="0" step="1"></div>
        <div class="form-group" style="margin-bottom:8px"></div>
        <div class="form-group" style="margin-bottom:0"><label title="0 = вимкнено">Занять/місяць для бонусу</label>
          <input type="number" id="pf-group-monthly-sessions" value="${t.group_monthly_bonus_sessions||0}" min="0" step="1"></div>
        <div class="form-group" style="margin-bottom:0"><label>Місячний бонус (грн)</label>
          <input type="number" id="pf-group-monthly-amount" value="${t.group_monthly_bonus_amount||0}" min="0" step="0.01"></div>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:12px">
      <button class="btn btn-primary" onclick="saveProfileInline(${t.id})">Зберегти</button>
      <span class="save-indicator" id="pf-saved">✓ Збережено</span>
    </div>`;
}

async function saveProfileInline(trainerId) {
  const res = await api('save', {
    trainer_id:                      trainerId,
    specialization:                  document.getElementById('pf-spec').value.trim(),
    work_type:                       document.getElementById('pf-work').value,
    personal_earn_type:              document.getElementById('pf-pers-type').value,
    personal_earn_value:             parseFloat(document.getElementById('pf-pers-val').value)||0,
    personal_tier_threshold:         parseInt(document.getElementById('pf-pers-tier-thr').value)||0,
    personal_tier_value:             parseFloat(document.getElementById('pf-pers-tier-val').value)||0,
    group_earn_rate:                 parseFloat(document.getElementById('pf-group-rate').value)||0,
    group_earn_bonus_per_client:     parseFloat(document.getElementById('pf-group-bonus').value)||0,
    group_bonus_threshold:           parseInt(document.getElementById('pf-group-threshold').value)||0,
    group_monthly_bonus_sessions:    parseInt(document.getElementById('pf-group-monthly-sessions').value)||0,
    group_monthly_bonus_amount:      parseFloat(document.getElementById('pf-group-monthly-amount').value)||0,
  }, 'trainers');
  if (res.success) {
    document.getElementById('pf-saved').classList.add('show');
    setTimeout(()=>document.getElementById('pf-saved').classList.remove('show'),3000);
    loadTrainersList();
  } else toast(res.error,'error');
}

// ── Модалка тренера (create/edit) ────────────────────────────
let _trainerUsers = [];
async function openTrainerModal(trainerId) {
  document.getElementById('trainer-err').style.display = 'none';
  document.getElementById('trainer-modal-title').textContent =
    trainerId ? 'Редагувати тренера' : 'Додати тренера';

  const sel     = document.getElementById('tm-user');
  const nameDiv = document.getElementById('tm-user-name');

  if (!trainerId) {
    // Завжди оновлюємо список (скидаємо кеш)
    _trainerUsers = [];
    const r = await api('get_trainer_users', {}, 'trainers');
    if (r.success) _trainerUsers = r.users;

    const available = _trainerUsers.filter(u => !u.trainer_profile_id);
    if (!available.length) {
      document.getElementById('trainer-err').textContent =
        'Немає тренерів без профілю. Спочатку запросіть тренера в розділі "Команда".';
      document.getElementById('trainer-err').style.display = 'block';
      sel.style.display = 'none';
      nameDiv.textContent = '';
      openModal('modal-trainer');
      return;
    }
    sel.innerHTML = available.map(u =>
      `<option value="${u.id}">${esc(u.full_name)} (${esc(u.email)})</option>`).join('');
    sel.style.display = 'block';
    nameDiv.textContent = '';
    document.getElementById('tm-spec').value = '';
    document.getElementById('tm-work-type').value = 'employee';
    document.getElementById('tm-pers-type').value = 'percent';
    document.getElementById('tm-pers-value').value = '50';
    document.getElementById('tm-pers-tier-threshold').value = '0';
    document.getElementById('tm-pers-tier-value').value = '0';
    document.getElementById('tm-group-rate').value = '0';
    document.getElementById('tm-group-bonus').value = '0';
    document.getElementById('tm-group-threshold').value = '0';
    document.getElementById('tm-group-monthly-sessions').value = '0';
    document.getElementById('tm-group-monthly-amount').value = '0';
  } else {
    // Режим редагування — завантажуємо поточні дані
    sel.style.display = 'none';
    const r = await api('get_one', { trainer_id: trainerId }, 'trainers');
    if (r.success) {
      const t = r.trainer;
      nameDiv.textContent = t.full_name || '';
      document.getElementById('tm-spec').value = t.specialization || '';
      document.getElementById('tm-work-type').value = t.work_type || 'employee';
      document.getElementById('tm-pers-type').value = t.personal_earn_type || 'percent';
      document.getElementById('tm-pers-value').value = t.personal_earn_value ?? 50;
      document.getElementById('tm-pers-tier-threshold').value = t.personal_tier_threshold ?? 0;
      document.getElementById('tm-pers-tier-value').value = t.personal_tier_value ?? 0;
      document.getElementById('tm-group-rate').value = t.group_earn_rate ?? 0;
      document.getElementById('tm-group-bonus').value = t.group_earn_bonus_per_client ?? 0;
      document.getElementById('tm-group-threshold').value = t.group_bonus_threshold ?? 0;
      document.getElementById('tm-group-monthly-sessions').value = t.group_monthly_bonus_sessions ?? 0;
      document.getElementById('tm-group-monthly-amount').value = t.group_monthly_bonus_amount ?? 0;
      // Зберігаємо trainer_id для збереження
      sel.dataset.editId = trainerId;
    }
  }
  updateEarnTypeHint();
  openModal('modal-trainer');
}

function updateEarnTypeHint() {
  const type = document.getElementById('tm-pers-type')?.value;
  const isPercent = type === 'percent';
  const lbl  = document.getElementById('tm-pers-value-label');
  const tlbl = document.getElementById('tm-pers-tier-label');
  if (lbl)  lbl.textContent  = isPercent ? 'Відсоток (%)' : 'Сума (грн)';
  if (tlbl) tlbl.textContent = isPercent ? 'Підвищений %' : 'Підвищена сума (грн)';
}

async function saveTrainer() {
  const errEl = document.getElementById('trainer-err');
  errEl.style.display = 'none';
  const sel      = document.getElementById('tm-user');
  const editId   = parseInt(sel.dataset.editId) || 0;
  const payload  = {
    specialization:                  document.getElementById('tm-spec').value.trim(),
    work_type:                       document.getElementById('tm-work-type').value,
    personal_earn_type:              document.getElementById('tm-pers-type').value,
    personal_earn_value:             parseFloat(document.getElementById('tm-pers-value').value)||0,
    personal_tier_threshold:         parseInt(document.getElementById('tm-pers-tier-threshold').value)||0,
    personal_tier_value:             parseFloat(document.getElementById('tm-pers-tier-value').value)||0,
    group_earn_rate:                 parseFloat(document.getElementById('tm-group-rate').value)||0,
    group_earn_bonus_per_client:     parseFloat(document.getElementById('tm-group-bonus').value)||0,
    group_bonus_threshold:           parseInt(document.getElementById('tm-group-threshold').value)||0,
    group_monthly_bonus_sessions:    parseInt(document.getElementById('tm-group-monthly-sessions').value)||0,
    group_monthly_bonus_amount:      parseFloat(document.getElementById('tm-group-monthly-amount').value)||0,
  };
  if (editId) {
    payload.trainer_id = editId;
  } else {
    payload.user_id = parseInt(sel.value) || 0;
    if (!payload.user_id) { errEl.textContent='Оберіть тренера'; errEl.style.display='block'; return; }
  }
  const res = await api('save', payload, 'trainers');
  if (res.success) {
    closeModal('modal-trainer');
    sel.dataset.editId = '';
    toast('Збережено', 'success');
    _trainerUsers = [];
    loadTrainersList();
  } else { errEl.textContent = res.error; errEl.style.display = 'block'; }
}

async function toggleTrainer(trainerId, newState) {
  const res = await api('toggle', { trainer_id: trainerId }, 'trainers');
  if (res.success) {
    toast(res.message, 'success');
    renderMgrBtns(trainerId, res.is_active);
    // Оновлюємо аватар opacity
    const avatar = document.getElementById('tr-avatar');
    if (avatar) avatar.style.opacity = res.is_active ? '1' : '0.45';
  } else toast(res.error, 'error');
}

// ── Мої дані (тренер) ─────────────────────────────────────────
async function loadMyData() {
  const [sumRes, earRes, profRes] = await Promise.all([
    api('my_summary',  {}, 'trainers'),
    api('my_earnings', {}, 'trainers'),
    api('my_profile',  {}, 'trainers'),
  ]);
  if (sumRes.success)  renderSummary('my-summary', sumRes.summary);
  if (earRes.success)  renderMyEarnings(earRes.earnings);
  if (profRes.success) renderMySettings(profRes.trainer);
}

function switchMyTab(id, btn) {
  document.querySelectorAll('.tr-detail-tab').forEach(b => b.classList.remove('active'));
  document.getElementById('my-tab-earnings').style.display = 'none';
  document.getElementById('my-tab-settings').style.display = 'none';
  btn.classList.add('active');
  document.getElementById('my-tab-' + id).style.display = 'block';
}

function renderMySettings(t) {
  const wrap = document.getElementById('my-settings-wrap');
  if (!t) { wrap.innerHTML = '<div style="color:var(--text-muted)">Налаштування недоступні</div>'; return; }

  const persType  = t.personal_earn_type === 'percent' ? '%' : 'грн (фікс.)';
  const tierHtml  = t.personal_tier_threshold > 0
    ? `<div class="my-set-row">
        <span class="my-set-label">Тир (від ${t.personal_tier_threshold} клієнтів)</span>
        <span class="my-set-val">${t.personal_tier_value} ${t.personal_earn_type === 'percent' ? '%' : 'грн'}</span>
       </div>` : '';

  const groupBonusHtml = t.group_earn_bonus_per_client > 0
    ? `<div class="my-set-row">
        <span class="my-set-label">Бонус за учасника</span>
        <span class="my-set-val">${t.group_earn_bonus_per_client} грн
          ${t.group_bonus_threshold > 0 ? `(від ${t.group_bonus_threshold} осіб)` : '(з першого)'}
        </span>
       </div>` : '';

  const monthlyHtml = t.group_monthly_bonus_sessions > 0
    ? `<div class="my-set-row">
        <span class="my-set-label">Місячний бонус</span>
        <span class="my-set-val">${t.group_monthly_bonus_amount} грн
          (якщо ≥ ${t.group_monthly_bonus_sessions} занять/міс)
        </span>
       </div>` : '';

  wrap.innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px">

      <div class="card">
        <div class="card-title" style="margin-bottom:14px">👤 Загальне</div>
        <div class="my-set-row">
          <span class="my-set-label">Спеціалізація</span>
          <span class="my-set-val">${esc(t.specialization||'—')}</span>
        </div>
        <div class="my-set-row">
          <span class="my-set-label">Тип роботи</span>
          <span class="my-set-val">${WORK_TYPE_LABELS[t.work_type]||'—'}</span>
        </div>
      </div>

      <div class="card">
        <div class="card-title" style="margin-bottom:14px">🏋 Персональні тренування</div>
        <div class="my-set-row">
          <span class="my-set-label">Тип нарахування</span>
          <span class="my-set-val">${persType}</span>
        </div>
        <div class="my-set-row">
          <span class="my-set-label">${t.personal_earn_type === 'percent' ? 'Відсоток' : 'Сума за заняття'}</span>
          <span class="my-set-val accent">${t.personal_earn_value} ${t.personal_earn_type === 'percent' ? '%' : 'грн'}</span>
        </div>
        ${tierHtml}
      </div>

      <div class="card">
        <div class="card-title" style="margin-bottom:14px">👥 Групові тренування</div>
        <div class="my-set-row">
          <span class="my-set-label">Ставка за заняття</span>
          <span class="my-set-val accent">${t.group_earn_rate} грн</span>
        </div>
        ${groupBonusHtml}
        ${monthlyHtml}
        ${!groupBonusHtml && !monthlyHtml && !t.group_earn_rate
          ? '<div style="color:var(--text-muted);font-size:13px">Групові нарахування не налаштовано</div>' : ''}
      </div>

    </div>
    <div style="margin-top:12px;font-size:12px;color:var(--text-muted)">
      ℹ Налаштування встановлюються клубом. Для зміни зверніться до менеджера.
    </div>`;
}

function renderMyEarnings(earnings) {
  const tbody = document.getElementById('my-earnings-tbody');
  if (!earnings.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--text-muted)">Нарахувань немає</td></tr>';
    return;
  }
  tbody.innerHTML = earnings.map(e => `
    <tr>
      <td>${esc(e.client_name||'—')}</td>
      <td>${EARN_TYPE_LABELS[e.earn_type]||e.earn_type}</td>
      <td style="font-size:12px;color:var(--text-secondary)">${TRIGGER_LABELS[e.release_trigger]||''}</td>
      <td><strong>${formatMoney(e.amount)}</strong></td>
      <td class="${parseFloat(e.available_amount)>0?'positive':''}">${formatMoney(e.available_amount)}</td>
      <td>${formatMoney(e.paid_amount)}</td>
      <td>${STATUS_BADGE[e.status]||e.status}</td>
    </tr>`).join('');
}

// ── Допоміжні ─────────────────────────────────────────────────
async function reloadSummary() {
  const res = await api('get_summary', { trainer_id: T.currentTrainerId }, 'trainers');
  if (res.success) renderSummary('tr-summary', res.summary);
}

function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
</script>
</body>
