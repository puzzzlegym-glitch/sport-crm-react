<?php
$pageTitle = 'Команда';
$pageCss   = 'users';
require __DIR__ . '/../partials/head.php';
?>
<body>
<div class="app-layout">

  <!-- aside і header НЕ пишемо — sidebar.js вставить їх сам -->

  <main class="app-main">

    <!-- Заголовок -->
    <div class="page-header" style="display:flex;align-items:flex-start;
         justify-content:space-between;flex-wrap:wrap;gap:12px">
      <div>
        <h1 class="page-title">Команда клубу</h1>
        <p class="page-subtitle" id="page-subtitle">Завантаження...</p>
      </div>
      <button class="btn btn-primary" id="btn-invite"
              onclick="openInviteModal()" style="display:none">
        + Запросити співробітника
      </button>
    </div>

    <!-- Підказка що це за розділ -->
    <div id="section-hint" class="alert" style="
      background:rgba(79,156,249,.08);border:1px solid rgba(79,156,249,.2);
      color:var(--text-secondary);font-size:13px;margin-bottom:20px;display:none">
      <strong style="color:var(--accent)">Що це за розділ?</strong>
      Тут ви керуєте доступом ваших співробітників до клубу.
      Додайте менеджера — і він зможе реєструвати клієнтів та продавати абонементи.
      Додайте тренера — і він бачитиме своїх клієнтів.
    </div>

    <!-- Картки ролей (лічильники) -->
    <div class="role-summary" id="role-summary"></div>

    <!-- Ліміт плану -->
    <div id="limit-warning" class="alert" style="
      background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.25);
      color:var(--warning);font-size:13px;margin-bottom:16px;
      display:none;align-items:center;gap:10px">
      <span>&#9888;</span>
      <span id="limit-text"></span>
      <a href="/billing" class="btn btn-ghost btn-sm" style="margin-left:auto">
        Підвищити план →
      </a>
    </div>

    <!-- Вкладки -->
    <div style="display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:20px">
      <button class="page-tab-btn active" onclick="switchTab('team',this)">
        &#128101; Команда
      </button>
      <button class="page-tab-btn" onclick="switchTab('profile',this)">
        &#128100; Мій профіль і пароль
      </button>
    </div>

    <!-- ══ ВКЛАДКА: КОМАНДА ══ -->
    <div class="page-tab-content active" id="tab-team">
      <div class="card" style="padding:0;overflow:hidden">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Співробітник</th>
                <th>Роль</th>
                <th>Права</th>
                <th>Статус</th>
                <th>Останній вхід</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="team-tbody">
              <tr><td colspan="6">
                <div class="loader"><div class="spinner"></div> Завантаження...</div>
              </td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══ ВКЛАДКА: ПРОФІЛЬ ══ -->
    <div class="page-tab-content" id="tab-profile">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">

        <!-- Профіль -->
        <div class="card">
          <div class="card-title">Особисті дані</div>
          <div id="profile-msg" class="alert" style="display:none"></div>
          <div class="form-group">
            <label>Ім'я та прізвище *</label>
            <input type="text" id="p-name" placeholder="Іван Петренко" maxlength="120">
          </div>
          <div class="form-group">
            <label>Email</label>
            <input type="email" id="p-email" disabled
                   style="opacity:.55;cursor:not-allowed">
            <div style="font-size:12px;color:var(--text-muted);margin-top:3px">
              Email змінити неможливо
            </div>
          </div>
          <div class="form-group">
            <label>Телефон</label>
            <input type="tel" id="p-phone" placeholder="+38 067 123 45 67">
          </div>
          <button class="btn btn-primary" onclick="saveProfile()">Зберегти</button>
        </div>

        <!-- Зміна пароля -->
        <div class="card">
          <div class="card-title">&#128274; Зміна пароля</div>
          <div id="pwd-msg" class="alert" style="display:none"></div>
          <div class="form-group">
            <label>Поточний пароль *</label>
            <input type="password" id="pwd-cur" autocomplete="current-password"
                   placeholder="Введіть поточний">
          </div>
          <div class="form-group">
            <label>Новий пароль *</label>
            <input type="password" id="pwd-new" autocomplete="new-password"
                   placeholder="Мінімум 8 символів" oninput="updatePwdStrength()">
            <div style="height:3px;background:var(--border);border-radius:2px;
                        margin-top:6px;overflow:hidden">
              <div id="pwd-bar" style="height:100%;width:0;
                   border-radius:2px;transition:width .3s,background .3s"></div>
            </div>
            <div id="pwd-hint" style="font-size:11px;color:var(--text-muted);margin-top:3px">
              Мінімум 8 символів
            </div>
          </div>
          <div class="form-group">
            <label>Повторіть *</label>
            <input type="password" id="pwd-rep" autocomplete="new-password"
                   placeholder="Повторіть новий">
          </div>
          <button class="btn btn-ghost" onclick="changePassword()">
            Змінити пароль
          </button>
        </div>

      </div>
    </div>

  </main>
