<?php
$pageTitle = 'Дашборд';
$pageCss   = 'dashboard';
require __DIR__ . '/../partials/head.php';
?>
<body>
<div class="app-layout">

  <!-- aside і header НЕ пишемо — sidebar.js вставить їх сам -->

  <main class="app-main">

    <div class="page-header">
      <h1 class="page-title">Дашборд</h1>
      <p class="page-subtitle" id="dash-subtitle">Завантаження...</p>
    </div>

    <!-- SuperAdmin без клубу -->
    <div class="superadmin-banner" id="superadmin-banner" style="display:none">
      <span style="font-size:20px">&#128737;</span>
      <div>
        <strong>Режим SuperAdmin</strong> — зведена статистика всієї платформи.
        Оберіть клуб у перемикачі або перейдіть у
        <a href="/clubs" style="color:var(--accent)">список клубів</a>.
      </div>
    </div>

    <!-- Стат-картки -->
    <div class="stats-grid" style="margin-bottom:24px">
      <div class="stat-card">
        <div class="stat-label" id="label-stat-1">—</div>
        <div class="stat-value" id="stat-1"><span class="spinner"></span></div>
        <div class="stat-delta" id="stat-1-sub">—</div>
      </div>
      <div class="stat-card green">
        <div class="stat-label" id="label-stat-2">—</div>
        <div class="stat-value" id="stat-2">—</div>
        <div class="stat-delta" id="stat-2-sub">—</div>
      </div>
      <div class="stat-card orange">
        <div class="stat-label" id="label-stat-3">—</div>
        <div class="stat-value" id="stat-3">—</div>
        <div class="stat-delta" id="stat-3-sub">—</div>
      </div>
      <div class="stat-card red">
        <div class="stat-label" id="label-stat-4">—</div>
        <div class="stat-value" id="stat-4">—</div>
        <div class="stat-delta" id="stat-4-sub">—</div>
      </div>
    </div>

    <!-- Два блоки -->
    <div class="dash-grid">
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
          <div class="card-title" style="margin:0" id="recent-title">Завантаження...</div>
          <a href="#" class="btn btn-ghost btn-sm" id="recent-link">Всі →</a>
        </div>
        <div id="recent-list"><div class="loader"><div class="spinner"></div></div></div>
      </div>
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
          <div class="card-title" style="margin:0" id="second-title">—</div>
          <a href="#" class="btn btn-ghost btn-sm" id="second-link">Всі →</a>
        </div>
        <div id="second-list"><div class="loader"><div class="spinner"></div></div></div>
      </div>
    </div>

    <!-- Секція товарів (лише для клубу) -->
    <div id="products-section" style="display:none;margin-top:24px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
        <div style="font-size:15px;font-weight:600;color:var(--text-primary)">🛒 Товари</div>
        <a href="/products" class="btn btn-ghost btn-sm">Каталог →</a>
      </div>
      <div class="stats-grid" id="prod-stats-grid">
        <div class="stat-card">
          <div class="stat-label">Активних товарів</div>
          <div class="stat-value" id="pd-total"><span class="spinner"></span></div>
          <div class="stat-delta" id="pd-stock-value">—</div>
        </div>
        <div class="stat-card red">
          <div class="stat-label">Немає на складі</div>
          <div class="stat-value" id="pd-out">—</div>
          <div class="stat-delta" id="pd-low">—</div>
        </div>
        <div class="stat-card green">
          <div class="stat-label">Продано цього місяця</div>
          <div class="stat-value" id="pd-sold">—</div>
          <div class="stat-delta" id="pd-revenue">—</div>
        </div>
        <div class="stat-card orange">
          <div class="stat-label">Прибуток місяця</div>
          <div class="stat-value" id="pd-profit">—</div>
          <div class="stat-delta">виручка − собівартість</div>
        </div>
      </div>
    </div>

  </main>
</div>
<?php require __DIR__ . '/../partials/scripts.php'; ?>
<script>
window.addEventListener('DOMContentLoaded', async () => {
  const ctx = await initPage({ title: 'Дашборд' });
  if (!ctx) return;
  guardBlockedClub(ctx.isBlocked);

  const { isSuperAdmin, inClubMode, club } = ctx;
  const today = new Date().toLocaleDateString('uk-UA', {
    weekday: 'long', day: 'numeric', month: 'long',
  });

  if (isSuperAdmin && !inClubMode) {
    // ── SuperAdmin без клубу — системна панель ───────────
    document.getElementById('superadmin-banner').style.display = 'flex';
    document.getElementById('dash-subtitle').textContent = 'Системна панель · ' + today;
    loadSuperAdminStats();

  } else if (club) {
    // ── Клуб обрано ──────────────────────────────────────
    document.getElementById('dash-subtitle').textContent =
      club.name + (club.city ? ' · ' + club.city : '') + ' · ' + today;
    loadClubStats();
    loadRecentClients();
    loadProductStats();

  } else {
    // ── Немає клубу ──────────────────────────────────────
    document.getElementById('dash-subtitle').textContent =
      'Клуб не підключено. Зверніться до адміністратора.';
    document.getElementById('stat-1').textContent    = '—';
    document.getElementById('recent-list').innerHTML =
      '<div class="empty-state" style="padding:30px 0"><p>Немає активного клубу</p></div>';
    document.getElementById('second-list').innerHTML = '';
  }
});

