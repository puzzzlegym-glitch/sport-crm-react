<?php
$pageTitle = 'Підписка';
$pageCss   = 'billing';
require __DIR__ . '/../partials/head.php';
?>
<body>
<div class="app-layout">

  <!-- aside і header НЕ пишемо — sidebar.js вставить їх сам -->

    <main class="app-main">

    <div class="page-header">
      <h1 class="page-title">Підписка</h1>
      <p class="page-subtitle">Управління тарифом і платежами</p>
    </div>

    <div id="billing-content">
      <div class="loader"><div class="spinner"></div> Завантаження...</div>
    </div>

  </main>
</div>

<!-- Модалка оплати -->
<div class="modal-overlay" id="modal-pay">
  <div class="modal" style="max-width:440px">
    <button class="modal-close" onclick="closeModal('modal-pay')">&#10005;</button>
    <h2 class="modal-title">Оплата підписки</h2>

    <div id="pay-plan-info" style="margin-bottom:20px;padding:14px;background:var(--bg-elevated);border-radius:var(--radius-md)">
      <!-- Заповнюється JS -->
    </div>

    <div class="form-group">
      <label>Кількість місяців</label>
      <select id="pay-months" onchange="updatePayTotal()">
        <option value="1">1 місяць</option>
        <option value="3">3 місяці (−5%)</option>
        <option value="6">6 місяців (−10%)</option>
        <option value="12">12 місяців (−15%)</option>
      </select>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;padding:12px 0;border-top:1px solid var(--border)">
      <span style="font-size:14px;color:var(--text-secondary)">До сплати:</span>
      <span style="font-size:22px;font-weight:700;color:var(--accent)" id="pay-total">—</span>
    </div>

    <button class="btn btn-primary" style="width:100%;padding:13px;font-size:15px" onclick="processPayment()">
      Оплатити через WayForPay
    </button>

    <p style="text-align:center;font-size:12px;color:var(--text-muted);margin-top:12px">
      Безпечна оплата · WayForPay · Visa / Mastercard
    </p>
  </div>
</div>
<?php require __DIR__ . '/../partials/scripts.php'; ?>
<script>
let billingData = null;
let selectedPlan = null;

window.addEventListener('DOMContentLoaded', async () => {
  const ctx = await initPage({ title: 'Підписка і оплата' });
  if (!ctx) return;

  // Якщо повернулись після оплати
  if (new URLSearchParams(location.search).get('paid') === '1') {
    toast('Оплату підтверджено! Підписку активовано.', 'success', 5000);
    history.replaceState({}, '', '/billing');
  }

  loadBilling();
});

async function loadBilling() {
  const el = document.getElementById('billing-content');
  el.innerHTML = '<div class="loader"><div class="spinner"></div> Завантаження...</div>';

  const [billingRes, plansRes] = await Promise.all([
    api('get_my_billing', {}, 'billing'),
    api('get_plans',      {}, 'billing'),
  ]);

  if (!billingRes.success) {
    el.innerHTML = `<div class="alert alert-error">${billingRes.error}</div>`;
    return;
  }

  billingData = billingRes.subscription;
  const plans = plansRes.plans || [];

  el.innerHTML = renderBilling(billingData, billingRes.invoices || [], plans);
}

