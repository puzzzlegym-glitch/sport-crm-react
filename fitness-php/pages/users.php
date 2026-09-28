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
    <div class="page-header" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <!-- Іконка підказки -->
      <button id="btn-hint" class="btn btn-ghost btn-sm hint-toggle"
              onclick="toggleHint()" style="display:none;width:32px;height:32px;
              padding:0;border-radius:50%;font-size:15px" title="Що це за розділ?">
        &#63;
      </button>
    </div>

    <!-- Підказка (прихована, розкривається кліком на ?) -->
    <div id="section-hint" class="alert" style="
      background:rgba(79,156,249,.08);border:1px solid rgba(79,156,249,.2);
      color:var(--text-secondary);font-size:13px;margin-bottom:16px;display:none">
      <strong style="color:var(--accent)">Що це за розділ?</strong>
      Тут ви керуєте доступом ваших співробітників до клубу.
      Додайте менеджера — і він зможе реєструвати клієнтів та продавати абонементи.
      Додайте тренера — і він бачитиме своїх клієнтів.
    </div>

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
      <button class="page-tab-btn" id="btn-tab-payroll"
              onclick="switchTab('payroll',this);loadPayroll();loadPayrollSummary()" style="display:none">
        &#128176; Зарплата
      </button>
    </div>

    <!-- ══ ВКЛАДКА: КОМАНДА ══ -->
    <div class="page-tab-content active" id="tab-team">
      <div style="display:flex;justify-content:flex-end;margin-bottom:12px">
        <button class="btn btn-primary" id="btn-invite"
                onclick="openInviteModal()" style="display:none">
          + Запросити співробітника
        </button>
      </div>
      <div class="card" style="padding:0;overflow:hidden">
        <div class="table-wrap">
          <table>
            <thead class="mob-hide">
              <tr>
                <th>Співробітник</th>
                <th>Роль</th>
                <th class="mob-hide">Права</th>
                <th>Статус</th>
                <th class="mob-hide">Останній вхід</th>
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

    <!-- ══ ВКЛАДКА: ЗАРПЛАТА ══ -->
    <div class="page-tab-content" id="tab-payroll">

      <!-- Фільтри -->
      <div class="payroll-filter-bar">
        <select id="pr-user-filter" onchange="loadPayroll();loadPayrollSummary()">
          <option value="">Всі співробітники</option>
        </select>
        <input type="month" id="pr-month-filter"
               value="" onchange="loadPayroll();loadPayrollSummary()">
        <button class="btn btn-primary btn-sm" onclick="openCreatePayroll()">
          + Нарахувати
        </button>
      </div>

      <!-- Зведені картки -->
      <div class="payroll-summary-cards" id="pr-summary-cards">
        <div class="pr-card"><div class="pr-card-val" id="prc-total">—</div><div class="pr-card-label">Нараховано</div></div>
        <div class="pr-card"><div class="pr-card-val success" id="prc-paid">—</div><div class="pr-card-label">Виплачено</div></div>
        <div class="pr-card"><div class="pr-card-val warning" id="prc-pending">—</div><div class="pr-card-label">До виплати</div></div>
        <div class="pr-card"><div class="pr-card-val" id="prc-cnt">—</div><div class="pr-card-label">Записів</div></div>
      </div>

      <!-- Таблиця нарахувань -->
      <div class="card" style="padding:0;overflow:hidden">
        <div class="table-wrap">
          <table>
            <thead class="mob-hide">
              <tr>
                <th>Місяць</th>
                <th>Співробітник</th>
                <th>Оклад</th>
                <th>Бонус</th>
                <th>Всього</th>
                <th>Виплачено</th>
                <th>Статус</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="pr-tbody">
              <tr><td colspan="8"><div class="loader"><div class="spinner"></div></div></td></tr>
            </tbody>
          </table>
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
<!-- ════ МОДАЛКА: НАЛАШТУВАННЯ ЗАРПЛАТИ ════ -->
<div class="modal-overlay" id="modal-salary-settings">
  <div class="modal" style="max-width:520px">
    <button class="modal-close" onclick="closeModal('modal-salary-settings')">&#10005;</button>
    <h2 class="modal-title">&#128176; Налаштування зарплати</h2>
    <div style="font-size:13px;color:var(--text-secondary);margin-bottom:16px">
      Співробітник: <strong id="ss-user-name">—</strong>
    </div>
    <div id="ss-err" class="alert alert-error" style="display:none"></div>

    <!-- Компоненти нарахування -->
    <div class="salary-components">

      <div class="salary-row">
        <label class="salary-check-label">
          <input type="checkbox" id="ss-pay-month-on" onchange="ssSyncRow('month')">
          <span class="salary-label-icon">📅</span>
          Оклад / місяць
        </label>
        <div class="salary-input-wrap">
          <input type="number" id="ss-pay-month-amount" class="salary-amount-input"
                 min="0" step="1" placeholder="0" disabled>
          <span class="salary-unit">грн</span>
        </div>
      </div>

      <div class="salary-row">
        <label class="salary-check-label">
          <input type="checkbox" id="ss-pay-day-on" onchange="ssSyncRow('day')">
          <span class="salary-label-icon">🗓️</span>
          Ставка за день
        </label>
        <div class="salary-input-wrap">
          <input type="number" id="ss-pay-day-amount" class="salary-amount-input"
                 min="0" step="1" placeholder="0" disabled>
          <span class="salary-unit">грн</span>
        </div>
      </div>

      <div class="salary-row">
        <label class="salary-check-label">
          <input type="checkbox" id="ss-pay-hour-on" onchange="ssSyncRow('hour')">
          <span class="salary-label-icon">⏱️</span>
          Ставка за годину
        </label>
        <div class="salary-input-wrap">
          <input type="number" id="ss-pay-hour-amount" class="salary-amount-input"
                 min="0" step="0.01" placeholder="0" disabled>
          <span class="salary-unit">грн</span>
        </div>
      </div>

      <div class="salary-row">
        <label class="salary-check-label">
          <input type="checkbox" id="ss-pct-tovar-on" onchange="ssSyncRow('tovar')">
          <span class="salary-label-icon">🛒</span>
          % від продажів товарів
        </label>
        <div class="salary-input-wrap">
          <input type="number" id="ss-pct-tovar-value" class="salary-amount-input"
                 min="0" max="100" step="0.01" placeholder="0" disabled>
          <span class="salary-unit">%</span>
        </div>
      </div>

      <div class="salary-row">
        <label class="salary-check-label">
          <input type="checkbox" id="ss-pct-abon-on" onchange="ssSyncRow('abon')">
          <span class="salary-label-icon">🏋️</span>
          % від абонементів
        </label>
        <div class="salary-input-wrap">
          <input type="number" id="ss-pct-abon-value" class="salary-amount-input"
                 min="0" max="100" step="0.01" placeholder="0" disabled>
          <span class="salary-unit">%</span>
        </div>
      </div>

    </div>

    <div class="form-group" style="margin-top:16px">
      <label>Примітка</label>
      <input type="text" id="ss-notes" maxlength="200" placeholder="Необов'язково">
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" onclick="saveSalarySettings()">Зберегти</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-salary-settings')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: НАРАХУВАТИ ЗАРПЛАТУ ════ -->
<div class="modal-overlay" id="modal-create-payroll">
  <div class="modal" style="max-width:500px">
    <button class="modal-close" onclick="closeModal('modal-create-payroll')">&#10005;</button>
    <h2 class="modal-title">&#10133; Нарахувати зарплату</h2>
    <div id="cp-err" class="alert alert-error" style="display:none"></div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
      <div class="form-group">
        <label>Співробітник *</label>
        <select id="cp-user" onchange="calcPreview()"></select>
      </div>
      <div class="form-group">
        <label>Місяць *</label>
        <input type="month" id="cp-month" onchange="calcPreview()">
      </div>
    </div>

    <!-- Динамічні поля (показуються якщо є pay_day / pay_hour) -->
    <div id="cp-days-row" style="display:none">
      <div class="form-group">
        <label>Відпрацьовано днів</label>
        <input type="number" id="cp-days" min="0" max="31" step="0.5"
               placeholder="напр. 22" oninput="calcPreview()">
      </div>
    </div>
    <div id="cp-hours-row" style="display:none">
      <div class="form-group">
        <label>Відпрацьовано годин</label>
        <input type="number" id="cp-hours" min="0" step="0.5"
               placeholder="напр. 176" oninput="calcPreview()">
      </div>
    </div>

    <!-- Preview блок -->
    <div id="cp-preview" style="display:none;background:var(--bg-elevated);
         border-radius:var(--radius-sm);padding:14px;margin-bottom:16px">
      <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;
                  text-transform:uppercase;letter-spacing:.4px">Розрахунок</div>
      <div id="pv-rows"></div>
      <div class="pr-preview-row" style="border-top:1px solid var(--border);margin-top:8px;padding-top:8px">
        <span style="font-weight:600">Разом</span>
        <strong id="pv-total" style="color:var(--accent);font-size:16px">—</strong>
      </div>
    </div>
    <div id="cp-preview-loading" style="display:none;color:var(--text-muted);font-size:13px;margin-bottom:12px">
      &#9203; Розраховую...
    </div>

    <div class="form-group">
      <label>Примітка</label>
      <input type="text" id="cp-notes" maxlength="200" placeholder="Необов'язково">
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" id="cp-save-btn" onclick="confirmCreatePayroll()" disabled>
        Зафіксувати
      </button>
      <button class="btn btn-ghost" onclick="closeModal('modal-create-payroll')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: ВИПЛАТА / АВАНС ════ -->
