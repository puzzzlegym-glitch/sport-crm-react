<?php
$pageTitle = 'Тарифи';
$pageCss   = 'tariffs';
require __DIR__ . '/../partials/head.php';
?>
<body>
<div class="app-layout">

  <main class="app-main">

    <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
      <div>
        <h1 class="page-title">Тарифи</h1>
        <p class="page-subtitle" id="page-subtitle">Завантаження...</p>
      </div>
      <div style="display:flex;gap:10px;align-items:center">
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
          <input type="checkbox" id="show-archived" onchange="loadTariffs()"> Показати архівні
        </label>
        <button class="btn btn-primary" id="btn-add" onclick="editTariff(null)" style="display:none">
          + Додати тариф
        </button>
      </div>
    </div>

    <!-- Статистика -->
    <div class="tariff-stats">
      <div class="tariff-stat">
        <div class="ts-label">Активних тарифів</div>
        <div class="ts-value" id="st-active"><span class="spinner"></span></div>
      </div>
      <div class="tariff-stat green">
        <div class="ts-label">Продано абонементів</div>
        <div class="ts-value" id="st-total">—</div>
        <div class="ts-sub">за весь час</div>
      </div>
      <div class="tariff-stat blue">
        <div class="ts-label">Активних абонементів</div>
        <div class="ts-value" id="st-invoices-active">—</div>
      </div>
    </div>

    <div id="tariffs-list">
      <div class="loader"><div class="spinner"></div> Завантаження...</div>
    </div>

  </main>
</div>

<!-- МОДАЛКА -->
<div class="modal-overlay" id="modal-tariff">
  <div class="modal" style="max-width:560px">
    <button class="modal-close" onclick="closeModal('modal-tariff')">&#10005;</button>
    <h2 class="modal-title" id="modal-title">Новий тариф</h2>
    <div id="tariff-error" class="alert alert-error" style="display:none"></div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px">
      <div class="form-group" style="grid-column:1/-1">
        <label>Назва *</label>
        <input type="text" id="f-name" placeholder="Наприклад: Безліміт 30 днів">
      </div>
      <div class="form-group">
        <label>Ціна (грн) *</label>
        <input type="number" id="f-price" min="0" step="1" placeholder="0">
      </div>
      <div class="form-group">
        <label>Тривалість (днів) *</label>
        <input type="number" id="f-duration" min="1" step="1" value="30">
      </div>
      <div class="form-group">
        <label>Ліміт відвідувань</label>
        <input type="number" id="f-visits" min="1" step="1" placeholder="порожньо = безліміт">
      </div>
      <div class="form-group">
        <label>Категорія</label>
        <input type="text" id="f-category" placeholder="Груп., Персон., Онлайн...">
      </div>
      <div class="form-group">
        <label>Колір бейджа</label>
        <input type="color" id="f-color" value="#4f9cf9" style="height:38px;padding:3px 6px">
      </div>
      <div class="form-group">
        <label>Макс. днів заморозки</label>
        <input type="number" id="f-freeze" min="0" step="1" value="0">
      </div>
      <div class="form-group">
        <label>Вартість продовження/день</label>
        <input type="number" id="f-prolong" min="0" step="0.01" value="0">
      </div>
      <div class="form-group">
        <label>Нарахування тренеру (грн)</label>
        <input type="number" id="f-trainer-sum" min="0" step="0.01" value="0">
      </div>
      <div class="form-group">
        <label>Тип нарахування</label>
        <select id="f-narah-type">
          <option value="">— Без нарахування —</option>
          <option value="fixed">Фіксована сума</option>
          <option value="percent">Відсоток</option>
        </select>
      </div>
      <div class="form-group">
        <label>Порядок сортування</label>
        <input type="number" id="f-sort" min="0" step="1" value="0">
      </div>
      <div class="form-group" style="grid-column:1/-1">
        <label>Опис</label>
        <textarea id="f-description" rows="2" placeholder="Необов'язково..."></textarea>
      </div>
    </div>

    <div style="display:flex;gap:10px;margin-top:8px">
      <button class="btn btn-primary" id="btn-save" onclick="saveTariff()">Зберегти</button>
      <button class="btn btn-ghost"   onclick="closeModal('modal-tariff')">Скасувати</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../partials/scripts.php'; ?>
