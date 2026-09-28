<?php
$pageTitle = 'Налаштування';
$pageCss   = 'settings';
require __DIR__ . '/../partials/head.php';
?>
<body>
<div class="app-layout">

  <!-- aside і header НЕ пишемо — sidebar.js вставить їх сам -->
  <main class="app-main">
    <div class="page-header"></div>

    <div id="settings-loading" class="loader" style="padding:60px 0">
      <div class="spinner"></div> Завантаження...
    </div>

    <div id="settings-wrap" style="display:none">

      <!-- Вкладки (тільки для SuperAdmin) -->
      <div id="tab-bar" style="display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:24px"></div>

      <!-- ══ ПРОФІЛЬ ══ -->
      <div class="settings-tab-pane active" id="pane-profile">
        <div class="settings-grid">

          <div class="settings-section">
            <div class="settings-section-title"><span class="icon">👤</span> Мій профіль</div>
            <div id="profile-msg" class="alert" style="display:none"></div>
            <div class="form-group"><label>Ім'я та прізвище *</label>
              <input type="text" id="p-name" placeholder="Іван Петренко"></div>
            <div class="form-group"><label>Email</label>
              <input type="email" id="p-email" disabled style="opacity:.5;cursor:not-allowed"></div>
            <div class="form-group"><label>Телефон</label>
              <input type="tel" id="p-phone" placeholder="+38 067 123 45 67"></div>
            <div style="display:flex;align-items:center;gap:16px">
              <button class="btn btn-primary" onclick="saveProfile()">Зберегти</button>
              <span class="save-indicator" id="profile-saved">✓ Збережено</span>
            </div>
          </div>

          <div class="settings-section">
            <div class="settings-section-title"><span class="icon">🔒</span> Зміна пароля</div>
            <div id="pwd-msg" class="alert" style="display:none"></div>
            <div class="form-group"><label>Поточний пароль</label>
              <input type="password" id="pwd-cur" autocomplete="current-password"></div>
            <div class="form-group"><label>Новий пароль</label>
              <input type="password" id="pwd-new" placeholder="Мін. 8 символів"
                     autocomplete="new-password" oninput="checkStrength()">
              <div style="height:3px;background:var(--border);border-radius:2px;margin-top:6px;overflow:hidden">
                <div id="pwd-bar" style="height:100%;width:0;border-radius:2px;transition:width .3s,background .3s"></div>
              </div>
              <div id="pwd-hint" style="font-size:11px;color:var(--text-muted);margin-top:3px">Мінімум 8 символів</div>
            </div>
            <div class="form-group"><label>Повторіть</label>
              <input type="password" id="pwd-rep" autocomplete="new-password"></div>
            <div style="display:flex;align-items:center;gap:16px">
              <button class="btn btn-ghost" onclick="changePassword()">Змінити</button>
              <span class="save-indicator" id="pwd-saved">✓ Змінено</span>
            </div>
          </div>

          <!-- Клуб (тільки якщо є активний клуб) -->
          <div class="settings-section" id="club-section" style="display:none">
            <div class="settings-section-title"><span class="icon">🏙</span> Профіль клубу</div>
            <div id="club-err" class="alert alert-error" style="display:none"></div>
            <div class="form-group"><label>Назва *</label>
              <input type="text" id="c-name"></div>
            <div class="settings-fields-2">
              <div class="form-group"><label>Місто</label><input type="text" id="c-city"></div>
              <div class="form-group"><label>Телефон</label><input type="tel" id="c-phone"></div>
            </div>
            <div class="form-group"><label>Email</label><input type="email" id="c-email"></div>
            <div class="form-group"><label>Адреса</label><input type="text" id="c-address"></div>
            <div class="settings-fields-2">
              <div class="form-group"><label>Часовий пояс</label>
                <select id="c-tz">
                  <option value="Europe/Kyiv">Europe/Kyiv (UTC+2/+3)</option>
                  <option value="Europe/Warsaw">Europe/Warsaw</option>
                  <option value="UTC">UTC</option>
                </select>
              </div>
              <div class="form-group"><label>Валюта</label>
                <select id="c-cur">
                  <option value="UAH">UAH</option>
                  <option value="USD">USD</option>
                  <option value="EUR">EUR</option>
                </select>
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:16px">
              <button class="btn btn-primary" onclick="saveClub()">Зберегти клуб</button>
              <span class="save-indicator" id="club-saved">✓ Збережено</span>
            </div>
          </div>

          <!-- Підписка -->
          <div class="settings-section" id="sub-section" style="display:none">
            <div class="settings-section-title"><span class="icon">💳</span> Підписка</div>
            <div id="sub-info"><div class="loader"><div class="spinner"></div></div></div>
          </div>

        </div>
      </div>

      <!-- ══ sys_roles ══ -->
      <div class="settings-tab-pane" id="pane-roles" style="display:none">
        <div class="card">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
            <div class="card-title" style="margin:0">sys_roles — Ролі системи</div>
          </div>
          <div id="roles-tbody-wrap">
            <div class="table-wrap">
              <table>
                <thead><tr><th>ID</th><th>Slug</th><th>Назва (укр.)</th><th>Рівень</th><th></th></tr></thead>
                <tbody id="roles-tbody"><tr><td colspan="5"><div class="loader"><div class="spinner"></div></div></td></tr></tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- ══ sys_users ══ -->
      <div class="settings-tab-pane" id="pane-users" style="display:none">
        <div style="display:flex;gap:10px;margin-bottom:14px">
          <div style="position:relative;flex:1;max-width:300px">
            <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted)">🔍</span>
            <input type="text" id="su-search" placeholder="Ім'я або email..."
                   style="padding-left:32px" oninput="scheduleUserSearch()">
          </div>
          <button class="btn btn-primary btn-sm" onclick="openUserModal(null)">+ Новий</button>
        </div>
        <div class="card" style="padding:0;overflow:hidden">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Користувач</th><th>Глобальна роль</th><th>Клубів</th><th>Статус</th><th>Вхід</th><th></th></tr></thead>
              <tbody id="su-tbody"><tr><td colspan="6"><div class="loader"><div class="spinner"></div></div></td></tr></tbody>
            </table>
          </div>
          <div id="su-pagination" style="padding:12px 20px"></div>
        </div>
      </div>

      <!-- ══ sys_user_clubs ══ -->
      <div class="settings-tab-pane" id="pane-uc" style="display:none">
        <div style="display:flex;gap:10px;margin-bottom:14px">
          <select id="uc-club-filter" style="width:auto;min-width:180px" onchange="loadUserClubs()">
            <option value="">Всі клуби</option>
          </select>
          <button class="btn btn-primary btn-sm" onclick="openUCModal(null)">+ Прив'язати</button>
        </div>
        <div class="card" style="padding:0;overflow:hidden">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Користувач</th><th>Клуб</th><th>Роль</th><th>Доступ</th><th>Додано</th><th></th></tr></thead>
              <tbody id="uc-tbody"><tr><td colspan="6"><div class="loader"><div class="spinner"></div></div></td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </main>
