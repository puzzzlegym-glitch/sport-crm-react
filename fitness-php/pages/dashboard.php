<?php
$pageTitle = 'Дашборд';
$pageCss   = 'dashboard';
require __DIR__ . '/../partials/head.php';
?>
<body>
<div class="app-layout">
  <main class="app-main">

    <!-- Перемикач періоду + банер зміни -->
    <div class="db-top-bar">
      <select id="db-period-select" class="db-period-select">
        <option value="today">Сьогодні</option>
        <option value="yesterday">Вчора</option>
        <option value="week">Тиждень</option>
        <option value="month">Місяць</option>
        <option value="quarter">Квартал</option>
        <option value="year">Рік</option>
        <option value="lastyear">Минулий рік</option>
      </select>
      <button id="db-shift-banner" class="db-shift-banner db-shift-closed" onclick="dbHandleShiftClick()">
        <span id="db-shift-dot" class="db-shift-dot"></span>
        <span id="db-shift-text" class="db-shift-text">Зміна не відкрита</span>
        <span id="db-shift-action" class="db-shift-action">Відкрити ▶</span>
      </button>
    </div>

    <!-- 6 карток -->
    <div class="db-grid">

      <a href="/cash" class="db-card" data-key="cash">
        <div class="db-card-top">
          <div class="db-card-icon">🏧</div>
          <div class="db-card-delta" id="db-cash-delta"></div>
        </div>
        <div class="db-card-label">Каса</div>
        <div class="db-card-value" id="db-cash">—</div>
        <div class="db-card-sub"   id="db-cash-sub">&nbsp;</div>
        <svg class="db-spark" id="db-cash-spark" viewBox="0 0 80 24" preserveAspectRatio="none"></svg>
      </a>

      <a href="/invoices" class="db-card" data-key="invoices">
        <div class="db-card-top">
          <div class="db-card-icon">📄</div>
          <div class="db-card-delta" id="db-inv-delta"></div>
        </div>
        <div class="db-card-label">Абонементи</div>
        <div class="db-card-value" id="db-inv">—</div>
        <div class="db-card-sub"   id="db-inv-sub">&nbsp;</div>
        <svg class="db-spark" id="db-inv-spark" viewBox="0 0 80 24" preserveAspectRatio="none"></svg>
      </a>

      <a href="/visits" class="db-card" data-key="visits">
        <div class="db-card-top">
          <div class="db-card-icon">👣</div>
          <div class="db-card-delta" id="db-vis-delta"></div>
        </div>
        <div class="db-card-label">Відвідування</div>
        <div class="db-card-value" id="db-vis">—</div>
        <div class="db-card-sub"   id="db-vis-sub">&nbsp;</div>
        <svg class="db-spark" id="db-vis-spark" viewBox="0 0 80 24" preserveAspectRatio="none"></svg>
      </a>

      <a href="/payments" class="db-card" data-key="payments">
        <div class="db-card-top">
          <div class="db-card-icon">💳</div>
          <div class="db-card-delta" id="db-pay-delta"></div>
        </div>
        <div class="db-card-label">Оплати</div>
        <div class="db-card-value" id="db-pay">—</div>
        <div class="db-card-sub"   id="db-pay-sub">&nbsp;</div>
        <svg class="db-spark" id="db-pay-spark" viewBox="0 0 80 24" preserveAspectRatio="none"></svg>
      </a>

      <a href="/sales" class="db-card" data-key="sales">
        <div class="db-card-top">
          <div class="db-card-icon">🧾</div>
          <div class="db-card-delta" id="db-sal-delta"></div>
        </div>
        <div class="db-card-label">Продажі</div>
        <div class="db-card-value" id="db-sal">—</div>
        <div class="db-card-sub"   id="db-sal-sub">&nbsp;</div>
        <svg class="db-spark" id="db-sal-spark" viewBox="0 0 80 24" preserveAspectRatio="none"></svg>
      </a>

      <a href="/arrivals" class="db-card" data-key="arrivals">
        <div class="db-card-top">
          <div class="db-card-icon">📦</div>
          <div class="db-card-delta" id="db-arr-delta"></div>
        </div>
        <div class="db-card-label">Прихід</div>
        <div class="db-card-value" id="db-arr">—</div>
        <div class="db-card-sub"   id="db-arr-sub">&nbsp;</div>
        <svg class="db-spark" id="db-arr-spark" viewBox="0 0 80 24" preserveAspectRatio="none"></svg>
      </a>

    </div>

    <!-- Bar chart -->
    <div class="db-chart-wrap" id="db-chart-wrap">
      <div class="db-chart-title" id="db-chart-title">Відвідування · динаміка</div>
      <div class="db-chart-inner">
        <svg class="db-chart-svg" id="db-chart-svg" preserveAspectRatio="xMidYMid meet"></svg>
      </div>
    </div>

  </main>