function renderBilling(sub, invoices, plans) {
  const status         = sub.status;
  const trialDays      = parseInt(sub.trial_days_left) || 0;
  const planName       = sub.plan_name || '—';
  const clientsUsed    = parseInt(sub.clients_used)    || 0;
  const usersUsed      = parseInt(sub.users_used)      || 0;
  const invoicesUsed   = parseInt(sub.invoices_used)   || 0;
  const clientsLimit   = sub.clients_limit   ? parseInt(sub.clients_limit)   : null;
  const usersLimit     = sub.users_limit     ? parseInt(sub.users_limit)     : null;
  const invoicesLimit  = sub.invoices_limit  ? parseInt(sub.invoices_limit)  : null;

  // Банер
  let bannerClass = 'trial', bannerIcon = 'ℹ️', bannerTitle = '', bannerText = '', bannerBtn = '';

  if (status === 'trial') {
    if (trialDays <= 3) {
      bannerClass = 'trial_warn';
      bannerIcon = '⚠️';
      bannerTitle = `Тріал закінчується через ${trialDays} дн.`;
      bannerText = 'Оберіть план щоб продовжити роботу без переривань';
      bannerBtn = `<button class="btn btn-primary btn-sm" onclick="document.getElementById('plans-section').scrollIntoView({behavior:'smooth'})">Обрати план →</button>`;
    } else {
      bannerTitle = `Тріал · ${trialDays} днів залишилось`;
      bannerText = `План Business · закінчується ${formatDate(sub.trial_ends_at)}`;
    }
  } else if (status === 'trial_expired') {
    bannerClass = 'expired';
    bannerIcon = '🔒';
    bannerTitle = 'Тріал закінчився';
    bannerText = 'Лише перегляд. Оберіть план для відновлення доступу.';
    bannerBtn = `<button class="btn btn-primary btn-sm" onclick="document.getElementById('plans-section').scrollIntoView({behavior:'smooth'})">Обрати план →</button>`;
  } else if (status === 'active') {
    bannerClass = 'active';
    bannerIcon = '✅';
    bannerTitle = `Підписка активна · ${planName}`;
    bannerText = `Наступне списання: ${formatDate(sub.current_period_end)}`;
  }

  // Прогрес лімітів
  const clientPct   = clientsLimit  ? Math.min(100, Math.round(clientsUsed  / clientsLimit  * 100)) : 0;
  const userPct     = usersLimit    ? Math.min(100, Math.round(usersUsed    / usersLimit    * 100)) : 0;
  const invoicePct  = invoicesLimit ? Math.min(100, Math.round(invoicesUsed / invoicesLimit * 100)) : 0;
  const barClass    = pct => pct >= 90 ? 'full' : pct >= 70 ? 'warn' : 'ok';

  // Плани
  const plansHtml = plans.map(p => `
    <div class="billing-plan-card ${p.slug === sub.plan_slug ? 'current' : ''}">
      ${p.slug === sub.plan_slug ? '<div class="plan-current-label">Поточний план</div>' : ''}
      <div class="bp-name">${p.name}</div>
      <div class="bp-price">${p.price_monthly} <small>грн/міс</small></div>
      <div class="bp-features">
        ${(p.features||[]).map(f => `<div class="bp-feature">${f}</div>`).join('')}
      </div>
      ${p.slug !== sub.plan_slug ? `
        <button class="btn btn-ghost" style="width:100%" onclick="openPayModal(${p.id}, '${p.name}', ${p.price_monthly})">
          ${p.price_monthly > sub.price_monthly ? 'Підвищити' : 'Перейти'} →
        </button>
      ` : '<button class="btn btn-ghost" style="width:100%;opacity:.5" disabled>Активний</button>'}
    </div>
  `).join('');

  // Рахунки
  const invoicesHtml = invoices.length ? `
    <table>
      <thead>
        <tr>
          <th>Рахунок</th>
          <th>Період</th>
          <th>Сума</th>
          <th>Статус</th>
          <th>Оплачено</th>
        </tr>
      </thead>
      <tbody>
        ${invoices.map(inv => `
          <tr>
            <td style="font-size:13px">#${inv.id}</td>
            <td style="font-size:13px">${formatDate(inv.period_start)} — ${formatDate(inv.period_end)}</td>
            <td style="font-weight:500">${inv.amount} грн</td>
            <td><span class="invoice-status-${inv.status}">${inv.status === 'paid' ? '✓ Оплачено' : inv.status}</span></td>
            <td style="font-size:13px;color:var(--text-muted)">${inv.paid_at ? formatDate(inv.paid_at) : '—'}</td>
          </tr>
        `).join('')}
      </tbody>
    </table>
  ` : '<div class="empty-state" style="padding:30px 0"><p>Рахунків ще немає</p></div>';

  return `
    <!-- Банер -->
    <div class="sub-banner ${bannerClass}">
      <div class="sub-banner-text">
        <h3>${bannerIcon} ${bannerTitle}</h3>
        <p>${bannerText}</p>
      </div>
      ${bannerBtn}
    </div>

    <!-- Поточний план -->
    <div class="card" style="margin-bottom:24px">
      <div class="card-title">Поточний план</div>
      <div class="current-plan-card">
        <div class="plan-badge">${planName}</div>
        <div class="plan-meta">
          <div class="plan-meta-label">Статус</div>
          <div class="plan-meta-value">
            <span class="badge ${status === 'active' ? 'badge-active' : status === 'trial' ? 'badge-info' : 'badge-inactive'}">
              ${{ trial: 'Тріал', trial_expired: 'Тріал закінчився', active: 'Активна', past_due: 'Прострочено', cancelled: 'Скасовано' }[status] || status}
            </span>
          </div>
        </div>
        <div class="plan-meta">
          <div class="plan-meta-label">Ціна</div>
          <div class="plan-meta-value">${sub.price_monthly} грн/міс</div>
        </div>
        ${status === 'trial' ? `
        <div class="plan-meta">
          <div class="plan-meta-label">Тріал до</div>
          <div class="plan-meta-value">${formatDate(sub.trial_ends_at)}</div>
        </div>` : ''}
      </div>

      <!-- Ліміти -->
      <div class="limit-item">
        <div class="limit-label">
          <span>Активні абонементи</span>
          <span class="${invoicesLimit && invoicesUsed >= invoicesLimit * 0.9 ? 'text-warn' : ''}">${invoicesUsed} / ${invoicesLimit ? invoicesLimit : '∞'}</span>
        </div>
        ${invoicesLimit ? `
        <div class="limit-bar">
          <div class="limit-bar-fill ${barClass(invoicePct)}" style="width:${invoicePct}%"></div>
        </div>
        ${invoicePct >= 90 ? `<div style="font-size:12px;color:var(--warning);margin-top:4px">
          ⚠ Залишилось лише ${invoicesLimit - invoicesUsed} абонементів до ліміту плану
        </div>` : ''}` : ''}
      </div>
      <div class="limit-item">
        <div class="limit-label">
          <span>Клієнти</span>
          <span>${clientsUsed} / ${clientsLimit ? clientsLimit : '∞'}</span>
        </div>
        ${clientsLimit ? `
        <div class="limit-bar">
          <div class="limit-bar-fill ${barClass(clientPct)}" style="width:${clientPct}%"></div>
        </div>` : ''}
      </div>
      <div class="limit-item">
        <div class="limit-label">
          <span>Співробітники</span>
          <span>${usersUsed} / ${usersLimit ? usersLimit : '∞'}</span>
        </div>
        ${usersLimit ? `
        <div class="limit-bar">
          <div class="limit-bar-fill ${barClass(userPct)}" style="width:${userPct}%"></div>
        </div>` : ''}
      </div>
    </div>

    <!-- Плани -->
    <div id="plans-section" class="card" style="margin-bottom:24px">
      <div class="card-title">Тарифні плани</div>
      <div class="plans-grid">${plansHtml}</div>
    </div>

    <!-- Рахунки -->
    <div class="card">
      <div class="card-title">Історія платежів</div>
      <div class="table-wrap">${invoicesHtml}</div>
    </div>
  `;
}

