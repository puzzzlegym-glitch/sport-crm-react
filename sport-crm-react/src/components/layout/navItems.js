/**
 * Конфігурація пунктів меню — 1:1 з NAV_ITEMS у sidebar.js.
 * Порядок і іконки — тут. Видимість — з auth.menu (сервер).
 * icon — назва з набору components/ui/Icon.jsx
 *
 * Доступ до сторінки залежить від ДВОХ незалежних джерел на бекенді — не плутати:
 *
 * menuGate — чи сторінку може приховати сам власник клубу для конкретної ролі
 * (Auth::getMenuSettings() → таблиця club_menu_settings → auth.menu[slug]).
 * PHP-дефолти там жорстко прописані на 15 "старих" slug'ів — 'sklad'/'access'/'help' (нові
 * сторінки цього React-переписування) серед них немає, тому вони НЕ підпадають під цю
 * перевірку (інакше auth.menu[slug] буде undefined і сторінка зникне для всіх).
 *
 * planGate — чи сторінку обмежує тарифний SaaS-план клубу
 * (saas_plans.allowed_pages → auth.allowed_pages, довільний JSON-масив без обмежень бекенду
 * на конкретні slug'и — на відміну від menuGate, тут немає "старого"/"нового" поділу).
 * Показується як чекбокс на SaasPage і реально перевіряється в ProtectedRoute.
 *
 * Кожен новий пункт МУСИТЬ явно вказати обидва прапорці true/false — це продуктове рішення
 * (хто може обмежити доступ: власник клубу? тарифний план?), а не деталь реалізації.
 */
export const NAV_ITEMS = {
  dashboard: { href: '/dashboard', icon: 'grid', label: 'Дашборд', section: 'main', permission: 'dashboard.view', menuGate: true, planGate: false },
  clients: { href: '/clients', icon: 'user', label: 'Клієнти', section: 'main', permission: 'clients.view', menuGate: true, planGate: true },
  invoices: { href: '/invoices', icon: 'fileText', label: 'Абонементи', section: 'main', permission: 'invoices.view', menuGate: true, planGate: true },
  payments: { href: '/payments', icon: 'banknote', label: 'Оплати', section: 'main', permission: 'payments.view', menuGate: true, planGate: true },
  tariffs: { href: '/tariffs', icon: 'tag', label: 'Тарифи', section: 'main', permission: 'tariffs.view', menuGate: true, planGate: true },
  visits: { href: '/visits', icon: 'calendar', label: 'Відвідування', section: 'main', permission: 'visits.view', menuGate: true, planGate: true },
  products: { href: '/products', icon: 'cart', label: 'Товари', section: 'main', permission: 'products.view', menuGate: true, planGate: true },
  arrivals: { href: '/arrivals', icon: 'package', label: 'Прихід', section: 'main', permission: 'arrivals.view', menuGate: true, planGate: true },
  sales: { href: '/sales', icon: 'receipt', label: 'Продажі', section: 'main', permission: 'sales.view', menuGate: true, planGate: true },
  sklad: { href: '/sklad', icon: 'warehouse', label: 'Склад', section: 'main', permission: 'warehouse.view', menuGate: false, planGate: true },
  finance: { href: '/finance', icon: 'wallet', label: 'Фінанси', section: 'main', permission: 'finance.view', menuGate: true, planGate: true },
  certificates: { href: '/certificates', icon: 'gift', label: 'Сертифікати', section: 'main', permission: 'certificates.view', menuGate: false, planGate: true },
  cash: { href: '/cash', icon: 'atm', label: 'Каса', section: 'main', permission: 'cash.view', menuGate: true, planGate: true },
  trainers: { href: '/trainers', icon: 'dumbbell', label: 'Тренери', section: 'main', permission: 'trainers.view', menuGate: true, planGate: true },
  // menuGate: false — це НОВИЙ slug (див. коментар зверху файлу про 15 "старих" slug'ів).
  equipment: { href: '/equipment', icon: 'gear', label: 'Обладнання', section: 'main', permission: 'equipment.view', menuGate: false, planGate: true },
  // menuGate: false — це НОВИЙ slug, PHP-дефолти Auth::getMenuSettings() прописані
  // лише на 15 "старих" slug'ів (див. коментар зверху файлу); true тут зробило б
  // auth.menu['group_sessions'] === undefined і сторінка зникла б для всіх.
  group_sessions: { href: '/group-sessions', icon: 'clock', label: 'Розклад і запис', section: 'main', permission: 'group_sessions.view', menuGate: false, planGate: true },

  users: { href: '/users', icon: 'users', label: 'Команда', section: 'manage', permission: 'users.manage', menuGate: true, planGate: true },
  billing: { href: '/billing', icon: 'card', label: 'Підписка', section: 'manage', menuGate: true, planGate: false },
  settings: { href: '/settings', icon: 'gear', label: 'Налаштування', section: 'manage', menuGate: true, planGate: true },
  prro: { href: '/prro', icon: 'receipt', label: 'ПРРО / Термінали', section: 'manage', permission: 'prro.manage', menuGate: false, planGate: false },
  access: { href: '/access', icon: 'key', label: 'Доступ і ролі', section: 'manage', menuGate: false, planGate: false, excludeTrainer: true },
  client_service: { href: '/client-service', icon: 'smartphone', label: 'Клієнтський сервіс', section: 'manage', permission: 'clients.view', menuGate: false, planGate: false },
  help: { href: '/help', icon: 'helpCircle', label: 'Довідка і підтримка', section: 'manage', menuGate: false, planGate: false, excludeTrainer: true },

  clubs: { href: '/clubs', icon: 'building', label: 'Всі клуби', section: 'clubs', menuGate: false, planGate: false },
  saas: { href: '/saas', icon: 'card', label: 'Білінг платформи', section: 'clubs', menuGate: false, planGate: false },
  saas_payments: { href: '/saas-payments', icon: 'wallet', label: 'Платежі', section: 'clubs', menuGate: false, planGate: false },
};