</div>
<!-- ════ МОДАЛКА: ВІДКРИТИ ЗМІНУ (дашборд) ════ -->
<div class="modal-overlay" id="db-modal-open-shift">
  <div class="modal" style="max-width:400px">
    <button class="modal-close" onclick="closeModal('db-modal-open-shift')">&#10005;</button>
    <h2 class="modal-title">▶ Відкрити зміну</h2>
    <div id="db-os-error" class="alert alert-error" style="display:none"></div>
    <p style="font-size:13px;color:var(--text-secondary);margin-bottom:12px">
      Залишок на початок: <strong id="db-os-balance">—</strong>
    </p>
    <div class="form-group">
      <label>Коментар (необов'язково)</label>
      <input type="text" id="db-os-notes" placeholder="Відкриття зміни...">
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-success" id="db-btn-os-submit" onclick="dbSubmitOpenShift()">Відкрити</button>
      <button class="btn btn-ghost" onclick="closeModal('db-modal-open-shift')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: ЗАКРИТИ ЗМІНУ (дашборд) ════ -->
<div class="modal-overlay" id="db-modal-close-shift">
  <div class="modal" style="max-width:400px">
    <button class="modal-close" onclick="closeModal('db-modal-close-shift')">&#10005;</button>
    <h2 class="modal-title">■ Закрити зміну</h2>
    <div id="db-cs-error" class="alert alert-error" style="display:none"></div>
    <div style="background:var(--bg-surface);border-radius:var(--radius);padding:14px;margin-bottom:16px;font-size:13px;display:grid;gap:6px">
      <div>Відкрив: <strong id="db-cs-opened-name">—</strong></div>
      <div>Час відкриття: <strong id="db-cs-opened-at">—</strong></div>
      <div>На початок: <strong id="db-cs-balance-open">—</strong></div>
      <div>Зараз у касі: <strong id="db-cs-balance-now">—</strong></div>
    </div>
    <div class="form-group">
      <label>Коментар (необов'язково)</label>
      <input type="text" id="db-cs-notes" placeholder="Підсумок зміни...">
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-danger" id="db-btn-cs-submit" onclick="dbSubmitCloseShift()">Закрити зміну</button>
      <button class="btn btn-ghost" onclick="closeModal('db-modal-close-shift')">Скасувати</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../partials/scripts.php'; ?>
<script>
// ── Зміна (глобальні змінні доступні з IIFE) ────────────
let _dbShiftId   = null;
let _dbCanWrite  = false;

async function dbLoadShift() {
  const res = await api('get_shift', {}, 'cash');
  if (!res.success) return;
  _dbCanWrite = true;
  const shift  = res.shift;
  const banner = document.getElementById('db-shift-banner');
  const text   = document.getElementById('db-shift-text');
  const action = document.getElementById('db-shift-action');
  if (shift) {
    _dbShiftId = shift.id;
    banner.className = 'db-shift-banner db-shift-open';
    text.textContent = 'Зміна відкрита';
    action.textContent = 'Закрити ■';
  } else {
    _dbShiftId = null;
    banner.className = 'db-shift-banner db-shift-closed';
    text.textContent = 'Зміна не відкрита';
    action.textContent = 'Відкрити ▶';
  }
}

function dbHandleShiftClick() {
  if (_dbShiftId) dbCloseShift(); else dbOpenShift();
}

async function dbOpenShift() {
  document.getElementById('db-os-notes').value = '';
  document.getElementById('db-os-error').style.display = 'none';
  const res = await api('get_shift', {}, 'cash');
  document.getElementById('db-os-balance').textContent = res.success ? formatMoney(res.balance) : '—';
  openModal('db-modal-open-shift');
}

async function dbSubmitOpenShift() {
  const errEl = document.getElementById('db-os-error');
  errEl.style.display = 'none';
  const btn = document.getElementById('db-btn-os-submit');
  btn.disabled = true; btn.textContent = 'Відкриваємо...';
  const res = await api('open_shift', { notes: document.getElementById('db-os-notes').value.trim() }, 'cash');
  btn.disabled = false; btn.textContent = 'Відкрити';
  if (res.success) { closeModal('db-modal-open-shift'); toast('Зміну відкрито', 'success'); dbLoadShift(); }
  else { errEl.textContent = res.error; errEl.style.display = 'block'; }
}

async function dbCloseShift() {
  if (!_dbShiftId) return;
  document.getElementById('db-cs-notes').value = '';
  document.getElementById('db-cs-error').style.display = 'none';
  const res = await api('get_shift', {}, 'cash');
  if (res.success && res.shift) {
    document.getElementById('db-cs-opened-name').textContent  = res.shift.opened_name;
    document.getElementById('db-cs-opened-at').textContent    = formatDate(res.shift.opened_at);
    document.getElementById('db-cs-balance-open').textContent = formatMoney(res.shift.balance_open);
    document.getElementById('db-cs-balance-now').textContent  = formatMoney(res.balance);
  }
  openModal('db-modal-close-shift');
}

async function dbSubmitCloseShift() {
  const errEl = document.getElementById('db-cs-error');
  errEl.style.display = 'none';
  const btn = document.getElementById('db-btn-cs-submit');
  btn.disabled = true; btn.textContent = 'Закриваємо...';
  const res = await api('close_shift', { shift_id: _dbShiftId, notes: document.getElementById('db-cs-notes').value.trim() }, 'cash');
  btn.disabled = false; btn.textContent = 'Закрити зміну';
  if (res.success) {
    closeModal('db-modal-close-shift');
    toast(`Зміну закрито. Початок: ${formatMoney(res.balance_open)} → Кінець: ${formatMoney(res.balance_close)}`, 'success');
    dbLoadShift();
  } else { errEl.textContent = res.error; errEl.style.display = 'block'; }
}

(async () => {
  const ctx = await initPage({ title: 'Дашборд' });
  if (!ctx) return;
  guardBlockedClub(ctx.isBlocked);

  const perms = getPermissions(ctx);
  if (perms.canWrite) {
    dbLoadShift();
  }

  // ── Стан ────────────────────────────────────────────────
  let period     = 'today';
  let activeKey  = 'visits';
  let trendCache = null;

  // ── Метадані карток ─────────────────────────────────────
  const CARDS = {
    cash:     { id:'cash', sub:'cash',  label:'Каса',         fmt:'money', field:'amount' },
    invoices: { id:'inv',  sub:'inv',   label:'Абонементи',   fmt:'count', field:'count'  },
    visits:   { id:'vis',  sub:'vis',   label:'Відвідування', fmt:'count', field:'count'  },
    payments: { id:'pay',  sub:'pay',   label:'Оплати',       fmt:'count', field:'count'  },
    sales:    { id:'sal',  sub:'sal',   label:'Продажі',      fmt:'count', field:'count'  },
    arrivals: { id:'arr',  sub:'arr',   label:'Прихід',       fmt:'money', field:'amount' },
  };

  // ── Перемикач періоду (select) ─────────────────────────
  document.getElementById('db-period-select').addEventListener('change', e => {
    period     = e.target.value;
    trendCache = null;
    loadAll();
  });

  // ── Клік на картку → міняє chart ────────────────────────
  document.querySelectorAll('.db-card').forEach(card => {
    card.addEventListener('click', e => {
      e.preventDefault();
      const key = card.dataset.key;
      if (!key) return;
      setActiveCard(key);
      if (trendCache) drawChart(trendCache, key);
    });
  });

  function setActiveCard(key) {
    activeKey = key;
    document.querySelectorAll('.db-card').forEach(c =>
      c.classList.toggle('db-card-active', c.dataset.key === key)
    );
  }

  // ── Головне завантаження ─────────────────────────────────
  loadAll();

  async function loadAll() {
    setSpinners();
    const [r1, r2] = await Promise.all([
      api('get_dashboard',       { period },  'finance'),
      api('get_dashboard_trend', { period },  'finance'),
    ]);
    if (r1.success) fillCards(r1.data, r2.success ? r2.trend : null);
    if (r2.success) { trendCache = r2.trend; drawChart(r2.trend, activeKey); }
  }

  // ── Заповнення карток ────────────────────────────────────
  function fillCards(d, trend) {
    const fm = v => formatMoney(v);

    // Каса
    const ca = d.cash.amount;
    set('db-cash', fm(ca), ca < 0 ? 'neg' : '');
    set('db-cash-sub', ca >= 0 ? 'готівка' : 'витрати > надходжень');
    setDelta('db-cash-delta', trend, 'cash', 'amount');
    drawSpark('db-cash-spark', trend, 'cash', 'amount');

    // Абонементи
    set('db-inv', d.invoices.count);
    set('db-inv-sub', fm(d.invoices.amount));
    setDelta('db-inv-delta', trend, 'invoices', 'count');
    drawSpark('db-inv-spark', trend, 'invoices', 'count');

    // Відвідування
    set('db-vis', d.visits.count);
    set('db-vis-sub', 'чол.');
    setDelta('db-vis-delta', trend, 'visits', 'count');
    drawSpark('db-vis-spark', trend, 'visits', 'count');

    // Оплати
    set('db-pay', d.payments.count);
    set('db-pay-sub', fm(d.payments.amount));
    setDelta('db-pay-delta', trend, 'payments', 'count');
    drawSpark('db-pay-spark', trend, 'payments', 'count');

    // Продажі
    set('db-sal', d.sales.count);
    set('db-sal-sub', fm(d.sales.amount));
    setDelta('db-sal-delta', trend, 'sales', 'count');
    drawSpark('db-sal-spark', trend, 'sales', 'count');

    // Прихід
    set('db-arr', d.arrivals.count);
    set('db-arr-sub', fm(d.arrivals.amount));
    setDelta('db-arr-delta', trend, 'arrivals', 'amount');
    drawSpark('db-arr-spark', trend, 'arrivals', 'amount');
  }

  // ── Утиліти DOM ─────────────────────────────────────────
  function set(id, val, cls = null) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = val;
    if (cls !== null) el.className = el.className.replace(/\bneg\b/, '').trim() + (cls ? ' ' + cls : '');
  }

  function setSpinners() {
    ['cash','inv','vis','pay','sal','arr'].forEach(k => {
      const el = document.getElementById('db-' + k);
      if (el) el.innerHTML = '<span class="spinner"></span>';
      const sub = document.getElementById('db-' + k + '-sub');
      if (sub) sub.innerHTML = '&nbsp;';
      const dl = document.getElementById('db-' + k + '-delta');
      if (dl) dl.textContent = '';
    });
  }

  // ── Delta % ──────────────────────────────────────────────
  function setDelta(elId, trend, metric, field) {
    const el = document.getElementById(elId);
    if (!el || !trend) { if (el) el.textContent = ''; return; }
    const cur  = trend.current  ? trend.current.reduce((s,d)  => s + (d[metric]?.[field] || 0), 0) : 0;
    const prev = trend.previous ? trend.previous.reduce((s,d) => s + (d[metric]?.[field] || 0), 0) : 0;
    if (prev === 0 && cur === 0) { el.textContent = ''; return; }
    if (prev === 0) { el.textContent = '+100%'; el.className = 'db-card-delta up'; return; }
    const pct = Math.round((cur - prev) / prev * 100);
    el.textContent = (pct >= 0 ? '+' : '') + pct + '%';
    el.className   = 'db-card-delta ' + (pct >= 0 ? 'up' : 'down');
  }

  // ── Sparkline SVG ────────────────────────────────────────
  function drawSpark(elId, trend, metric, field) {
    const svg = document.getElementById(elId);
    if (!svg || !trend?.current) return;
    const vals = trend.current.map(d => d[metric]?.[field] || 0);
    svg.innerHTML = sparkPath(vals);
  }

  function sparkPath(vals) {
    if (!vals.length) return '';
    const W = 80, H = 24, pad = 2;
    const max = Math.max(...vals, 1);
    const pts = vals.map((v, i) => {
      const x = pad + (i / Math.max(vals.length - 1, 1)) * (W - pad * 2);
      const y = H - pad - (v / max) * (H - pad * 2);
      return `${x.toFixed(1)},${y.toFixed(1)}`;
    });
    return `<polyline points="${pts.join(' ')}" fill="none" stroke="var(--accent)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>`;
  }

  // ── Bar Chart ────────────────────────────────────────────
  function drawChart(trend, key) {
    const svg   = document.getElementById('db-chart-svg');
    const title = document.getElementById('db-chart-title');
    if (!svg || !trend?.current) return;

    const meta  = CARDS[key];
    const days  = trend.current;
    const field = meta.field;

    title.textContent = meta.label + ' · динаміка 7 днів';

    const W = svg.parentElement.offsetWidth || 600;
    const H = 160, pt = 16, pb = 28, pl = 44, pr = 12;
    svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
    svg.setAttribute('width',  W);
    svg.setAttribute('height', H);

    const vals   = days.map(d => d[key]?.[field] || 0);
    const maxVal = Math.max(...vals, 1);
    const bW     = Math.max(8, Math.floor((W - pl - pr) / days.length * 0.6));
    const step   = (W - pl - pr) / days.length;
    const isMoney = meta.fmt === 'money';

    // Осі Y (4 мітки)
    let out = '';
    for (let i = 0; i <= 4; i++) {
      const v = maxVal * i / 4;
      const y = pt + (H - pt - pb) * (1 - i / 4);
      const lbl = isMoney ? (v >= 1000 ? (v/1000).toFixed(0)+'k' : v.toFixed(0)) : Math.round(v);
      out += `<line x1="${pl}" y1="${y.toFixed(1)}" x2="${W - pr}" y2="${y.toFixed(1)}" stroke="var(--border)" stroke-width="1"/>`;
      out += `<text x="${pl - 6}" y="${(y + 4).toFixed(1)}" text-anchor="end" class="db-axis-lbl">${lbl}</text>`;
    }

    // Стовпці
    days.forEach((d, i) => {
      const v  = d[key]?.[field] || 0;
      const bH = Math.max(2, ((v / maxVal) * (H - pt - pb)));
      const x  = pl + step * i + step / 2 - bW / 2;
      const y  = H - pb - bH;
      const lbl = d.date ? d.date.slice(5) : ''; // MM-DD
      out += `<rect x="${x.toFixed(1)}" y="${y.toFixed(1)}" width="${bW}" height="${bH.toFixed(1)}" rx="3" class="db-bar"/>`;
      out += `<text x="${(x + bW/2).toFixed(1)}" y="${(H - pb + 14).toFixed(1)}" text-anchor="middle" class="db-axis-lbl">${lbl}</text>`;
    });

    svg.innerHTML = out;
    setActiveCard(key);
  }

})();
</script>
</body>
</html>