</div>

<!-- ════ МОДАЛКА: РЕДАГУВАННЯ РОЛІ ════ -->
<div class="modal-overlay" id="modal-role">
  <div class="modal" style="max-width:380px">
    <button class="modal-close" onclick="closeModal('modal-role')">✕</button>
    <h2 class="modal-title">Редагувати роль</h2>
    <div id="role-err" class="alert alert-error" style="display:none"></div>
    <div style="padding:10px 14px;background:var(--bg-elevated);border-radius:var(--radius-sm);margin-bottom:16px;font-size:13px">
      <div style="display:flex;justify-content:space-between;padding:4px 0">
        <span style="color:var(--text-secondary)">Slug</span>
        <code id="role-slug" style="color:var(--accent)">—</code>
      </div>
      <div style="display:flex;justify-content:space-between;padding:4px 0">
        <span style="color:var(--text-secondary)">Рівень доступу</span>
        <span id="role-level">—</span>
      </div>
    </div>
    <div class="form-group"><label>Назва (укр.) *</label>
      <input type="text" id="role-name-ua" placeholder="Менеджер"></div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" onclick="saveRole()">Зберегти</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-role')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: РЕДАГУВАННЯ КОРИСТУВАЧА ════ -->
<div class="modal-overlay" id="modal-user">
  <div class="modal" style="max-width:460px">
    <button class="modal-close" onclick="closeModal('modal-user')">✕</button>
    <h2 class="modal-title" id="user-modal-title">Редагувати користувача</h2>
    <div id="user-err" class="alert alert-error" style="display:none"></div>
    <div class="form-group"><label>Ім'я та прізвище *</label>
      <input type="text" id="um-name" placeholder="Іван Петренко"></div>
    <div class="form-group"><label>Email *</label>
      <input type="email" id="um-email" placeholder="user@example.com"></div>
    <div class="form-group"><label>Телефон</label>
      <input type="tel" id="um-phone" placeholder="+38 067..."></div>
    <div class="form-group"><label>Глобальна роль</label>
      <select id="um-role">
        <option value="">— Без ролі —</option>
        <option value="1">SuperAdmin (100)</option>
        <option value="2">Owner (80)</option>
        <option value="3">Manager (50)</option>
        <option value="4">Trainer (30)</option>
      </select>
    </div>
    <div id="um-pwd-wrap" style="display:none">
      <div class="form-group"><label>Пароль *</label>
        <input type="password" id="um-pwd" placeholder="Мін. 8 символів" autocomplete="new-password"></div>
    </div>
    <div class="form-group" style="display:flex;align-items:center;gap:10px">
      <input type="checkbox" id="um-active" checked>
      <label for="um-active" style="margin:0;font-weight:400">Активний</label>
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" onclick="saveUser()">Зберегти</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-user')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: ПРИВ'ЯЗКА USER → CLUB ════ -->
<div class="modal-overlay" id="modal-uc">
  <div class="modal" style="max-width:420px">
    <button class="modal-close" onclick="closeModal('modal-uc')">✕</button>
    <h2 class="modal-title" id="uc-modal-title">Прив'язати до клубу</h2>
    <div id="uc-err" class="alert alert-error" style="display:none"></div>
    <div class="form-group"><label>Користувач *</label>
      <select id="uc-user"></select>
    </div>
    <div class="form-group"><label>Клуб *</label>
      <select id="uc-club"></select>
    </div>
    <div class="form-group"><label>Роль *</label>
      <select id="uc-role"></select>
    </div>
    <div class="form-group" style="display:flex;align-items:center;gap:10px">
      <input type="checkbox" id="uc-active" checked>
      <label for="uc-active" style="margin:0;font-weight:400">Активний доступ</label>
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" onclick="saveUC()">Зберегти</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-uc')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: ПІДТВЕРДЖЕННЯ ВИДАЛЕННЯ ════ -->
<div class="modal-overlay" id="modal-confirm">
  <div class="modal" style="max-width:360px">
    <h2 class="modal-title">Підтвердити дію</h2>
    <p style="font-size:14px;color:var(--text-secondary);margin-bottom:20px" id="confirm-text">—</p>
    <div style="display:flex;gap:10px">
      <button class="btn btn-danger" id="confirm-ok">Підтвердити</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-confirm')">Скасувати</button>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../partials/scripts.php'; ?>
