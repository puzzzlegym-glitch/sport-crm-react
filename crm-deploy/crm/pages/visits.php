<?php
$pageTitle = 'Відвідування';
$pageCss   = 'visits';
require __DIR__ . '/../partials/head.php';
?>
<body>
<div class="app-layout">

  <!-- aside і header НЕ пишемо — sidebar.js вставить їх сам -->

  <main class="app-main">

    <div class="page-header" style="display:flex;align-items:flex-start;
         justify-content:space-between;flex-wrap:wrap;gap:12px">
      <div>
        <h1 class="page-title">Журнал відвідувань</h1>
        <p class="page-subtitle" id="page-subtitle">Завантаження...</p>
      </div>
      <button class="btn btn-ghost" onclick="openManualModal()">
        + Ручна відмітка
      </button>
    </div>

    <!-- ══ БЛОК СКАНУВАННЯ ══ -->
    <div class="scan-block">
      <div class="scan-title">&#128197; Сканування штрих-коду</div>
      <div class="scan-input-wrap">
        <input type="text" id="scan-input" class="scan-input"
               placeholder="Прикладіть картку або введіть телефон"
               autocomplete="off" autocorrect="off" spellcheck="false"
               inputmode="numeric">
        <button class="btn btn-primary" onclick="doScan()" style="padding:14px 20px">
          &#10003;
        </button>
      </div>
      <div class="scan-hint">
        Введіть штрих-код картки або номер телефону та натисніть <strong>Enter</strong>
      </div>

      <!-- Результат сканування -->
      <div class="scan-result" id="scan-result">
        <div class="scan-result-card" id="scan-result-card">
          <div class="scan-avatar" id="sr-avatar">?</div>
          <div style="flex:1;min-width:0">
            <div class="scan-client-name" id="sr-name">—</div>
            <div class="scan-client-sub"  id="sr-tariff">—</div>
            <div id="sr-warning"></div>
          </div>
          <div class="scan-check-icon" id="sr-icon">&#10003;</div>
        </div>
      </div>
    </div>

    <!-- ══ СТАТИСТИКА СЬОГОДНІ ══ -->
    <div class="today-stats">
      <div class="today-stat">
        <div class="ts-value" id="st-today">—</div>
        <div class="ts-label">Відвідувань сьогодні</div>
      </div>
      <div class="today-stat">
        <div class="ts-value" id="st-unique">—</div>
        <div class="ts-label">Унікальних клієнтів</div>
      </div>
      <div class="today-stat">
        <div class="ts-value" id="st-week">—</div>
        <div class="ts-label">За 7 днів</div>
      </div>
      <div class="today-stat">
        <div class="ts-value" id="st-month">—</div>
        <div class="ts-label">Цього місяця</div>
      </div>
    </div>

    <!-- ══ ДВА БЛОКИ: СЬОГОДНІ + ЖУРНАЛ ══ -->
    <div style="display:grid;grid-template-columns:1fr 1.4fr;gap:20px">

      <!-- Сьогодні -->
      <div class="card" style="max-height:500px;overflow:hidden;display:flex;flex-direction:column">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-shrink:0">
          <div class="card-title" style="margin:0">
            Сьогодні <span id="today-count" style="font-size:13px;color:var(--text-muted)"></span>
          </div>
          <button class="btn btn-ghost btn-sm" onclick="loadToday()">&#8635;</button>
        </div>

        <!-- Графік по годинах -->
        <div id="hours-chart" class="chart-bars" style="margin-bottom:12px;flex-shrink:0"></div>

        <div id="today-list" style="overflow-y:auto;flex:1">
          <div class="loader"><div class="spinner"></div></div>
        </div>
      </div>

      <!-- Журнал з фільтрами -->
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;
                    margin-bottom:14px;flex-wrap:wrap;gap:8px">
          <div class="card-title" style="margin:0">Журнал</div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <input type="date" id="jrn-from" style="width:auto" onchange="loadJournal()">
            <input type="date" id="jrn-to"   style="width:auto" onchange="loadJournal()">
          </div>
        </div>

        <div style="display:flex;gap:8px;margin-bottom:12px">
          <div style="position:relative;flex:1">
            <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);
                         color:var(--text-muted);font-size:13px;pointer-events:none">&#128269;</span>
            <input type="text" id="jrn-search" placeholder="Ім'я або телефон..."
                   style="padding-left:32px" oninput="scheduleJrnSearch()">
          </div>
        </div>

        <div id="journal-list">
          <div class="loader"><div class="spinner"></div></div>
        </div>
        <div id="journal-pagination" style="padding-top:12px"></div>
      </div>

    </div>

  </main>
