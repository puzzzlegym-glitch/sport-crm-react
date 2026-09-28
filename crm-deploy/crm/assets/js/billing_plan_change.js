// ════════════════════════════════════════════════════════════
// Цей код вставити в billing.html — модалка зміни плану
// з перерахунком ціни в реальному часі
// ════════════════════════════════════════════════════════════

// HTML модалки — вставити в <body> перед </body>:
const PLAN_CHANGE_MODAL_HTML = `
<!-- МОДАЛКА: ЗМІНА ПЛАНУ -->
<div class="modal-overlay" id="modal-change-plan">
  <div class="modal" style="max-width:480px">
    <button class="modal-close" onclick="closeModal('modal-change-plan')">✕</button>
    <h2 class="modal-title">Змінити тарифний план</h2>

    <div id="cp-error" class="alert alert-error" style="display:none"></div>

    <!-- Поточний план -->
    <div style="padding:12px 14px;background:var(--bg-elevated);border-radius:var(--radius-sm);
                margin-bottom:20px;font-size:13px">
      <div style="color:var(--text-muted);margin-bottom:4px">Поточний план</div>
      <div style="font-weight:600" id="cp-current-plan">—</div>
      <div style="font-size:12px;color:var(--text-muted);margin-top:2px" id="cp-current-end">—</div>
    </div>

    <!-- Новий план -->
    <div class="form-group">
      <label>Новий план</label>
      <select id="cp-plan-select" onchange="recalcPlanChange()">
        <option value="">— Оберіть план —</option>
      </select>
    </div>

    <!-- Тривалість -->
    <div class="form-group">
      <label>Тривалість</label>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px" id="cp-duration-btns">
        <button class="btn btn-ghost active" id="cp-btn-monthly"
                onclick="setDuration('monthly')"
                style="justify-content:center">
          Щомісяця
        </button>
        <button class="btn btn-ghost" id="cp-btn-yearly"
                onclick="setDuration('yearly')"
                style="justify-content:center;position:relative">
          Щорічно
          <span style="position:absolute;top:-8px;right:-6px;background:var(--success);
                       color:#fff;font-size:10px;padding:1px 5px;border-radius:10px">-20%</span>
        </button>
      </div>
    </div>

    <!-- Розрахунок -->
    <div id="cp-calc" style="display:none;padding:14px;background:var(--bg-elevated);
         border-radius:var(--radius-sm);margin-bottom:20px;font-size:14px">
      <div style="display:flex;justify-content:space-between;padding:6px 0;
                  border-bottom:1px solid var(--border)">
        <span style="color:var(--text-secondary)">Ціна плану</span>
        <span id="cp-price-base">—</span>
      </div>
      <div id="cp-discount-row" style="display:none;padding:6px 0;
           border-bottom:1px solid var(--border)">
        <div style="display:flex;justify-content:space-between">
          <span style="color:var(--success)">Знижка 20% (річна)</span>
          <span id="cp-discount-val" style="color:var(--success)">—</span>
        </div>
      </div>
      <div style="display:flex;justify-content:space-between;padding:8px 0;
                  font-weight:700;font-size:16px">
        <span>До сплати</span>
        <span id="cp-total" style="color:var(--accent)">—</span>
      </div>
      <div style="font-size:12px;color:var(--text-muted);margin-top:4px" id="cp-period-label">—</div>
    </div>

    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" id="cp-btn-save" onclick="savePlanChange()" disabled>
        Змінити план
      </button>
      <button class="btn btn-ghost" onclick="closeModal('modal-change-plan')">Скасувати</button>
    </div>
  </div>
</div>`;