<script>
let S = {
  isSA: false, currentClubId: null,
  editRoleId: null, editUserId: null, editUcId: null,
  userSearchTimer: null, suPage: 1,
  allUsers: [], allClubs: [], allRoles: [],
};

// ── Ініціалізація ─────────────────────────────────────────
window.addEventListener('DOMContentLoaded', async () => {
  const ctx = await initPage({ title: 'Налаштування' });
  if (!ctx) return;
  guardBlockedClub(ctx.isBlocked);

  const { u, isSuperAdmin, club, clubRole } = ctx;
  S.isSA = isSuperAdmin;
  S.currentClubId = club?.id || null;

  const roleLevel   = isSuperAdmin ? 100 : (clubRole?.level ?? 0);
  const isOwnerPlus = roleLevel >= 80;

  // Заповнюємо профіль
  document.getElementById('p-name').value  = u.full_name || '';
  document.getElementById('p-email').value = u.email     || '';

  // Клуб і підписка — тільки для owner+ (менеджер і нижче — лише свій профіль)
  if (S.currentClubId && isOwnerPlus) {
    document.getElementById('club-section').style.display = 'block';
    document.getElementById('sub-section').style.display  = 'block';
    loadClub(); loadSub();
  }

  // Вкладки SuperAdmin
  buildTabs(isSuperAdmin);

  document.getElementById('settings-loading').style.display = 'none';
  document.getElementById('settings-wrap').style.display    = 'block';
});