<script>
let state = { tariffs: [], editId: null, isOwner: false };

window.addEventListener('DOMContentLoaded', async () => {
  const ctx = await initPage({ title: 'Тарифи' });
  if (!ctx) return;
  guardBlockedClub(ctx.isBlocked);
  const lvl = ctx.isSuperAdmin ? 100 : (ctx.clubRole?.level ?? 0);
  state.isOwner = lvl >= 80;
  if (state.isOwner) document.getElementById('btn-add').style.display = 'inline-flex';
  loadTariffs();
});

async function loadTariffs() {
  const archived = document.getElementById('show-archived').checked ? 1 : 0;
  const res = await api('get_list', { archived }, 'tariffs');
  if (!res.success) {
    document.getElementById('tariffs-list').innerHTML =
      `<div class="alert alert-error">${esc(res.error)}</div>`;
    return;
  }

  state.tariffs = res.tariffs;
  const totals  = res.tariffs.reduce(
    (a, t) => ({
      active: a.active + (t.is_active == 1 ? 1 : 0),
      usage:  a.usage  + parseInt(t.usage_total  || 0),
      inv:    a.inv    + parseInt(t.usage_active || 0),
    }),
    { active: 0, usage: 0, inv: 0 }
  );

  document.getElementById('st-active').textContent          = totals.active;
  document.getElementById('st-total').textContent           = totals.usage;
  document.getElementById('st-invoices-active').textContent = totals.inv;
  document.getElementById('page-subtitle').textContent =
    'Тарифів: ' + res.tariffs.length + (archived ? ' (архівні)' : '');

  const list = document.getElementById('tariffs-list');
  if (!res.tariffs.length) {
    list.innerHTML = `<div class="empty-state" style="padding:60px 20px;text-align:center">
      <div style="font-size:40px;margin-bottom:12px">🏷</div>
      <h3>${archived ? 'Архів порожній' : 'Тарифів ще немає'}</h3>
      ${state.isOwner && !archived ? '<p>Натисніть "+ Додати тариф"</p>' : ''}
    </div>`;
    return;
  }
  list.innerHTML = `<div class="tariff-grid">${res.tariffs.map(renderCard).join('')}</div>`;
}

function renderCard(t) {
  const color    = esc(t.color || '#4f9cf9');
  const archived = !t.is_active;
  return `
  <div class="tariff-row${archived ? ' archived' : ''}" style="border-left:4px solid ${color}">
    <div class="tariff-row-main">
      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <span class="tariff-name">${esc(t.name)}</span>
          ${t.category ? `<span class="badge badge-info">${esc(t.category)}</span>` : ''}
          ${archived   ? '<span class="badge badge-inactive">Архів</span>' : ''}
        </div>
        <div class="tariff-meta">
          ${t.duration_days} дн.
          &middot; ${t.visits_limit ? t.visits_limit + ' відвід.' : 'безліміт'}
          ${t.freeze_days_max > 0 ? ' &middot; заморозка до ' + t.freeze_days_max + ' дн.' : ''}
        </div>
        ${t.description ? `<div class="tariff-desc">${esc(t.description)}</div>` : ''}
        <div class="tariff-row-stats">
          <span>Продано: <strong>${t.usage_total}</strong></span>
          <span>Активних: <strong>${t.usage_active}</strong></span>
        </div>
      </div>
      <div style="text-align:right;flex-shrink:0">
        <div class="tariff-price">${formatMoney(t.price)}</div>
        ${state.isOwner ? `
        <div style="display:flex;gap:6px;margin-top:10px;justify-content:flex-end">
          <button class="btn btn-ghost btn-sm" onclick="editTariff(${t.id})" title="Редагувати">✏️</button>
          <button class="btn btn-ghost btn-sm" onclick="doArchive(${t.id})"
                  style="color:${archived ? 'var(--success)' : 'var(--text-muted)'}"
                  title="${archived ? 'Відновити' : 'Архівувати'}">
            ${archived ? '↩' : '📦'}
          </button>
        </div>` : ''}
      </div>
    </div>
  </div>`;
}

