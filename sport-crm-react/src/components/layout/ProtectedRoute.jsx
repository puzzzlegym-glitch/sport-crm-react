import { useEffect, useRef } from 'react';
import { Navigate, useLocation, Link } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import Spinner from '../ui/Spinner';
import Icon from '../ui/Icon';
import AppLayout from './AppLayout';
import { DEMO_ALLOWED_PATHS } from '../../demo';
import { NAV_ITEMS } from './navItems';

function AccessDenied() {
  return (
    <AppLayout title="Доступ обмежено">
      <div className="card" style={{ textAlign: 'center', padding: '48px 20px', color: 'var(--text-muted)' }}>
        <Icon name="lock" size={28} style={{ marginBottom: 12 }} />
        <div style={{ fontSize: 15, fontWeight: 500, color: 'var(--text-primary)', marginBottom: 4 }}>Немає доступу</div>
        <div style={{ fontSize: 13 }}>Власник клубу обмежив доступ до цього розділу для вашої ролі.</div>
      </div>
    </AppLayout>
  );
}

function PlanRestricted() {
  return (
    <AppLayout title="Недоступно на плані">
      <div className="card" style={{ textAlign: 'center', padding: '48px 20px', color: 'var(--text-muted)' }}>
        <Icon name="card" size={28} style={{ marginBottom: 12 }} />
        <div style={{ fontSize: 15, fontWeight: 500, color: 'var(--text-primary)', marginBottom: 4 }}>Розділ недоступний на вашому тарифному плані</div>
        <div style={{ fontSize: 13, marginBottom: 16 }}>Щоб отримати доступ, перейдіть на план, який його підтримує.</div>
        <Link to="/billing" className="btn btn-primary btn-sm">Перейти до підписки</Link>
      </div>
    </AppLayout>
  );
}

/** Знаходить slug сторінки з NAV_ITEMS за поточним шляхом (для перевірки тарифного обмеження). */
function findSlugByPath(pathname) {
  const entry = Object.entries(NAV_ITEMS).find(
    ([, v]) => pathname === v.href || pathname.startsWith(v.href + '/')
  );
  return entry?.[0] ?? null;
}

/**
 * Обгортка захищеного маршруту. Заміна ручного виклику initPage() на сторінці.
 *
 * @param {boolean} requireSuperAdmin — якщо true, пускає лише SuperAdmin
 * @param {boolean} excludeTrainer — якщо true, тренера (level 30) редіректить на /dashboard
 * @param {string} permission — slug з sys_permissions (напр. 'dashboard.view'); без нього — не блокує
 */
export default function ProtectedRoute({ children, requireSuperAdmin = false, excludeTrainer = false, permission = null }) {
  const { status, permissions, isDemo, allowedPages, inClubMode, refresh } = useAuth();
  const location = useLocation();
  const didInit = useRef(false);

  useEffect(() => {
    if (!didInit.current) {
      didInit.current = true;
      refresh();
    }
  }, [refresh]);

  if (status === 'loading') return <Spinner fullPage />;
  if (status === 'unauthenticated') return <Navigate to="/login" replace />;
  if (requireSuperAdmin && !permissions.isSuperAdmin) return <Navigate to="/dashboard" replace />;
  if (excludeTrainer && permissions.level === 30 && !permissions.isSuperAdmin) return <Navigate to="/dashboard" replace />;
  // У режимі клубу SuperAdmin має бачити рівно те саме, що й реальний власник клубу —
  // тож тут bypass діє лише поза режимом клубу (платформний доступ), не всередині клубу.
  const bypassBySuperAdmin = permissions.isSuperAdmin && !inClubMode;
  if (permission && !bypassBySuperAdmin && !permissions.has(permission)) return <AccessDenied />;
  if (isDemo && !DEMO_ALLOWED_PATHS.includes(location.pathname)) return <Navigate to="/dashboard" replace />;

  // Реальне (не лише візуальне в сайдбарі) обмеження доступу тарифним планом — auth.allowed_pages
  // (saas_plans.allowed_pages), не auth.menu (те — про видимість за роллю, окрема перевірка вище через `permission`).
  if (!requireSuperAdmin && !permissions.isSuperAdmin && !isDemo) {
    const slug = findSlugByPath(location.pathname);
    if (slug && NAV_ITEMS[slug]?.planGate && Array.isArray(allowedPages) && !allowedPages.includes(slug)) {
      return <PlanRestricted />;
    }
  }

  return children;
}