// ── Вкладки ────────────────────────────────────────────────
function buildTabs(isSA) {
  const bar = document.getElementById('tab-bar');
  const tabs = [{ id:'profile', label:'👤 Профіль' }];
  if (isSA) {
    tabs.push(
      { id:'roles',  label:'🔑 Ролі доступу' },
      { id:'users',  label:'👥 Користувачі' },
      { id:'uc',     label:'🔗 Прив\'язки до клубів' }
    );
  }
  if (tabs.length === 1) { bar.style.display='none'; return; }

  bar.innerHTML = tabs.map((t,i) => `
    <button class="fin-tab-btn ${i===0?'active':''}"
            onclick="switchTab('${t.id}',this)">${t.label}</button>`).join('');
  document.getElementById('pane-profile').style.display = 'block';
}

function switchTab(id, btn) {
  document.querySelectorAll('.fin-tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.settings-tab-pane').forEach(p => p.style.display='none');
  btn.classList.add('active');
  document.getElementById('pane-'+id).style.display = 'block';
  if (id==='roles' && !document.getElementById('roles-tbody').dataset.loaded) loadRoles();
  if (id==='users' && !document.getElementById('su-tbody').dataset.loaded) loadSysUsers();
  if (id==='uc'    && !document.getElementById('uc-tbody').dataset.loaded)  loadUserClubs();
}

// ════════════════════════════════════════════════════════════
// ПРОФІЛЬ
// ════════════════════════════════════════════════════════════
async function saveProfile() {
  const el = document.getElementById('profile-msg');
  el.style.display='none';
  const res = await api('update_profile', {
    full_name: document.getElementById('p-name').value.trim(),
    phone:     document.getElementById('p-phone').value.trim(),
  }, 'settings');
  if (res.success) {
    showSaved('profile-saved');
    const name = document.getElementById('p-name').value.trim();
    document.getElementById('user-name').textContent   = name;
    document.getElementById('user-avatar').textContent = getInitials(name);
  } else { el.className='alert alert-error'; el.textContent=res.error; el.style.display='block'; }
}

function checkStrength() {
  const pwd=document.getElementById('pwd-new').value;
  const bar=document.getElementById('pwd-bar');
  const hint=document.getElementById('pwd-hint');
  let sc=0;
  if(pwd.length>=8)sc++;if(pwd.length>=12)sc++;
  if(/[A-Z]/.test(pwd))sc++;if(/[0-9]/.test(pwd))sc++;if(/[^A-Za-z0-9]/.test(pwd))sc++;
  const lv=[
    {w:'0',bg:'transparent',t:'Мінімум 8 символів'},
    {w:'25%',bg:'var(--danger)',t:'Слабкий'},
    {w:'50%',bg:'var(--warning)',t:'Середній'},
    {w:'75%',bg:'var(--info)',t:'Гарний'},
    {w:'90%',bg:'var(--success)',t:'Сильний'},
    {w:'100%',bg:'var(--success)',t:'Відмінний'},
  ][Math.min(sc,5)];
  bar.style.width=lv.w;bar.style.background=lv.bg;hint.textContent=lv.t;
}