</div>

<!-- ════ МОДАЛКА: РУЧНА ВІДМІТКА ════ -->
<div class="modal-overlay" id="modal-manual">
  <div class="modal" style="max-width:440px">
    <button class="modal-close" onclick="closeModal('modal-manual')">&#10005;</button>
    <h2 class="modal-title">Ручна відмітка відвідування</h2>
    <div id="manual-error" class="alert alert-error" style="display:none"></div>

    <div class="form-group">
      <label>Клієнт *</label>
      <div style="position:relative">
        <input type="text" id="manual-search"
               placeholder="Введіть ім'я або телефон..."
               oninput="searchManualClient()" autocomplete="off">
        <div id="manual-client-dd" style="
          display:none; position:absolute; top:100%; left:0; right:0;
          background:var(--bg-elevated); border:1px solid var(--border-light);
          border-radius:var(--radius-sm); z-index:100; max-height:200px;
          overflow-y:auto; box-shadow:var(--shadow-md); margin-top:4px;
        "></div>
      </div>
      <div id="manual-client-badge" style="display:none;margin-top:8px;padding:10px 12px;
           background:var(--accent-dim);border-radius:var(--radius-sm);font-size:14px">
        <strong id="manual-client-name">—</strong>
        <span style="margin-left:8px;color:var(--text-muted);font-size:12px"
              id="manual-client-phone">—</span>
        <button onclick="clearManualClient()"
                style="float:right;background:none;border:none;cursor:pointer;color:var(--text-muted)">
          &#10005;
        </button>
      </div>
    </div>

    <div class="form-group">
      <label>Примітка</label>
      <input type="text" id="manual-notes" placeholder="Необов'язково">
    </div>

    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" onclick="submitManual()">Відмітити</button>
      <button class="btn btn-ghost"   onclick="closeModal('modal-manual')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: ШТРИХ-КОД КЛІЄНТА ════ -->
<div class="modal-overlay" id="modal-barcode">
  <div class="modal" style="max-width:380px;text-align:center">
    <button class="modal-close" onclick="closeModal('modal-barcode')">&#10005;</button>
    <h2 class="modal-title">Штрих-код клієнта</h2>
    <div style="font-size:15px;font-weight:500;margin-bottom:16px" id="bc-client-name">—</div>
    <div id="bc-current" style="font-size:13px;color:var(--text-muted);margin-bottom:16px"></div>
    <div class="form-group">
      <input type="text" id="bc-input" placeholder="Відскануйте або введіть штрих-код">
    </div>
    <button class="btn btn-primary" style="width:100%" onclick="saveBarcode()">
      Зберегти штрих-код
    </button>
  </div>
</div>
<?php require __DIR__ . '/../partials/scripts.php'; ?>
<script>
let state = {
  manualClientId: null,
  barcodeClientId: null,
  jrnSearchTimer: null,
  manualTimer: null,
  jrnPage: 1,
  autoRefreshTimer: null,
};

// ── Ініціалізація ─────────────────────────────────────────
window.addEventListener('DOMContentLoaded', async () => {
  const ctx = await initPage();
  if (!ctx) return;
  guardBlockedClub(ctx.isBlocked);

  const today = new Date().toISOString().split('T')[0];
  document.getElementById('jrn-from').value = today;
  document.getElementById('jrn-to').value   = today;
  document.getElementById('page-subtitle').textContent =
    new Date().toLocaleDateString('uk-UA', { weekday:'long', day:'numeric', month:'long' });

  // Фокус на полі сканування
  document.getElementById('scan-input').focus();

  // Клавіша Enter на полі сканування
  document.getElementById('scan-input').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); doScan(); }
  });

  loadStats();
  loadToday();
  loadJournal();

  // Автооновлення кожні 30 секунд
  state.autoRefreshTimer = setInterval(() => {
    loadToday();
    loadStats();
  }, 30000);
});