<div class="modal-overlay" id="modal-pay-payroll">
  <div class="modal" style="max-width:400px">
    <button class="modal-close" onclick="closeModal('modal-pay-payroll')">&#10005;</button>
    <h2 class="modal-title">&#128184; Виплата</h2>
    <div id="pp-err" class="alert alert-error" style="display:none"></div>

    <!-- Інфо рядки -->
    <div style="background:var(--bg-elevated);border-radius:var(--radius-sm);
                padding:12px 14px;margin-bottom:16px;font-size:13px">
      <div style="display:flex;justify-content:space-between;margin-bottom:6px">
        <span style="color:var(--text-muted)">Нараховано</span>
        <strong id="pp-total">—</strong>
      </div>
      <div style="display:flex;justify-content:space-between;margin-bottom:6px">
        <span style="color:var(--text-muted)">Вже виплачено</span>
        <strong id="pp-paid" style="color:var(--success)">—</strong>
      </div>
      <div style="display:flex;justify-content:space-between;border-top:1px solid var(--border);padding-top:6px">
        <span style="color:var(--text-muted)">Залишок</span>
        <strong id="pp-rest" style="color:var(--warning)">—</strong>
      </div>
    </div>

    <!-- Швидкі кнопки авансу -->
    <div style="margin-bottom:12px">
      <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px">Швидкий вибір</div>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <button class="btn btn-ghost btn-sm" onclick="ppSetPercent(25)">25%</button>
        <button class="btn btn-ghost btn-sm" onclick="ppSetPercent(50)">Аванс 50%</button>
        <button class="btn btn-ghost btn-sm" onclick="ppSetPercent(100)">Повна виплата</button>
      </div>
    </div>

    <div class="form-group">
      <label>Сума виплати (грн)</label>
      <input type="number" id="pp-amount" min="0.01" step="0.01" placeholder="0.00">
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" onclick="confirmPayPayroll()">&#10003; Виплатити</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-pay-payroll')">Скасувати</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../partials/scripts.php'; ?>
<script>
const S = { myUserId: null, myLevel: 0, roles: [], editUserId: null, removeUserId: null };

