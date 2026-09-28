<?php
$pageTitle = 'Тарифи';
$pageCss   = 'tariffs';
require __DIR__ . '/../partials/head.php';
?>
<body>
<div class="app-layout">

  <main class="app-main">

    <div class="page-header prod-page-header">
      <div class="prod-header-top">
        <div class="prod-toolbar">
          <label class="low-stock-label">
            <input type="checkbox" id="show-archived" onchange="loadTariffs()"> Показати архівні
          </label>
        </div>
        <div class="prod-header-actions">
          <button class="btn btn-primary" id="btn-add" onclick="editTariff(null)" style="display:none">+ Додати тариф</button>
        </div>
      </div>
    </div>



    <div class="table-wrap" id="tariffs-wrap">
      <table id="tariffs-table">
        <thead class="mob-hide">
          <tr>
            <th>Назва / Категорія</th>
            <th>Ціна</th>
            <th>Строк</th>
            <th>Заморозка</th>
            <th>Відвідувань</th>
            <th>Нарахування</th>
            <th>Продано</th>
            <th>Дії</th>
          </tr>
        </thead>
        <tbody id="tariffs-list">
          <tr><td colspan="8"><div class="loader"><div class="spinner"></div> Завантаження...</div></td></tr>
        </tbody>
      </table>
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
  renderTariffs(res.tariffs);
}

const NARAH_LABELS = { '': '—', fixed: 'Фіксована', percent: 'Відсоток' };

function renderTariffs(tariffs) {
  const archived = document.getElementById('show-archived').checked ? 1 : 0;
  const list  = document.getElementById('tariffs-list');
  const wrap  = document.getElementById('tariffs-wrap');
  let cards   = document.getElementById('tariffs-cards');

  if (!tariffs.length) {
    wrap.style.display = 'none';
    if (cards) cards.style.display = 'none';
    list.innerHTML = `<tr><td colspan="8"><div class="empty-state" style="padding:40px;text-align:center">
      <div style="font-size:36px;margin-bottom:10px">🏷</div>
      <h3>${archived ? 'Архів порожній' : 'Тарифів ще немає'}</h3>
      ${state.isOwner && !archived ? '<p>Натисніть "+ Додати тариф"</p>' : ''}
    </div></td></tr>`;
    wrap.style.display = '';
    return;
  }

  const isMobile = window.innerWidth <= 768;
  if (isMobile) {
    // Замінюємо tbody на div-контейнер поруч
    const wrap = document.getElementById('tariffs-wrap');
    wrap.style.display = 'none';
    let cards = document.getElementById('tariffs-cards');
    if (!cards) {
      cards = document.createElement('div');
      cards.id = 'tariffs-cards';
      wrap.parentNode.insertBefore(cards, wrap.nextSibling);
    }
    cards.style.display = 'block';
    cards.innerHTML = tariffs.map(renderCard).join('');
  } else {
    const wrap = document.getElementById('tariffs-wrap');
    wrap.style.display = '';
    const cards = document.getElementById('tariffs-cards');
    if (cards) cards.style.display = 'none';
    list.innerHTML = tariffs.map(renderRow).join('');
  }
}

function renderCard(t) {
  const color    = esc(t.color || '#4f9cf9');
  const archived = !t.is_active;
  const usageActive = parseInt(t.usage_active) || 0;
  const usageTotal  = parseInt(t.usage_total)  || 0;
  const actionBtns = state.isOwner ? `
    <button class="btn btn-ghost btn-sm" onclick="editTariff(${t.id})" title="Редагувати">&#9998;</button>
    <button class="btn btn-ghost btn-sm" onclick="doArchive(${t.id})"
            style="color:${archived ? 'var(--success)' : 'var(--text-muted)'}"
            title="${archived ? 'Відновити' : 'Архівувати'}">${archived ? '&#8617;' : '&#128230;'}</button>` : '';
  return `
  <div class="tariff-card ${archived ? 'archived' : ''}" style="border-left-color:${color}">
    <div class="tariff-card-main">
      <div class="tariff-card-left">
        <div class="tariff-card-title">
          ${esc(t.name)}
          ${t.category ? `<span class="badge badge-info">${esc(t.category)}</span>` : ''}
          ${archived   ? '<span class="badge badge-inactive">Архів</span>' : ''}
        </div>
        <div class="tariff-card-meta">
          ${t.duration_days} дн. &middot; ${t.visits_limit ? t.visits_limit + ' відвід.' : 'безліміт'}
          ${t.freeze_days_max > 0 ? ' &middot; заморозка до ' + t.freeze_days_max + ' дн.' : ''}
        </div>
        <div class="tariff-card-stats">
          Продано: <strong>${usageTotal}</strong> &nbsp;&nbsp; Активних: <strong>${usageActive}</strong>
        </div>
      </div>
      <div class="tariff-card-right">
        <div class="tariff-card-price">${formatMoney(t.price)}</div>
        <div class="tariff-card-actions">${actionBtns}</div>
      </div>
    </div>
  </div>`;
}

function renderRow(t) {
  const color    = esc(t.color || '#4f9cf9');
  const archived = !t.is_active;
  const usageActive = parseInt(t.usage_active) || 0;
  const usageTotal  = parseInt(t.usage_total)  || 0;
  const actionBtns = state.isOwner ? `
    <button class="btn btn-ghost btn-sm" onclick="event.stopPropagation();editTariff(${t.id})" title="Редагувати">&#9998;</button>
    <button class="btn btn-ghost btn-sm" onclick="event.stopPropagation();doArchive(${t.id})"
            style="color:${archived ? 'var(--success)' : 'var(--text-muted)'}"
            title="${archived ? 'Відновити' : 'Архівувати'}">${archived ? '&#8617;' : '&#128230;'}</button>` : '';
  return `
  <tr class="tariff-tr ${archived ? 'archived' : ''}" style="--t-color:${color}">
    <td>
      <span class="tariff-name">${esc(t.name)}</span>
      ${t.category ? `<span class="badge badge-info" style="margin-left:6px">${esc(t.category)}</span>` : ''}
      ${archived   ? '<span class="badge badge-inactive" style="margin-left:4px">Архів</span>' : ''}
    </td>
    <td style="white-space:nowrap"><strong style="color:var(--accent)">${formatMoney(t.price)}</strong></td>
    <td style="font-size:13px">${t.duration_days} дн.</td>
    <td style="font-size:13px">${t.freeze_days_max > 0 ? t.freeze_days_max + ' дн.' : '—'}</td>
    <td style="font-size:13px">${t.visits_limit || 'безліміт'}</td>
    <td style="font-size:12px;color:var(--text-muted)">${NARAH_LABELS[t.narah_summ_type || ''] || '—'}</td>
    <td style="font-size:13px">${usageTotal} <span style="font-size:11px;color:var(--text-muted)">(${usageActive} акт.)</span></td>
    <td onclick="event.stopPropagation()" style="white-space:nowrap">${actionBtns}</td>
  </tr>`;
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