</div>

<!-- ════ МОДАЛКА: ЗАПРОСИТИ СПІВРОБІТНИКА ════ -->
<div class="modal-overlay" id="modal-invite">
  <div class="modal" style="max-width:480px">
    <button class="modal-close" onclick="closeModal('modal-invite')">&#10005;</button>
    <h2 class="modal-title">Запросити співробітника</h2>

    <!-- Пояснення -->
    <div style="padding:12px 14px;background:var(--bg-elevated);border-radius:var(--radius-sm);
                font-size:13px;color:var(--text-secondary);margin-bottom:18px;line-height:1.6">
      Якщо людина вже має акаунт у системі — вкажіть її email і вона отримає доступ до вашого клубу.
      Якщо ні — буде створено новий акаунт і на пошту надіслано тимчасовий пароль.
    </div>

    <div id="invite-error" class="alert alert-error" style="display:none"></div>
    <div id="invite-success" style="display:none">
      <div class="alert alert-success">Запрошення надіслано!</div>
      <div id="tmp-pwd-wrap" style="display:none;margin-top:12px;padding:12px 14px;
           background:var(--bg-elevated);border-radius:var(--radius-sm)">
        <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px">
          ТИМЧАСОВИЙ ПАРОЛЬ (якщо лист не дійде)
        </div>
        <div id="tmp-pwd-val" style="font-size:20px;font-weight:700;
             color:var(--success);letter-spacing:1px">—</div>
      </div>
      <button class="btn btn-primary" style="margin-top:16px;width:100%"
              onclick="closeModal('modal-invite');loadTeam()">Готово</button>
    </div>

    <div id="invite-form">
      <div class="form-group">
        <label>Ім'я та прізвище *</label>
        <input type="text" id="inv-name" placeholder="Олена Коваль" maxlength="120">
      </div>
      <div class="form-group">
        <label>Email *</label>
        <input type="email" id="inv-email" placeholder="olena@example.com">
      </div>
      <div class="form-group">
        <label>Телефон</label>
        <input type="tel" id="inv-phone" placeholder="+38 067 123 45 67">
      </div>
      <div class="form-group">
        <label>Роль *</label>
        <select id="inv-role" onchange="updateRoleDesc()">
          <option value="">— Оберіть роль —</option>
        </select>
        <div id="role-desc" style="font-size:12px;color:var(--text-muted);margin-top:4px"></div>
      </div>
      <div style="display:flex;gap:10px">
        <button class="btn btn-primary" id="btn-invite-ok" onclick="submitInvite()">
          Надіслати запрошення
        </button>
        <button class="btn btn-ghost" onclick="closeModal('modal-invite')">Скасувати</button>
      </div>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: ЗМІНИТИ РОЛЬ ════ -->
