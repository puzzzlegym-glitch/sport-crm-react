import { DEMO_CLUB, DEMO_USER, DEMO_CLUB_ROLE } from './seedData';
import { isDemoActive, startDemo, stopDemo, resetDemo } from './demoStore';
import { demoApi } from './demoApi';

export { isDemoActive, startDemo, stopDemo, resetDemo, demoApi };

/**
 * Сторінки, доступні у демо-режимі (решта — редірект на /dashboard). 1:1 з DEMO_SLUGS
 * у components/layout/navItems.js (шляхи замість slug'ів) — клуб на найвищому тарифі.
 */
export const DEMO_ALLOWED_PATHS = [
  '/dashboard', '/clients', '/invoices', '/payments', '/tariffs', '/visits', '/products', '/arrivals',
  '/sales', '/sklad', '/finance', '/certificates', '/cash', '/trainers', '/users', '/settings', '/client-service', '/help', '/support',
];

/** Фейковий стан AuthContext для демо — власник клубу з повним доступом, без звернення до бекенду */
export function buildDemoAuthState() {
  return {
    status: 'ready',
    user: DEMO_USER,
    club: DEMO_CLUB,
    clubRole: DEMO_CLUB_ROLE,
    inClubMode: true,
    menu: {},
    allowedPages: null,
    planIsFree: false,
    clientsUnlimited: true,
    limitsExceeded: false,
    activeShiftId: null,
    isBlocked: false,
    isDemo: true,
    permissions: { canWrite: true, isOwner: true, isSuperAdmin: false, level: 80, permissions: {}, has: () => true },
  };
}
