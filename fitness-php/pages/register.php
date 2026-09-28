<?php
$pageTitle = 'Реєстрація клубу';
$pageCss   = 'register';
$extraCss  = '
.plan-card.selected{border-color:var(--accent);background:rgba(79,156,249,.1);box-shadow:0 0 0 2px rgba(79,156,249,.3)}
.plan-card{cursor:pointer;transition:border-color .15s,box-shadow .15s}
.plan-card .plan-select-mark{width:18px;height:18px;border-radius:50%;border:2px solid var(--border);flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:11px;color:transparent;transition:all .15s}
.plan-card.selected .plan-select-mark{background:var(--accent);border-color:var(--accent);color:#fff}
.selected-plan-summary{background:rgba(79,156,249,.08);border:1px solid rgba(79,156,249,.25);border-radius:var(--radius-sm);padding:10px 14px;font-size:13px;color:var(--accent);margin-bottom:16px;display:none}
.selected-plan-summary strong{color:var(--text-primary)}
';
require __DIR__ . '/../partials/head_auth.php';
?>
<body>
<div class="reg-wrap">

  <div class="reg-header">
    <div class="reg-logo">Sport<span>CRM</span></div>
    <div class="reg-tagline">Система управління спортивним клубом</div>
  </div>

  <!-- Екран успіху -->
  <div class="success-screen" id="success-screen">
    <div class="success-icon">🎉</div>
    <h2>Вітаємо! Реєстрацію завершено</h2>
    <p>
      Ваш клуб підключено до Sport CRM.<br><br>
      На пошту <strong id="success-email"></strong> надіслано листа з
      <strong>посиланням для підтвердження</strong>.<br><br>
      Перейдіть за посиланням у листі — після цього зможете увійти в систему.<br><br>
      <span style="font-size:13px;color:var(--text-muted)">
        Не отримали листа? Перевірте папку «Спам».
      </span>
    </p>
    <a href="/login" class="btn btn-primary" style="margin-top:24px;display:inline-flex">
      Перейти до входу →
    </a>
  </div>

  <!-- Основний контент -->
  <div class="reg-grid" id="reg-main">

    <!-- Ліворуч: плани -->
    <div>
      <div style="font-size:14px;font-weight:600;color:var(--text-secondary);
                  margin-bottom:12px;text-transform:uppercase;letter-spacing:.5px">
        Оберіть тарифний план
      </div>
      <div class="plan-cards" id="plan-cards">
        <div class="loader"><div class="spinner"></div></div>
      </div>
      <div style="margin-top:16px;padding:14px;background:var(--bg-surface);
                  border:1px solid var(--border);border-radius:var(--radius-md);
                  font-size:13px;color:var(--text-secondary)">
        <div style="font-weight:600;color:var(--text-primary);margin-bottom:6px">
          🎁 14 днів безкоштовно
        </div>
        Після реєстрації ви отримуєте повний доступ до обраного плану без оплати.
        Картка не потрібна.
      </div>
    </div>

    <!-- Праворуч: форма -->
    <div class="reg-form-card" id="reg-form-card">
      <div class="reg-form-title">Реєстрація клубу</div>
      <div class="reg-form-sub">Почніть безкоштовно — 14 днів тріалу</div>

      <!-- Підсумок обраного плану -->
      <div class="selected-plan-summary" id="selected-plan-summary">
        Обраний план: <strong id="summary-plan-name">—</strong>
        <span id="summary-plan-price" style="margin-left:8px;color:var(--text-secondary)"></span>
      </div>

      <div class="trial-badge">
        <span>⏱</span> 14 днів безкоштовно · картка не потрібна
      </div>

      <div id="reg-error" class="alert alert-error" style="display:none"></div>

      <div style="font-size:12px;font-weight:600;color:var(--text-muted);
                  text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px">
        Про вас
      </div>

      <div class="form-group">
        <label>Ім'я та прізвище *</label>
        <input type="text" id="r-name" placeholder="Іван Петренко"
               maxlength="120" oninput="clearError()">
      </div>

      <div class="form-group">
        <label>Email *</label>
        <input type="email" id="r-email" placeholder="ivan@gmail.com"
               oninput="clearError(); scheduleEmailCheck()" autocomplete="email">
        <div class="email-status" id="email-status"></div>
      </div>

      <div class="form-row-2">
        <div class="form-group">
          <label>Пароль *</label>
          <input type="password" id="r-pwd" placeholder="Мінімум 8 символів"
                 oninput="checkPwdStrength()" autocomplete="new-password">
          <div class="pwd-strength"><div class="pwd-strength-bar" id="pwd-bar"></div></div>
          <div class="pwd-hint" id="pwd-hint">Мінімум 8 символів</div>
        </div>
        <div class="form-group">
          <label>Повторіть пароль *</label>
          <input type="password" id="r-pwd2" placeholder="Повторіть"
                 oninput="clearError()" autocomplete="new-password">
        </div>
      </div>

      <div style="font-size:12px;font-weight:600;color:var(--text-muted);
                  text-transform:uppercase;letter-spacing:.5px;margin:20px 0 12px">
        Про клуб
      </div>

      <div class="form-row-2">
        <div class="form-group" style="grid-column:1/-1">
          <label>Назва клубу *</label>
          <input type="text" id="r-club" placeholder="Drive Sport Club"
                 maxlength="120" oninput="clearError()">
        </div>
        <div class="form-group">
          <label>Місто</label>
          <input type="text" id="r-city" placeholder="Київ" maxlength="80">
        </div>
      </div>

      <button class="btn-register" id="btn-register" onclick="submitReg()">
        Зареєструватись безкоштовно
      </button>

      <p style="text-align:center;margin-top:16px;font-size:12px;color:var(--text-muted)">
        Вже маєте акаунт? <a href="/login">Увійти →</a>
      </p>
    </div>
  </div>
</div>

<script src="/assets/js/helpers.js"></script>
<script>
let emailCheckTimer = null;
let emailOk        = null;
let selectedPlanId = null;   // ← ID обраного плану

// ── Завантаження планів ───────────────────────────────────
window.addEventListener('DOMContentLoaded', async () => {
  const res = await api('get_plans', {}, 'billing');
  if (!res.success) return;

  document.getElementById('plan-cards').innerHTML = res.plans.map((p, i) => `
    <div class="plan-card ${i === 1 ? 'featured' : ''}"
         id="plan-card-${p.id}"
         onclick="selectPlan(${p.id}, '${esc(p.name)}', ${p.price_monthly})">
      ${i === 1 ? '<div class="plan-featured-badge">Рекомендовано</div>' : ''}
      <div class="plan-head">
        <div style="display:flex;align-items:center;gap:8px">
          <div class="plan-select-mark">✓</div>
          <div class="plan-name">${p.name}</div>
        </div>
        <div class="plan-price">${p.price_monthly} <span>грн/міс</span></div>
      </div>
      <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px">
        ${p.clients_limit ? 'до ' + p.clients_limit + ' клієнтів' : 'Необмежено клієнтів'} ·
        ${p.users_limit   ? 'до ' + p.users_limit + ' співробітників' : 'Необмежено співробітників'}
      </div>
      <div class="plan-features">
        ${(p.features || []).map(f => `<div class="plan-feature">${f}</div>`).join('')}
      </div>
    </div>
  `).join('');

  // Автоматично обираємо рекомендований план (індекс 1 = Business)
  if (res.plans.length > 1) {
    const def = res.plans[1];
    selectPlan(def.id, def.name, def.price_monthly);
  }
});

// ── Вибір плану ───────────────────────────────────────────
function selectPlan(planId, planName, priceMonthly) {
  selectedPlanId = planId;

  // Знімаємо виділення з усіх
  document.querySelectorAll('.plan-card').forEach(c => c.classList.remove('selected'));
  // Виділяємо обраний
  const card = document.getElementById('plan-card-' + planId);
  if (card) card.classList.add('selected');

  // Підсумок у формі
  const summaryEl = document.getElementById('selected-plan-summary');
  summaryEl.style.display = 'block';
  document.getElementById('summary-plan-name').textContent  = planName;
  document.getElementById('summary-plan-price').textContent =
    '· ' + priceMonthly + ' грн/міс після тріалу';
}

// ── Перевірка email ───────────────────────────────────────
function scheduleEmailCheck() {
  emailOk = null;
  clearTimeout(emailCheckTimer);
  emailCheckTimer = setTimeout(checkEmail, 600);
}

async function checkEmail() {
  const email    = document.getElementById('r-email').value.trim();
  const statusEl = document.getElementById('email-status');
  if (!email || !email.includes('@')) { statusEl.textContent = ''; return; }

  statusEl.textContent = 'Перевіряємо...';
  statusEl.className   = 'email-status';

  const res = await api('check_email', { email }, 'register');
  if (res.available === true) {
    statusEl.textContent = '✓ Email вільний';
    statusEl.className   = 'email-status ok';
    emailOk = true;
  } else {
    statusEl.textContent = '✗ Цей email вже зареєстровано';
    statusEl.className   = 'email-status err';
    emailOk = false;
  }
}

// ── Сила пароля ───────────────────────────────────────────
function checkPwdStrength() {
  const pwd  = document.getElementById('r-pwd').value;
  const bar  = document.getElementById('pwd-bar');
  const hint = document.getElementById('pwd-hint');
  let sc = 0;
  if (pwd.length >= 8)            sc++;
  if (pwd.length >= 12)           sc++;
  if (/[A-Z]/.test(pwd))         sc++;
  if (/[0-9]/.test(pwd))         sc++;
  if (/[^A-Za-z0-9]/.test(pwd))  sc++;
  const levels = [
    { w: '0%',   bg: 'transparent',    t: 'Мінімум 8 символів' },
    { w: '25%',  bg: 'var(--danger)',  t: 'Слабкий пароль' },
    { w: '50%',  bg: 'var(--warning)', t: 'Середній пароль' },
    { w: '75%',  bg: 'var(--info)',    t: 'Гарний пароль' },
    { w: '90%',  bg: 'var(--success)', t: 'Сильний пароль' },
    { w: '100%', bg: 'var(--success)', t: 'Відмінний пароль! 💪' },
  ][Math.min(sc, 5)];
  bar.style.width      = levels.w;
  bar.style.background = levels.bg;
  hint.textContent     = levels.t;
}

// ── Надсилання форми ──────────────────────────────────────
async function submitReg() {
  const btn   = document.getElementById('btn-register');
  const name  = document.getElementById('r-name').value.trim();
  const email = document.getElementById('r-email').value.trim();
  const pwd   = document.getElementById('r-pwd').value;
  const pwd2  = document.getElementById('r-pwd2').value;
  const club  = document.getElementById('r-club').value.trim();
  const city  = document.getElementById('r-city').value.trim();

  if (!name)             return showError("Введіть ваше ім'я");
  if (!email)            return showError('Введіть email');
  if (emailOk === false) return showError('Цей email вже зареєстровано');
  if (pwd.length < 8)    return showError('Пароль — мінімум 8 символів');
  if (pwd !== pwd2)      return showError('Паролі не збігаються');
  if (!club)             return showError('Введіть назву клубу');
  if (!selectedPlanId)   return showError('Оберіть тарифний план');

  btn.disabled  = true;
  btn.innerHTML = '<div class="spinner" style="border-top-color:#fff;width:16px;height:16px;border-width:2px"></div> Реєструємо...';

  const res = await api('register', {
    owner_name: name, email,
    password: pwd, password2: pwd2,
    club_name: club, club_city: city,
    plan_id: selectedPlanId,          // ← передаємо обраний план
  }, 'register');

  if (res.success) {
    document.getElementById('reg-main').style.display          = 'none';
    document.getElementById('success-email').textContent       = email;
    document.getElementById('success-screen').style.display    = 'block';
    window.scrollTo(0, 0);
  } else {
    showError(res.error || 'Помилка реєстрації');
    btn.disabled    = false;
    btn.textContent = 'Зареєструватись безкоштовно';
  }
}

function showError(msg) {
  const el = document.getElementById('reg-error');
  el.textContent = msg;
  el.style.display = 'block';
  el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
function clearError() {
  document.getElementById('reg-error').style.display = 'none';
}
function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
</script>
</body>

</html>