<div class="modal-overlay" id="modal-role">
  <div class="modal" style="max-width:360px">
    <button class="modal-close" onclick="closeModal('modal-role')">&#10005;</button>
    <h2 class="modal-title">Змінити роль</h2>
    <div id="role-error" class="alert alert-error" style="display:none"></div>
    <div style="margin-bottom:14px">
      <div style="font-size:12px;color:var(--text-muted);margin-bottom:2px">Співробітник</div>
      <div style="font-size:15px;font-weight:600" id="role-modal-name">—</div>
    </div>
    <div class="form-group">
      <label>Нова роль</label>
      <select id="role-select"></select>
    </div>
    <div style="display:flex;gap:10px;margin-top:4px">
      <button class="btn btn-primary" onclick="saveRole()">Зберегти</button>
      <button class="btn btn-ghost"   onclick="closeModal('modal-role')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: ВИДАЛИТИ ════ -->
<div class="modal-overlay" id="modal-remove">
  <div class="modal" style="max-width:360px">
    <h2 class="modal-title">Прибрати з команди?</h2>
    <p style="font-size:14px;color:var(--text-secondary);margin-bottom:6px">
      <strong id="remove-name">—</strong> втратить доступ до клубу.
    </p>
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:20px">
      Акаунт залишиться. Повторне запрошення поверне доступ.
    </p>
    <div style="display:flex;gap:10px">
      <button class="btn btn-danger" onclick="confirmRemove()">Прибрати</button>
      <button class="btn btn-ghost"  onclick="closeModal('modal-remove')">Скасувати</button>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../partials/scripts.php'; ?>
<script>
const S = { myUserId: null, myLevel: 0, roles: [], editUserId: null, removeUserId: null };

// ── Ініціалізація ─────────────────────────────────────────
window.addEventListener('DOMContentLoaded', async () => {
  const ctx = await initPage();
  if (!ctx) return;

  const { u, isSuperAdmin, inClubMode, club, clubRole } = ctx;
  S.myUserId = u.id;
  S.myLevel  = isSuperAdmin ? (inClubMode ? 80 : 100) : (clubRole?.level ?? 0);

  document.getElementById('page-subtitle').textContent =
    club ? club.name : '—';

  // Показуємо підказку тільки якщо команда ще порожня або для нових користувачів
  if (S.myLevel >= 80) {
    document.getElementById('section-hint').style.display = 'block';
    document.getElementById('btn-invite').style.display = 'inline-flex';
  }

  // Заповнюємо профіль
  document.getElementById('p-name').value  = u.full_name || '';
  document.getElementById('p-email').value = u.email     || '';

  // Ролі
  const rolesRes = await api('get_roles', {}, 'users');
  if (rolesRes.success) {
    S.roles = rolesRes.roles;
    fillRoleSelect('inv-role', true);
  }

  loadTeam();
});

