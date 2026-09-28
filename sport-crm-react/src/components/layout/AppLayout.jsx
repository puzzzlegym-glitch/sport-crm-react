import { useEffect, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../ui/ToastProvider';
import { logout as apiLogout, switchClub as apiSwitchClub, exitClub as apiExitClub, myClubs, setViewRole as apiSetViewRole } from '../../api/auth';
import { getPermissionsCatalog } from '../../api/permissions';
import { getUnreadSupportCount } from '../../api/support';
import { getInitials } from '../../utils/format';
import Icon from '../ui/Icon';
import { NAV_ITEMS, MAIN_SLUGS, MANAGE_SLUGS, DEMO_SLUGS, BLOCKED_STATUS_LABELS } from './navItems';

const SUPPORT_POLL_MS = 60000;

/**
 * Заміна sidebar.js (initPage layout-частина): sidebar + header + мобільне меню.
 * Використання: <AppLayout title="Дашборд" subtitle="...">...контент сторінки...</AppLayout>
 */
export default function AppLayout({ title = '', subtitle = '', children }) {
  const { user, club, clubRole, inClubMode, menu, allowedPages, isBlocked, permissions, isDemo, exitDemo, refresh } = useAuth();
  const { isSuperAdmin } = permissions;
  const location = useLocation();
  const navigate = useNavigate();
  const toast = useToast();

  const [mobileOpen, setMobileOpen] = useState(false);
  const [clubPickerOpen, setClubPickerOpen] = useState(false);
  const [clubSearch, setClubSearch] = useState('');
  const [clubs, setClubs] = useState(null);
  const [unreadSupport, setUnreadSupport] = useState(0);
  const [viewRoles, setViewRoles] = useState(null);
  const [switchingRole, setSwitchingRole] = useState(false);

  useEffect(() => {
    document.title = title ? `${title} — Sport CRM` : 'Sport CRM';
  }, [title]);

  useEffect(() => {
    let cancelled = false;
    async function pollUnread() {
      const res = await getUnreadSupportCount();
      if (!cancelled && res.success) setUnreadSupport(res.count || 0);
    }
    pollUnread();
    const timer = setInterval(pollUnread, SUPPORT_POLL_MS);
    return () => { cancelled = true; clearInterval(timer); };
  }, [club?.id, inClubMode]);

  const isActive = (href) => location.pathname === href || location.pathname.startsWith(href + '/');

  async function loadClubs() {
    if (clubs) return;
    const res = await myClubs();
    setClubs(res.success ? (res.clubs ?? []) : []);
  }

  useEffect(() => {
    if (isSuperAdmin && !inClubMode) loadClubs();
  }, [isSuperAdmin, inClubMode]);

  useEffect(() => {
    if (!(isSuperAdmin && inClubMode) || viewRoles) return;
    (async () => {
      const res = await getPermissionsCatalog();
      if (res.success) setViewRoles(res.roles || []);
    })();
  }, [isSuperAdmin, inClubMode, viewRoles]);

  /** SuperAdmin: переглянути активний клуб під іншою роллю (owner/manager/trainer) — для швидкого пошуку багів у розмежуванні прав */
  async function handleSetViewRole(slug) {
    if (slug === clubRole?.slug || switchingRole) return;
    setSwitchingRole(true);
    const res = await apiSetViewRole(slug);
    setSwitchingRole(false);
    if (res.success) {
      await refresh();
    } else {
      toast(res.error, 'error');
    }
  }

  async function handleSwitchClub(id) {
    const res = await apiSwitchClub(id);
    if (res.success) {
      window.location.href = '/dashboard';
    } else {
      toast(res.error, 'error');
    }
  }

  async function handleExitClub() {
    const res = await apiExitClub();
    if (res.success) {
      window.location.href = '/clubs';
    } else {
      toast(res.error, 'error');
    }
  }

  async function handleLogout() {
    if (isDemo) {
      exitDemo();
      window.location.href = '/login';
      return;
    }
    await apiLogout();
    window.location.href = '/login';
  }

  function toggleClubPicker() {
    const opening = !clubPickerOpen;
    setClubPickerOpen(opening);
    if (opening) loadClubs();
  }

  const renderLink = (slug) => {
    const item = NAV_ITEMS[slug];
    if (!item) return null;
    return (
      <Link key={slug} to={item.href} className={`nav-item ${isActive(item.href) ? 'active' : ''}`} onClick={() => setMobileOpen(false)}>
        <span className="nav-icon"><Icon name={item.icon} size={17} /></span>{item.label}
      </Link>
    );
  };

  const filteredClubs = clubs && clubSearch
    ? clubs.filter((c) => c.name.toLowerCase().includes(clubSearch.toLowerCase()))
    : clubs;

  const clubPicker = (
    <div style={{ padding: '0 8px 4px' }}>
      <input
        type="text"
        placeholder="Пошук клубу..."
        value={clubSearch}
        onChange={(e) => setClubSearch(e.target.value)}
        style={{
          width: '100%', boxSizing: 'border-box', padding: '6px 10px',
          background: 'var(--bg-elevated)', border: '1px solid var(--border)',
          borderRadius: 6, color: 'var(--text-primary)', fontSize: 12,
          fontFamily: 'inherit', outline: 'none', marginBottom: 4,
        }}
      />
      <div style={{ maxHeight: 240, overflowY: 'auto' }}>
        {filteredClubs === null && <div style={{ fontSize: 12, color: 'var(--text-muted)', padding: '4px 2px' }}>Завантаження...</div>}
        {filteredClubs?.length === 0 && <div style={{ fontSize: 12, color: 'var(--text-muted)', padding: '4px 2px' }}>Клубів немає</div>}
        {filteredClubs?.map((c) => (
          <div
            key={c.id}
            className="nav-item"
            style={{ cursor: 'pointer', fontSize: 12, padding: '7px 10px' }}
            onClick={() => handleSwitchClub(c.id)}
          >
            {c.name}
          </div>
        ))}
      </div>
    </div>
  );

  let nav;
  if (isSuperAdmin && !inClubMode) {
    nav = (
      <>
        <div className="sidebar-section">
          <div className="sidebar-section-label">Клуби</div>
          {['clubs', 'saas', 'saas_payments'].map(renderLink)}
        </div>
        <div className="sidebar-section">
          <div className="sidebar-section-label">Система</div>
          {['settings', 'access', 'help'].map(renderLink)}
        </div>
        <div className="sidebar-section">
          <div className="sidebar-section-label">Перейти до клубу</div>
          {clubPicker}
        </div>
      </>
    );
  } else {
    const hasMenu = Object.keys(menu).length > 0;
    // Дві незалежні перевірки, обидві мають пройти: menuGate — видимість пункту за роллю в клубі,
    // яку задає власник (club_menu_settings, auth.menu[slug]); planGate — реальне обмеження тарифним
    // SaaS-планом (saas_plans.allowed_pages, auth.allowed_pages, null = дозволено все).
    const visible = (slug) => {
      const requiredPermission = NAV_ITEMS[slug]?.permission;
      if (requiredPermission && !permissions.has(requiredPermission)) return false;
      if (NAV_ITEMS[slug]?.excludeTrainer && permissions.level === 30 && !isSuperAdmin) return false;
      if (isDemo) return DEMO_SLUGS.includes(slug);
      if (NAV_ITEMS[slug]?.menuGate && hasMenu && menu[slug] !== true) return false;
      if (NAV_ITEMS[slug]?.planGate && Array.isArray(allowedPages) && !allowedPages.includes(slug)) return false;
      return true;
    };
    const mainLinks = MAIN_SLUGS.filter(visible).map(renderLink);
    // У демо DEMO_SLUGS вирішує, які пункти розділу "Управління" показати (team/settings — так,
    // billing/access — ні, бо без реальної підписки й команди вони нерелевантні пробному режиму).
    const manageLinks = MANAGE_SLUGS.filter(visible).map(renderLink);
    const clubLabel = club ? <><Icon name="pin" size={12} style={{ marginRight: 4, verticalAlign: -1 }} />{club.name}</> : 'Головне';

    nav = (
      <>
        <div className="sidebar-section">
          <div className="sidebar-section-label">{clubLabel}</div>
          {mainLinks}
        </div>
        {manageLinks.length > 0 && (
          <div className="sidebar-section">
            <div className="sidebar-section-label">Управління</div>
            {manageLinks}
          </div>
        )}
        {isSuperAdmin && inClubMode && (
          <div className="sidebar-section">
            <div className="sidebar-section-label">SuperAdmin</div>
            <div style={{ padding: '2px 10px 8px' }}>
              <div style={{ fontSize: 11, color: 'var(--text-muted)', marginBottom: 6 }}>Переглядати клуб як:</div>
              <div style={{ display: 'flex', gap: 4 }}>
                {(viewRoles?.length ? viewRoles : [{ slug: 'owner', name_ua: 'Owner' }, { slug: 'manager', name_ua: 'Manager' }, { slug: 'trainer', name_ua: 'Trainer' }]).map((r) => {
                  const active = (clubRole?.slug || 'owner') === r.slug;
                  return (
                    <button
                      key={r.slug}
                      type="button"
                      disabled={switchingRole}
                      onClick={() => handleSetViewRole(r.slug)}
                      style={{
                        flex: 1, fontSize: 11, fontWeight: 600, padding: '6px 4px',
                        borderRadius: 6, cursor: switchingRole ? 'default' : 'pointer',
                        border: '1px solid ' + (active ? 'rgba(167,139,250,.6)' : 'var(--border)'),
                        background: active ? 'rgba(167,139,250,.18)' : 'transparent',
                        color: active ? 'rgba(167,139,250,.95)' : 'var(--text-secondary)',
                        opacity: switchingRole ? 0.6 : 1,
                      }}
                    >
                      {r.name_ua || r.slug}
                    </button>
                  );
                })}
              </div>
            </div>
            <button
              className="nav-item"
              style={{ color: 'rgba(167,139,250,.9)' }}
              onClick={handleExitClub}
            >
              <span className="nav-icon"><Icon name="arrowLeft" size={17} /></span> Вийти з клубу
            </button>
            <button
              className="nav-item"
              style={{ color: 'rgba(167,139,250,.9)' }}
              onClick={toggleClubPicker}
            >
              <span className="nav-icon"><Icon name="switch" size={17} /></span> Змінити клуб
            </button>
            {clubPickerOpen && clubPicker}
          </div>
        )}
      </>
    );
  }

  const showLogout = !(isSuperAdmin && inClubMode);
  const blockedInfo = isBlocked && club ? (BLOCKED_STATUS_LABELS[club.subscription_status] ?? { icon: 'alertTriangle', text: 'Клуб неактивний' }) : null;

  return (
    <div className="app-layout">
      <aside className={`app-sidebar ${mobileOpen ? 'mobile-open' : ''}`}>
        <Link to="/dashboard" className="sidebar-logo">Sport<span>CRM</span></Link>
        <nav>{nav}</nav>
        {showLogout && (
          <div className="sidebar-bottom">
            <button
              className="nav-item"
              style={{ color: 'var(--danger)' }}
              onClick={handleLogout}
            >
              <span className="nav-icon"><Icon name="logout" size={17} /></span> {isDemo ? 'Вийти з демо' : 'Вийти'}
            </button>
          </div>
        )}
      </aside>

      <div
        className={`sidebar-overlay ${mobileOpen ? 'open' : ''}`}
        onClick={() => setMobileOpen(false)}
      />

      <header className="app-header">
        <button id="burger-btn" aria-label="Відкрити меню" onClick={() => setMobileOpen((v) => !v)}><Icon name="menu" size={20} /></button>
        <div className="header-title">
          <span>{title}</span>
          {subtitle && <span className="header-subtitle">{subtitle}</span>}
        </div>
        <div className="header-user">
          <button
            className={`header-bell ${unreadSupport > 0 ? 'header-bell-active' : ''}`}
            aria-label="Сповіщення підтримки"
            title={unreadSupport > 0 ? `Нових відповідей: ${unreadSupport}` : 'Немає нових відповідей'}
            onClick={() => navigate('/support')}
          >
            <Icon name="bell" size={18} />
            {unreadSupport > 0 && <span className="header-bell-dot" />}
          </button>
          <div className="avatar">{getInitials(user?.full_name) || '?'}</div>
          <span>{user?.full_name}</span>
        </div>
      </header>

      <main className="app-main">
        {isDemo && (
          <div
            style={{
              position: 'sticky', top: 0, zIndex: 150,
              background: 'rgba(79,156,249,.12)',
              borderBottom: '1px solid rgba(79,156,249,.35)',
              color: 'var(--accent)',
              padding: '10px 20px',
              display: 'flex', alignItems: 'center', gap: 12,
              fontSize: 14, fontWeight: 500,
              marginBottom: 20,
            }}
          >
            <Icon name="trendingUp" size={16} style={{ flexShrink: 0 }} />
            <span style={{ flex: 1 }}>Демо-режим</span>
            <Link
              to="/register"
              style={{
                background: 'var(--accent)', color: '#fff', padding: '5px 16px',
                borderRadius: 20, fontSize: 12, fontWeight: 600,
                textDecoration: 'none', whiteSpace: 'nowrap',
                display: 'inline-flex', alignItems: 'center', gap: 6,
              }}
            >
              Зареєструватися
            </Link>
          </div>
        )}
        {blockedInfo && (
          <div
            style={{
              position: 'sticky', top: 0, zIndex: 150,
              background: 'rgba(220,38,38,.12)',
              borderBottom: '1px solid rgba(220,38,38,.35)',
              color: '#fca5a5',
              padding: '10px 20px',
              display: 'flex', alignItems: 'center', gap: 12,
              fontSize: 14, fontWeight: 500,
              marginBottom: 20,
            }}
          >
            <Icon name={blockedInfo.icon} size={16} style={{ flexShrink: 0 }} />
            <span style={{ flex: 1 }}>{blockedInfo.text} — дані доступні лише для перегляду</span>
            <Link
              to="/billing"
              style={{
                background: '#dc2626', color: '#fff', padding: '5px 16px',
                borderRadius: 20, fontSize: 12, fontWeight: 600,
                textDecoration: 'none', whiteSpace: 'nowrap',
                display: 'inline-flex', alignItems: 'center', gap: 6,
              }}
            >
              <Icon name="card" size={13} /> Оплатити підписку
            </Link>
          </div>
        )}
        {children}
      </main>
    </div>
  );
}
