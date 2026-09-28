import { createContext, useContext, useState, useCallback } from 'react';
import { authCheck } from '../api/auth';
import { isDemoActive, startDemo, stopDemo, buildDemoAuthState } from '../demo';

const BLOCKED_STATUSES = ['trial_expired', 'past_due', 'cancelled', 'deleted'];

const AuthContext = createContext(null);

/**
 * Рахує права на основі відповіді /auth?action=check.
 * Логіка 1:1 з getPermissions() у sidebar.js.
 */
export function computePermissions(auth) {
  const isSuperAdmin = !!auth?.user?.is_superadmin;
  const inClubMode   = !!auth?.in_club_mode;
  const clubRole     = auth?.club_role || null;
  const permissions  = auth?.permissions || {};

  if (isSuperAdmin && !inClubMode) {
    return { canWrite: false, isOwner: false, isSuperAdmin: true, level: 100, permissions, has: () => true };
  }

  // У режимі клубу (в т.ч. для SuperAdmin) права рахуються ЛИШЕ з реальної ролі в клубі,
  // без universal-bypass — бекенд у цьому режимі підставляє SuperAdmin роль "owner" (див.
  // auth_api.php), тож тут це навмисно НЕ форсується через isSuperAdmin: SuperAdmin має
  // бачити рівно те саме (і ті самі помилки/обмеження), що й реальний власник клубу.
  const level = clubRole?.level ?? 0;
  const hasServerPerms = Object.keys(permissions).length > 0;

  const canWrite = hasServerPerms
    ? !!(permissions['clients.create'] ?? permissions['invoices.sell'] ?? level >= 50)
    : level >= 50;
  const isOwner = hasServerPerms
    ? !!(permissions['finance.delete'] ?? permissions['users.manage'] ?? level >= 80)
    : level >= 80;

  /**
   * Перевірка конкретної дії за slug (напр. 'clients.delete') з довідника sys_permissions.
   * Джерело — Auth::getPermissions() на бекенді: золотий стандарт ролі + override клубу.
   * Якщо сервер ще не віддав жодних permissions (hasServerPerms=false, старий бекенд без
   * цього API) — падає назад на canWrite як розумний дефолт.
   */
  const has = (slug) => (hasServerPerms ? !!permissions[slug] : canWrite);

  return { canWrite, isOwner, isSuperAdmin, level, permissions, has };
}

export function AuthProvider({ children }) {
  const [state, setState] = useState({
    status: 'loading', // 'loading' | 'ready' | 'unauthenticated'
    user: null,
    club: null,
    clubRole: null,
    inClubMode: false,
    menu: {},
    allowedPages: null,
    planIsFree: false,
    clientsUnlimited: false,
    limitsExceeded: false,
    activeShiftId: null,
    isBlocked: false,
    isDemo: false,
    permissions: { canWrite: false, isOwner: false, isSuperAdmin: false, level: 0, has: () => false },
  });

  /** Увійти в демо-режим: без бекенду, фейкові права власника клубу на тестових даних */
  const enterDemo = useCallback(() => {
    startDemo();
    setState(buildDemoAuthState());
  }, []);

  /** Вийти з демо-режиму й повернутись на сторінку входу */
  const exitDemo = useCallback(() => {
    stopDemo();
    setState((s) => ({ ...s, status: 'unauthenticated', isDemo: false }));
  }, []);

  /** Викликати при старті додатку і після дій, що змінюють права (switch_club тощо) */
  const refresh = useCallback(async () => {
    if (isDemoActive()) {
      setState(buildDemoAuthState());
      return buildDemoAuthState();
    }

    const res = await authCheck();

    if (!res.authenticated) {
      setState((s) => ({ ...s, status: 'unauthenticated' }));
      return null;
    }

    const club = res.active_club || null;
    const isBlocked = !!(club && BLOCKED_STATUSES.includes(club.subscription_status));

    const next = {
      status: 'ready',
      user: res.user,
      club,
      clubRole: res.club_role || null,
      inClubMode: !!res.in_club_mode,
      menu: res.menu || {},
      allowedPages: res.allowed_pages ?? null,
      planIsFree: !!res.plan_is_free,
      clientsUnlimited: !!res.clients_unlimited,
      limitsExceeded: !!res.limits_exceeded,
      activeShiftId: res.active_shift_id ?? null,
      isBlocked,
      permissions: computePermissions(res),
    };
    setState(next);
    return next;
  }, []);

  return (
    <AuthContext.Provider value={{ ...state, refresh, enterDemo, exitDemo }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth() має використовуватись всередині <AuthProvider>');
  return ctx;
}