// ── Команда ───────────────────────────────────────────────
async function loadTeam() {
  const tbody = document.getElementById('team-tbody');
  tbody.innerHTML =
    '<tr><td colspan="6"><div class="loader"><div class="spinner"></div></div></td></tr>';

  const res = await api('get_list', {}, 'users');
  if (!res.success) {
    tbody.innerHTML =
      `<tr><td colspan="6"><div class="alert alert-error" style="margin:16px">${res.error}</div></td></tr>`;
    return;
  }

  const members = res.users || [];

  // Рахуємо по ролях
  const cnt = { owner:0, manager:0, trainer:0 };
  members.forEach(m => { if (cnt[m.role_slug] !== undefined) cnt[m.role_slug]++; });

  // Картки ролей з поясненнями
  document.getElementById('role-summary').innerHTML = [
    ['owner',   '#c4b5fd', 'Власники',   'Повний доступ'],
    ['manager', 'var(--success)', 'Менеджери', 'Клієнти, абонементи, каса'],
    ['trainer', 'var(--warning)', 'Тренери',   'Свої клієнти і заняття'],
    [null,       'var(--accent)',  'Всього',    members.length + ' осіб'],
  ].map(([slug, color, label, sub]) => `
    <div class="role-card">
      <div class="role-card-count" style="color:${color}">
        ${slug ? cnt[slug] : members.length}
      </div>
      <div class="role-card-label">${label}</div>
      <div style="font-size:11px;color:var(--text-muted);margin-top:2px">${sub}</div>
    </div>`).join('');

  // Ліміт
  const limit = res.plan_limit;
  if (limit !== null && limit !== undefined) {
    const limitEl = document.getElementById('limit-warning');
    if (members.length >= limit) {
      document.getElementById('limit-text').textContent =
        `Ліміт співробітників (${limit}) досягнуто`;
      limitEl.style.display = 'flex';
      document.getElementById('btn-invite').disabled = true;
    } else {
      limitEl.style.display = 'none';
      document.getElementById('btn-invite').disabled = false;
    }
  }

  if (!members.length) {
    tbody.innerHTML = `<tr><td colspan="6">
      <div class="empty-state" style="padding:40px">
        <div style="font-size:40px;margin-bottom:12px">&#128101;</div>
        <h3>Команда порожня</h3>
        <p style="color:var(--text-muted);margin-top:6px">
          ${S.myLevel >= 80
            ? 'Натисніть "+ Запросити співробітника" щоб додати першого члена команди'
            : 'Зверніться до власника клубу'}
        </p>
      </div>
    </td></tr>`;
    return;
  }

  tbody.innerHTML = members.map(m => renderMemberRow(m)).join('');
}

function renderMemberRow(m) {
  const isMe = m.id === S.myUserId;

  const roleColors = {
    owner:   ['#c4b5fd', 'Власник',   'Повний доступ'],
    manager: ['var(--success)', 'Менеджер', 'Клієнти · абонементи · каса'],
    trainer: ['var(--warning)', 'Тренер',   'Свої клієнти'],
  };
  const [rColor, rLabel, rDesc] = roleColors[m.role_slug] || ['var(--accent)', m.role_name, ''];

  const statusBadge = m.club_access != 0
    ? '<span class="badge badge-active">Активний</span>'
    : '<span class="badge badge-inactive">Призупинено</span>';

  let actions = '';
  if (S.myLevel >= 80 && !isMe) {
    actions = `
      <button class="btn btn-ghost btn-sm"
              onclick="openRoleModal(${m.id},'${esc(m.full_name)}',${m.role_id})"
              title="Змінити роль">&#9998;</button>
      <button class="btn btn-ghost btn-sm"
              onclick="toggleAccess(${m.id})"
              title="${m.club_access != 0 ? 'Призупинити' : 'Відновити'}"
              style="${m.club_access != 0 ? '' : 'color:var(--success)'}">
        ${m.club_access != 0 ? '&#128683;' : '&#9989;'}
      </button>
      <button class="btn btn-ghost btn-sm" style="color:var(--danger)"
              onclick="openRemoveModal(${m.id},'${esc(m.full_name)}')"
              title="Прибрати з команди">&#128465;</button>`;
  }

  return `
    <tr class="${m.club_access == 0 ? 'staff-inactive' : ''}">
      <td>
        <div class="user-info">
          <div class="user-avatar ${m.role_slug}">${getInitials(m.full_name)}</div>
          <div>
            <div class="user-name">
              ${esc(m.full_name)}
              ${isMe ? '<span style="font-size:11px;color:var(--text-muted);margin-left:6px">(ви)</span>' : ''}
            </div>
            <div class="user-email">${esc(m.email)}</div>
          </div>
        </div>
      </td>
      <td>
        <span style="font-weight:600;color:${rColor}">${rLabel}</span>
      </td>
      <td style="font-size:12px;color:var(--text-muted)">${rDesc}</td>
      <td>${statusBadge}</td>
      <td style="font-size:13px;color:var(--text-secondary)">
        ${m.last_login_at ? formatDate(m.last_login_at) : 'Ніколи'}
      </td>
      <td onclick="event.stopPropagation()" style="white-space:nowrap">${actions}</td>
    </tr>`;
}