// ════ SuperAdmin — статистика платформи ══════════════════════
async function loadSuperAdminStats() {
  document.getElementById('label-stat-1').textContent = 'Клубів у системі';
  document.getElementById('label-stat-2').textContent = 'На тріалі';
  document.getElementById('stat-2-sub').textContent   = 'безкоштовний доступ';
  document.getElementById('label-stat-3').textContent = 'Тріал закінчується';
  document.getElementById('stat-3-sub').textContent   = 'протягом 7 днів';
  document.getElementById('label-stat-4').textContent = 'Тріал закінчився';
  document.getElementById('stat-4-sub').textContent   = 'потребують оплати';
  document.getElementById('recent-title').textContent = 'Нові клуби';
  document.getElementById('recent-link').href         = '/clubs';
  document.getElementById('recent-link').textContent  = 'Всі клуби →';
  document.getElementById('second-title').textContent = 'Тріал закінчується';
  document.getElementById('second-link').href         = '/clubs';
  document.getElementById('second-link').textContent  = 'Всі клуби →';

  const res = await api('admin_list', { page: 1 }, 'billing');
  if (!res.success) {
    document.getElementById('stat-1').textContent = '—';
    return;
  }

  const clubs       = res.clubs || [];
  const total       = res.pagination?.total || clubs.length;
  const trialCount  = clubs.filter(c => c.sub_status === 'trial').length;
  const activeCount = clubs.filter(c => c.sub_status === 'active').length;
  const expiredCnt  = clubs.filter(c => c.sub_status === 'trial_expired').length;
  const expiringList = clubs.filter(c =>
    c.sub_status === 'trial' &&
    parseInt(c.trial_days_left) >= 0 &&
    parseInt(c.trial_days_left) <= 7
  );

  document.getElementById('stat-1').textContent     = total;
  document.getElementById('stat-1-sub').textContent = 'активних платних: ' + activeCount;
  document.getElementById('stat-2').textContent     = trialCount;
  document.getElementById('stat-3').textContent     = expiringList.length;
  document.getElementById('stat-4').textContent     = expiredCnt;

  // Нові клуби
  document.getElementById('recent-list').innerHTML = clubs.slice(0, 5).length
    ? clubs.slice(0, 5).map(c => `
        <div class="recent-item" onclick="enterClub(${c.id})">
          <div class="recent-avatar">${getInitials(c.name)}</div>
          <div>
            <div class="recent-name">${esc(c.name)}</div>
            <div class="recent-sub">${c.owner_email}${c.city ? ' · ' + c.city : ''}</div>
          </div>
          <div class="recent-date">
            <span class="badge ${
              c.sub_status === 'active'        ? 'badge-active'   :
              c.sub_status === 'trial'         ? 'badge-info'     :
              c.sub_status === 'trial_expired' ? 'badge-inactive' : 'badge-pending'
            }">${
              c.sub_status === 'active'        ? 'Активний'        :
              c.sub_status === 'trial'         ? 'Тріал'           :
              c.sub_status === 'trial_expired' ? 'Тріал закінчився':
              c.sub_status === 'past_due'      ? 'Прострочено'     :
              c.sub_status === 'cancelled'     ? 'Скасовано'       :
              c.sub_status === 'deleted'       ? 'Видалено'        : c.sub_status
            }</span>
          </div>
        </div>`).join('')
    : '<div class="empty-state" style="padding:30px 0"><p>Клубів ще немає</p></div>';

  // Закінчуються
  document.getElementById('second-list').innerHTML = expiringList.length
    ? expiringList.map(c => `
        <div class="recent-item">
          <div class="recent-avatar">${getInitials(c.name)}</div>
          <div>
            <div class="recent-name">${esc(c.name)}</div>
            <div class="recent-sub">${c.owner_email}</div>
          </div>
          <div class="recent-date" style="color:var(--warning);font-weight:600">
            ${c.trial_days_left} дн.
          </div>
        </div>`).join('')
    : '<div class="empty-state" style="padding:30px 0"><p>Немає клубів що закінчуються</p></div>';
}