async function changePassword() {
  const el=document.getElementById('pwd-msg');
  el.style.display='none';
  const cur=document.getElementById('pwd-cur').value;
  const nw=document.getElementById('pwd-new').value;
  const rep=document.getElementById('pwd-rep').value;
  if(!cur){showMsg('pwd-msg','Введіть поточний пароль','error');return;}
  if(nw.length<8){showMsg('pwd-msg','Мінімум 8 символів','error');return;}
  if(nw!==rep){showMsg('pwd-msg','Паролі не збігаються','error');return;}
  const res=await api('change_password',{current_password:cur,new_password:nw,new_password2:rep},'users');
  if(res.success){showSaved('pwd-saved');['pwd-cur','pwd-new','pwd-rep'].forEach(id=>document.getElementById(id).value='');}
  else showMsg('pwd-msg',res.error,'error');
}

// ════════════════════════════════════════════════════════════
// КЛУБ
// ════════════════════════════════════════════════════════════
async function loadClub() {
  const res=await api('get_club',{},'settings');
  if(!res.success)return;
  const c=res.club;
  document.getElementById('c-name').value   =c.name||'';
  document.getElementById('c-city').value   =c.city||'';
  document.getElementById('c-phone').value  =c.phone||'';
  document.getElementById('c-email').value  =c.email||'';
  document.getElementById('c-address').value=c.address||'';
  document.getElementById('c-tz').value     =c.timezone||'Europe/Kyiv';
  document.getElementById('c-cur').value    =c.currency||'UAH';
}

async function saveClub() {
  document.getElementById('club-err').style.display='none';
  const res=await api('update_club',{
    name:    document.getElementById('c-name').value.trim(),
    city:    document.getElementById('c-city').value.trim(),
    phone:   document.getElementById('c-phone').value.trim(),
    email:   document.getElementById('c-email').value.trim(),
    address: document.getElementById('c-address').value.trim(),
    timezone:document.getElementById('c-tz').value,
    currency:document.getElementById('c-cur').value,
  },'settings');
  if(res.success) showSaved('club-saved');
  else { document.getElementById('club-err').textContent=res.error; document.getElementById('club-err').style.display='block'; }
}

async function loadSub() {
  const res=await api('get_my_billing',{},'billing');
  const el=document.getElementById('sub-info');
  if(!res.success){el.innerHTML=`<div style="font-size:13px;color:var(--text-muted)">${res.error||'—'}</div>`;return;}
  const s=res.subscription;
  const sLabel={trial:'Тріал',active:'Активна',trial_expired:'Закінчився',past_due:'Прострочено'};
  const sClass={trial:'badge-info',active:'badge-active',trial_expired:'badge-inactive',past_due:'badge-pending'};
  el.innerHTML=`
    <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:14px">
      <span style="color:var(--text-secondary)">План</span>
      <strong>${esc(s.plan_name||'—')}</strong>
    </div>
    <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:14px">
      <span style="color:var(--text-secondary)">Статус</span>
      <span class="badge ${sClass[s.status]||'badge-info'}">${sLabel[s.status]||s.status}</span>
    </div>
    <div style="display:flex;justify-content:space-between;padding:8px 0;font-size:14px">
      <span style="color:var(--text-secondary)">Клієнтів</span>
      <span>${s.clients_used} / ${s.clients_limit||'∞'}</span>
    </div>
    <a href="/billing" class="btn btn-ghost btn-sm" style="margin-top:12px;width:100%;justify-content:center">
      Керувати підпискою →
    </a>`;
}