// ── Запрошення ────────────────────────────────────────────
function openInviteModal() {
  document.getElementById('invite-error').style.display   = 'none';
  document.getElementById('invite-success').style.display = 'none';
  document.getElementById('invite-form').style.display    = 'block';
  ['inv-name','inv-email','inv-phone'].forEach(id =>
    document.getElementById(id).value = '');
  document.getElementById('inv-role').value = '';
  document.getElementById('role-desc').textContent = '';
  openModal('modal-invite');
  document.getElementById('inv-name').focus();
}

function updateRoleDesc() {
  const sel = document.getElementById('inv-role');
  const opt = sel.options[sel.selectedIndex];
  const slug = opt?.dataset?.slug || '';
  const descs = {
    owner:   'Повний доступ до клубу. Може запрошувати інших.',
    manager: 'Реєструє клієнтів, продає абонементи, працює з касою.',
    trainer: 'Бачить своїх клієнтів і розклад. Обмежений доступ.',
  };
  document.getElementById('role-desc').textContent = descs[slug] || '';
}

async function submitInvite() {
  const errEl = document.getElementById('invite-error');
  errEl.style.display = 'none';
  const btn = document.getElementById('btn-invite-ok');
  btn.disabled = true; btn.textContent = 'Надсилаємо...';

  const name  = document.getElementById('inv-name').value.trim();
  const email = document.getElementById('inv-email').value.trim();
  const phone = document.getElementById('inv-phone').value.trim();

  const roleOpt  = document.getElementById('inv-role');
  const roleSlug = roleOpt.options[roleOpt.selectedIndex]?.dataset?.slug || '';

  if (!name)      { showInvErr("Введіть ім'я"); return; }
  if (!email)     { showInvErr('Введіть email'); return; }
  if (!roleSlug)  { showInvErr('Оберіть роль'); return; }

  const res = await api('invite', { full_name:name, email, phone, role_slug:roleSlug }, 'users');
  btn.disabled = false; btn.textContent = 'Надіслати запрошення';

  if (res.success) {
    document.getElementById('invite-form').style.display    = 'none';
    document.getElementById('invite-success').style.display = 'block';
    if (res.tmp_pwd) {
      document.getElementById('tmp-pwd-wrap').style.display = 'block';
      document.getElementById('tmp-pwd-val').textContent    = res.tmp_pwd;
    }
  } else {
    showInvErr(res.error);
  }
}

function showInvErr(msg) {
  const el = document.getElementById('invite-error');
  el.textContent = msg; el.style.display = 'block';
}

// ── Роль ─────────────────────────────────────────────────
function openRoleModal(userId, name, currentRoleId) {
  S.editUserId = userId;
  document.getElementById('role-modal-name').textContent = name;
  document.getElementById('role-error').style.display    = 'none';
  fillRoleSelect('role-select', false, currentRoleId);
  openModal('modal-role');
}

async function saveRole() {
  const sel = document.getElementById('role-select');
  const roleSlug = sel.options[sel.selectedIndex]?.dataset?.slug || '';
  if (!roleSlug) return;
  const res = await api('update_role', { user_id:S.editUserId, role_slug:roleSlug }, 'users');
  if (res.success) { closeModal('modal-role'); toast('Роль змінено', 'success'); loadTeam(); }
  else { document.getElementById('role-error').textContent=res.error; document.getElementById('role-error').style.display='block'; }
}

// ── Доступ ────────────────────────────────────────────────
async function toggleAccess(userId) {
  const res = await api('toggle_access', { user_id:userId }, 'users');
  if (res.success) { toast(res.message, 'success'); loadTeam(); }
  else toast(res.error, 'error');
}

// ── Видалення ─────────────────────────────────────────────
function openRemoveModal(userId, name) {
  S.removeUserId = userId;
  document.getElementById('remove-name').textContent = name;
  openModal('modal-remove');
}