// ════ Клуб — статистика клієнтів ═════════════════════════════
async function loadClubStats() {
  document.getElementById('label-stat-1').textContent = 'Активних клієнтів';
  document.getElementById('label-stat-2').textContent = 'Нових цього місяця';
  document.getElementById('stat-2-sub').textContent   = 'за останні 30 днів';
  document.getElementById('label-stat-3').textContent = 'Закінчуються абонементи';
  document.getElementById('stat-3-sub').textContent   = 'протягом 7 днів';
  document.getElementById('label-stat-4').textContent = 'Заморожених';
  document.getElementById('recent-title').textContent = 'Нові клієнти';
  document.getElementById('recent-link').href         = '/clients';
  document.getElementById('recent-link').textContent  = 'Всі →';
  document.getElementById('second-title').textContent = 'Абонементи закінчуються';
  document.getElementById('second-link').href         = '/invoices';
  document.getElementById('second-link').textContent  = 'Всі →';

  const res = await api('get_stats', {}, 'clients');
  if (!res.success) {
    document.getElementById('stat-1').textContent = '—';
    return;
  }

  const s = res.stats;
  document.getElementById('stat-1').textContent     = s.total_active;
  document.getElementById('stat-1-sub').textContent = 'неактивних: ' + s.total_inactive;
  document.getElementById('stat-2').textContent     = s.new_this_month;
  document.getElementById('stat-3').textContent     = s.expiring_soon;
  document.getElementById('stat-4').textContent     = s.total_frozen;
  document.getElementById('stat-4-sub').textContent = '—';

  document.getElementById('second-list').innerHTML =
    '<div class="empty-state" style="padding:30px 0">' +
    '<div style="font-size:32px;margin-bottom:8px">&#128196;</div>' +
    '<p>Буде доступно у наступному оновленні</p></div>';
}

async function loadRecentClients() {
  const res = await api('get_list', { per_page: 5, order: 'created_at', dir: 'desc' }, 'clients');
  if (res.success && res.clients?.length) {
    document.getElementById('recent-list').innerHTML = res.clients.map(c => `
      <div class="recent-item" onclick="window.location='/clients'">
        <div class="recent-avatar">${getInitials(c.full_name)}</div>
        <div>
          <div class="recent-name">${esc(c.full_name)}</div>
          <div class="recent-sub">${c.phone || '—'}</div>
        </div>
        <div class="recent-date">${relDate(c.created_at)}</div>
      </div>`).join('');
  } else {
    document.getElementById('recent-list').innerHTML =
      '<div class="empty-state" style="padding:30px 0">' +
      '<div style="font-size:32px;margin-bottom:8px">&#128100;</div>' +
      '<p>Клієнтів ще немає</p>' +
      '<a href="/clients" class="btn btn-primary btn-sm" style="margin-top:12px">+ Додати клієнта</a></div>';
  }
}

// SuperAdmin входить у клуб
async function enterClub(id) {
  const res = await api('enter_club', { club_id: id }, 'clubs');
  if (res.success) {
    window.location.href = res.redirect || '/dashboard';
  } else {
    toast(res.error, 'error');
  }
}

// ════ Клуб — статистика товарів ══════════════════════════════
async function loadProductStats() {
  const res = await api('get_stats', {}, 'products');
  if (!res.success) return;
  const s = res.stats;
  document.getElementById('products-section').style.display = 'block';
  document.getElementById('pd-total').textContent       = s.active_products  || 0;
  document.getElementById('pd-stock-value').textContent = 'вартість складу: ' + formatMoney(s.stock_value || 0);
  document.getElementById('pd-out').textContent         = s.out_of_stock     || 0;
  document.getElementById('pd-low').textContent         = 'малий залишок: '  + (s.low_stock || 0);
  document.getElementById('pd-sold').textContent        = s.month_sold       || 0;
  document.getElementById('pd-revenue').textContent     = formatMoney(s.month_revenue || 0);
  document.getElementById('pd-profit').textContent      = formatMoney(s.month_profit  || 0);
}

// ── Утиліти ───────────────────────────────────────────────
function relDate(d) {
  if (!d) return '—';
  const days = Math.floor((new Date() - new Date(d)) / 86400000);
  if (days === 0) return 'сьогодні';
  if (days === 1) return 'вчора';
  if (days < 7)   return days + ' дн. тому';
  return new Date(d).toLocaleDateString('uk-UA');
}

function esc(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}
</script>
</body>

</html>