// ════════════════════════════════════════════════════════════
// SYS_ROLES
// ════════════════════════════════════════════════════════════
async function loadRoles() {
  document.getElementById('roles-tbody').dataset.loaded='1';
  const res=await api('get_sys_roles',{},'settings');
  if(!res.success)return;
  S.allRoles=res.roles;
  document.getElementById('roles-tbody').innerHTML=res.roles.map(r=>`
    <tr>
      <td style="color:var(--text-muted)">${r.id}</td>
      <td><code style="background:var(--bg-elevated);padding:2px 6px;border-radius:4px;font-size:12px">${esc(r.slug)}</code></td>
      <td style="font-weight:500">${esc(r.name_ua)}</td>
      <td>${r.level}</td>
      <td>
        <button class="btn btn-ghost btn-sm" onclick="openRoleModal(${r.id},'${esc(r.slug)}',${r.level},'${esc(r.name_ua)}')">
          ✎ Редагувати
        </button>
      </td>
    </tr>`).join('');
}

function openRoleModal(id,slug,level,nameUa) {
  S.editRoleId=id;
  document.getElementById('role-slug').textContent =slug;
  document.getElementById('role-level').textContent=level;
  document.getElementById('role-name-ua').value    =nameUa;
  document.getElementById('role-err').style.display='none';
  openModal('modal-role');
  document.getElementById('role-name-ua').focus();
}

async function saveRole() {
  const name=document.getElementById('role-name-ua').value.trim();
  if(!name){document.getElementById('role-err').textContent='Введіть назву';document.getElementById('role-err').style.display='block';return;}
  const res=await api('update_sys_role',{id:S.editRoleId,name_ua:name},'settings');
  if(res.success){closeModal('modal-role');toast('Збережено','success');document.getElementById('roles-tbody').dataset.loaded='';loadRoles();}
  else{document.getElementById('role-err').textContent=res.error;document.getElementById('role-err').style.display='block';}
}

// ════════════════════════════════════════════════════════════
// SYS_USERS
// ════════════════════════════════════════════════════════════
async function loadSysUsers(page=1) {
  S.suPage=page;
  document.getElementById('su-tbody').dataset.loaded='1';
  const tbody=document.getElementById('su-tbody');
  tbody.innerHTML='<tr><td colspan="6"><div class="loader"><div class="spinner"></div></div></td></tr>';

  const res=await api('get_sys_users',{search:document.getElementById('su-search').value.trim(),page},'settings');
  if(!res.success)return;

  S.allUsers=res.users||[];
  const {users,pagination}=res;

  tbody.innerHTML=users.map(u=>`
    <tr class="${u.is_active?'':'staff-inactive'}">
      <td><div style="font-weight:500">${esc(u.full_name)}</div>
          <div style="font-size:12px;color:var(--text-muted)">${esc(u.email)}</div></td>
      <td>${u.global_role?`<span class="badge badge-${u.global_role}">${esc(u.global_role_name||u.global_role)}</span>`:'<span style="color:var(--text-muted);font-size:12px">—</span>'}</td>
      <td style="font-size:14px">${u.clubs_count}</td>
      <td><span class="badge ${u.is_active?'badge-active':'badge-inactive'}">${u.is_active?'Активний':'Вимкнено'}</span></td>
      <td style="font-size:13px;color:var(--text-secondary)">${u.last_login_at?formatDate(u.last_login_at):'—'}</td>
      <td style="white-space:nowrap">
        <button class="btn btn-ghost btn-sm" onclick="openUserModal(${u.id})">✎</button>
        <button class="btn btn-ghost btn-sm" style="${u.is_active?'color:var(--warning)':'color:var(--success)'}"
                onclick="toggleSysUser(${u.id})">${u.is_active?'🚫':'✅'}</button>
      </td>
    </tr>`).join('');

  const pgEl=document.getElementById('su-pagination');
  pgEl.innerHTML=pagination.pages>1?`
    <div class="pagination">
      <span>${pagination.total} користувачів</span>
      <div class="pagination-btns">
        <button class="page-btn" onclick="loadSysUsers(${page-1})" ${page<=1?'disabled':''}>‹</button>
        <button class="page-btn active">${page}/${pagination.pages}</button>
        <button class="page-btn" onclick="loadSysUsers(${page+1})" ${page>=pagination.pages?'disabled':''}>›</button>
      </div>
    </div>`:`<div style="font-size:13px;color:var(--text-muted)">${pagination.total} користувачів</div>`;
}