async function confirmRemove() {
  const res = await api('remove', { user_id:S.removeUserId }, 'users');
  closeModal('modal-remove');
  if (res.success) { toast('Прибрано з команди', 'success'); loadTeam(); }
  else toast(res.error, 'error');
}

// ── Профіль ───────────────────────────────────────────────
async function saveProfile() {
  const el = document.getElementById('profile-msg');
  el.style.display = 'none';
  const res = await api('update_profile', {
    full_name: document.getElementById('p-name').value.trim(),
    phone:     document.getElementById('p-phone').value.trim(),
  }, 'settings');
  if (res.success) {
    el.className = 'alert alert-success';
    el.textContent = 'Збережено';
    el.style.display = 'block';
    const name = document.getElementById('p-name').value.trim();
    document.getElementById('user-name').textContent   = name;
    document.getElementById('user-avatar').textContent = getInitials(name);
    setTimeout(() => el.style.display='none', 3000);
  } else {
    el.className = 'alert alert-error';
    el.textContent = res.error;
    el.style.display = 'block';
  }
}

// ── Пароль ────────────────────────────────────────────────
function updatePwdStrength() {
  const pwd = document.getElementById('pwd-new').value;
  const bar = document.getElementById('pwd-bar');
  const hint= document.getElementById('pwd-hint');
  let sc = 0;
  if (pwd.length>=8)           sc++;
  if (pwd.length>=12)          sc++;
  if (/[A-Z]/.test(pwd))      sc++;
  if (/[0-9]/.test(pwd))      sc++;
  if (/[^A-Za-z0-9]/.test(pwd))sc++;
  const lvl=[
    {w:'0',bg:'transparent',  t:'Мінімум 8 символів'},
    {w:'25%',bg:'var(--danger)',  t:'Слабкий'},
    {w:'50%',bg:'var(--warning)', t:'Середній'},
    {w:'75%',bg:'var(--info)',    t:'Гарний'},
    {w:'90%',bg:'var(--success)', t:'Сильний'},
    {w:'100%',bg:'var(--success)',t:'Відмінний'},
  ][Math.min(sc,5)];
  bar.style.width=lvl.w; bar.style.background=lvl.bg;
  hint.textContent=lvl.t;
}

async function changePassword() {
  const el = document.getElementById('pwd-msg');
  el.style.display = 'none';
  const cur=document.getElementById('pwd-cur').value;
  const nw =document.getElementById('pwd-new').value;
  const rep=document.getElementById('pwd-rep').value;
  const show=(msg,ok=false)=>{
    el.className='alert '+(ok?'alert-success':'alert-error');
    el.textContent=msg; el.style.display='block';
  };
  if (!cur)          { show('Введіть поточний пароль'); return; }
  if (nw.length < 8) { show('Мінімум 8 символів'); return; }
  if (nw !== rep)    { show('Паролі не збігаються'); return; }
  const res = await api('change_password',
    {current_password:cur,new_password:nw,new_password2:rep}, 'users');
  if (res.success) {
    show('Пароль змінено. Інші сесії завершено.', true);
    ['pwd-cur','pwd-new','pwd-rep'].forEach(id=>document.getElementById(id).value='');
    document.getElementById('pwd-bar').style.width='0';
  } else show(res.error);
}

// ── Вкладки ───────────────────────────────────────────────
function switchTab(tab, btn) {
  document.querySelectorAll('.page-tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.page-tab-content').forEach(c => c.style.display='none');
  btn.classList.add('active');
  document.getElementById('tab-'+tab).style.display = 'block';
}

// ── Утиліти ───────────────────────────────────────────────
function fillRoleSelect(id, addPlaceholder=false, selected=null) {
  const sel = document.getElementById(id);
  if (!sel) return;
  sel.innerHTML = (addPlaceholder ? '<option value="">— Оберіть роль —</option>' : '') +
    S.roles.map(r =>
      `<option value="${r.id}" data-slug="${r.slug}"
        ${r.id==selected?'selected':''}>${r.name_ua}</option>`
    ).join('');
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
</script>
</body>