function editTariff(id) {
  state.editId = id;
  document.getElementById('tariff-error').style.display = 'none';
  document.getElementById('modal-title').textContent = id ? 'Редагувати тариф' : 'Новий тариф';

  if (id) {
    const t = state.tariffs.find(x => x.id == id);
    if (!t) return;
    document.getElementById('f-name').value          = t.name              || '';
    document.getElementById('f-price').value         = t.price             || 0;
    document.getElementById('f-duration').value      = t.duration_days     || 30;
    document.getElementById('f-visits').value        = t.visits_limit      || '';
    document.getElementById('f-category').value      = t.category          || '';
    document.getElementById('f-color').value         = t.color             || '#4f9cf9';
    document.getElementById('f-freeze').value        = t.freeze_days_max   || 0;
    document.getElementById('f-prolong').value       = t.prolong_sum       || 0;
    document.getElementById('f-trainer-sum').value   = t.sum_group_trainer || 0;
    document.getElementById('f-narah-type').value    = t.narah_summ_type   || '';
    document.getElementById('f-sort').value          = t.sort_order        || 0;
    document.getElementById('f-description').value   = t.description       || '';
  } else {
    ['f-name','f-category','f-description'].forEach(i => {
      document.getElementById(i).value = '';
    });
    document.getElementById('f-price').value         = '';
    document.getElementById('f-duration').value      = 30;
    document.getElementById('f-visits').value        = '';
    document.getElementById('f-color').value         = '#4f9cf9';
    document.getElementById('f-freeze').value        = 0;
    document.getElementById('f-prolong').value       = 0;
    document.getElementById('f-trainer-sum').value   = 0;
    document.getElementById('f-narah-type').value    = '';
    document.getElementById('f-sort').value          = 0;
  }
  openModal('modal-tariff');
}

async function saveTariff() {
  const errEl = document.getElementById('tariff-error');
  errEl.style.display = 'none';

  const name = document.getElementById('f-name').value.trim();
  if (!name) {
    errEl.textContent   = 'Назва обов\'язкова';
    errEl.style.display = 'block';
    return;
  }

  const btn = document.getElementById('btn-save');
  btn.disabled = true; btn.textContent = 'Збереження...';

  const visitsVal = document.getElementById('f-visits').value;
  const res = await api(state.editId ? 'update' : 'create', {
    id:                state.editId,
    name,
    price:             parseFloat(document.getElementById('f-price').value)       || 0,
    duration_days:     parseInt(document.getElementById('f-duration').value)       || 30,
    visits_limit:      visitsVal !== '' ? parseInt(visitsVal)                      : null,
    category:          document.getElementById('f-category').value.trim(),
    color:             document.getElementById('f-color').value,
    freeze_days_max:   parseInt(document.getElementById('f-freeze').value)         || 0,
    prolong_sum:       parseFloat(document.getElementById('f-prolong').value)      || 0,
    sum_group_trainer: parseFloat(document.getElementById('f-trainer-sum').value)  || 0,
    narah_summ_type:   document.getElementById('f-narah-type').value,
    sort_order:        parseInt(document.getElementById('f-sort').value)            || 0,
    description:       document.getElementById('f-description').value.trim(),
  }, 'tariffs');

  btn.disabled = false; btn.textContent = 'Зберегти';

  if (res.success) {
    closeModal('modal-tariff');
    toast(res.message || 'Збережено', 'success');
    loadTariffs();
  } else {
    errEl.textContent   = res.error;
    errEl.style.display = 'block';
  }
}

async function doArchive(id) {
  const t = state.tariffs.find(x => x.id == id);
  if (!t) return;
  const verb = t.is_active ? 'Архівувати' : 'Відновити';
  if (!confirm(`${verb} тариф "${t.name}"?`)) return;
  const res = await api('archive', { id }, 'tariffs');
  if (res.success) { toast(res.message, 'success'); loadTariffs(); }
  else              toast(res.error,   'error');
}

function esc(s) {
  return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