function scheduleUserSearch(){
  clearTimeout(S.userSearchTimer);
  S.userSearchTimer=setTimeout(()=>loadSysUsers(1),350);
}

function openUserModal(id) {
  S.editUserId=id;
  document.getElementById('user-modal-title').textContent=id?'Редагувати користувача':'Новий користувач';
  document.getElementById('user-err').style.display='none';
  document.getElementById('um-pwd-wrap').style.display=id?'none':'block';

  if(id){
    const u=S.allUsers.find(x=>x.id==id);
    if(u){
      document.getElementById('um-name').value  =u.full_name||'';
      document.getElementById('um-email').value =u.email||'';
      document.getElementById('um-phone').value =u.phone||'';
      document.getElementById('um-active').checked=!!u.is_active;
    }
  } else {
    ['um-name','um-email','um-phone','um-pwd'].forEach(i=>document.getElementById(i).value='');
    document.getElementById('um-active').checked=true;
  }
  openModal('modal-user');
}

async function saveUser() {
  const errEl=document.getElementById('user-err');
  errEl.style.display='none';
  const payload={
    id:        S.editUserId,
    full_name: document.getElementById('um-name').value.trim(),
    email:     document.getElementById('um-email').value.trim(),
    phone:     document.getElementById('um-phone').value.trim(),
    global_role_id: document.getElementById('um-role').value||null,
    is_active: document.getElementById('um-active').checked?1:0,
  };
  if(!S.editUserId) payload.password=document.getElementById('um-pwd').value;

  // Якщо новий — використовуємо спеціальну дію
  const action=S.editUserId?'update_sys_user_profile':'create_sys_user';
  const res=await api(action,payload,'settings');
  if(res.success){closeModal('modal-user');toast(S.editUserId?'Збережено':'Створено','success');loadSysUsers(S.suPage);}
  else{errEl.textContent=res.error;errEl.style.display='block';}
}

async function toggleSysUser(id) {
  const res=await api('toggle_sys_user',{id},'settings');
  if(res.success){toast(res.message,'success');loadSysUsers(S.suPage);}
  else toast(res.error,'error');
}

// ════════════════════════════════════════════════════════════
// SYS_USER_CLUBS
// ════════════════════════════════════════════════════════════
async function loadUserClubs() {
  document.getElementById('uc-tbody').dataset.loaded='1';
  const tbody=document.getElementById('uc-tbody');
  tbody.innerHTML='<tr><td colspan="6"><div class="loader"><div class="spinner"></div></div></td></tr>';

  const clubId=document.getElementById('uc-club-filter').value||0;
  const res=await api('get_sys_user_clubs',{club_id:clubId},'settings');
  if(!res.success)return;

  tbody.innerHTML=res.links.map(l=>`
    <tr class="${l.is_active?'':'staff-inactive'}">
      <td><div style="font-weight:500">${esc(l.full_name)}</div>
          <div style="font-size:12px;color:var(--text-muted)">${esc(l.email)}</div></td>
      <td style="font-weight:500">${esc(l.club_name)}</td>
      <td><span style="font-weight:600;color:${
        l.role_slug==='owner'?'#c4b5fd':
        l.role_slug==='manager'?'var(--success)':'var(--warning)'
      }">${esc(l.role_name)}</span></td>
      <td><span class="badge ${l.is_active?'badge-active':'badge-inactive'}">${l.is_active?'Активний':'Вимкнено'}</span></td>
      <td style="font-size:13px;color:var(--text-secondary)">${formatDate(l.granted_at)}</td>
      <td style="white-space:nowrap">
        <button class="btn btn-ghost btn-sm" onclick="openUCModal(${l.id},${l.user_id},${l.club_id},${l.role_id||2},${l.is_active})">✎</button>
        <button class="btn btn-ghost btn-sm" style="color:var(--danger)"
                onclick="confirmAction('Видалити прив\'язку?',()=>deleteUC(${l.id}))">🗑</button>
      </td>
    </tr>`).join('');
}