// ── Модалка оплати ────────────────────────────────────────
function openPayModal(planId, planName, priceMonthly) {
  selectedPlan = { id: planId, name: planName, price: priceMonthly };
  document.getElementById('pay-plan-info').innerHTML = `
    <div style="font-size:15px;font-weight:600">${planName}</div>
    <div style="font-size:13px;color:var(--text-secondary);margin-top:2px">${priceMonthly} грн / місяць</div>
  `;
  document.getElementById('pay-months').value = '1';
  updatePayTotal();
  openModal('modal-pay');
}

function updatePayTotal() {
  if (!selectedPlan) return;
  const months = parseInt(document.getElementById('pay-months').value);
  const discounts = { 1: 0, 3: 0.05, 6: 0.10, 12: 0.15 };
  const disc   = discounts[months] || 0;
  const total  = Math.round(selectedPlan.price * months * (1 - disc));
  document.getElementById('pay-total').textContent = total + ' грн';
}

async function processPayment() {
  if (!selectedPlan) return;
  const months = parseInt(document.getElementById('pay-months').value);
  const btn = document.querySelector('#modal-pay .btn-primary');
  btn.disabled = true; btn.textContent = 'Перенаправлення...';

  const res = await api('create_payment', {
    plan_id: selectedPlan.id,
    months,
  }, 'billing');

  if (!res.success) {
    btn.disabled = false; btn.textContent = 'Оплатити через WayForPay';
    toast(res.error, 'error');
    return;
  }

  // Вставляємо форму без <script>, потім сабмітимо вручну
  const div = document.createElement('div');
  div.style.display = 'none';
  // Прибираємо script-тег з HTML щоб уникнути проблем з innerHTML
  div.innerHTML = res.wfp_form.html.replace(/<script[\s\S]*?<\/script>/gi, '');
  document.body.appendChild(div);
  const form = div.querySelector('form');
  if (form) form.submit();
}
</script>
</body>