// JavaScript для модалки — вставити в <script> блок:
const PLAN_CHANGE_JS = `
let cpState = { planId: null, yearly: false, plans: [], currentSub: null };

function openChangePlanModal(sub, plans) {
  cpState.currentSub = sub;
  cpState.plans = plans;
  cpState.planId = null;
  cpState.yearly = false;

  document.getElementById('cp-error').style.display = 'none';
  document.getElementById('cp-calc').style.display  = 'none';
  document.getElementById('cp-btn-save').disabled   = true;

  // Поточний план
  document.getElementById('cp-current-plan').textContent =
    sub.plan_name + (sub.status === 'trial' ? ' (Тріал)' : '');
  document.getElementById('cp-current-end').textContent =
    sub.status === 'trial'
      ? 'Тріал до: ' + formatDate(sub.trial_ends_at) + ' (' + sub.trial_days_left + ' дн.)'
      : sub.current_period_end
        ? 'Діє до: ' + formatDate(sub.current_period_end)
        : '—';

  // Список планів
  document.getElementById('cp-plan-select').innerHTML =
    '<option value="">— Оберіть план —</option>' +
    plans.map(p =>
      '<option value="' + p.id + '"' + (p.id == sub.plan_id ? ' disabled' : '') + '>' +
      p.name + ' — ' + p.price_monthly + ' грн/міс</option>'
    ).join('');

  setDuration('monthly');
  openModal('modal-change-plan');
}

function setDuration(type) {
  cpState.yearly = (type === 'yearly');
  document.getElementById('cp-btn-monthly').classList.toggle('active', !cpState.yearly);
  document.getElementById('cp-btn-yearly').classList.toggle('active',  cpState.yearly);
  recalcPlanChange();
}

function recalcPlanChange() {
  cpState.planId = parseInt(document.getElementById('cp-plan-select').value) || null;
  const plan = cpState.plans.find(p => p.id === cpState.planId);

  if (!plan) {
    document.getElementById('cp-calc').style.display = 'none';
    document.getElementById('cp-btn-save').disabled  = true;
    return;
  }

  const months  = cpState.yearly ? 12 : 1;
  const base    = plan.price_monthly;
  const full    = base * months;
  const discount= cpState.yearly ? Math.round(full * 0.20) : 0;
  const total   = full - discount;

  document.getElementById('cp-calc').style.display          = 'block';
  document.getElementById('cp-price-base').textContent      =
    base + ' грн/міс' + (cpState.yearly ? ' × 12 = ' + full + ' грн' : '');
  document.getElementById('cp-discount-row').style.display  =
    cpState.yearly ? 'block' : 'none';
  document.getElementById('cp-discount-val').textContent    = '−' + discount + ' грн';
  document.getElementById('cp-total').textContent           = total + ' грн';
  document.getElementById('cp-period-label').textContent    =
    cpState.yearly
      ? 'Щорічна оплата · ' + total + ' грн за 12 місяців'
      : 'Щомісячна оплата · ' + total + ' грн';

  // Якщо є залишок тріалу — показуємо примітку
  if (cpState.currentSub?.status === 'trial' && cpState.currentSub?.trial_days_left > 0) {
    document.getElementById('cp-period-label').textContent +=
      ' · Тріал (' + cpState.currentSub.trial_days_left + ' дн.) додається до підписки';
  }

  document.getElementById('cp-btn-save').disabled = false;
}

async function savePlanChange() {
  if (!cpState.planId) return;
  const errEl = document.getElementById('cp-error');
  errEl.style.display = 'none';

  const btn = document.getElementById('cp-btn-save');
  btn.disabled = true;
  btn.textContent = 'Збереження...';

  const res = await api('change_plan', {
    plan_id: cpState.planId,
    yearly:  cpState.yearly,
  }, 'billing');

  if (res.success) {
    closeModal('modal-change-plan');
    toast(res.message || 'План змінено', 'success');
    loadBilling(); // оновлюємо сторінку білінгу
  } else {
    errEl.textContent = res.error;
    errEl.style.display = 'block';
    btn.disabled = false;
    btn.textContent = 'Змінити план';
  }
}`;

console.log('Скопіюйте PLAN_CHANGE_MODAL_HTML перед </body>');
console.log('Скопіюйте PLAN_CHANGE_JS у <script> блок');