async function openUCModal(id, userId=null, clubId=null, roleId=2, isActive=1) {
  S.editUcId=id;
  document.getElementById('uc-modal-title').textContent=id?'Редагувати прив\'язку':'Нова прив\'язку';
  document.getElementById('uc-err').style.display='none';
  document.getElementById('uc-active').checked=!!isActive;

  // Завантажуємо списки
  if(!S.allUsers.length){
    const r=await api('get_sys_users',{page:1},'settings');
    if(r.success) S.allUsers=r.users;
  }
  if(!S.allClubs.length){
    const r=await api('admin_list',{page:1},'billing');
    if(r.success) S.allClubs=r.clubs||[];
  }

  // Users
  document.getElementById('uc-user').innerHTML=S.allUsers.map(u=>
    `<option value="${u.id}" ${u.id==userId?'selected':''}>${esc(u.full_name)} (${esc(u.email)})</option>`).join('');

  // Clubs
  document.getElementById('uc-club').innerHTML=S.allClubs.map(c=>
    `<option value="${c.id}" ${c.id==clubId?'selected':''}>${esc(c.name)}</option>`).join('');

  // Roles
  if(!S.allRoles.length){
    const r=await api('get_sys_roles',{},'settings');
    if(r.success) S.allRoles=r.roles;
  }
  document.getElementById('uc-role').innerHTML=S.allRoles
    .filter(r=>r.slug!=='superadmin')
    .map(r=>`<option value="${r.id}" ${r.id==roleId?'selected':''}>${esc(r.name_ua)}</option>`).join('');

  openModal('modal-uc');
}

async function saveUC() {
  const errEl=document.getElementById('uc-err');
  errEl.style.display='none';
  const action=S.editUcId?'update_sys_user_club':'add_sys_user_club';
  const res=await api(action,{
    id:       S.editUcId,
    user_id:  parseInt(document.getElementById('uc-user').value),
    club_id:  parseInt(document.getElementById('uc-club').value),
    role_id:  parseInt(document.getElementById('uc-role').value),
    is_active:document.getElementById('uc-active').checked?1:0,
  },'settings');
  if(res.success){closeModal('modal-uc');toast('Збережено','success');loadUserClubs();}
  else{errEl.textContent=res.error;errEl.style.display='block';}
}

async function deleteUC(id) {
  const res=await api('remove_sys_user_club',{id},'settings');
  if(res.success){toast('Видалено','success');loadUserClubs();}
  else toast(res.error,'error');
}

// ════════════════════════════════════════════════════════════
// УТИЛІТИ
// ════════════════════════════════════════════════════════════
function confirmAction(text, fn) {
  document.getElementById('confirm-text').textContent=text;
  const btn=document.getElementById('confirm-ok');
  btn.onclick=()=>{closeModal('modal-confirm');fn();};
  openModal('modal-confirm');
}

function showSaved(id){
  const el=document.getElementById(id);
  el.classList.add('show');
  setTimeout(()=>el.classList.remove('show'),3000);
}

function showMsg(id,text,type='error'){
  const el=document.getElementById(id);
  el.className='alert alert-'+type;
  el.textContent=text;
  el.style.display='block';
}

function esc(s){
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
