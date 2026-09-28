/**
 * Демо-режим: фейкові дані для пробного використання клубу без реєстрації.
 * Дати рахуються відносно поточного дня, щоб демо завжди виглядало "живим".
 */

function pad(n) { return String(n).padStart(2, '0'); }

function fmtDate(d) { return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`; }

/** 'YYYY-MM-DD' на n днів від сьогодні (n може бути від'ємним) */
export function daysFromNow(n) {
  const d = new Date();
  d.setDate(d.getDate() + n);
  return fmtDate(d);
}

/** 'YYYY-MM-DD HH:MM:SS' на n днів від сьогодні, з заданим часом */
export function datetimeFromNow(n, h = 10, m = 0) {
  const d = new Date();
  d.setDate(d.getDate() + n);
  d.setHours(h, m, 0, 0);
  return `${fmtDate(d)} ${pad(d.getHours())}:${pad(d.getMinutes())}:00`;
}

export const DEMO_CLUB = { id: 9001, name: 'Демо Фітнес Клуб', subscription_status: 'active' };

export const DEMO_USER = {
  id: 9001,
  full_name: 'Демо Власник',
  email: 'demo@sportcrm.pp.ua',
  is_superadmin: false,
};

export const DEMO_CLUB_ROLE = { level: 80, name: 'owner' };

export const DEMO_TARIFFS = [
  { id: 1, name: 'Разовий візит', category: 'Разові', is_active: true, color: '#4f9cf9', price: 150, duration_days: 1, freeze_days_max: 0, freeze_days_min: 0, visits_limit: 1, has_trainer: false, earn_release_trigger: 'on_each_visit', usage_total: 34, usage_active: 2, prolong_sum: 0, sort_order: 1, description: 'Одне відвідування залу' },
  { id: 2, name: 'Місячний безліміт', category: 'Абонементи', is_active: true, color: '#22c55e', price: 1200, duration_days: 30, freeze_days_max: 7, freeze_days_min: 3, visits_limit: null, has_trainer: false, earn_release_trigger: 'on_each_visit', usage_total: 58, usage_active: 11, prolong_sum: 0, sort_order: 2, description: 'Необмежені відвідування протягом місяця' },
  { id: 3, name: 'Квартальний', category: 'Абонементи', is_active: true, color: '#a78bfa', price: 3200, duration_days: 90, freeze_days_max: 14, freeze_days_min: 0, visits_limit: null, has_trainer: false, earn_release_trigger: 'on_each_visit', usage_total: 22, usage_active: 6, prolong_sum: 0, sort_order: 3, description: 'Абонемент на 3 місяці зі знижкою' },
  { id: 4, name: 'Персональні тренування (8 занять)', category: 'Персональні', is_active: true, color: '#f59e0b', price: 4800, duration_days: 60, freeze_days_max: 0, freeze_days_min: 0, visits_limit: 8, has_trainer: true, earn_release_trigger: 'on_each_visit', usage_total: 14, usage_active: 3, prolong_sum: 0, sort_order: 4, description: '8 персональних тренувань з тренером' },
  { id: 5, name: 'Річний VIP', category: 'Абонементи', is_active: false, color: '#ef4444', price: 11000, duration_days: 365, freeze_days_max: 30, freeze_days_min: 0, visits_limit: null, has_trainer: false, earn_release_trigger: 'on_each_visit', usage_total: 3, usage_active: 0, prolong_sum: 0, sort_order: 5, description: 'Річний абонемент з розширеним доступом' },
];

export const DEMO_TRAINERS = [
  {
    id: 1, full_name: 'Олена Ковальчук', user_id: 101, is_active: true,
    specialization: 'Персональні тренування, йога', work_type: 'employee',
    personal_earn_type: 'percent', personal_earn_value: 40, personal_tier_threshold: 5, personal_tier_value: 45,
    group_earn_rate: 150, group_earn_bonus_per_client: 20, group_bonus_threshold: 8,
    group_monthly_bonus_sessions: 20, group_monthly_bonus_amount: 1000,
  },
  {
    id: 2, full_name: 'Дмитро Гнатюк', user_id: 102, is_active: true,
    specialization: 'Силові тренування, кросфіт', work_type: 'both',
    personal_earn_type: 'fixed', personal_earn_value: 300, personal_tier_threshold: 0, personal_tier_value: 0,
    group_earn_rate: 180, group_earn_bonus_per_client: 0, group_bonus_threshold: 0,
    group_monthly_bonus_sessions: 0, group_monthly_bonus_amount: 0,
  },
];

/** sys_roles клубу — фіксований набір ролей команди (owner/manager/trainer), як у реальному бекенді */
export const DEMO_ROLES = [
  { id: 1, slug: 'owner', name_ua: 'Власник', level: 80 },
  { id: 2, slug: 'manager', name_ua: 'Менеджер', level: 50 },
  { id: 3, slug: 'trainer', name_ua: 'Тренер', level: 30 },
];

/** Команда клубу (users_api.php: get_list) — власник + тренери (прив'язані до DEMO_TRAINERS) + менеджер */
export function buildStaff() {
  return [
    { id: DEMO_USER.id, full_name: DEMO_USER.full_name, email: DEMO_USER.email, phone: '+380671234500', role_id: 1, role_slug: 'owner', role_name: 'Власник', club_access: 1, is_active: true, last_login_at: datetimeFromNow(0, 9, 0) },
    { id: 101, full_name: 'Олена Ковальчук', email: 'olena.kovalchuk@sportcrm.pp.ua', phone: '+380671112201', role_id: 3, role_slug: 'trainer', role_name: 'Тренер', club_access: 1, is_active: true, last_login_at: datetimeFromNow(0, 11, 0), trainer_profile_id: 1 },
    { id: 102, full_name: 'Дмитро Гнатюк', email: 'dmytro.hnatiuk@sportcrm.pp.ua', phone: '+380671112202', role_id: 3, role_slug: 'trainer', role_name: 'Тренер', club_access: 1, is_active: true, last_login_at: datetimeFromNow(-1, 17, 0), trainer_profile_id: 2 },
    { id: 103, full_name: 'Марина Топчій', email: 'maryna.topchii@sportcrm.pp.ua', phone: '+380671112203', role_id: 2, role_slug: 'manager', role_name: 'Менеджер', club_access: 1, is_active: true, last_login_at: datetimeFromNow(0, 8, 30) },
  ];
}

export function buildTrainerEarnings() {
  return [
    { id: 1, trainer_id: 1, client_id: 5, client_name: 'Мельник Софія', earn_type: 'personal_percent', release_trigger: 'on_each_visit', amount: 1920, available_amount: 1200, paid_amount: 800, status: 'partial', end_date: daysFromNow(20), visits_total: 8, visits_used: 5 },
    { id: 2, trainer_id: 1, client_id: 2, client_name: 'Петренко Олег', earn_type: 'group_fixed', release_trigger: 'on_each_visit', amount: 450, available_amount: 450, paid_amount: 0, status: 'available', end_date: null, visits_total: null, visits_used: null },
    { id: 3, trainer_id: 1, client_id: 9, client_name: 'Гриценко Дарина', earn_type: 'group_bonus', release_trigger: 'on_visits_done', amount: 200, available_amount: 0, paid_amount: 0, status: 'locked', end_date: null, visits_total: null, visits_used: null },
    { id: 4, trainer_id: 2, client_id: 6, client_name: 'Бондаренко Артем', earn_type: 'group_fixed', release_trigger: 'on_each_visit', amount: 540, available_amount: 540, paid_amount: 540, status: 'paid', end_date: null, visits_total: null, visits_used: null },
    { id: 5, trainer_id: 2, client_id: 1, client_name: 'Іваненко Марія', earn_type: 'group_fixed', release_trigger: 'on_each_visit', amount: 360, available_amount: 360, paid_amount: 0, status: 'available', end_date: null, visits_total: null, visits_used: null },
  ];
}

export function buildTrainerRent() {
  return [
    { id: 1, trainer_id: 2, rent_type: 'manual', amount: 1500, period_start: daysFromNow(-30), period_end: daysFromNow(0), status: 'paid', notes: 'Оренда за минулий місяць' },
    { id: 2, trainer_id: 2, rent_type: 'deduction', amount: 1500, period_start: daysFromNow(0), period_end: daysFromNow(30), status: 'pending', notes: '' },
  ];
}

export function buildSalarySettings() {
  return {
    101: { pay_month_on: 1, pay_month_amount: 8000, pay_day_on: 0, pay_day_amount: 0, pay_hour_on: 0, pay_hour_amount: 0, pct_tovar_on: 0, pct_tovar_value: 0, pct_abon_on: 1, pct_abon_value: 5, notes: '' },
    102: { pay_month_on: 0, pay_month_amount: 0, pay_day_on: 1, pay_day_amount: 400, pay_hour_on: 0, pay_hour_amount: 0, pct_tovar_on: 0, pct_tovar_value: 0, pct_abon_on: 0, pct_abon_value: 0, notes: '' },
    103: { pay_month_on: 1, pay_month_amount: 12000, pay_day_on: 0, pay_day_amount: 0, pay_hour_on: 0, pay_hour_amount: 0, pct_tovar_on: 1, pct_tovar_value: 3, pct_abon_on: 1, pct_abon_value: 2, notes: '' },
  };
}

export function buildPayroll() {
  const prevDate = new Date(); prevDate.setMonth(prevDate.getMonth() - 1);
  const prevMonth = `${prevDate.getFullYear()}-${pad(prevDate.getMonth() + 1)}`;
  return [
    { id: 1, user_id: 103, full_name: 'Марина Топчій', role_name: 'Менеджер', period_month: prevMonth, status: 'paid', pay_month: 12000, pay_day: 0, pay_hour: 0, pct_tovar: 340, pct_abon: 640, total_amount: 12980, paid_amount: 12980, notes: '' },
    { id: 2, user_id: 101, full_name: 'Олена Ковальчук', role_name: 'Тренер', period_month: prevMonth, status: 'partial', pay_month: 8000, pay_day: 0, pay_hour: 0, pct_tovar: 0, pct_abon: 480, total_amount: 8480, paid_amount: 5000, notes: '' },
  ];
}

const CASH_BASE_BALANCE = 900;

export function buildCashLedger() {
  const rows = [
    [-6, 9, 30, 'income', 'Оплати за день (готівка)', null, 1450],
    [-6, 19, 0, 'expense', 'Закупка води', 'Закупка товарів', 320],
    [-5, 10, 0, 'income', 'Оплати за день (готівка)', null, 980],
    [-4, 18, 30, 'encashment', 'Інкасація', null, 1000],
    [-3, 9, 15, 'income', 'Оплати за день (готівка)', null, 1720],
    [-2, 20, 0, 'expense', 'Господарські товари', 'Господарські', 260],
    [-1, 11, 0, 'income', 'Оплати за день (готівка)', null, 1340],
    [0, 8, 30, 'income', 'Оплати за день (готівка)', null, 610],
  ];
  let bal = CASH_BASE_BALANCE;
  const list = rows.map(([d, h, m, type, description, category, amount], i) => {
    bal += type === 'income' ? amount : -amount;
    return { id: i + 1, type, description, category, amount, admin_name: DEMO_USER.full_name, created_at: datetimeFromNow(d, h, m), source: 'auto', running_balance: bal };
  });
  return { rows: list, endingBalance: bal, baseBalance: CASH_BASE_BALANCE };
}

export function buildExpenses() {
  return [
    { id: 1, category: 'Оренда', description: 'Оренда приміщення за місяць', amount: 15000, expense_date: daysFromNow(-20), payment_method: 'transfer', notes: '', created_at: datetimeFromNow(-20) },
    { id: 2, category: 'Комунальні', description: 'Світло, вода, опалення', amount: 3200, expense_date: daysFromNow(-18), payment_method: 'card', notes: '', created_at: datetimeFromNow(-18) },
    { id: 3, category: 'Закупка товарів', description: 'Поповнення бару', amount: 1420, expense_date: daysFromNow(-6), payment_method: 'cash', notes: 'Вода, батончики', created_at: datetimeFromNow(-6) },
    { id: 4, category: 'Реклама', description: 'Просування в Instagram', amount: 2000, expense_date: daysFromNow(-5), payment_method: 'card', notes: '', created_at: datetimeFromNow(-5) },
    { id: 5, category: 'Господарські', description: 'Прибирання, витратні матеріали', amount: 850, expense_date: daysFromNow(-2), payment_method: 'cash', notes: '', created_at: datetimeFromNow(-2) },
  ];
}

export function buildDeposits() {
  return [
    { id: 1, client_id: 10, operation: 'top_up', payment_method: 'cash', amount: 500, notes: 'Поповнення наперед', created_at: datetimeFromNow(-2) },
    { id: 2, client_id: 2, operation: 'top_up', payment_method: 'card', amount: 250, notes: '', created_at: datetimeFromNow(-40) },
    { id: 3, client_id: 5, operation: 'top_up', payment_method: 'cash', amount: 300, notes: '', created_at: datetimeFromNow(-60) },
    { id: 4, client_id: 5, operation: 'pay_product', payment_method: 'deposit', amount: -200, notes: 'Оплата протеїну', created_at: datetimeFromNow(-2) },
  ];
}

export function buildClients() {
  return [
    { id: 1, full_name: 'Іваненко Марія', phone: '+380671112233', email: 'ivanenko.maria@gmail.com', birthday: '1994-03-12', gender: 'F', address: 'м. Київ, вул. Хрещатик, 10', status: 'regular', status_reason: '', status_changed_at: datetimeFromNow(-90), source: 'instagram', notes: 'Любить групові тренування', balance: 0, created_at: datetimeFromNow(-120), active_tariff: 'Місячний безліміт', tariff_end_date: daysFromNow(12), last_visit: datetimeFromNow(-1, 18, 30) },
    { id: 2, full_name: 'Петренко Олег', phone: '+380501234567', email: 'petrenko.oleg@gmail.com', birthday: '1988-07-21', gender: 'M', address: 'м. Київ, вул. Січових Стрільців, 5', status: 'premium', status_reason: '', status_changed_at: datetimeFromNow(-200), source: 'referral', notes: '', balance: 250, created_at: datetimeFromNow(-260), active_tariff: 'Квартальний', tariff_end_date: daysFromNow(45), last_visit: datetimeFromNow(0, 8, 10) },
    { id: 3, full_name: 'Коваль Анна', phone: '+380931122334', email: 'koval.anna@gmail.com', birthday: '1997-11-02', gender: 'F', address: 'м. Київ, просп. Перемоги, 34', status: 'regular', status_reason: '', status_changed_at: datetimeFromNow(-40), source: 'website', notes: '', balance: 0, created_at: datetimeFromNow(-45), active_tariff: null, tariff_end_date: null, last_visit: datetimeFromNow(-20, 19, 0) },
    { id: 4, full_name: 'Сидоренко Ігор', phone: '+380661239876', email: 'sydorenko.ihor@gmail.com', birthday: '1985-01-30', gender: 'M', address: 'м. Київ, вул. Богдана Хмельницького, 22', status: 'blocked', status_reason: 'Заборгованість по оплаті', status_changed_at: datetimeFromNow(-15), source: 'walk-in', notes: 'Обіцяв оплатити заборгованість', balance: 0, created_at: datetimeFromNow(-300), active_tariff: null, tariff_end_date: null, last_visit: datetimeFromNow(-60, 17, 15) },
    { id: 5, full_name: 'Мельник Софія', phone: '+380935556677', email: 'melnyk.sofia@gmail.com', birthday: '1999-05-18', gender: 'F', address: 'м. Київ, вул. Велика Васильківська, 100', status: 'premium', status_reason: '', status_changed_at: datetimeFromNow(-70), source: 'instagram', notes: 'Персональні з Оленою', balance: 100, created_at: datetimeFromNow(-90), active_tariff: 'Персональні тренування (8 занять)', tariff_end_date: daysFromNow(20), last_visit: datetimeFromNow(-2, 11, 0) },
    { id: 6, full_name: 'Бондаренко Артем', phone: '+380509998877', email: 'bondarenko.artem@gmail.com', birthday: '1992-09-09', gender: 'M', address: 'м. Київ, вул. Антоновича, 44', status: 'regular', status_reason: '', status_changed_at: datetimeFromNow(-10), source: 'referral', notes: '', balance: 0, created_at: datetimeFromNow(-35), active_tariff: 'Місячний безліміт', tariff_end_date: daysFromNow(3), last_visit: datetimeFromNow(0, 7, 45) },
    { id: 7, full_name: 'Ткаченко Юлія', phone: '+380671114455', email: 'tkachenko.yulia@gmail.com', birthday: '1996-02-14', gender: 'F', address: 'м. Київ, вул. Саксаганського, 15', status: 'regular', status_reason: '', status_changed_at: datetimeFromNow(-5), source: 'website', notes: '', balance: 0, created_at: datetimeFromNow(-8), active_tariff: null, tariff_end_date: null, last_visit: datetimeFromNow(-3, 20, 0) },
    { id: 8, full_name: 'Кравченко Максим', phone: '+380933332211', email: 'kravchenko.maxym@gmail.com', birthday: '1990-12-25', gender: 'M', address: 'м. Київ, вул. Дегтярівська, 8', status: 'regular', status_reason: '', status_changed_at: datetimeFromNow(-1), source: 'walk-in', notes: '', balance: 0, created_at: datetimeFromNow(-1), active_tariff: 'Разовий візит', tariff_end_date: daysFromNow(1), last_visit: datetimeFromNow(0, 9, 20) },
    { id: 9, full_name: 'Гриценко Дарина', phone: '+380661237788', email: 'grytsenko.daryna@gmail.com', birthday: '1993-06-06', gender: 'F', address: 'м. Київ, вул. Гончара, 19', status: 'premium', status_reason: '', status_changed_at: datetimeFromNow(-150), source: 'instagram', notes: 'Абонемент закінчився, планує продовжити', balance: 0, created_at: datetimeFromNow(-180), active_tariff: null, tariff_end_date: null, last_visit: datetimeFromNow(-15, 18, 0) },
    { id: 10, full_name: 'Литвиненко Богдан', phone: '+380507771122', email: 'lytvynenko.bogdan@gmail.com', birthday: '1991-04-04', gender: 'M', address: 'м. Київ, вул. Володимирська, 60', status: 'regular', status_reason: '', status_changed_at: datetimeFromNow(-2), source: 'website', notes: 'Ще не приходив, поповнив депозит наперед', balance: 500, created_at: datetimeFromNow(-2), active_tariff: null, tariff_end_date: null, last_visit: null },
  ];
}

/** Кожен інвойс прив'язаний до client_id/tariff_id; для активних клієнтів — активний інвойс, плюс трохи історії */
export function buildInvoices() {
  return [
    { id: 1, client_id: 1, tariff_id: 2, tariff_name: 'Місячний безліміт', status: 'active', start_date: daysFromNow(-18), end_date: daysFromNow(12), price: 1200, paid_amount: 1200, debt: 0, discount: 0, visits_total: null, visits_used: 9, trainer_id: 0, trainer_name: '', freeze_days: 0, freeze_start: null, admin_name: DEMO_USER.full_name, notes: '', created_at: datetimeFromNow(-18) },
    { id: 2, client_id: 1, tariff_id: 2, tariff_name: 'Місячний безліміт', status: 'expired', start_date: daysFromNow(-48), end_date: daysFromNow(-18), price: 1200, paid_amount: 1200, debt: 0, discount: 0, visits_total: null, visits_used: 15, trainer_id: 0, trainer_name: '', freeze_days: 0, freeze_start: null, admin_name: DEMO_USER.full_name, notes: '', created_at: datetimeFromNow(-48) },
    { id: 3, client_id: 2, tariff_id: 3, tariff_name: 'Квартальний', status: 'active', start_date: daysFromNow(-45), end_date: daysFromNow(45), price: 3200, paid_amount: 2700, debt: 500, discount: 5, visits_total: null, visits_used: 28, trainer_id: 0, trainer_name: '', freeze_days: 0, freeze_start: null, admin_name: DEMO_USER.full_name, notes: 'Знижка за друга', created_at: datetimeFromNow(-45) },
    { id: 4, client_id: 3, tariff_id: 2, tariff_name: 'Місячний безліміт', status: 'expired', start_date: daysFromNow(-50), end_date: daysFromNow(-20), price: 1200, paid_amount: 1200, debt: 0, discount: 0, visits_total: null, visits_used: 6, trainer_id: 0, trainer_name: '', freeze_days: 0, freeze_start: null, admin_name: DEMO_USER.full_name, notes: '', created_at: datetimeFromNow(-50) },
    { id: 5, client_id: 5, tariff_id: 4, tariff_name: 'Персональні тренування (8 занять)', status: 'active', start_date: daysFromNow(-40), end_date: daysFromNow(20), price: 4800, paid_amount: 4800, debt: 0, discount: 0, visits_total: 8, visits_used: 5, trainer_id: 1, trainer_name: 'Олена Ковальчук', freeze_days: 0, freeze_start: null, admin_name: DEMO_USER.full_name, notes: '', created_at: datetimeFromNow(-40) },
    { id: 6, client_id: 6, tariff_id: 2, tariff_name: 'Місячний безліміт', status: 'frozen', start_date: daysFromNow(-27), end_date: daysFromNow(3), price: 1200, paid_amount: 1200, debt: 0, discount: 0, visits_total: null, visits_used: 4, trainer_id: 0, trainer_name: '', freeze_days: 5, freeze_start: daysFromNow(-4), admin_name: DEMO_USER.full_name, notes: 'Заморожено через відрядження', created_at: datetimeFromNow(-27) },
    { id: 7, client_id: 8, tariff_id: 1, tariff_name: 'Разовий візит', status: 'active', start_date: daysFromNow(0), end_date: daysFromNow(1), price: 150, paid_amount: 150, debt: 0, discount: 0, visits_total: 1, visits_used: 0, trainer_id: 0, trainer_name: '', freeze_days: 0, freeze_start: null, admin_name: DEMO_USER.full_name, notes: '', created_at: datetimeFromNow(0) },
    { id: 8, client_id: 9, tariff_id: 3, tariff_name: 'Квартальний', status: 'cancelled', start_date: daysFromNow(-100), end_date: daysFromNow(-10), price: 3200, paid_amount: 1600, debt: 1600, discount: 0, visits_total: null, visits_used: 12, trainer_id: 0, trainer_name: '', freeze_days: 0, freeze_start: null, admin_name: DEMO_USER.full_name, notes: 'Скасовано на прохання клієнта', created_at: datetimeFromNow(-100) },
    { id: 9, client_id: 4, tariff_id: 2, tariff_name: 'Місячний безліміт', status: 'expired', start_date: daysFromNow(-90), end_date: daysFromNow(-60), price: 1200, paid_amount: 900, debt: 300, discount: 0, visits_total: null, visits_used: 10, trainer_id: 0, trainer_name: '', freeze_days: 0, freeze_start: null, admin_name: DEMO_USER.full_name, notes: '', created_at: datetimeFromNow(-90) },
    { id: 10, client_id: 7, tariff_id: 1, tariff_name: 'Разовий візит', status: 'expired', start_date: daysFromNow(-3), end_date: daysFromNow(-2), price: 150, paid_amount: 150, debt: 0, discount: 0, visits_total: 1, visits_used: 1, trainer_id: 0, trainer_name: '', freeze_days: 0, freeze_start: null, admin_name: DEMO_USER.full_name, notes: '', created_at: datetimeFromNow(-3) },
    { id: 11, client_id: 10, tariff_id: 2, tariff_name: 'Місячний безліміт', status: 'active', start_date: daysFromNow(5), end_date: daysFromNow(35), price: 1200, paid_amount: 1200, debt: 0, discount: 0, visits_total: null, visits_used: 0, trainer_id: 0, trainer_name: '', freeze_days: 0, freeze_start: null, admin_name: DEMO_USER.full_name, notes: 'Куплено заздалегідь, старт через 5 днів', created_at: datetimeFromNow(-2) },
  ];
}

export function buildInvoicePayments() {
  return [
    { id: 1, invoice_id: 1, amount: 1200, payment_method: 'card', admin_name: DEMO_USER.full_name, notes: '', created_at: datetimeFromNow(-18) },
    { id: 2, invoice_id: 2, amount: 1200, payment_method: 'cash', admin_name: DEMO_USER.full_name, notes: '', created_at: datetimeFromNow(-48) },
    { id: 3, invoice_id: 3, amount: 2000, payment_method: 'card', admin_name: DEMO_USER.full_name, notes: 'Перший платіж', created_at: datetimeFromNow(-45) },
    { id: 4, invoice_id: 3, amount: 700, payment_method: 'cash', admin_name: DEMO_USER.full_name, notes: 'Доплата', created_at: datetimeFromNow(-30) },
    { id: 5, invoice_id: 4, amount: 1200, payment_method: 'terminal', admin_name: DEMO_USER.full_name, notes: '', created_at: datetimeFromNow(-50) },
    { id: 6, invoice_id: 5, amount: 4800, payment_method: 'card', admin_name: DEMO_USER.full_name, notes: '', created_at: datetimeFromNow(-40) },
    { id: 7, invoice_id: 6, amount: 1200, payment_method: 'cash', admin_name: DEMO_USER.full_name, notes: '', created_at: datetimeFromNow(-27) },
    { id: 8, invoice_id: 7, amount: 150, payment_method: 'cash', admin_name: DEMO_USER.full_name, notes: '', created_at: datetimeFromNow(0) },
    { id: 9, invoice_id: 8, amount: 1600, payment_method: 'card', admin_name: DEMO_USER.full_name, notes: '', created_at: datetimeFromNow(-100) },
    { id: 10, invoice_id: 9, amount: 900, payment_method: 'cash', admin_name: DEMO_USER.full_name, notes: '', created_at: datetimeFromNow(-90) },
    { id: 11, invoice_id: 10, amount: 150, payment_method: 'terminal', admin_name: DEMO_USER.full_name, notes: '', created_at: datetimeFromNow(-3) },
  ];
}

/** Прив'язує кожне відвідування до конкретного абонемента (client_id + дата в межах start_date..end_date) —
 * потрібно для вкладки "Відвідування" на картці абонемента (фільтр по invoice_id). */
export function resolveVisitInvoiceIds(invoices, visits) {
  for (const v of visits) {
    const day = v.visited_at.slice(0, 10);
    const inv = invoices.find((i) => i.client_id === v.client_id && i.start_date <= day && i.end_date >= day);
    v.invoice_id = inv ? inv.id : null;
  }
  return visits;
}

export function buildVisits() {
  const rows = [];
  let id = 1;
  const push = (dayOffset, h, m, clientId, clientName, tariffName, trainerName, method) => {
    rows.push({ id: id++, client_id: clientId, client_name: clientName, client_photo: null, tariff_name: tariffName, trainer_name: trainerName || '', method, visited_at: datetimeFromNow(dayOffset, h, m), notes: '' });
  };
  push(0, 7, 45, 6, 'Бондаренко Артем', 'Місячний безліміт', '', 'barcode');
  push(0, 8, 10, 2, 'Петренко Олег', 'Квартальний', '', 'barcode');
  push(0, 9, 20, 8, 'Кравченко Максим', 'Разовий візит', '', 'manual');
  push(-1, 18, 30, 1, 'Іваненко Марія', 'Місячний безліміт', '', 'barcode');
  push(-1, 19, 5, 6, 'Бондаренко Артем', 'Місячний безліміт', '', 'barcode');
  push(-2, 11, 0, 5, 'Мельник Софія', 'Персональні тренування (8 занять)', 'Олена Ковальчук', 'admin');
  push(-2, 17, 40, 2, 'Петренко Олег', 'Квартальний', '', 'barcode');
  push(-3, 20, 0, 7, 'Ткаченко Юлія', 'Разовий візит', '', 'manual');
  push(-3, 8, 15, 1, 'Іваненко Марія', 'Місячний безліміт', '', 'barcode');
  push(-4, 12, 30, 6, 'Бондаренко Артем', 'Місячний безліміт', '', 'barcode');
  push(-4, 19, 0, 2, 'Петренко Олег', 'Квартальний', '', 'barcode');
  push(-5, 9, 0, 1, 'Іваненко Марія', 'Місячний безліміт', '', 'barcode');
  push(-5, 16, 20, 5, 'Мельник Софія', 'Персональні тренування (8 занять)', 'Олена Ковальчук', 'admin');
  push(-6, 10, 10, 6, 'Бондаренко Артем', 'Місячний безліміт', '', 'barcode');
  push(-6, 18, 45, 2, 'Петренко Олег', 'Квартальний', '', 'barcode');
  push(-15, 18, 0, 9, 'Гриценко Дарина', 'Квартальний', '', 'barcode');
  push(-20, 19, 0, 3, 'Коваль Анна', 'Місячний безліміт', '', 'manual');
  push(-60, 17, 15, 4, 'Сидоренко Ігор', 'Місячний безліміт', '', 'barcode');
  return rows;
}

export function buildProducts() {
  return [
    { id: 1, name: 'Вода негазована 0.5л', category: 'Напої', supplier: 'АкваСвіт', purchase_price: 8, sale_price: 20, stock_qty: 42, stock_min: 15, barcode: '4820000001', photo_url: '', is_active: true },
    { id: 2, name: 'Протеїновий батончик', category: 'Снеки', supplier: 'FitFood', purchase_price: 25, sale_price: 55, stock_qty: 18, stock_min: 10, barcode: '4820000002', photo_url: '', is_active: true },
    { id: 3, name: 'Сироватковий протеїн 1кг', category: 'Спортхарчування', supplier: 'PowerNutrition', purchase_price: 650, sale_price: 950, stock_qty: 6, stock_min: 5, barcode: '4820000003', photo_url: '', is_active: true },
    { id: 4, name: 'Ізотонік 750мл', category: 'Напої', supplier: 'АкваСвіт', purchase_price: 30, sale_price: 65, stock_qty: 3, stock_min: 10, barcode: '4820000004', photo_url: '', is_active: true },
    { id: 5, name: 'Рушник спортивний', category: 'Аксесуари', supplier: 'TextilePro', purchase_price: 90, sale_price: 180, stock_qty: 12, stock_min: 5, barcode: '4820000005', photo_url: '', is_active: true },
    { id: 6, name: 'Гумки для фітнесу (набір)', category: 'Аксесуари', supplier: 'TextilePro', purchase_price: 120, sale_price: 250, stock_qty: 9, stock_min: 4, barcode: '4820000006', photo_url: '', is_active: true },
    { id: 7, name: 'Магнезія 56г', category: 'Спортхарчування', supplier: 'PowerNutrition', purchase_price: 45, sale_price: 90, stock_qty: 0, stock_min: 5, barcode: '4820000007', photo_url: '', is_active: true },
    { id: 8, name: 'Шейкер 600мл', category: 'Аксесуари', supplier: 'TextilePro', purchase_price: 60, sale_price: 130, stock_qty: 14, stock_min: 5, barcode: '4820000008', photo_url: '', is_active: true },
    { id: 9, name: 'Енергетичний батончик', category: 'Снеки', supplier: 'FitFood', purchase_price: 22, sale_price: 48, stock_qty: 27, stock_min: 10, barcode: '4820000009', photo_url: '', is_active: true },
    { id: 10, name: 'Одноразові рушники (уп.)', category: 'Аксесуари', supplier: 'TextilePro', purchase_price: 40, sale_price: 0, stock_qty: 5, stock_min: 8, barcode: '4820000010', photo_url: '', is_active: false },
  ];
}

export function buildArrivals() {
  return [
    { id: 1, product_id: 1, product_name: 'Вода негазована 0.5л', category: 'Напої', operation: 'arrival', status: 'paid', quantity: 24, expected_qty: 24, purchase_price: 8, total_cost: 192, supplier: 'АкваСвіт', admin_name: DEMO_USER.full_name, notes: '', created_at: datetimeFromNow(-6, 9, 0) },
    { id: 2, product_id: 3, product_name: 'Сироватковий протеїн 1кг', category: 'Спортхарчування', operation: 'arrival', status: 'paid', quantity: 6, expected_qty: 6, purchase_price: 650, total_cost: 3900, supplier: 'PowerNutrition', admin_name: DEMO_USER.full_name, notes: '', created_at: datetimeFromNow(-5, 10, 30) },
    { id: 3, product_id: 4, product_name: 'Ізотонік 750мл', category: 'Напої', operation: 'arrival', status: 'unpaid', quantity: 20, expected_qty: 20, purchase_price: 30, total_cost: 600, supplier: 'АкваСвіт', admin_name: DEMO_USER.full_name, notes: 'Оплата по факту реалізації', created_at: datetimeFromNow(-4, 11, 0) },
    { id: 4, product_id: 2, product_name: 'Протеїновий батончик', category: 'Снеки', operation: 'arrival', status: 'pending', quantity: 30, expected_qty: 30, purchase_price: 25, total_cost: 750, supplier: 'FitFood', admin_name: DEMO_USER.full_name, notes: 'Очікується доставка', created_at: datetimeFromNow(-1, 15, 0) },
    { id: 5, product_id: 7, product_name: 'Магнезія 56г', category: 'Спортхарчування', operation: 'arrival', status: 'paid', quantity: 10, expected_qty: 10, purchase_price: 45, total_cost: 450, supplier: 'PowerNutrition', admin_name: DEMO_USER.full_name, notes: '', created_at: datetimeFromNow(-20, 9, 0) },
    { id: 6, product_id: 7, product_name: 'Магнезія 56г', category: 'Спортхарчування', operation: 'overdue', status: 'paid', quantity: 10, expected_qty: 10, purchase_price: 45, total_cost: -450, supplier: 'PowerNutrition', admin_name: DEMO_USER.full_name, notes: 'Прострочений товар списано', created_at: datetimeFromNow(-2, 12, 0) },
    { id: 7, product_id: 8, product_name: 'Шейкер 600мл', category: 'Аксесуари', operation: 'arrival', status: 'paid', quantity: 15, expected_qty: 15, purchase_price: 60, total_cost: 900, supplier: 'TextilePro', admin_name: DEMO_USER.full_name, notes: '', created_at: datetimeFromNow(-14, 9, 0) },
  ];
}

export function buildSales() {
  const rows = [];
  let id = 1;
  const push = (dayOffset, h, m, productId, productName, qty, salePrice, purchasePrice, discount, clientId, clientName, method) => {
    const total = qty * salePrice - discount;
    rows.push({ id: id++, product_id: productId, product_name: productName, category: '', quantity: qty, sale_price: salePrice, purchase_price: purchasePrice, discount, total_amount: total, client_id: clientId, client_name: clientName || '', payment_method: method, admin_name: DEMO_USER.full_name, notes: '', sale_date: daysFromNow(dayOffset), created_at: datetimeFromNow(dayOffset, h, m) });
  };
  push(0, 8, 5, 1, 'Вода негазована 0.5л', 2, 20, 8, 0, 2, 'Петренко Олег', 'cash');
  push(0, 9, 25, 9, 'Енергетичний батончик', 1, 48, 22, 0, 8, 'Кравченко Максим', 'card');
  push(-1, 18, 40, 8, 'Шейкер 600мл', 1, 130, 60, 10, 1, 'Іваненко Марія', 'terminal');
  push(-1, 19, 10, 1, 'Вода негазована 0.5л', 3, 20, 8, 0, 6, 'Бондаренко Артем', 'cash');
  push(-2, 11, 15, 3, 'Сироватковий протеїн 1кг', 1, 950, 650, 50, 5, 'Мельник Софія', 'deposit');
  push(-2, 17, 50, 2, 'Протеїновий батончик', 2, 55, 25, 0, 2, 'Петренко Олег', 'card');
  push(-3, 20, 5, 9, 'Енергетичний батончик', 2, 48, 22, 0, 7, 'Ткаченко Юлія', 'cash');
  push(-4, 12, 40, 6, 'Гумки для фітнесу (набір)', 1, 250, 120, 0, 6, 'Бондаренко Артем', 'card');
  push(-5, 9, 10, 1, 'Вода негазована 0.5л', 1, 20, 8, 0, 1, 'Іваненко Марія', 'cash');
  push(-6, 10, 20, 5, 'Рушник спортивний', 1, 180, 90, 0, 6, 'Бондаренко Артем', 'terminal');
  push(-7, 15, 0, 9, 'Енергетичний батончик', 3, 48, 22, 0, null, '', 'cash');
  push(-9, 13, 30, 8, 'Шейкер 600мл', 1, 130, 60, 0, 2, 'Петренко Олег', 'card');
  return rows;
}

/** Чеки для нової "Продаж товарів" (sale_orders): кілька позицій, статус, номер чека */
export function buildSaleOrders() {
  const mk = (id, h, m, clientName, items, method, status, extra = {}) => {
    const orderItems = items.map((it, i) => ({
      id: id * 100 + i, product_id: it.product_id, product_name: it.product_name,
      quantity: it.quantity, sale_price: it.sale_price, discount: it.discount || 0,
      total_amount: it.quantity * it.sale_price - (it.discount || 0),
    }));
    const subtotal = orderItems.reduce((sum, it) => sum + it.quantity * it.sale_price, 0);
    const discount_amount = orderItems.reduce((sum, it) => sum + it.discount, 0);
    return {
      id, order_number: id, club_id: DEMO_CLUB.id,
      client_id: null, client_name: clientName,
      items: orderItems, items_count: orderItems.length,
      subtotal, discount_amount, total_amount: subtotal - discount_amount,
      payment_method: method, status,
      shift_id: null, admin_id: DEMO_USER.id, admin_name: DEMO_USER.full_name,
      notes: '', return_reason: extra.return_reason || null,
      returned_at: extra.returned_at || null, returned_by_name: extra.returned_at ? DEMO_USER.full_name : null,
      created_at: datetimeFromNow(0, h, m),
    };
  };

  return [
    mk(1257, 10, 42, 'Іваненко Тарас', [
      { product_id: 3, product_name: 'Сироватковий протеїн 1кг', quantity: 1, sale_price: 1200 },
      { product_id: 5, product_name: 'Рушник спортивний', quantity: 2, sale_price: 400 },
      { product_id: 8, product_name: 'Шейкер 600мл', quantity: 1, sale_price: 240 },
    ], 'card', 'completed'),
    mk(1256, 10, 15, 'Петренко Марія', [
      { product_id: 2, product_name: 'Протеїновий батончик', quantity: 2, sale_price: 90 },
      { product_id: 3, product_name: 'Сироватковий протеїн 1кг', quantity: 1, sale_price: 800 },
    ], 'cash', 'completed'),
    mk(1255, 9, 58, 'Сидоренко Андрій', [
      { product_id: 3, product_name: 'Сироватковий протеїн 1кг', quantity: 1, sale_price: 950 },
      { product_id: 8, product_name: 'Шейкер 600мл', quantity: 1, sale_price: 300 },
      { product_id: 5, product_name: 'Рушник спортивний', quantity: 1, sale_price: 200 },
      { product_id: 6, product_name: 'Гумки для фітнесу (набір)', quantity: 1, sale_price: 110 },
    ], 'card', 'completed'),
    mk(1254, 9, 30, 'Ковальчук Ігор', [
      { product_id: 7, product_name: 'Магнезія 56г', quantity: 1, sale_price: 350 },
    ], 'cash', 'returned', { returned_at: datetimeFromNow(0, 9, 45), return_reason: 'Клієнт передумав' }),
    mk(1253, 9, 12, 'Мельник Ольга', [
      { product_id: 5, product_name: 'Рушник спортивний', quantity: 1, sale_price: 300 },
      { product_id: 6, product_name: 'Гумки для фітнесу (набір)', quantity: 1, sale_price: 320 },
    ], 'card', 'completed'),
  ];
}

export function buildSupportTickets() {
  return [
    { id: 1, club_id: DEMO_CLUB.id, subject: 'Не приходять email-квитанції клієнтам', status: 'in_progress', unread_by_club: true, unread_by_admin: false, created_at: datetimeFromNow(-3, 10, 0), last_message_at: datetimeFromNow(-1, 14, 20) },
    { id: 2, club_id: DEMO_CLUB.id, subject: 'Як налаштувати нагадування в Telegram?', status: 'resolved', unread_by_club: false, unread_by_admin: false, created_at: datetimeFromNow(-10, 9, 0), last_message_at: datetimeFromNow(-9, 16, 0) },
  ];
}

export function buildSupportMessages() {
  return [
    { id: 1, ticket_id: 1, sender_type: 'club', sender_name: DEMO_USER.full_name, message: 'Вітаю! Клієнти скаржаться, що не приходять email з квитанцією про оплату абонементу. Підкажіть, як це виправити?', created_at: datetimeFromNow(-3, 10, 0) },
    { id: 2, ticket_id: 1, sender_type: 'admin', sender_name: 'Підтримка Sport CRM', message: 'Доброго дня! Перевіряємо налаштування пошти для вашого клубу, повернемось з відповіддю найближчим часом.', created_at: datetimeFromNow(-2, 11, 30) },
    { id: 3, ticket_id: 1, sender_type: 'admin', sender_name: 'Підтримка Sport CRM', message: 'Проблему знайдено — листи потрапляли у спам через відсутній SPF-запис. Виправили, перевірте, будь ласка, найближчу оплату.', created_at: datetimeFromNow(-1, 14, 20) },
    { id: 4, ticket_id: 2, sender_type: 'club', sender_name: DEMO_USER.full_name, message: 'Де в налаштуваннях увімкнути нагадування клієнтам про закінчення абонементу через Telegram?', created_at: datetimeFromNow(-10, 9, 0) },
    { id: 5, ticket_id: 2, sender_type: 'admin', sender_name: 'Підтримка Sport CRM', message: 'Розділ "Налаштування" → вкладка Telegram, там перемикач "Нагадування про закінчення". За потреби — можемо допомогти прив\'язати бота.', created_at: datetimeFromNow(-9, 16, 0) },
  ];
}

/**
 * Версія форми стану демо-сесії. Піднімайте це число щоразу, коли змінюєте форму об'єкта
 * buildSeed() (нове поле стору, нова структура запису тощо) — demoStore.load() звіряє його
 * зі збереженим у sessionStorage станом і скидає застарілий кеш на свіжий buildSeed(), інакше
 * стара сесія клієнта (відкрита вкладка з часів до деплою) падає з "Помилка демо-режиму" на
 * кожному виклику, що торкається нового поля.
 */
export const SEED_VERSION = 4;

/** Свіжий стан демо-даних для нової сесії */
export function buildSeed() {
  const cash = buildCashLedger();
  const invoices = buildInvoices();
  const visits = resolveVisitInvoiceIds(invoices, buildVisits());
  return {
    _v: SEED_VERSION,
    tariffs: DEMO_TARIFFS.map((t) => ({ ...t })),
    trainers: DEMO_TRAINERS.map((t) => ({ ...t })),
    staff: buildStaff(),
    clients: buildClients(),
    invoices,
    invoicePayments: buildInvoicePayments(),
    visits,
    products: buildProducts(),
    arrivals: buildArrivals(),
    sales: buildSales(),
    saleOrders: buildSaleOrders(),
    supportTickets: buildSupportTickets(),
    supportMessages: buildSupportMessages(),
    trainerEarnings: buildTrainerEarnings(),
    trainerRent: buildTrainerRent(),
    salarySettings: buildSalarySettings(),
    payroll: buildPayroll(),
    expenses: buildExpenses(),
    deposits: buildDeposits(),
    cashLedger: cash.rows,
    cashBase: cash.baseBalance,
    cashShift: null,
    cashBalance: cash.endingBalance,
    clubSettings: { name: DEMO_CLUB.name, city: 'Київ', phone: '+380671234500', email: DEMO_USER.email, address: 'вул. Хрещатик, 1', timezone: 'Europe/Kyiv', currency: 'UAH' },
    permissionOverrides: [],
    nextId: {
      client: 11, invoice: 12, payment: 12, tariff: 6, product: 11, arrival: 8, sale: 13, saleOrder: 1258,
      visit: 19, supportTicket: 3, supportMessage: 6, expense: 6, deposit: 5, cashRow: cash.rows.length + 1,
      trainerEarning: 6, trainerRent: 3, payroll: 3, staff: 104,
    },
  };
}