export const MAIN_SLUGS = ['dashboard', 'clients', 'invoices', 'payments', 'tariffs', 'visits', 'products', 'arrivals', 'sales', 'sklad', 'finance', 'certificates', 'cash', 'trainers', 'group_sessions', 'equipment'];
export const MANAGE_SLUGS = ['users', 'billing', 'settings', 'prro', 'access', 'client_service', 'help'];

/** Сторінки, доступність яких визначає власник клубу (auth.menu). Похідне від NAV_ITEMS. */
export const MENU_GATABLE_SLUGS = Object.keys(NAV_ITEMS).filter((slug) => NAV_ITEMS[slug].menuGate === true);
/** Сторінки, які реально обмежує тарифний план (auth.allowed_pages). Похідне від NAV_ITEMS. */
export const PLAN_GATABLE_SLUGS = Object.keys(NAV_ITEMS).filter((slug) => NAV_ITEMS[slug].planGate === true);

if (import.meta.env?.DEV) {
  const undeclared = Object.entries(NAV_ITEMS).filter(([, v]) => typeof v.menuGate !== 'boolean' || typeof v.planGate !== 'boolean');
  if (undeclared.length > 0) {
    console.warn(`[navItems] Пункти без явних menuGate/planGate (додайте true/false обом): ${undeclared.map(([k]) => k).join(', ')}`);
  }
}

/**
 * Сторінки, показані в сайдбарі під час демо-режиму (без реєстрації) — див. src/demo.
 * Демо емулює клуб на найвищому тарифі: усі сторінки з planGate: true доступні (жодна не
 * прихована тарифом), плюс client_service/help (завжди відкриті) — крім клубного/платформного
 * SuperAdmin-розділу (clubs/saas/saas_payments) і billing/access, які до пробного режиму не
 * стосуються (немає реальної підписки чи потреби налаштовувати ролі без команди).
 */
export const DEMO_SLUGS = [...MAIN_SLUGS, 'users', 'settings', 'client_service', 'help'];

export const BLOCKED_STATUS_LABELS = {
  trial_expired: { icon: 'clock', text: 'Тріал закінчився' },
  past_due: { icon: 'alertTriangle', text: 'Підписка прострочена' },
  cancelled: { icon: 'ban', text: 'Підписку скасовано' },
  deleted: { icon: 'trash', text: 'Клуб видалено' },
};