// ── Ініціалізація ─────────────────────────────────────────
window.addEventListener('DOMContentLoaded', async () => {
  const ctx = await initPage({ title: 'Команда' });
  if (!ctx) return;

  const { u, isSuperAdmin, inClubMode, club, clubRole } = ctx;
  S.myUserId = u.id;
  S.myLevel  = isSuperAdmin ? (inClubMode ? 80 : 100) : (clubRole?.level ?? 0);

  // Показуємо підказку тільки якщо команда ще порожня або для нових користувачів
  if (S.myLevel >= 80) {
    document.getElementById('btn-invite').style.display      = 'inline-flex';
    document.getElementById('btn-hint').style.display        = 'inline-flex';
    document.getElementById('btn-tab-payroll').style.display = 'inline-flex';
  }

  // Встановлюємо поточний місяць у фільтрах
  const curMonth = new Date().toISOString().slice(0, 7);
  document.getElementById('pr-month-filter').value = curMonth;
  const rolesRes = await api('get_roles', {}, 'users');
  if (rolesRes.success) {
    S.roles = rolesRes.roles;
    fillRoleSelect('inv-role', true);
  }

  loadTeam();
  if (S.myLevel >= 80) loadPayrollTeam();
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
    const salaryBtn = m.role_slug !== 'trainer'
      ? `<button class="btn btn-ghost btn-sm"
                onclick="openSalarySettings(${m.id},'${esc(m.full_name)}')"
                title="Налаштування зарплати">&#128176;</button>` : '';
    actions = `
      <button class="btn btn-ghost btn-sm"
              onclick="openRoleModal(${m.id},'${esc(m.full_name)}',${m.role_id})"
              title="Змінити роль">&#9998;</button>
      ${salaryBtn}
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
      <td class="mob-primary">
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
      <td data-label="Роль"><span style="font-weight:600;color:${rColor}">${rLabel}</span></td>
      <td data-label="Права" class="mob-hide" style="font-size:12px;color:var(--text-muted)">${rDesc}</td>
      <td data-label="Статус">${statusBadge}</td>
      <td data-label="Останній вхід" class="mob-hide" style="font-size:13px;color:var(--text-secondary)">
        ${m.last_login_at ? formatDate(m.last_login_at) : 'Ніколи'}
      </td>
      <td class="mob-actions" onclick="event.stopPropagation()" style="white-space:nowrap">${actions}</td>
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

// ── Вкладки ───────────────────────────────────────────────
function switchTab(tab, btn) {
  document.querySelectorAll('.page-tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.page-tab-content').forEach(c => c.style.display='none');
  btn.classList.add('active');
  document.getElementById('tab-'+tab).style.display = 'block';
}

// ── Підказка (?) ──────────────────────────────────────────
function toggleHint() {
  const el  = document.getElementById('section-hint');
  const btn = document.getElementById('btn-hint');
  const vis = el.style.display !== 'none';
  el.style.display  = vis ? 'none' : 'block';
  btn.style.background = vis ? '' : 'rgba(79,156,249,.15)';
  btn.style.color      = vis ? '' : 'var(--accent)';
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

// ══════════════════════════════════════════════════════════════
// PAYROLL — Зарплата
// ══════════════════════════════════════════════════════════════

const PR = { payrollId: null, maxPay: 0, total: 0, team: [], previewTimer: null };

// Статус → бейдж
function prStatusBadge(s) {
  const map = { pending:'badge-warning', partial:'badge-info', paid:'badge-success' };
  const lbl = { pending:'Очікує', partial:'Частково', paid:'Виплачено' };
  return `<span class="badge ${map[s]||''}">${lbl[s]||s}</span>`;
}

// Форматування грошей
const fmt = v => Number(v).toLocaleString('uk-UA', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' ₴';

// ── Завантажити список нарахувань ──────────────────────────
async function loadPayroll() {
  const tbody  = document.getElementById('pr-tbody');
  tbody.innerHTML = '<tr><td colspan="8"><div class="loader"><div class="spinner"></div></div></td></tr>';

  const userId = document.getElementById('pr-user-filter').value;
  const month  = document.getElementById('pr-month-filter').value;

  const res = await api('get_payroll', { user_id: userId||0, month }, 'payroll');
  if (!res.success) { tbody.innerHTML = `<tr><td colspan="8" class="empty">Помилка</td></tr>`; return; }

  const rows = res.payroll;
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="8" class="empty">Нарахувань не знайдено</td></tr>';
    return;
  }
  tbody.innerHTML = rows.map(r => `
    <tr>
      <td><strong>${r.period_month}</strong></td>
      <td>${esc(r.full_name)}<br><span style="font-size:11px;color:var(--text-muted)">${esc(r.role_name||'')}</span></td>
      <td>${fmt(r.base_amount)}</td>
      <td>${fmt(r.bonus_amount)}
        ${r.bonus_pct>0?`<span style="font-size:11px;color:var(--text-muted)">(${r.bonus_pct}%)</span>`:''}
      </td>
      <td><strong>${fmt(r.total_amount)}</strong></td>
      <td>${fmt(r.paid_amount)}</td>
      <td>${prStatusBadge(r.status)}</td>
      <td>
        ${r.status!=='paid' ? `<button class="btn btn-sm btn-ghost" onclick="openPayModal(${r.id},${r.total_amount},${r.paid_amount})">Виплатити</button>` : ''}
        <button class="btn btn-sm btn-ghost" onclick="openSalarySettings(${r.user_id},'${esc(r.full_name)}')">⚙️</button>
      </td>
    </tr>`).join('');
}

// ── Зведені картки ─────────────────────────────────────────
async function loadPayrollSummary() {
  const month = document.getElementById('pr-month-filter').value;
  const userId = document.getElementById('pr-user-filter').value;
  const res = await api('get_summary', { month, user_id: userId||0 }, 'payroll');
  if (!res.success) return;
  const s = res.summary;
  document.getElementById('prc-total').textContent   = fmt(s.total_accrued);
  document.getElementById('prc-paid').textContent    = fmt(s.total_paid);
  document.getElementById('prc-pending').textContent = fmt(s.total_pending);
  document.getElementById('prc-cnt').textContent     = s.cnt;
}

// ── Завантажити команду в фільтри і dropdown ───────────────
async function loadPayrollTeam() {
  if (PR.team.length) return; // кеш
  const res = await api('get_team', {}, 'payroll');
  if (!res.success) return;
  PR.team = res.team;

  // Фільтр у вкладці
  const filter = document.getElementById('pr-user-filter');
  filter.innerHTML = '<option value="">Всі співробітники</option>' +
    PR.team.map(t => `<option value="${t.id}">${esc(t.full_name)} (${esc(t.role_name)})</option>`).join('');

  // Dropdown у модалці нарахування
  const sel = document.getElementById('cp-user');
  sel.innerHTML = '<option value="">— Оберіть —</option>' +
    PR.team.map(t => `<option value="${t.id}">${esc(t.full_name)} (${esc(t.role_name)})</option>`).join('');
}

// ── Відкрити модалку налаштувань зарплати ──────────────────
async function openSalarySettings(userId, name) {
  document.getElementById('ss-user-name').textContent = name || '—';
  document.getElementById('ss-err').style.display = 'none';

  const res = await api('get_settings', { user_id: userId }, 'payroll');
  const cfg = (res.success && res.settings) ? res.settings : {};

  // Заповнюємо прапорці і поля
  const map = [
    ['pay_month_on', 'ss-pay-month-on'], ['pay_month_amount', 'ss-pay-month-amount'],
    ['pay_day_on',   'ss-pay-day-on'],   ['pay_day_amount',   'ss-pay-day-amount'],
    ['pay_hour_on',  'ss-pay-hour-on'],  ['pay_hour_amount',  'ss-pay-hour-amount'],
    ['pct_tovar_on', 'ss-pct-tovar-on'], ['pct_tovar_value',  'ss-pct-tovar-value'],
    ['pct_abon_on',  'ss-pct-abon-on'],  ['pct_abon_value',   'ss-pct-abon-value'],
  ];
  map.forEach(([key, elId]) => {
    const el = document.getElementById(elId);
    if (!el) return;
    if (el.type === 'checkbox') { el.checked = !!(+cfg[key]); }
    else { el.value = cfg[key] ?? 0; }
  });
  document.getElementById('ss-notes').value = cfg.notes || '';

  // Синхронізуємо disabled стан полів
  ['month','day','hour','tovar','abon'].forEach(ssSyncRow);

  document.getElementById('modal-salary-settings').dataset.userId = userId;
  document.getElementById('modal-salary-settings').classList.add('open');
}

// Вмикає/вимикає поле введення залежно від стану прапорця
function ssSyncRow(key) {
  const map = { month:['ss-pay-month-on','ss-pay-month-amount'], day:['ss-pay-day-on','ss-pay-day-amount'],
                hour:['ss-pay-hour-on','ss-pay-hour-amount'], tovar:['ss-pct-tovar-on','ss-pct-tovar-value'],
                abon:['ss-pct-abon-on','ss-pct-abon-value'] };
  const [chkId, inpId] = map[key] || [];
  const chk = document.getElementById(chkId);
  const inp = document.getElementById(inpId);
  if (chk && inp) inp.disabled = !chk.checked;
}

async function saveSalarySettings() {
  const modal  = document.getElementById('modal-salary-settings');
  const userId = parseInt(modal.dataset.userId || 0);
  const errEl  = document.getElementById('ss-err');
  errEl.style.display = 'none';

  const g = id => document.getElementById(id);
  const payload = {
    user_id:          userId,
    pay_month_on:     g('ss-pay-month-on').checked  ? 1 : 0,
    pay_month_amount: parseFloat(g('ss-pay-month-amount').value) || 0,
    pay_day_on:       g('ss-pay-day-on').checked    ? 1 : 0,
    pay_day_amount:   parseFloat(g('ss-pay-day-amount').value)   || 0,
    pay_hour_on:      g('ss-pay-hour-on').checked   ? 1 : 0,
    pay_hour_amount:  parseFloat(g('ss-pay-hour-amount').value)  || 0,
    pct_tovar_on:     g('ss-pct-tovar-on').checked  ? 1 : 0,
    pct_tovar_value:  parseFloat(g('ss-pct-tovar-value').value)  || 0,
    pct_abon_on:      g('ss-pct-abon-on').checked   ? 1 : 0,
    pct_abon_value:   parseFloat(g('ss-pct-abon-value').value)   || 0,
    notes:            g('ss-notes').value.trim(),
  };

  const res = await api('save_settings', payload, 'payroll');
  if (res.success) { closeModal('modal-salary-settings'); }
  else { errEl.textContent = res.error || 'Помилка збереження'; errEl.style.display = 'block'; }
}

// ── Відкрити модалку "Нарахувати" ──────────────────────────
async function openCreatePayroll() {
  document.getElementById('cp-err').style.display          = 'none';
  document.getElementById('cp-preview').style.display      = 'none';
  document.getElementById('cp-preview-loading').style.display = 'none';
  document.getElementById('cp-save-btn').disabled          = true;
  document.getElementById('cp-notes').value                = '';
  document.getElementById('cp-month').value                = new Date().toISOString().slice(0,7);
  // Показуємо/ховаємо поля days/hours
  cpSyncDynamicFields();
  await loadPayrollTeam();
  document.getElementById('modal-create-payroll').classList.add('open');
}

// Показати поля days/hours якщо вибраний user має такі компоненти
async function cpUserChanged() {
  await calcPreview();
}

// Preview нарахування
async function calcPreview() {
  clearTimeout(PR.previewTimer);
  const userId = document.getElementById('cp-user').value;
  const month  = document.getElementById('cp-month').value;
  if (!userId || !month) return;

  document.getElementById('cp-preview').style.display         = 'none';
  document.getElementById('cp-preview-loading').style.display = 'block';
  document.getElementById('cp-save-btn').disabled             = true;

  PR.previewTimer = setTimeout(async () => {
    const days  = parseFloat(document.getElementById('cp-days')?.value  || 0);
    const hours = parseFloat(document.getElementById('cp-hours')?.value || 0);
    const res = await api('calc_preview', { user_id: userId, month, days_worked: days, hours_worked: hours }, 'payroll');
    document.getElementById('cp-preview-loading').style.display = 'none';
    if (!res.success) {
      document.getElementById('cp-err').textContent   = res.error;
      document.getElementById('cp-err').style.display = 'block';
      return;
    }
    document.getElementById('cp-err').style.display = 'none';
    const p = res.preview;
    const cfg = p.cfg || {};

    // Показуємо поля days/hours якщо потрібно
    document.getElementById('cp-days-row').style.display  = cfg.pay_day_on  ? 'block' : 'none';
    document.getElementById('cp-hours-row').style.display = cfg.pay_hour_on ? 'block' : 'none';

    // Розбивка по рядках
    const rows = [
      [cfg.pay_month_on,  'Оклад/місяць',            p.pay_month],
      [cfg.pay_day_on,    `Ставка за день (×${days})`, p.pay_day],
      [cfg.pay_hour_on,   `Ставка за год. (×${hours})`,p.pay_hour],
      [cfg.pct_tovar_on,  `% товари (${cfg.pct_tovar_value}%)`,  p.pct_tovar],
      [cfg.pct_abon_on,   `% абонем. (${cfg.pct_abon_value}%)`,  p.pct_abon],
    ].filter(r => r[0]);

    document.getElementById('pv-rows').innerHTML = rows.map(([,label,val]) =>
      `<div class="pr-preview-row"><span>${label}</span><strong>${fmt(val)}</strong></div>`
    ).join('') || '<div style="color:var(--text-muted);font-size:13px">Немає активних компонентів</div>';

    document.getElementById('pv-total').textContent     = fmt(p.total);
    document.getElementById('cp-preview').style.display = 'block';
    document.getElementById('cp-save-btn').disabled     = p.total <= 0;
  }, 400);
}

function cpSyncDynamicFields() {
  document.getElementById('cp-days-row').style.display  = 'none';
  document.getElementById('cp-hours-row').style.display = 'none';
}

async function confirmCreatePayroll() {
  const userId = document.getElementById('cp-user').value;
  const month  = document.getElementById('cp-month').value;
  const notes  = document.getElementById('cp-notes').value.trim();
  const days   = parseFloat(document.getElementById('cp-days')?.value  || 0);
  const hours  = parseFloat(document.getElementById('cp-hours')?.value || 0);
  const errEl  = document.getElementById('cp-err');
  errEl.style.display = 'none';

  if (!userId) { errEl.textContent='Оберіть співробітника'; errEl.style.display='block'; return; }
  if (!month)  { errEl.textContent='Оберіть місяць';        errEl.style.display='block'; return; }

  const btn = document.getElementById('cp-save-btn');
  btn.disabled = true;
  const res = await api('create_payroll', { user_id: userId, month, notes, days_worked: days, hours_worked: hours }, 'payroll');
  btn.disabled = false;

  if (res.success) {
    closeModal('modal-create-payroll');
    loadPayroll(); loadPayrollSummary();
  } else {
    errEl.textContent = res.error || 'Помилка';
    errEl.style.display = 'block';
  }
}

// ── Виплата / Аванс ────────────────────────────────────────
function openPayModal(payrollId, total, paid) {
  PR.payrollId = payrollId;
  PR.total     = total;
  PR.maxPay    = Math.round((total - paid) * 100) / 100;
  document.getElementById('pp-err').style.display    = 'none';
  document.getElementById('pp-total').textContent    = fmt(total);
  document.getElementById('pp-paid').textContent     = fmt(paid);
  document.getElementById('pp-rest').textContent     = fmt(PR.maxPay);
  document.getElementById('pp-amount').value         = PR.maxPay;
  document.getElementById('pp-amount').max           = PR.maxPay;
  document.getElementById('modal-pay-payroll').classList.add('open');
}

function ppSetPercent(pct) {
  const val = Math.round(PR.maxPay * pct) / 100;
  document.getElementById('pp-amount').value = val.toFixed(2);
}

async function confirmPayPayroll() {
  const amount = parseFloat(document.getElementById('pp-amount').value);
  const errEl  = document.getElementById('pp-err');
  errEl.style.display = 'none';

  if (!amount || amount <= 0) { errEl.textContent='Введіть суму'; errEl.style.display='block'; return; }
  if (amount > PR.maxPay)     { errEl.textContent=`Максимум: ${fmt(PR.maxPay)}`; errEl.style.display='block'; return; }

  const res = await api('pay_payroll', { payroll_id: PR.payrollId, amount }, 'payroll');
  if (res.success) {
    closeModal('modal-pay-payroll');
    loadPayroll(); loadPayrollSummary();
  } else {
    errEl.textContent = res.error || 'Помилка';
    errEl.style.display = 'block';
  }
}
</script>
</body>