// ── Сканування ────────────────────────────────────────────
async function doScan() {
  const query = document.getElementById('scan-input').value.trim();
  if (!query) return;

  const resultEl = document.getElementById('scan-result');
  const cardEl   = document.getElementById('scan-result-card');

  const res = await api('scan', { query }, 'visits');

  // Показуємо результат
  resultEl.style.display = 'block';

  const avatarEl  = document.getElementById('sr-avatar');
  const nameEl    = document.getElementById('sr-name');
  const tariffEl  = document.getElementById('sr-tariff');
  const iconEl    = document.getElementById('sr-icon');
  const warningEl = document.getElementById('sr-warning');
  warningEl.innerHTML = '';

  if (res.success) {
    const c = res.client;
    const inv = res.invoice;

    // Аватар
    if (c.photo_url) {
      avatarEl.innerHTML = `<img src="${esc(c.photo_url)}" onerror="this.parentNode.textContent='${getInitials(c.full_name)}'">`;
    } else {
      avatarEl.textContent = getInitials(c.full_name);
    }

    nameEl.textContent = c.full_name;

    if (inv) {
      tariffEl.textContent = inv.tariff_name +
        (inv.visits_total ? ` · ${inv.visits_used}/${inv.visits_total} відвід.` : '') +
        ` · до ${formatDate(inv.end_date)}`;
    } else {
      tariffEl.textContent = 'Без абонементу';
    }

    if (res.warning) {
      warningEl.innerHTML = `<div class="scan-warning">⚠️ ${esc(res.warning)}</div>`;
    }

    if (res.already_checked_in) {
      cardEl.className = 'scan-result-card already';
      iconEl.textContent = 'ℹ️';
    } else if (res.warning) {
      cardEl.className = 'scan-result-card warning';
      iconEl.textContent = '⚠️';
    } else {
      cardEl.className = 'scan-result-card success';
      iconEl.textContent = '✅';
    }

    // Звуковий сигнал (якщо браузер підтримує)
    playBeep(res.already_checked_in ? 'warn' : 'ok');

    // Оновлюємо списки
    setTimeout(() => {
      loadToday();
      loadStats();
    }, 300);

  } else {
    nameEl.textContent   = res.error || 'Клієнта не знайдено';
    tariffEl.textContent = '';
    avatarEl.textContent = '✗';
    iconEl.textContent   = '❌';
    cardEl.className     = 'scan-result-card error';
    playBeep('error');
  }

  // Очищаємо поле і ставимо фокус
  document.getElementById('scan-input').value = '';
  setTimeout(() => document.getElementById('scan-input').focus(), 100);

  // Ховаємо результат через 4 секунди
  setTimeout(() => {
    resultEl.style.display = 'none';
  }, 4000);
}

// ── Статистика ────────────────────────────────────────────
async function loadStats() {
  const res = await api('get_stats', {}, 'visits');
  if (!res.success) return;
  const s = res.stats;
  document.getElementById('st-today').textContent  = s.total_today;
  document.getElementById('st-unique').textContent = s.unique_today;
  document.getElementById('st-week').textContent   = s.total_week;
  document.getElementById('st-month').textContent  = s.total_month;

  // Графік по годинах
  renderHoursChart(res.hours || []);
}

function renderHoursChart(hours) {
  const chart = document.getElementById('hours-chart');
  if (!hours.length) { chart.innerHTML = ''; return; }

  const max = Math.max(...hours.map(h => h.cnt), 1);
  // Показуємо 8:00–22:00
  const slots = [];
  for (let h = 8; h <= 22; h++) {
    const found = hours.find(x => parseInt(x.hr) === h);
    slots.push({ h, cnt: found ? parseInt(found.cnt) : 0 });
  }

  chart.innerHTML = slots.map(s => `
    <div class="chart-bar-wrap" title="${s.h}:00 — ${s.cnt} відвід.">
      <div class="chart-bar" style="height:${s.cnt ? Math.max(8, Math.round(s.cnt / max * 56)) : 3}px"></div>
      <div class="chart-bar-label">${s.h % 2 === 0 ? s.h : ''}</div>
    </div>
  `).join('');
}

// ── Відвідування сьогодні ─────────────────────────────────
async function loadToday() {
  const res = await api('get_today', {}, 'visits');
  if (!res.success) return;

  const { visits, count } = res;
  document.getElementById('today-count').textContent = `(${count})`;

  document.getElementById('today-list').innerHTML = visits.length
    ? visits.map(v => renderVisitRow(v, true)).join('')
    : '<div class="empty-state" style="padding:30px 0"><p>Ще ніхто не завітав сьогодні</p></div>';
}

// ── Журнал ────────────────────────────────────────────────
async function loadJournal(page = 1) {
  state.jrnPage = page;
  document.getElementById('journal-list').innerHTML =
    '<div class="loader"><div class="spinner"></div></div>';

  const res = await api('get_list', {
    date_from: document.getElementById('jrn-from').value,
    date_to:   document.getElementById('jrn-to').value,
    search:    document.getElementById('jrn-search').value.trim(),
    page,
  }, 'visits');

  if (!res.success) return;

  const { visits, pagination } = res;

  document.getElementById('journal-list').innerHTML = visits.length
    ? visits.map(v => renderVisitRow(v, false)).join('')
    : '<div class="empty-state" style="padding:30px 0"><p>Відвідувань не знайдено</p></div>';

  // Пагінація
  const pgEl = document.getElementById('journal-pagination');
  if (pagination.pages > 1) {
    const from = (page - 1) * pagination.per_page + 1;
    const to   = Math.min(page * pagination.per_page, pagination.total);
    pgEl.innerHTML = `
      <div class="pagination">
        <span>${from}–${to} з ${pagination.total}</span>
        <div class="pagination-btns">
          <button class="page-btn" onclick="loadJournal(${page-1})"
                  ${page<=1?'disabled':''}>&#8249;</button>
          <button class="page-btn active">${page}</button>
          <button class="page-btn" onclick="loadJournal(${page+1})"
                  ${page>=pagination.pages?'disabled':''}>&#8250;</button>
        </div>
      </div>`;
  } else {
    pgEl.innerHTML = '';
  }
}

function renderVisitRow(v, compact) {
  const methodLabel = { barcode:'&#x1F4F7;', manual:'&#9998;', admin:'&#128737;' };
  const methodClass = v.method || 'manual';
  const time = new Date(v.visited_at).toLocaleTimeString('uk-UA',
    { hour:'2-digit', minute:'2-digit' });
  const date = compact ? '' :
    new Date(v.visited_at).toLocaleDateString('uk-UA', { day:'numeric', month:'short' }) + ' ';

  let avatarHtml = '';
  if (v.client_photo) {
    avatarHtml = `<img src="${esc(v.client_photo)}" onerror="this.parentNode.textContent='${getInitials(v.client_name)}'">`;
  } else {
    avatarHtml = getInitials(v.client_name);
  }

  return `
    <div class="visit-row">
      <div class="visit-avatar">${avatarHtml}</div>
      <div style="flex:1;min-width:0">
        <div class="visit-name">${esc(v.client_name)}</div>
        <div class="visit-sub">
          ${v.tariff_name ? esc(v.tariff_name) : '—'}
          ${v.trainer_name ? ' · ' + esc(v.trainer_name) : ''}
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:6px">
        <span class="visit-method-badge ${methodClass}"
              title="${methodClass}">${methodLabel[methodClass]||methodClass}</span>
        <div class="visit-time">${date}${time}</div>
      </div>
    </div>`;
}

function scheduleJrnSearch() {
  clearTimeout(state.jrnSearchTimer);
  state.jrnSearchTimer = setTimeout(() => loadJournal(1), 350);
}

// ── Ручна відмітка ────────────────────────────────────────
function openManualModal() {
  state.manualClientId = null;
  document.getElementById('manual-error').style.display = 'none';
  document.getElementById('manual-search').value = '';
  document.getElementById('manual-notes').value  = '';
  document.getElementById('manual-client-badge').style.display = 'none';
  openModal('modal-manual');
  document.getElementById('manual-search').focus();
}

function searchManualClient() {
  clearTimeout(state.manualTimer);
  const q = document.getElementById('manual-search').value.trim();
  if (q.length < 2) {
    document.getElementById('manual-client-dd').style.display = 'none';
    return;
  }
  state.manualTimer = setTimeout(async () => {
    const res = await api('search', { q }, 'clients');
    const dd  = document.getElementById('manual-client-dd');
    if (!res.success || !res.results?.length) { dd.style.display='none'; return; }
    dd.innerHTML = res.results.map(c => `
      <div onclick="selectManualClient(${c.id},'${esc(c.full_name)}','${c.phone||''}')"
           style="padding:9px 12px;cursor:pointer;font-size:14px;
                  border-bottom:1px solid var(--border)"
           onmouseover="this.style.background='var(--bg-hover)'"
           onmouseout="this.style.background=''">
        <strong>${esc(c.full_name)}</strong>
        <span style="color:var(--text-muted);font-size:12px;margin-left:8px">${c.phone||''}</span>
      </div>`).join('');
    dd.style.display = 'block';
  }, 300);
}

function selectManualClient(id, name, phone) {
  state.manualClientId = id;
  document.getElementById('manual-search').value           = '';
  document.getElementById('manual-client-dd').style.display= 'none';
  document.getElementById('manual-client-badge').style.display = 'block';
  document.getElementById('manual-client-name').textContent = name;
  document.getElementById('manual-client-phone').textContent= phone;
}

function clearManualClient() {
  state.manualClientId = null;
  document.getElementById('manual-client-badge').style.display = 'none';
}

async function submitManual() {
  const errEl = document.getElementById('manual-error');
  errEl.style.display = 'none';

  if (!state.manualClientId) {
    errEl.textContent = 'Оберіть клієнта'; errEl.style.display='block'; return;
  }

  const res = await api('check_in', {
    client_id: state.manualClientId,
    notes:     document.getElementById('manual-notes').value.trim(),
  }, 'visits');

  if (res.success) {
    closeModal('modal-manual');
    toast('Відвідування відмічено', 'success');
    loadToday(); loadStats(); loadJournal(state.jrnPage);
  } else {
    errEl.textContent = res.error; errEl.style.display='block';
  }
}

// ── Звуковий сигнал ───────────────────────────────────────
function playBeep(type) {
  try {
    const ctx  = new (window.AudioContext || window.webkitAudioContext)();
    const osc  = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.connect(gain);
    gain.connect(ctx.destination);

    if (type === 'ok') {
      osc.frequency.value = 880;
      gain.gain.setValueAtTime(0.3, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.2);
      osc.start(); osc.stop(ctx.currentTime + 0.2);
    } else if (type === 'warn') {
      osc.frequency.value = 440;
      gain.gain.setValueAtTime(0.2, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.3);
      osc.start(); osc.stop(ctx.currentTime + 0.3);
    } else {
      // error — два коротких низьких сигнали
      osc.frequency.value = 220;
      gain.gain.setValueAtTime(0.3, ctx.currentTime);
      gain.gain.setValueAtTime(0, ctx.currentTime + 0.15);
      gain.gain.setValueAtTime(0.3, ctx.currentTime + 0.2);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4);
      osc.start(); osc.stop(ctx.currentTime + 0.4);
    }
  } catch (e) { /* тихо ігноруємо якщо браузер не підтримує */ }
}

// ── Утиліти ───────────────────────────────────────────────
function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
</script>
</body>
