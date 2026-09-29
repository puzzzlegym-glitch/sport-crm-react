import { getStore, nextId, persist } from './demoStore';
import { DEMO_USER, DEMO_CLUB, DEMO_ROLES, daysFromNow, datetimeFromNow } from './seedData';

function pad(n) { return String(n).padStart(2, '0'); }
function fmtDate(d) { return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`; }
function addDays(dateStr, days) {
  const d = new Date(`${dateStr}T00:00:00`);
  d.setDate(d.getDate() + days);
  return fmtDate(d);
}
function nowDatetime() { return datetimeFromNow(0, new Date().getHours(), new Date().getMinutes()); }
function digits(s) { return (s || '').toString().replace(/\D/g, ''); }
function ok(data = {}) { return { success: true, ...data }; }
function fail(error, extra = {}) { return { success: false, error, ...extra }; }
function delay(data) { return new Promise((resolve) => setTimeout(() => resolve(data), 200)); }

function paginate(list, page = 1, perPage = 25) {
  const total = list.length;
  const pages = Math.max(1, Math.ceil(total / perPage));
  const p = Math.min(Math.max(1, Number(page) || 1), pages);
  const start = (p - 1) * perPage;
  return { rows: list.slice(start, start + perPage), pagination: { total, page: p, pages, per_page: perPage } };
}

function sortBy(list, order, dir) {
  if (!order) return list;
  const mul = dir === 'asc' ? 1 : -1;
  return [...list].sort((a, b) => {
    const av = a[order], bv = b[order];
    if (av == null && bv == null) return 0;
    if (av == null) return 1;
    if (bv == null) return -1;
    if (typeof av === 'string') return av.localeCompare(bv) * mul;
    return (av - bv) * mul;
  });
}

function findClient(s, id) { return s.clients.find((c) => c.id === Number(id)); }
function findTariff(s, id) { return s.tariffs.find((t) => t.id === Number(id)); }
function findProduct(s, id) { return s.products.find((p) => p.id === Number(id)); }
function findInvoice(s, id) { return s.invoices.find((i) => i.id === Number(id)); }
function findStaff(s, id) { return s.staff.find((u) => u.id === Number(id)); }
function findTrainer(s, id) { return s.trainers.find((t) => t.id === Number(id)); }
function roleById(id) { return DEMO_ROLES.find((r) => r.id === Number(id)); }
function roleBySlug(slug) { return DEMO_ROLES.find((r) => r.slug === slug); }

function stockStatus(p) {
  if (p.stock_qty <= 0) return 'out';
  if (p.stock_qty <= p.stock_min) return 'low';
  return 'ok';
}
function totalSold(s, productId) {
  return s.sales.filter((r) => r.product_id === productId).reduce((sum, r) => sum + r.quantity, 0);
}

// ---------- clients ----------
function handleClients(action, body, s) {
  if (action === 'get_list') {
    const { search = '', status = '', page = 1, per_page = 25, order = 'created_at', dir = 'desc' } = body;
    let list = s.clients;
    if (status) list = list.filter((c) => c.status === status);
    if (search.trim()) {
      const q = search.trim().toLowerCase();
      const qd = digits(search);
      list = list.filter((c) => c.full_name.toLowerCase().includes(q) || (qd && digits(c.phone).includes(qd)));
    }
    list = sortBy(list, order, dir);
    const { rows, pagination } = paginate(list, page, per_page);
    return ok({ clients: rows, pagination });
  }
  if (action === 'get_one') {
    const client = findClient(s, body.id);
    if (!client) return fail('Клієнта не знайдено');
    const invoices = s.invoices.filter((i) => i.client_id === client.id).sort((a, b) => b.created_at.localeCompare(a.created_at));
    const visits = s.visits.filter((v) => v.client_id === client.id).sort((a, b) => b.visited_at.localeCompare(a.visited_at));
    const total_debt = invoices.filter((i) => i.status !== 'cancelled').reduce((sum, i) => sum + (i.debt || 0), 0);
    return ok({ client, total_debt, invoices, visits });
  }
  if (action === 'get_activity') {
    const client = findClient(s, body.id);
    if (!client) return fail('Клієнта не знайдено');
    const visitEvents = s.visits
      .filter((v) => v.client_id === client.id)
      .map((v) => ({ type: 'visit', event_at: v.visited_at, amount: null, payment_method: null, tariff_name: null, notes: v.notes || '' }));
    const paymentEvents = s.invoicePayments
      .filter((p) => { const inv = findInvoice(s, p.invoice_id); return inv && inv.client_id === client.id; })
      .map((p) => {
        const inv = findInvoice(s, p.invoice_id);
        return { type: 'payment', event_at: p.created_at, amount: p.amount, payment_method: p.payment_method, tariff_name: inv?.tariff_name || null, notes: p.notes || '' };
      });
    const saleEvents = s.saleOrders
      .filter((o) => o.client_id === client.id && o.status === 'completed')
      .map((o) => ({ type: 'sale', event_at: o.created_at, amount: o.total_amount, payment_method: o.payment_method, tariff_name: null, notes: `${o.items_count}x ${o.items?.[0]?.product_name || ''}` }));
    const activity = [...visitEvents, ...paymentEvents, ...saleEvents]
      .sort((a, b) => b.event_at.localeCompare(a.event_at))
      .slice(0, 10);
    return ok({ activity });
  }
  if (action === 'create') {
    const client = {
      id: nextId('client'), full_name: '', phone: '', email: '', birthday: '', gender: '', address: '',
      status: 'regular', status_reason: '', status_changed_at: nowDatetime(), source: '', notes: '',
      balance: 0, active_tariff: null, tariff_end_date: null, last_visit: null, created_at: nowDatetime(),
      ...body,
    };
    s.clients.push(client);
    return ok();
  }
  if (action === 'update') {
    const client = findClient(s, body.id);
    if (!client) return fail('Клієнта не знайдено');
    Object.assign(client, body);
    return ok();
  }
  if (action === 'import') {
    const rows = Array.isArray(body.rows) ? body.rows : [];
    const existingPhones = new Set(s.clients.map((c) => digits(c.phone)).filter(Boolean));
    const existingEmails = new Set(s.clients.map((c) => (c.email || '').toLowerCase()).filter(Boolean));
    let added = 0;
    const skipped = [];
    rows.forEach((row, i) => {
      const rowNum = i + 2;
      const name = (row.full_name || '').trim();
      if (name.length < 2) { skipped.push({ row: rowNum, name, reason: 'Немає ПІБ (мінімум 2 символи)' }); return; }
      const phone = (row.phone || '').trim();
      const phoneD = digits(phone);
      if (phone && existingPhones.has(phoneD)) { skipped.push({ row: rowNum, name, reason: `Клієнт з телефоном ${phone} вже є в клубі` }); return; }
      const email = (row.email || '').trim().toLowerCase();
      if (email && existingEmails.has(email)) { skipped.push({ row: rowNum, name, reason: `Клієнт з email ${row.email} вже є в клубі` }); return; }
      s.clients.push({
        id: nextId('client'), full_name: name, phone, email: row.email || '', birthday: row.birthday || '',
        gender: row.gender || '', address: row.address || '', status: row.status === 'premium' ? 'premium' : 'regular',
        status_reason: '', status_changed_at: nowDatetime(), source: row.source || '', notes: row.notes || '',
        balance: 0, active_tariff: null, tariff_end_date: null, last_visit: null, created_at: nowDatetime(),
      });
      added++;
      if (phoneD) existingPhones.add(phoneD);
      if (email) existingEmails.add(email);
    });
    return ok({ added, skipped });
  }
  if (action === 'search') {
    const q = (body.q || '').trim().toLowerCase();
    const qd = digits(body.q);
    if (!q) return ok({ results: [] });
    const results = s.clients
      .filter((c) => c.full_name.toLowerCase().includes(q) || (qd && digits(c.phone).includes(qd)))
      .slice(0, 8)
      .map((c) => ({ id: c.id, full_name: c.full_name, phone: c.phone }));
    return ok({ results });
  }
  return fail('Невідома дія');
}

// ---------- invoices ----------
// Реальний статус рахується з дат/відвідувань, а не зі збереженого поля status
// (frozen/cancelled лишаються ручними станами) — дзеркалить логіку invoices_api.php.
// Рівно 5 статусів: future / active / frozen / finished / cancelled.
function effectiveStatus(inv) {
  if (inv.status === 'cancelled' || inv.status === 'frozen') return inv.status;
  const today = daysFromNow(0);
  if (inv.start_date > today) return 'future';
  if (inv.end_date < today) return 'finished';
  if (inv.visits_total != null && inv.visits_used >= inv.visits_total) return 'finished';
  return 'active';
}

function invoiceWithClient(s, inv) {
  const c = findClient(s, inv.client_id);
  const t = findTariff(s, inv.tariff_id);
  const days_left = Math.ceil((new Date(`${inv.end_date}T00:00:00`) - new Date(`${daysFromNow(0)}T00:00:00`)) / 86400000);
  return {
    ...inv, status: effectiveStatus(inv), client_name: c?.full_name || '—', client_phone: c?.phone || '', days_left,
    tariff_freeze_max: t?.freeze_days_max || 0, tariff_freeze_min: t?.freeze_days_min || 0,
  };
}

function handleInvoices(action, body, s) {
  // Групові абонементи в демо не емулюються — порожній список замість помилки.
  if (action === 'group_list') return ok({ groups: [] });
  if (action.startsWith('group_')) return fail('Групові абонементи недоступні в демо-режимі');
  if (action === 'get_tariffs') {
    return ok({ tariffs: s.tariffs.filter((t) => t.is_active).map((t) => ({ id: t.id, name: t.name, price: t.price, duration_days: t.duration_days, visits_limit: t.visits_limit, description: t.description, has_trainer: !!t.has_trainer })) });
  }
  if (action === 'get_list') {
    const { search = '', status = '', page = 1 } = body;
    let list = s.invoices.map((inv) => invoiceWithClient(s, inv));
    if (status) list = list.filter((i) => i.status === status);
    if (search.trim()) {
      const q = search.trim().toLowerCase();
      const qd = digits(search);
      list = list.filter((i) => i.client_name.toLowerCase().includes(q) || (qd && digits(i.client_phone).includes(qd)));
    }
    list.sort((a, b) => b.created_at.localeCompare(a.created_at));
    const { rows, pagination } = paginate(list, page, 25);
    return ok({ invoices: rows, pagination });
  }
  if (action === 'get_one') {
    const invoice = findInvoice(s, body.id);
    if (!invoice) return fail('Абонемент не знайдено');
    const payments = s.invoicePayments.filter((p) => p.invoice_id === invoice.id).sort((a, b) => b.created_at.localeCompare(a.created_at));
    return ok({ invoice: invoiceWithClient(s, invoice), payments });
  }
  if (action === 'create') {
    const { client_id, tariff_id, start_date, discount = 0, paid_amount = 0, payment_method = 'cash', notes = '' } = body;
    const client = findClient(s, client_id);
    const tariff = findTariff(s, tariff_id);
    if (!client || !tariff) return fail('Клієнта або тариф не знайдено');
    const clientInvoices = s.invoices.filter((i) => i.client_id === client.id);
    const existingDebt = clientInvoices
      .filter((i) => i.status === 'active' || i.status === 'frozen')
      .reduce((sum, i) => sum + Math.max(0, i.price - i.paid_amount), 0);
    if (existingDebt > 0.01) {
      return fail(`У клієнта непогашений борг ${Math.round(existingDebt)} грн за попередній абонемент. Продаж нового абонемента недоступний до повного розрахунку.`);
    }
    if (body.trainer_id && !tariff.has_trainer) return fail('Цей тариф не передбачає призначення тренера');
    // Тип продажу — рахується системою автоматично, дзеркалить invoices_api.php.
    const saleType = clientInvoices.length === 0
      ? 'new'
      : clientInvoices.some((i) => effectiveStatus(i) === 'active' || effectiveStatus(i) === 'frozen')
        ? 'renewal' : 'return';
    const price = Math.round(tariff.price * (1 - (parseFloat(discount) || 0) / 100));
    // -1, бо статус "активний" тримається включно по end_date (дзеркалить invoices_api.php).
    const end_date = addDays(start_date, tariff.duration_days - 1);
    const trainerId = tariff.has_trainer ? (Number(body.trainer_id) || 0) : 0;
    const invoice = {
      id: nextId('invoice'), client_id: client.id, tariff_id: tariff.id, tariff_name: tariff.name,
      status: 'active', sale_type: saleType, start_date, end_date, price, paid_amount: parseFloat(paid_amount) || 0,
      debt: Math.max(0, price - (parseFloat(paid_amount) || 0)), discount: parseFloat(discount) || 0,
      visits_total: tariff.visits_limit || null, visits_used: 0, trainer_id: trainerId,
      trainer_name: trainerId ? (s.trainers.find((t) => t.id === trainerId)?.full_name || '') : '',
      freeze_days: 0, freeze_start: null, admin_name: DEMO_USER.full_name, notes, created_at: nowDatetime(),
    };
    s.invoices.push(invoice);
    if (invoice.paid_amount > 0) {
      s.invoicePayments.push({ id: nextId('payment'), invoice_id: invoice.id, amount: invoice.paid_amount, payment_method, admin_name: DEMO_USER.full_name, notes: '', created_at: nowDatetime() });
    }
    client.active_tariff = tariff.name;
    client.tariff_end_date = end_date;
    return ok({ id: invoice.id });
  }
  if (action === 'update') {
    const invoice = findInvoice(s, body.id);
    if (!invoice) return fail('Абонемент не знайдено');
    const tariff = findTariff(s, body.tariff_id);
    if (tariff) {
      invoice.tariff_id = tariff.id;
      invoice.tariff_name = tariff.name;
    }
    invoice.start_date = body.start_date;
    invoice.end_date = tariff ? addDays(body.start_date, tariff.duration_days - 1) : invoice.end_date;
    invoice.price = tariff ? tariff.price : invoice.price;
    invoice.visits_total = tariff ? (tariff.visits_limit || null) : invoice.visits_total;
    invoice.debt = Math.max(0, invoice.price - invoice.paid_amount);
    invoice.trainer_id = (tariff && tariff.has_trainer) ? (Number(body.trainer_id) || 0) : 0;
    invoice.trainer_name = invoice.trainer_id ? (s.trainers.find((t) => t.id === invoice.trainer_id)?.full_name || '') : '';
    invoice.notes = body.notes || '';
    return ok();
  }
  if (action === 'add_payment') {
    const invoice = findInvoice(s, body.invoice_id);
    if (!invoice) return fail('Абонемент не знайдено');
    const amount = parseFloat(body.amount) || 0;
    s.invoicePayments.push({ id: nextId('payment'), invoice_id: invoice.id, amount, payment_method: body.payment_method, admin_name: DEMO_USER.full_name, notes: '', created_at: nowDatetime() });
    invoice.paid_amount = (invoice.paid_amount || 0) + amount;
    invoice.debt = Math.max(0, invoice.price - invoice.paid_amount);
    return ok();
  }
  if (action === 'freeze') {
    const invoice = findInvoice(s, body.id);
    if (!invoice) return fail('Абонемент не знайдено');
    if (invoice.status === 'frozen') {
      // Реально використані дні заморозки: від freeze_start до сьогодні, не більше запланованого
      const today = daysFromNow(0);
      const actualDays = Math.max(0, Math.round((new Date(`${today}T00:00:00`) - new Date(`${invoice.freeze_start}T00:00:00`)) / 86400000));
      const usedFreeze = Math.min(actualDays, invoice.freeze_days || 0);
      const unusedFreeze = (invoice.freeze_days || 0) - usedFreeze;
      invoice.end_date = addDays(invoice.end_date, -unusedFreeze);
      invoice.status = 'active';
      invoice.freeze_days = usedFreeze;
      invoice.freeze_start = null;
      return ok({ message: `Розморожено. Використано ${usedFreeze} дн. заморозки.` });
    }
    const today = daysFromNow(0);
    const freezeStart = body.freeze_start && body.freeze_start >= today ? body.freeze_start : today;
    const minFreezeStart = addDays(invoice.start_date, 1);
    const maxFreezeStart = addDays(invoice.end_date, -1);
    if (invoice.status !== 'active' || freezeStart < minFreezeStart || freezeStart > maxFreezeStart) {
      return fail(`Заморозка можлива лише в межах дії абонементу: з ${minFreezeStart} по ${maxFreezeStart}`);
    }
    const freezeDays = Number(body.days) || 0;
    const tariff = findTariff(s, invoice.tariff_id);
    if (tariff?.freeze_days_max > 0 && freezeDays > tariff.freeze_days_max) {
      return fail(`Максимум заморозки для цього тарифу: ${tariff.freeze_days_max} дн.`);
    }
    if (tariff?.freeze_days_min > 0 && freezeDays < tariff.freeze_days_min) {
      return fail(`Мінімум заморозки для цього тарифу: ${tariff.freeze_days_min} дн.`);
    }
    invoice.status = 'frozen';
    invoice.freeze_days = freezeDays;
    invoice.freeze_start = freezeStart;
    return ok({ message: 'Абонемент заморожено' });
  }
  if (action === 'cancel_freeze') {
    const invoice = findInvoice(s, body.id);
    if (!invoice || invoice.status !== 'frozen') return fail('Абонемент не знайдено або не заморожений');
    const revertedEnd = addDays(invoice.end_date, -(invoice.freeze_days || 0));
    const lastVisit = s.visits
      .filter((v) => v.client_id === invoice.client_id && v.visited_at.slice(0, 10) > revertedEnd && v.visited_at.slice(0, 10) <= invoice.end_date)
      .sort((a, b) => b.visited_at.localeCompare(a.visited_at))[0];
    if (lastVisit) {
      return fail(`Неможливо відмінити заморозку: є відвідування від ${lastVisit.visited_at.slice(0, 10)}, яке вийде за межі терміну абонементу після скасування. Скористайтесь «Розморозити».`);
    }
    invoice.status = 'active';
    invoice.freeze_days = 0;
    invoice.freeze_start = null;
    invoice.end_date = revertedEnd;
    return ok({ message: `Заморозку відмінено. Термін абонементу: до ${revertedEnd}.` });
  }
  if (action === 'update_freeze_days') {
    const invoice = findInvoice(s, body.id);
    if (!invoice || invoice.status !== 'frozen') return fail('Абонемент не знайдено або не заморожений');
    const newDays = Number(body.days);
    if (!newDays || newDays < 1) return fail('Вкажіть кількість днів заморозки');
    const tariff = findTariff(s, invoice.tariff_id);
    if (tariff?.freeze_days_max > 0 && newDays > tariff.freeze_days_max) {
      return fail(`Максимум заморозки для цього тарифу: ${tariff.freeze_days_max} дн.`);
    }
    if (tariff?.freeze_days_min > 0 && newDays < tariff.freeze_days_min) {
      return fail(`Мінімум заморозки для цього тарифу: ${tariff.freeze_days_min} дн.`);
    }
    const newEnd = addDays(invoice.end_date, newDays - (invoice.freeze_days || 0));
    const lastVisit = s.visits
      .filter((v) => v.client_id === invoice.client_id && v.visited_at.slice(0, 10) > newEnd)
      .sort((a, b) => b.visited_at.localeCompare(a.visited_at))[0];
    if (lastVisit) {
      return fail(`Неможливо змінити на ${newDays} дн.: є відвідування від ${lastVisit.visited_at.slice(0, 10)}, яке вийде за межі терміну абонементу.`);
    }
    invoice.end_date = newEnd;
    invoice.freeze_days = newDays;
    return ok({ message: `Кількість днів заморозки змінено на ${newDays}. Новий термін дії: до ${newEnd}.` });
  }
  if (action === 'cancel') {
    const invoice = findInvoice(s, body.id);
    if (!invoice) return fail('Абонемент не знайдено');
    const reason = (body.reason || '').trim();
    if (!reason) return fail('Вкажіть причину дострокового скасування');
    if (effectiveStatus(invoice) !== 'active') return fail('Достроково скасувати можна лише діючий абонемент');
    invoice.status = 'cancelled';
    invoice.notes = [invoice.notes, `[Дострокове припинення: ${reason}]`].filter(Boolean).join(' ');
    return ok();
  }
  if (action === 'restore') {
    const invoice = findInvoice(s, body.id);
    if (!invoice) return fail('Абонемент не знайдено');
    invoice.status = 'active';
    return ok();
  }
  return fail('Невідома дія');
}

// ---------- tariffs ----------
function handleTariffs(action, body, s) {
  if (action === 'get_list') {
    const archived = !!Number(body.archived);
    return ok({ tariffs: s.tariffs.filter((t) => t.is_active === !archived) });
  }
  if (action === 'create') {
    const tariff = { id: nextId('tariff'), is_active: true, usage_total: 0, usage_active: 0, ...body };
    s.tariffs.push(tariff);
    return ok({ message: 'Тариф створено' });
  }
  if (action === 'update') {
    const tariff = s.tariffs.find((t) => t.id === Number(body.id));
    if (!tariff) return fail('Тариф не знайдено');
    Object.assign(tariff, body);
    return ok({ message: 'Збережено' });
  }
  if (action === 'archive') {
    const tariff = s.tariffs.find((t) => t.id === Number(body.id));
    if (!tariff) return fail('Тариф не знайдено');
    tariff.is_active = !tariff.is_active;
    return ok({ message: tariff.is_active ? 'Тариф відновлено з архіву' : 'Тариф переміщено в архів' });
  }
  return fail('Невідома дія');
}

// ---------- visits ----------
function activeInvoiceFor(s, clientId) {
  const today = daysFromNow(0);
  return s.invoices.find((i) => i.client_id === clientId && i.status === 'active' && i.start_date <= today && i.end_date >= today);
}

function noActiveInvoiceMessage(s, clientId) {
  const frozen = s.invoices.find((i) => i.client_id === clientId && i.status === 'frozen');
  if (frozen) return { message: 'Абонемент заморожений. Відвідування заборонено.', reason: 'frozen', invoiceId: frozen.id };
  const today = daysFromNow(0);
  const future = s.invoices.find((i) => i.client_id === clientId && i.status === 'active' && i.start_date > today);
  if (future) return { message: `Абонемент ще не розпочався (діє з ${future.start_date.split('-').reverse().join('.')}). Відвідування заборонено.`, reason: 'future', invoiceId: null };
  return { message: 'Немає активного абонементу. Відвідування заборонено.', reason: 'none', invoiceId: null };
}

// Заробіток тренера — лише за фактом відвідування, за правилом його профілю
// (particular club_trainers.personal_earn_type/value), дзеркалить visits_api.php.
function createDemoTrainerEarning(s, invoice, trainerId, visitId) {
  if (!invoice || !trainerId) return;
  const tariff = findTariff(s, invoice.tariff_id);
  if (!tariff?.has_trainer || !invoice.visits_total) return;
  const trainer = findTrainer(s, trainerId);
  if (!trainer) return;
  const baseAmount = Math.round((invoice.price / invoice.visits_total) * 100) / 100;
  const earnType = trainer.personal_earn_type === 'fixed' ? 'personal_fixed' : 'personal_percent';
  const value = trainer.personal_earn_value || 0;
  const roundedAmount = Math.round((earnType === 'personal_percent' ? baseAmount * value / 100 : value) * 100) / 100;
  if (roundedAmount <= 0) return;
  s.trainerEarnings.push({
    id: nextId('trainerEarning'), trainer_id: trainerId, client_id: invoice.client_id,
    client_name: findClient(s, invoice.client_id)?.full_name || '',
    earn_type: earnType, release_trigger: tariff.earn_release_trigger || 'on_each_visit',
    amount: roundedAmount, available_amount: roundedAmount, paid_amount: 0, status: 'available',
    end_date: invoice.end_date, visits_total: invoice.visits_total, visits_used: invoice.visits_used,
    source_visit_id: visitId,
  });
}

function handleVisits(action, body, s) {
  const today = daysFromNow(0);
  if (action === 'get_stats') {
    const weekStart = daysFromNow(-6), monthStart = daysFromNow(-29);
    const todays = s.visits.filter((v) => v.visited_at.slice(0, 10) === today);
    return ok({
      stats: {
        total_today: todays.length,
        unique_today: new Set(todays.map((v) => v.client_id)).size,
        total_week: s.visits.filter((v) => v.visited_at.slice(0, 10) >= weekStart).length,
        total_month: s.visits.filter((v) => v.visited_at.slice(0, 10) >= monthStart).length,
      },
    });
  }
  if (action === 'get_list') {
    const { date_from, date_to, invoice_id, search = '', page = 1 } = body;
    let list = s.visits;
    if (invoice_id) {
      list = list.filter((v) => v.invoice_id === Number(invoice_id));
    } else if (date_from && date_to) {
      list = list.filter((v) => v.visited_at.slice(0, 10) >= date_from && v.visited_at.slice(0, 10) <= date_to);
    }
    if (search.trim()) {
      const q = search.trim().toLowerCase();
      const qd = digits(search);
      list = list.filter((v) => {
        const c = findClient(s, v.client_id);
        return v.client_name.toLowerCase().includes(q) || (qd && c && digits(c.phone).includes(qd));
      });
    }
    list = [...list].sort((a, b) => b.visited_at.localeCompare(a.visited_at));
    const { rows, pagination } = paginate(list, page, 30);
    return ok({ visits: rows, pagination });
  }
  if (action === 'scan') {
    const q = String(body.query || '').trim();
    const qd = digits(q);
    let client = qd ? s.clients.find((c) => digits(c.phone).includes(qd)) : null;
    if (!client) client = s.clients.find((c) => c.full_name.toLowerCase() === q.toLowerCase());
    if (!client) return fail(`Клієнта не знайдено. Спробуйте ввести номер телефону, напр. ${s.clients[0].phone}`);
    const invoice = activeInvoiceFor(s, client.id);
    if (!invoice) {
      const info = noActiveInvoiceMessage(s, client.id);
      return fail(info.message, { reason: info.reason, invoice_id: info.invoiceId });
    }
    const already = s.visits.some((v) => v.client_id === client.id && v.visited_at.slice(0, 10) === today);
    const visitId = nextId('visit');
    s.visits.unshift({ id: visitId, client_id: client.id, client_name: client.full_name, client_photo: null, tariff_name: invoice.tariff_name, trainer_name: invoice.trainer_name || '', method: 'barcode', visited_at: nowDatetime(), notes: '' });
    if (invoice.visits_total) invoice.visits_used = (invoice.visits_used || 0) + 1;
    createDemoTrainerEarning(s, invoice, invoice.trainer_id, visitId);
    return ok({
      client: { photo_url: null, full_name: client.full_name },
      invoice: { tariff_name: invoice.tariff_name, visits_total: invoice.visits_total, visits_used: invoice.visits_used, end_date: invoice.end_date },
      already_checked_in: already,
      warning: invoice.visits_total && invoice.visits_used >= invoice.visits_total ? 'Це останнє відвідування за абонементом' : '',
    });
  }
  if (action === 'get_active_invoice') {
    const invoice = activeInvoiceFor(s, Number(body.client_id));
    return ok({ invoice: invoice ? { id: invoice.id, tariff_name: invoice.tariff_name, trainer_id: invoice.trainer_id || null, trainer_name: invoice.trainer_name || null } : null });
  }
  if (action === 'get_checkin_trainers') {
    return ok({ trainers: s.trainers.filter((t) => t.is_active).map((t) => ({ id: t.id, full_name: t.full_name })) });
  }
  if (action === 'check_in') {
    const client = findClient(s, body.client_id);
    if (!client) return fail('Клієнта не знайдено');
    const overrideTrainerId = Number(body.trainer_id) || 0;
    if (!body.force) {
      const invoice = activeInvoiceFor(s, client.id);
      if (!invoice) {
        const info = noActiveInvoiceMessage(s, client.id);
        return fail(info.message, { reason: info.reason, invoice_id: info.invoiceId });
      }
      if (invoice.visits_total) invoice.visits_used = (invoice.visits_used || 0) + 1;
      const trainerId = overrideTrainerId || invoice.trainer_id || 0;
      const trainerName = trainerId ? (findTrainer(s, trainerId)?.full_name || '') : '';
      const visitId = nextId('visit');
      s.visits.unshift({ id: visitId, client_id: client.id, client_name: client.full_name, client_photo: null, tariff_name: invoice.tariff_name, trainer_name: trainerName, method: 'admin', visited_at: nowDatetime(), notes: body.notes || '' });
      createDemoTrainerEarning(s, invoice, trainerId, visitId);
    } else {
      s.visits.unshift({ id: nextId('visit'), client_id: client.id, client_name: client.full_name, client_photo: null, tariff_name: client.active_tariff || '', trainer_name: '', method: 'admin', visited_at: nowDatetime(), notes: body.notes || '' });
    }
    return ok();
  }
  if (action === 'delete') {
    const visitId = Number(body.id);
    const earning = s.trainerEarnings.find((e) => e.source_visit_id === visitId);
    if (earning && earning.paid_amount > 0 && !body.confirm_reversal) {
      const trainer = findTrainer(s, earning.trainer_id);
      return fail(
        `Тренеру ${trainer?.full_name || ''} вже виплачено ${earning.paid_amount} грн за це відвідування. Видалення поверне ці кошти в касу/облік і скасує нарахування — виплату правильному тренеру потрібно буде зробити заново. Підтвердіть видалення.`,
        { requires_confirmation: true, paid_amount: earning.paid_amount, trainer_name: trainer?.full_name || '' }
      );
    }
    if (earning) {
      // Сторно вже виплачених коштів: видаляємо захищені системні витрати,
      // породжені цим нарахуванням — гроші "повертаються".
      s.expenses = s.expenses.filter((e) => !(e.source === 'trainer_payout' && e.source_id === earning.id));
      s.trainerEarnings = s.trainerEarnings.filter((e) => e !== earning);
    }
    s.visits = s.visits.filter((v) => v.id !== Number(body.id));
    return ok();
  }
  return fail('Невідома дія');
}

// ---------- products ----------
function withComputedProduct(s, p) {
  return { ...p, stock_status: stockStatus(p), total_sold: totalSold(s, p.id) };
}

function handleProducts(action, body, s) {
  if (action === 'get_categories') {
    return ok({ categories: [...new Set(s.products.map((p) => p.category).filter(Boolean))] });
  }
  if (action === 'get_list') {
    const { search = '', category = '', low_stock = false, inactive = false } = body;
    let list = s.products.filter((p) => p.is_active === !inactive);
    if (category) list = list.filter((p) => p.category === category);
    if (search.trim()) {
      const q = search.trim().toLowerCase();
      list = list.filter((p) => p.name.toLowerCase().includes(q) || (p.barcode || '').includes(q) || (p.supplier || '').toLowerCase().includes(q));
    }
    let mapped = list.map((p) => withComputedProduct(s, p));
    if (low_stock) mapped = mapped.filter((p) => p.stock_status !== 'ok');
    return ok({ products: mapped });
  }
  if (action === 'get_one') {
    const p = findProduct(s, body.id);
    if (!p) return fail('Товар не знайдено');
    return ok({ product: withComputedProduct(s, p) });
  }
  if (action === 'create') {
    const product = { id: nextId('product'), is_active: true, stock_qty: 0, photo_url: '', barcode: '', ...body };
    s.products.push(product);
    return ok();
  }
  if (action === 'update') {
    const p = findProduct(s, body.id);
    if (!p) return fail('Товар не знайдено');
    Object.assign(p, body);
    return ok();
  }
  if (action === 'toggle_active') {
    const p = findProduct(s, body.id);
    if (!p) return fail('Товар не знайдено');
    p.is_active = !p.is_active;
    return ok({ message: p.is_active ? 'Товар відновлено з архіву' : 'Товар переміщено в архів' });
  }
  if (action === 'add_arrival') {
    const p = findProduct(s, body.product_id);
    if (!p) return fail('Товар не знайдено');
    const qty = Number(body.quantity) || 0;
    const purchasePrice = parseFloat(body.purchase_price) || p.purchase_price;
    const sign = body.operation === 'arrival' ? 1 : -1;
    const arrival = {
      id: nextId('arrival'), product_id: p.id, product_name: p.name, category: p.category,
      operation: body.operation, status: body.status, quantity: qty,
      expected_qty: body.status === 'pending' ? (Number(body.expected_qty) || qty) : null,
      purchase_price: purchasePrice, total_cost: sign * purchasePrice * qty,
      supplier: body.supplier || p.supplier, admin_name: DEMO_USER.full_name, notes: body.notes || '', created_at: nowDatetime(),
    };
    s.arrivals.unshift(arrival);
    if (body.status !== 'pending') {
      p.stock_qty += sign * qty;
      p.purchase_price = purchasePrice;
      if (body.sale_price) p.sale_price = parseFloat(body.sale_price);
      if (body.supplier) p.supplier = body.supplier;
    }
    return ok({ message: 'Прихід додано' });
  }
  if (action === 'get_arrivals') {
    const { date_from, date_to } = body;
    const list = s.arrivals.filter((a) => a.created_at.slice(0, 10) >= date_from && a.created_at.slice(0, 10) <= date_to);
    return ok({ arrivals: [...list].sort((a, b) => b.created_at.localeCompare(a.created_at)) });
  }
  if (action === 'get_sales') {
    const { date_from, date_to } = body;
    const list = s.sales.filter((r) => r.created_at.slice(0, 10) >= date_from && r.created_at.slice(0, 10) <= date_to);
    const summary = {
      cnt: list.length,
      revenue: list.reduce((sum, r) => sum + r.total_amount, 0),
      profit: list.reduce((sum, r) => sum + (r.total_amount - r.quantity * (r.purchase_price || 0)), 0),
    };
    return ok({ sales: [...list].sort((a, b) => b.created_at.localeCompare(a.created_at)), summary });
  }
  return fail('Невідома дія');
}

/**
 * Спільна побудова чека (sale_orders) з N позицій: списує залишки, пише по
 * рядку в s.sales (для звіту "Товари → Продажі" / totalSold) і 1 заголовок
 * у s.saleOrders. Використовується і create_order (кошик/сканер/швидкий
 * продаж), і handleSell (одиночний продаж з /products) — щоб обидва шляхи
 * однаково потрапляли в історію на сторінці "Продаж товарів".
 */
function buildOrderFromItems(s, rawItems, meta) {
  for (const it of rawItems) {
    const product = findProduct(s, it.product_id);
    if (!product) return { error: 'Товар не знайдено' };
    if ((Number(it.quantity) || 0) > product.stock_qty) return { error: `Недостатньо на складі: ${product.name} (${product.stock_qty} шт.)` };
  }
  const client = meta.client_id ? findClient(s, meta.client_id) : null;
  const orderId = nextId('saleOrder');
  const orderItems = rawItems.map((it) => {
    const product = findProduct(s, it.product_id);
    const qty = Number(it.quantity) || 0;
    const price = parseFloat(it.sale_price) || product.sale_price;
    const discount = parseFloat(it.discount) || 0;
    const lineTotal = qty * price - discount;
    product.stock_qty -= qty;
    s.sales.unshift({
      id: nextId('sale'), order_id: orderId, product_id: product.id, product_name: product.name, category: product.category,
      quantity: qty, sale_price: price, purchase_price: product.purchase_price, discount, total_amount: lineTotal,
      client_id: meta.client_id || null, client_name: meta.client_name || '', payment_method: meta.payment_method,
      admin_name: DEMO_USER.full_name, notes: '', sale_date: daysFromNow(0), created_at: nowDatetime(),
    });
    return { id: orderId * 100 + product.id, product_id: product.id, product_name: product.name, quantity: qty, sale_price: price, discount, total_amount: lineTotal };
  });
  const subtotal = orderItems.reduce((sum, it) => sum + it.quantity * it.sale_price, 0);
  const discount_amount = orderItems.reduce((sum, it) => sum + it.discount, 0) + (parseFloat(meta.discount_amount) || 0);
  const total_amount = subtotal - discount_amount;
  if (client && meta.payment_method === 'deposit') client.balance -= total_amount;
  const order = {
    id: orderId, order_number: orderId, club_id: DEMO_CLUB.id,
    client_id: meta.client_id || null, client_name: meta.client_name || '',
    items: orderItems, items_count: orderItems.length,
    subtotal, discount_amount, total_amount,
    payment_method: meta.payment_method, status: 'completed',
    shift_id: null, admin_id: DEMO_USER.id, admin_name: DEMO_USER.full_name,
    notes: meta.notes || '', return_reason: null, returned_at: null, returned_by_name: null,
    created_at: nowDatetime(),
  };
  s.saleOrders.unshift(order);
  return { order };
}

function checkDepositAllowed(s, body, total) {
  if (body.payment_method !== 'deposit') return null;
  if (!body.client_id) return "При оплаті депозитом клієнт обов'язковий";
  const client = findClient(s, body.client_id);
  if (!client) return 'Клієнта не знайдено';
  if ((client.balance || 0) < total) return 'Недостатньо коштів на депозиті клієнта';
  return null;
}

function orderMatchesSearch(order, query) {
  const q = query.trim().toLowerCase();
  if (!q) return true;
  if (String(order.order_number).includes(q)) return true;
  if ((order.client_name || '').toLowerCase().includes(q)) return true;
  return (order.items || []).some((it) => (it.product_name || '').toLowerCase().includes(q));
}

// ---------- sales (standalone page: чеки з кількома позиціями) ----------
function handleSales(action, body, s) {
  if (action === 'get_orders') {
    const { date = '', date_from = '', date_to = '', method = '', status = '', search = '', client_id = 0 } = body;
    let list = s.saleOrders;
    if (date) list = list.filter((o) => o.created_at.slice(0, 10) === date);
    else {
      if (date_from) list = list.filter((o) => o.created_at.slice(0, 10) >= date_from);
      if (date_to) list = list.filter((o) => o.created_at.slice(0, 10) <= date_to);
    }
    if (method) list = list.filter((o) => o.payment_method === method);
    if (status) list = list.filter((o) => o.status === status);
    if (Number(client_id)) list = list.filter((o) => o.client_id === Number(client_id));
    if (search) list = list.filter((o) => orderMatchesSearch(o, search));
    const orders = [...list]
      .sort((a, b) => b.created_at.localeCompare(a.created_at))
      .map(({ items, ...header }) => ({ ...header, first_product_name: items?.[0]?.product_name || '' }));
    return ok({ orders });
  }
  if (action === 'get_order') {
    const order = s.saleOrders.find((o) => o.id === Number(body.id));
    if (!order) return fail('Чек не знайдено');
    return ok({ order });
  }
  if (action === 'create_order') {
    const items = Array.isArray(body.items) ? body.items : [];
    if (items.length === 0) return fail('Додайте хоча б один товар');
    const total = items.reduce((sum, it) => {
      const product = findProduct(s, it.product_id);
      const price = parseFloat(it.sale_price) || product?.sale_price || 0;
      return sum + (Number(it.quantity) || 0) * price - (parseFloat(it.discount) || 0);
    }, 0) - (parseFloat(body.discount) || 0);
    const depositError = checkDepositAllowed(s, body, total);
    if (depositError) return fail(depositError);
    const { error, order } = buildOrderFromItems(s, items, {
      client_id: body.client_id || null, client_name: body.client_name || '',
      payment_method: body.payment_method, notes: body.notes || '', discount_amount: body.discount || 0,
    });
    if (error) return fail(error);
    return ok({ message: `Чек №${order.order_number} оформлено. Сума: ${order.total_amount.toFixed(2)} грн`, order_id: order.id, order_number: order.order_number });
  }
  if (action === 'return_order') {
    const order = s.saleOrders.find((o) => o.id === Number(body.id));
    if (!order) return fail('Чек не знайдено');
    if (order.status === 'returned') return fail('Чек уже повернуто');
    for (const it of order.items) {
      const product = findProduct(s, it.product_id);
      if (product) product.stock_qty += it.quantity;
    }
    order.status = 'returned';
    order.returned_at = nowDatetime();
    order.return_reason = body.reason || '';
    order.returned_by_name = DEMO_USER.full_name;
    return ok({ message: `Чек №${order.order_number} повернено, товар зараховано на склад` });
  }
  return fail('Невідома дія');
}

// ---------- sell (одиночний продаж із /products) ----------
function handleSell(action, body, s) {
  if (action !== 'sell') return fail('Невідома дія');
  const product = findProduct(s, body.product_id);
  if (!product) return fail('Товар не знайдено');
  const qty = Number(body.quantity) || 0;
  if (qty > product.stock_qty) return fail('Недостатньо товару на складі');
  const salePrice = parseFloat(body.sale_price) || product.sale_price;
  const discount = parseFloat(body.discount) || 0;
  const total = qty * salePrice - discount;
  const depositError = checkDepositAllowed(s, body, total);
  if (depositError) return fail(depositError);
  const { error } = buildOrderFromItems(s, [{ product_id: product.id, quantity: qty, sale_price: salePrice, discount }], {
    client_id: body.client_id || null, client_name: body.client_name || '', payment_method: body.payment_method,
  });
  if (error) return fail(error);
  return ok({ message: 'Продаж оформлено' });
}

// ---------- trainers ----------
function trainerCard(s, t) {
  const earnings = s.trainerEarnings.filter((e) => e.trainer_id === t.id);
  const rent = s.trainerRent.filter((r) => r.trainer_id === t.id);
  return {
    ...t,
    total_earned: earnings.reduce((sum, e) => sum + e.amount, 0),
    total_available: earnings.reduce((sum, e) => sum + e.available_amount, 0),
    total_paid: earnings.reduce((sum, e) => sum + e.paid_amount, 0),
    rent_pending: rent.filter((r) => r.status === 'pending').reduce((sum, r) => sum + r.amount, 0),
  };
}

function trainerSummary(s, trainerId) {
  const earnings = s.trainerEarnings.filter((e) => e.trainer_id === Number(trainerId));
  const rent = s.trainerRent.filter((r) => r.trainer_id === Number(trainerId));
  return {
    total_earned: earnings.reduce((sum, e) => sum + e.amount, 0),
    to_pay_out: earnings.reduce((sum, e) => sum + (e.available_amount - e.paid_amount), 0),
    total_paid: earnings.reduce((sum, e) => sum + e.paid_amount, 0),
    cnt_locked: earnings.filter((e) => e.status === 'locked').length,
    rent_pending: rent.filter((r) => r.status === 'pending').reduce((sum, r) => sum + r.amount, 0),
  };
}

function handleTrainers(action, body, s) {
  if (action === 'get_list') return ok({ trainers: s.trainers.map((t) => trainerCard(s, t)) });
  if (action === 'get_one') {
    const t = findTrainer(s, body.trainer_id);
    if (!t) return fail('Тренера не знайдено');
    return ok({ trainer: trainerCard(s, t) });
  }
  if (action === 'save') {
    const fields = {
      specialization: body.specialization, work_type: body.work_type,
      personal_earn_type: body.personal_earn_type, personal_earn_value: body.personal_earn_value,
      personal_tier_threshold: body.personal_tier_threshold, personal_tier_value: body.personal_tier_value,
      group_earn_rate: body.group_earn_rate, group_earn_bonus_per_client: body.group_earn_bonus_per_client,
      group_bonus_threshold: body.group_bonus_threshold,
      group_monthly_bonus_sessions: body.group_monthly_bonus_sessions, group_monthly_bonus_amount: body.group_monthly_bonus_amount,
    };
    if (body.trainer_id) {
      const t = findTrainer(s, body.trainer_id);
      if (!t) return fail('Тренера не знайдено');
      Object.assign(t, fields);
    } else {
      const user = findStaff(s, body.user_id);
      if (!user) return fail('Співробітника не знайдено');
      const newId = s.trainers.length ? Math.max(...s.trainers.map((x) => x.id)) + 1 : 1;
      const t = { id: newId, ...fields, full_name: user.full_name, user_id: user.id, is_active: true };
      s.trainers.push(t);
      user.trainer_profile_id = t.id;
    }
    return ok({ message: 'Збережено' });
  }
  if (action === 'toggle') {
    const t = findTrainer(s, body.trainer_id);
    if (!t) return fail('Тренера не знайдено');
    t.is_active = !t.is_active;
    return ok({ message: t.is_active ? 'Тренера активовано' : 'Тренера призупинено', is_active: t.is_active });
  }
  if (action === 'get_earnings') {
    let list = s.trainerEarnings.filter((e) => e.trainer_id === Number(body.trainer_id));
    if (body.status) list = list.filter((e) => e.status === body.status);
    return ok({ earnings: list });
  }
  if (action === 'pay_earning') {
    const e = s.trainerEarnings.find((x) => x.id === Number(body.earning_id));
    if (!e) return fail('Нарахування не знайдено');
    const amount = parseFloat(body.amount) || 0;
    const paymentMethod = body.payment_method === 'card' ? 'card' : 'cash';
    e.paid_amount = Math.min(e.available_amount, e.paid_amount + amount);
    if (e.paid_amount >= e.available_amount && e.status !== 'locked') e.status = 'paid';
    const trainer = findTrainer(s, e.trainer_id);
    // Захищений системний запис витрати — так само як у реальному бекенді,
    // готівка впливає на касу через Фінанси, картка — лише на облік витрат.
    s.expenses.push({
      id: nextId('expense'), category: 'Зарплата тренера',
      description: `Виплата тренеру ${trainer?.full_name || ''} (нарахування #${e.id})`,
      amount, expense_date: daysFromNow(0), payment_method: paymentMethod, notes: '',
      created_at: nowDatetime(), source: 'trainer_payout', source_id: e.id,
    });
    return ok({ message: 'Виплату зафіксовано' });
  }
  if (action === 'get_rent') {
    return ok({ rent: s.trainerRent.filter((r) => r.trainer_id === Number(body.trainer_id)) });
  }
  if (action === 'save_rent') {
    const rent = {
      id: nextId('trainerRent'), trainer_id: Number(body.trainer_id), rent_type: body.rent_type,
      amount: parseFloat(body.amount) || 0, period_start: body.period_start, period_end: body.period_end,
      status: 'pending', notes: body.notes || '',
    };
    s.trainerRent.push(rent);
    return ok({ message: 'Оренду додано' });
  }
  if (action === 'delete_rent') {
    s.trainerRent = s.trainerRent.filter((r) => r.id !== Number(body.rent_id));
    return ok({ message: 'Видалено' });
  }
  if (action === 'get_summary') return ok({ summary: trainerSummary(s, body.trainer_id) });
  if (action === 'get_trainer_users') {
    const users = s.staff.filter((u) => !u.trainer_profile_id && u.id !== DEMO_USER.id);
    return ok({ users });
  }
  if (action === 'my_profile') {
    const t = s.trainers[0];
    return ok({ trainer: t ? trainerCard(s, t) : null });
  }
  if (action === 'my_earnings') {
    const t = s.trainers[0];
    return ok({ earnings: t ? s.trainerEarnings.filter((e) => e.trainer_id === t.id) : [] });
  }
  if (action === 'my_summary') {
    const t = s.trainers[0];
    return ok({ summary: t ? trainerSummary(s, t.id) : null });
  }
  return fail('Невідома дія');
}

// ---------- cash ----------
function cashDelta(e) { return e.type === 'income' ? e.amount : -e.amount; }

function recomputeCashLedger(s) {
  const sorted = [...s.cashLedger].sort((a, b) => a.created_at.localeCompare(b.created_at) || a.id - b.id);
  let bal = s.cashBase ?? 0;
  for (const e of sorted) { bal += cashDelta(e); e.running_balance = bal; }
  s.cashBalance = bal;
}

function handleCash(action, body, s) {
  if (action === 'get_shift') return ok({ shift: s.cashShift, balance: s.cashBalance });
  if (action === 'open_shift') {
    s.cashShift = { id: 1, opened_name: DEMO_USER.full_name, opened_at: nowDatetime(), balance_open: s.cashBalance, notes: body.notes || '' };
    return ok();
  }
  if (action === 'close_shift') {
    const balance_open = s.cashShift?.balance_open ?? s.cashBalance;
    const balance_close = s.cashBalance;
    s.cashShift = null;
    return ok({ balance_open, balance_close });
  }
  if (action === 'get_summary') {
    const { date_from, date_to } = body;
    const inRange = s.cashLedger.filter((e) => e.created_at.slice(0, 10) >= date_from && e.created_at.slice(0, 10) <= date_to);
    const period_income = inRange.filter((e) => e.type === 'income').reduce((sum, e) => sum + e.amount, 0);
    const period_expenses = inRange.filter((e) => e.type !== 'income').reduce((sum, e) => sum + e.amount, 0);
    return ok({ balance: s.cashBalance, period_income, period_expenses, period_profit: period_income - period_expenses, shift: s.cashShift });
  }
  if (action === 'get_list') {
    const { date_from, date_to, type = '', page = 1 } = body;
    let list = s.cashLedger.filter((e) => e.created_at.slice(0, 10) >= date_from && e.created_at.slice(0, 10) <= date_to);
    if (type) list = list.filter((e) => e.type === type);
    list = [...list].sort((a, b) => b.created_at.localeCompare(a.created_at));
    const { rows, pagination } = paginate(list, page, 50);
    return ok({ rows, pagination });
  }
  if (action === 'add_expense') {
    s.cashLedger.push({
      id: nextId('cashRow'), type: 'expense', description: body.description, category: body.category || '',
      amount: parseFloat(body.amount) || 0, admin_name: DEMO_USER.full_name, created_at: nowDatetime(), source: 'manual',
    });
    recomputeCashLedger(s);
    return ok({ message: 'Витрату записано' });
  }
  if (action === 'encashment') {
    s.cashLedger.push({
      id: nextId('cashRow'), type: 'encashment', description: body.description || 'Інкасація', category: '',
      amount: parseFloat(body.amount) || 0, admin_name: DEMO_USER.full_name, created_at: nowDatetime(), source: 'manual',
    });
    recomputeCashLedger(s);
    return ok({ message: 'Інкасацію проведено' });
  }
  if (action === 'delete') {
    s.cashLedger = s.cashLedger.filter((e) => e.id !== Number(body.id));
    recomputeCashLedger(s);
    return ok({ message: 'Видалено' });
  }
  return fail('Невідома дія');
}

// ---------- finance (dashboard + deposit only) ----------
function countOnDay(list, field, dateStr) {
  return list.filter((x) => (x[field] || '').slice(0, 10) === dateStr).length;
}
function sumArrivalsOnDay(arrivals, dateStr) {
  return arrivals.filter((a) => a.created_at.slice(0, 10) === dateStr && a.total_cost > 0).reduce((sum, a) => sum + a.total_cost, 0);
}
function dayMetrics(s, dateStr) {
  return {
    date: dateStr,
    cash: { amount: s.cashBalance },
    invoices: { count: countOnDay(s.invoices, 'created_at', dateStr) },
    visits: { count: countOnDay(s.visits, 'visited_at', dateStr) },
    payments: { count: countOnDay(s.invoicePayments, 'created_at', dateStr) },
    sales: { count: countOnDay(s.sales, 'created_at', dateStr) },
    arrivals: { amount: sumArrivalsOnDay(s.arrivals, dateStr) },
  };
}
function rangeFor(period) {
  const today = daysFromNow(0);
  if (period === 'yesterday') return [daysFromNow(-1), daysFromNow(-1)];
  if (period === 'week') return [daysFromNow(-6), today];
  return [today, today]; // today + backend-quirk fallback for month/quarter/year/lastyear
}
function enumerateDates(from, to) {
  const dates = [];
  const cur = new Date(`${from}T00:00:00`);
  const end = new Date(`${to}T00:00:00`);
  while (cur <= end) {
    dates.push(fmtDate(cur));
    cur.setDate(cur.getDate() + 1);
  }
  return dates;
}

function financeIncomeRows(s, dateFrom, dateTo) {
  const inv = s.invoicePayments
    .filter((p) => p.created_at.slice(0, 10) >= dateFrom && p.created_at.slice(0, 10) <= dateTo)
    .map((p) => {
      const invoice = findInvoice(s, p.invoice_id);
      const client = invoice ? findClient(s, invoice.client_id) : null;
      return { id: p.id, source_type: 'invoice', description: invoice?.tariff_name || 'Абонемент', client_name: client?.full_name || '', payment_method: p.payment_method, amount: p.amount, created_at: p.created_at };
    });
  const prod = s.sales
    .filter((r) => r.created_at.slice(0, 10) >= dateFrom && r.created_at.slice(0, 10) <= dateTo)
    .map((r) => ({ id: r.id, source_type: 'product', description: r.product_name, client_name: r.client_name || '', payment_method: r.payment_method, amount: r.total_amount, created_at: r.created_at }));
  return [...inv, ...prod];
}

function handleFinance(action, body, s) {
  if (action === 'add_deposit') {
    const client = findClient(s, body.client_id);
    if (!client) return fail('Клієнта не знайдено');
    const amount = parseFloat(body.amount) || 0;
    client.balance = (client.balance || 0) + amount;
    s.deposits.push({ id: nextId('deposit'), client_id: client.id, operation: 'top_up', payment_method: body.payment_method, amount, notes: body.notes || '', created_at: nowDatetime() });
    return ok({ message: `Депозит поповнено на ${amount} грн` });
  }
  if (action === 'get_summary') {
    const { date_from, date_to } = body;
    const income = financeIncomeRows(s, date_from, date_to);
    const invoicesIncome = income.filter((r) => r.source_type === 'invoice');
    const productsIncome = income.filter((r) => r.source_type === 'product');
    const expenses = s.expenses.filter((e) => e.expense_date >= date_from && e.expense_date <= date_to);
    const depositsInRange = s.deposits.filter((d) => d.created_at.slice(0, 10) >= date_from && d.created_at.slice(0, 10) <= date_to);
    const depositsTopUp = depositsInRange.filter((d) => d.operation === 'top_up').reduce((sum, d) => sum + d.amount, 0);
    const byMethod = { cash: 0, card: 0, terminal: 0 };
    for (const r of income) if (byMethod[r.payment_method] !== undefined) byMethod[r.payment_method] += r.amount;
    const totalIncome = invoicesIncome.reduce((s2, r) => s2 + r.amount, 0) + productsIncome.reduce((s2, r) => s2 + r.amount, 0);
    const totalExpense = expenses.reduce((sum, e) => sum + e.amount, 0);
    const byCategory = {};
    for (const e of expenses) byCategory[e.category || 'Без категорії'] = (byCategory[e.category || 'Без категорії'] || 0) + e.amount;
    const expenses_by_category = Object.entries(byCategory).map(([category, total]) => ({ category, total })).sort((a, b) => b.total - a.total);
    return ok({
      summary: {
        total_income: totalIncome, invoices_income: invoicesIncome.reduce((s2, r) => s2 + r.amount, 0), invoices_count: invoicesIncome.length,
        products_income: productsIncome.reduce((s2, r) => s2 + r.amount, 0), products_count: productsIncome.length,
        deposits_top_up: depositsTopUp, total_expense: totalExpense, expenses_count: expenses.length,
        net_profit: totalIncome - totalExpense, by_method: byMethod,
      },
      expenses_by_category,
    });
  }
  if (action === 'get_income') {
    return ok({ income: financeIncomeRows(s, body.date_from, body.date_to) });
  }
  if (action === 'get_expenses') {
    let list = s.expenses.filter((e) => e.expense_date >= body.date_from && e.expense_date <= body.date_to);
    if (body.category) list = list.filter((e) => e.category === body.category);
    list = [...list].sort((a, b) => b.expense_date.localeCompare(a.expense_date));
    return ok({ expenses: list, total: list.reduce((sum, e) => sum + e.amount, 0) });
  }
  if (action === 'add_expense') {
    const expense = {
      id: nextId('expense'), category: body.category || '', description: body.description, amount: parseFloat(body.amount) || 0,
      expense_date: body.expense_date, payment_method: body.payment_method, notes: body.notes || '', created_at: nowDatetime(),
      source: 'manual', source_id: null,
    };
    s.expenses.push(expense);
    return ok({ message: 'Витрату записано' });
  }
  if (action === 'update_expense') {
    const e = s.expenses.find((x) => x.id === Number(body.id));
    if (!e) return fail('Витрату не знайдено');
    if (e.source && e.source !== 'manual') return fail('Це системний запис, створений автоматично (напр. виплата тренеру) — редагувати вручну не можна.');
    Object.assign(e, { category: body.category || '', description: body.description, amount: parseFloat(body.amount) || 0, expense_date: body.expense_date, payment_method: body.payment_method, notes: body.notes || '' });
    return ok({ message: 'Збережено' });
  }
  if (action === 'delete_expense') {
    const e = s.expenses.find((x) => x.id === Number(body.id));
    if (!e) return fail('Витрату не знайдено');
    if (e.source && e.source !== 'manual') return fail('Це системний запис, створений автоматично (напр. виплата тренеру) — видалити вручну не можна.');
    s.expenses = s.expenses.filter((x) => x.id !== Number(body.id));
    return ok({ message: 'Видалено' });
  }
  if (action === 'get_deposits') {
    const list = s.deposits
      .filter((d) => d.created_at.slice(0, 10) >= body.date_from && d.created_at.slice(0, 10) <= body.date_to)
      .map((d) => { const c = findClient(s, d.client_id); return { ...d, client_name: c?.full_name || '', client_phone: c?.phone || '' }; })
      .sort((a, b) => b.created_at.localeCompare(a.created_at));
    const top_up = list.filter((d) => d.amount > 0).reduce((sum, d) => sum + d.amount, 0);
    const write_off = list.filter((d) => d.amount < 0).reduce((sum, d) => sum + d.amount, 0);
    return ok({ deposits: list, summary: { top_up, write_off } });
  }
  if (action === 'get_expense_cats') {
    return ok({ categories: [...new Set(s.expenses.map((e) => e.category).filter(Boolean))] });
  }
  if (action === 'get_dashboard') {
    const [from, to] = rangeFor(body.period);
    const days = enumerateDates(from, to);
    const data = {
      cash: { amount: s.cashBalance },
      invoices: { count: days.reduce((sum, d) => sum + countOnDay(s.invoices, 'created_at', d), 0) },
      visits: { count: days.reduce((sum, d) => sum + countOnDay(s.visits, 'visited_at', d), 0) },
      payments: { count: days.reduce((sum, d) => sum + countOnDay(s.invoicePayments, 'created_at', d), 0) },
      sales: { count: days.reduce((sum, d) => sum + countOnDay(s.sales, 'created_at', d), 0) },
      arrivals: { amount: days.reduce((sum, d) => sum + sumArrivalsOnDay(s.arrivals, d), 0) },
    };
    return ok({ data });
  }
  if (action === 'get_dashboard_trend') {
    const current = Array.from({ length: 7 }, (_, i) => dayMetrics(s, daysFromNow(i - 6)));
    const previous = Array.from({ length: 7 }, (_, i) => dayMetrics(s, daysFromNow(i - 13)));
    return ok({ trend: { current, previous } });
  }
  return fail('Невідома дія');
}

// ---------- payments (журнал оплат) ----------
function paymentWithJoins(s, p) {
  const invoice = findInvoice(s, p.invoice_id);
  const client = invoice ? findClient(s, invoice.client_id) : null;
  return {
    id: p.id, invoice_id: p.invoice_id, amount: p.amount, payment_method: p.payment_method, notes: p.notes,
    created_at: p.created_at, admin_name: p.admin_name,
    client_id: client?.id || null, client_name: client?.full_name || '—', client_phone: client?.phone || '',
    tariff_name: invoice?.tariff_name || '', trainer_name: invoice?.trainer_name || '',
  };
}

function handlePayments(action, body, s) {
  if (action === 'get_list') {
    const { date_from, date_to, method = '', tariff_id = 0, search = '', client_id = 0, page = 1 } = body;
    let list = s.invoicePayments.map((p) => paymentWithJoins(s, p));
    list = list.filter((p) => p.created_at.slice(0, 10) >= date_from && p.created_at.slice(0, 10) <= date_to);
    if (method) list = list.filter((p) => p.payment_method === method);
    if (Number(tariff_id)) {
      const invoiceIdsForTariff = new Set(s.invoices.filter((i) => i.tariff_id === Number(tariff_id)).map((i) => i.id));
      list = list.filter((p) => invoiceIdsForTariff.has(p.invoice_id));
    }
    if (Number(client_id)) list = list.filter((p) => p.client_id === Number(client_id));
    if (search.trim()) {
      const q = search.trim().toLowerCase();
      const qd = digits(search);
      list = list.filter((p) => p.client_name.toLowerCase().includes(q) || (qd && digits(p.client_phone).includes(qd)));
    }
    list.sort((a, b) => b.created_at.localeCompare(a.created_at));
    const summaryMap = {};
    for (const p of list) summaryMap[p.payment_method] = (summaryMap[p.payment_method] || { payment_method: p.payment_method, total: 0, cnt: 0 });
    for (const p of list) { summaryMap[p.payment_method].total += p.amount; summaryMap[p.payment_method].cnt += 1; }
    const { rows, pagination } = paginate(list, page, 30);
    return ok({ payments: rows, summary: Object.values(summaryMap), pagination });
  }
  if (action === 'get_tariffs') {
    return ok({ tariffs: s.tariffs.map((t) => ({ id: t.id, name: t.name })) });
  }
  if (action === 'update') {
    const p = s.invoicePayments.find((x) => x.id === Number(body.id));
    if (!p) return fail('Оплату не знайдено');
    const invoice = findInvoice(s, p.invoice_id);
    const newAmount = parseFloat(body.amount) || 0;
    if (invoice) {
      invoice.paid_amount = Math.max(0, (invoice.paid_amount || 0) - p.amount + newAmount);
      invoice.debt = Math.max(0, invoice.price - invoice.paid_amount);
    }
    p.amount = newAmount;
    p.payment_method = body.payment_method;
    p.notes = body.notes || '';
    return ok({ message: 'Оплату оновлено' });
  }
  if (action === 'delete') {
    const p = s.invoicePayments.find((x) => x.id === Number(body.id));
    if (!p) return fail('Оплату не знайдено');
    const invoice = findInvoice(s, p.invoice_id);
    if (invoice) {
      invoice.paid_amount = Math.max(0, (invoice.paid_amount || 0) - p.amount);
      invoice.debt = Math.max(0, invoice.price - invoice.paid_amount);
    }
    s.invoicePayments = s.invoicePayments.filter((x) => x.id !== p.id);
    return ok({ message: 'Оплату видалено' });
  }
  return fail('Невідома дія');
}

// ---------- arrivals (журнал приходів) ----------
function arrivalStockSign(a) { return ['overdue', 'repack', 'transfer'].includes(a.operation) ? -1 : 1; }
function applyArrivalStock(product, arrival, factor) {
  if (!product || arrival.status === 'pending') return;
  product.stock_qty += arrivalStockSign(arrival) * arrival.quantity * factor;
}

function handleArrivals(action, body, s) {
  if (action === 'get_list') {
    const { date_from, date_to, operation = '', status = '', search = '', page = 1 } = body;
    let list = s.arrivals.filter((a) => a.created_at.slice(0, 10) >= date_from && a.created_at.slice(0, 10) <= date_to);
    if (operation) list = list.filter((a) => a.operation === operation);
    if (status) list = list.filter((a) => a.status === status);
    if (search.trim()) {
      const q = search.trim().toLowerCase();
      list = list.filter((a) => a.product_name.toLowerCase().includes(q));
    }
    list = [...list].sort((a, b) => b.created_at.localeCompare(a.created_at));
    const withStock = list.map((a) => ({ ...a, current_stock: findProduct(s, a.product_id)?.stock_qty ?? null }));
    const { rows, pagination } = paginate(withStock, page, 30);
    return ok({ arrivals: rows, pagination });
  }
  if (action === 'confirm') {
    const a = s.arrivals.find((x) => x.id === Number(body.id));
    if (!a) return fail('Запис не знайдено');
    const product = findProduct(s, a.product_id);
    const qty = parseInt(body.quantity) || 0;
    a.quantity = qty;
    a.status = body.status;
    a.total_cost = arrivalStockSign(a) * a.purchase_price * qty;
    applyArrivalStock(product, a, 1);
    return ok({ message: 'Прихід підтверджено' });
  }
  if (action === 'update') {
    const a = s.arrivals.find((x) => x.id === Number(body.id));
    if (!a) return fail('Запис не знайдено');
    const product = findProduct(s, a.product_id);
    applyArrivalStock(product, a, -1);
    a.quantity = parseInt(body.quantity) || 0;
    a.purchase_price = parseFloat(body.purchase_price) || 0;
    a.operation = body.operation;
    a.status = body.status;
    a.notes = body.notes || '';
    a.total_cost = body.total_cost != null ? (arrivalStockSign(a) * Math.abs(parseFloat(body.total_cost) || 0)) : arrivalStockSign(a) * a.purchase_price * a.quantity;
    applyArrivalStock(product, a, 1);
    return ok({ message: 'Збережено' });
  }
  if (action === 'delete') {
    const a = s.arrivals.find((x) => x.id === Number(body.id));
    if (!a) return fail('Запис не знайдено');
    applyArrivalStock(findProduct(s, a.product_id), a, -1);
    s.arrivals = s.arrivals.filter((x) => x.id !== a.id);
    return ok({ message: 'Видалено' });
  }
  return fail('Невідома дія');
}

// ---------- users (команда клубу) ----------
function handleUsers(action, body, s) {
  if (action === 'get_list') {
    const list = body.show_hidden ? s.staff : s.staff.filter((u) => u.club_access != 0);
    return ok({ users: list.map((u) => ({ ...u, role_name: roleById(u.role_id)?.name_ua || u.role_name })) });
  }
  if (action === 'get_roles') return ok({ roles: DEMO_ROLES });
  if (action === 'invite') {
    const role = roleBySlug(body.role_slug);
    const newId = nextId('staff');
    s.staff.push({
      id: newId, full_name: body.full_name, email: body.email, phone: body.phone || '',
      role_id: role?.id || 3, role_slug: role?.slug || 'trainer', role_name: role?.name_ua || 'Тренер',
      club_access: 1, is_active: true, last_login_at: null,
    });
    return ok({ tmp_pwd: 'Demo' + Math.floor(1000 + Math.random() * 9000) });
  }
  if (action === 'update_role') {
    const u = findStaff(s, body.user_id);
    if (!u) return fail('Співробітника не знайдено');
    const role = roleBySlug(body.role_slug);
    if (role) { u.role_id = role.id; u.role_slug = role.slug; u.role_name = role.name_ua; }
    return ok({ message: 'Роль змінено' });
  }
  if (action === 'toggle_access') {
    const u = findStaff(s, body.user_id);
    if (!u) return fail('Співробітника не знайдено');
    u.club_access = body.enabled ? 1 : 0;
    return ok({ message: u.club_access ? 'Доступ відновлено' : 'Доступ призупинено' });
  }
  if (action === 'remove') {
    s.staff = s.staff.filter((u) => u.id !== Number(body.user_id));
    return ok({ message: 'Прибрано з команди' });
  }
  if (action === 'change_password') return ok({ message: 'Пароль змінено' });
  return fail('Невідома дія');
}

// ---------- payroll ----------
// Демо-суми "продажів товарів"/"оплат абонементів" за місяць — фіксовані, узгоджені
// зі старими множниками нижче (value*40 === value/100*4000, value*100 === value/100*10000),
// щоб і %-компоненти, і бонус за план рахувались з однієї бази.
const DEMO_TOVAR_SUM = 4000;
const DEMO_ABON_SUM = 10000;

function payrollCalc(cfg, daysWorked, hoursWorked) {
  const pay_month = cfg.pay_month_on ? parseFloat(cfg.pay_month_amount) || 0 : 0;
  const pay_day = cfg.pay_day_on ? (parseFloat(cfg.pay_day_amount) || 0) * daysWorked : 0;
  const pay_hour = cfg.pay_hour_on ? (parseFloat(cfg.pay_hour_amount) || 0) * hoursWorked : 0;
  const pct_tovar = cfg.pct_tovar_on ? Math.round((parseFloat(cfg.pct_tovar_value) || 0) / 100 * Math.max(0, DEMO_TOVAR_SUM - (parseFloat(cfg.pct_tovar_min) || 0))) : 0;
  const pct_abon = cfg.pct_abon_on ? Math.round((parseFloat(cfg.pct_abon_value) || 0) / 100 * Math.max(0, DEMO_ABON_SUM - (parseFloat(cfg.pct_abon_min) || 0))) : 0;
  const plan_fact = cfg.plan_on ? DEMO_TOVAR_SUM + DEMO_ABON_SUM : 0;
  const plan_bonus = cfg.plan_on ? Math.round(Math.max(0, plan_fact - (parseFloat(cfg.plan_amount) || 0)) * (parseFloat(cfg.plan_bonus_pct) || 0) / 100) : 0;
  return {
    pay_month, pay_day, pay_hour, pct_tovar, pct_abon, plan_fact, plan_bonus,
    total: pay_month + pay_day + pay_hour + pct_tovar + pct_abon + plan_bonus,
  };
}
const EMPTY_SALARY_CFG = {
  pay_month_on: 0, pay_month_amount: 0, pay_day_on: 0, pay_day_amount: 0,
  pct_tovar_on: 0, pct_tovar_value: 0, pct_tovar_min: 0,
  pct_abon_on: 0, pct_abon_value: 0, pct_abon_min: 0,
  plan_on: 0, plan_amount: 0, plan_bonus_pct: 0, notes: '',
};

function handlePayroll(action, body, s) {
  if (action === 'get_team') {
    return ok({ team: s.staff.filter((u) => u.id !== DEMO_USER.id).map((u) => ({ id: u.id, full_name: u.full_name, role_name: roleById(u.role_id)?.name_ua || u.role_name })) });
  }
  if (action === 'get_worked_shifts') {
    return ok({ shifts_worked: 18, has_open_shift: false });
  }
  if (action === 'get_settings') {
    return ok({ settings: s.salarySettings[body.user_id] || EMPTY_SALARY_CFG });
  }
  if (action === 'save_settings') {
    const { user_id, ...cfg } = body;
    s.salarySettings[user_id] = cfg;
    return ok({ message: 'Налаштування збережено' });
  }
  if (action === 'calc_preview') {
    const cfg = s.salarySettings[body.user_id] || EMPTY_SALARY_CFG;
    const preview = payrollCalc(cfg, parseFloat(body.days_worked) || 0, parseFloat(body.hours_worked) || 0);
    return ok({ preview: { ...preview, cfg } });
  }
  if (action === 'create_payroll') {
    const user = findStaff(s, body.user_id);
    if (!user) return fail('Співробітника не знайдено');
    const cfg = s.salarySettings[body.user_id] || EMPTY_SALARY_CFG;
    const calc = payrollCalc(cfg, parseFloat(body.days_worked) || 0, parseFloat(body.hours_worked) || 0);
    s.payroll.push({
      id: nextId('payroll'), user_id: user.id, full_name: user.full_name, role_name: roleById(user.role_id)?.name_ua || '',
      period_month: body.month, status: 'pending', pay_month: calc.pay_month, pay_day: calc.pay_day, pay_hour: calc.pay_hour,
      pct_tovar: calc.pct_tovar, pct_abon: calc.pct_abon, plan_bonus: calc.plan_bonus, total_amount: calc.total, paid_amount: 0, notes: body.notes || '',
    });
    return ok({ message: 'Нараховано' });
  }
  if (action === 'pay_payroll') {
    const row = s.payroll.find((r) => r.id === Number(body.payroll_id));
    if (!row) return fail('Запис не знайдено');
    const amount = parseFloat(body.amount) || 0;
    row.paid_amount = Math.min(row.total_amount, row.paid_amount + amount);
    row.status = row.paid_amount >= row.total_amount - 0.01 ? 'paid' : row.paid_amount > 0 ? 'partial' : 'pending';
    return ok({ message: 'Виплату зафіксовано' });
  }
  if (action === 'get_payroll') {
    let list = s.payroll.filter((r) => r.period_month === body.month);
    if (Number(body.user_id)) list = list.filter((r) => r.user_id === Number(body.user_id));
    return ok({ payroll: list });
  }
  if (action === 'get_summary') {
    let list = s.payroll.filter((r) => r.period_month === body.month);
    if (Number(body.user_id)) list = list.filter((r) => r.user_id === Number(body.user_id));
    return ok({
      summary: {
        total_accrued: list.reduce((sum, r) => sum + r.total_amount, 0),
        total_paid: list.reduce((sum, r) => sum + r.paid_amount, 0),
        total_pending: list.reduce((sum, r) => sum + (r.total_amount - r.paid_amount), 0),
        cnt: list.length,
      },
    });
  }
  return fail('Невідома дія');
}

// ---------- settings (клуб + профіль) ----------
function handleSettings(action, body, s) {
  if (action === 'get_club') return ok({ club: s.clubSettings });
  if (action === 'update_club') {
    Object.assign(s.clubSettings, body);
    return ok({ message: 'Збережено' });
  }
  if (action === 'update_profile') return ok({ message: 'Збережено' });
  return fail('Невідома дія');
}

// ---------- telegram ----------
function handleTelegram(action, body, s) {
  const total = s.clients.length;
  const linked = Math.round(total * 0.57);
  if (action === 'get_stats') {
    return ok({ my_linked: true, stats: [{ club_name: DEMO_CLUB.name, linked_clients: linked, total_clients: total }], platform_wide: false });
  }
  if (action === 'broadcast_clients') return ok({ sent: linked });
  if (action === 'broadcast_owners') return ok({ sent: 1 });
  return fail('Цей розділ недоступний у демо-версії');
}

// ---------- permissions (доступ і ролі) ----------
const PERMISSION_CATALOG = [
  ['dashboard', 'Дашборд', [['dashboard.view', 'Перегляд']]],
  ['clients', 'Клієнти', [['clients.view', 'Перегляд'], ['clients.create', 'Створення'], ['clients.edit', 'Редагування'], ['clients.delete', 'Видалення']]],
  ['invoices', 'Абонементи', [['invoices.view', 'Перегляд'], ['invoices.sell', 'Продаж'], ['invoices.cancel', 'Скасування']]],
  ['payments', 'Оплати', [['payments.view', 'Перегляд'], ['payments.create', 'Додавання'], ['payments.edit', 'Редагування'], ['payments.delete', 'Видалення']]],
  ['tariffs', 'Тарифи', [['tariffs.view', 'Перегляд'], ['tariffs.edit', 'Редагування']]],
  ['visits', 'Відвідування', [['visits.view', 'Перегляд'], ['visits.checkin', 'Відмітка приходу'], ['visits.delete', 'Видалення']]],
  ['products', 'Товари', [['products.view', 'Перегляд'], ['products.edit', 'Редагування'], ['products.delete', 'Видалення']]],
  ['arrivals', 'Прихід', [['arrivals.view', 'Перегляд'], ['arrivals.create', 'Створення'], ['arrivals.edit', 'Редагування']]],
  ['sales', 'Продажі', [['sales.view', 'Перегляд'], ['sales.create', 'Продаж'], ['sales.delete', 'Видалення']]],
  ['warehouse', 'Склад', [['warehouse.view', 'Перегляд']]],
  ['finance', 'Фінанси', [['finance.manage', 'Керування'], ['finance.delete', 'Видалення']]],
  ['cash', 'Каса', [['cash.record', 'Проведення операцій'], ['cash.delete', 'Видалення']]],
  ['trainers', 'Тренери', [['trainers.view', 'Перегляд'], ['trainers.manage', 'Керування']]],
  ['users', 'Команда', [['users.manage', 'Керування']]],
  ['billing', 'Підписка', [['billing.view', 'Перегляд']]],
  ['settings', 'Налаштування клубу', [['settings.manage', 'Керування']]],
  ['access', 'Доступ і ролі', [['access.view', 'Перегляд']]],
  ['telegram', 'Telegram-бот', [['telegram.broadcast', 'Розсилка'], ['telegram.manage', 'Керування']]],
  ['support', 'Підтримка', [['support.view', 'Перегляд']]],
];
const MANAGER_EXCLUDED = new Set(['users.manage', 'settings.manage', 'finance.delete', 'cash.delete', 'trainers.manage']);
const TRAINER_ALLOWED = new Set(['dashboard.view', 'clients.view', 'visits.view', 'visits.checkin', 'trainers.view']);

function buildPermissionCatalog() {
  let sort = 0;
  const permissions = [];
  for (const [category, , actions] of PERMISSION_CATALOG) {
    for (const [slug, label] of actions) permissions.push({ slug, label, category, sort_order: sort++ });
  }
  return permissions;
}

function buildClubDefaults() {
  const owner = roleBySlug('owner'), manager = roleBySlug('manager'), trainer = roleBySlug('trainer');
  const rows = [];
  for (const [, , actions] of PERMISSION_CATALOG) {
    for (const [slug] of actions) {
      rows.push({ role_id: owner.id, permission_slug: slug });
      if (!MANAGER_EXCLUDED.has(slug)) rows.push({ role_id: manager.id, permission_slug: slug });
      if (TRAINER_ALLOWED.has(slug)) rows.push({ role_id: trainer.id, permission_slug: slug });
    }
  }
  return rows;
}

function handlePermissions(action, body, s) {
  if (action === 'get_catalog') return ok({ permissions: buildPermissionCatalog(), roles: DEMO_ROLES });
  if (action === 'get_club_matrix') return ok({ defaults: buildClubDefaults(), overrides: s.permissionOverrides, can_edit: true });
  if (action === 'save_club_matrix') {
    s.permissionOverrides = (body.rows || []).map((r) => ({ role_id: r.role_id, permission_slug: r.permission_slug, is_allowed: r.is_allowed }));
    return ok({ message: 'Збережено' });
  }
  return fail('Цей розділ недоступний у демо-версії');
}

// ---------- billing (підписка клубу) ----------
const DEMO_PLANS = [
  { id: 1, slug: 'free', name: 'Free', price_monthly: 0, price_effective: 0, discount_active: false, is_free: 1, clients_limit: 30, users_limit: 1, invoices_limit: 30, features: ['До 30 клієнтів', '1 співробітник', 'Базові звіти'] },
  { id: 2, slug: 'starter', name: 'Starter', price_monthly: 490, price_effective: 490, discount_active: false, is_free: 0, clients_limit: 150, users_limit: 3, invoices_limit: 150, features: ['До 150 клієнтів', '3 співробітники', 'Оплати та фінанси'] },
  { id: 3, slug: 'business', name: 'Business', price_monthly: 990, price_effective: 990, discount_active: false, is_free: 0, clients_limit: 600, users_limit: 10, invoices_limit: 600, features: ['До 600 клієнтів', '10 співробітників', 'Тренери та ЗП', 'Пріоритетна підтримка'] },
  { id: 4, slug: 'pro', name: 'Pro', price_monthly: 1990, price_effective: 1990, discount_active: false, is_free: 0, clients_limit: null, users_limit: null, invoices_limit: null, features: ['Необмежено клієнтів', 'Необмежена команда', 'Всі можливості', 'Персональний менеджер'] },
];

function handleBilling(action, body, s) {
  if (action === 'get_plans') return ok({ plans: DEMO_PLANS });
  if (action === 'get_my_billing') {
    const pro = DEMO_PLANS[3];
    return ok({
      subscription: {
        plan_slug: pro.slug, plan_name: pro.name, status: 'active', price_monthly: pro.price_monthly,
        clients_used: s.clients.length, clients_limit: null, users_used: s.staff.length, users_limit: null,
        invoices_used: s.invoices.filter((i) => i.status === 'active').length, invoices_limit: null,
        current_period_end: daysFromNow(20), trial_ends_at: null,
      },
      invoices: [
        { id: 1001, amount: pro.price_monthly, period_start: daysFromNow(-40), period_end: daysFromNow(-10), status: 'paid', paid_at: daysFromNow(-40) },
        { id: 1002, amount: pro.price_monthly, period_start: daysFromNow(-10), period_end: daysFromNow(20), status: 'paid', paid_at: daysFromNow(-10) },
      ],
    });
  }
  if (action === 'get_entitlements') {
    return ok({
      plan: { slug: 'pro', name: 'Pro' }, recommendedPlan: null,
      limits: { maxActiveClients: null, maxTeam: null },
      usage: { activeClients: s.clients.length, team: s.staff.length },
      permissions: { canAddClient: true, canAddMember: true },
    });
  }
  return fail('Недоступно в демо-режимі. Зареєструйтеся, щоб оформити передплату.');
}

// ---------- support ----------
function findTicket(s, id) { return s.supportTickets.find((t) => t.id === Number(id)); }

function handleSupport(action, body, s) {
  if (action === 'unread_count') {
    return ok({ count: s.supportTickets.filter((t) => t.unread_by_club).length });
  }
  if (action === 'list_tickets') {
    const { status = '' } = body;
    let list = s.supportTickets;
    if (status) list = list.filter((t) => t.status === status);
    list = [...list].sort((a, b) => b.last_message_at.localeCompare(a.last_message_at));
    return ok({ tickets: list.map((t) => ({ ...t, club_name: DEMO_CLUB.name })) });
  }
  if (action === 'get_ticket') {
    const ticket = findTicket(s, body.ticket_id);
    if (!ticket) return fail('Звернення не знайдено');
    ticket.unread_by_club = false;
    const messages = s.supportMessages.filter((m) => m.ticket_id === ticket.id).sort((a, b) => a.created_at.localeCompare(b.created_at));
    return ok({ ticket: { ...ticket, club_name: DEMO_CLUB.name }, messages });
  }
  if (action === 'create_ticket') {
    const subject = (body.subject || '').trim();
    const message = (body.message || '').trim();
    if (!subject || !message) return fail('Заповніть тему і опис питання');
    const ticket = { id: nextId('supportTicket'), club_id: DEMO_CLUB.id, subject, status: 'open', unread_by_club: false, unread_by_admin: false, created_at: nowDatetime(), last_message_at: nowDatetime() };
    s.supportTickets.unshift(ticket);
    s.supportMessages.push({ id: nextId('supportMessage'), ticket_id: ticket.id, sender_type: 'club', sender_name: DEMO_USER.full_name, message, created_at: nowDatetime() });
    return ok({ ticket_id: ticket.id });
  }
  if (action === 'send_message') {
    const ticket = findTicket(s, body.ticket_id);
    if (!ticket) return fail('Звернення не знайдено');
    const message = (body.message || '').trim();
    if (!message) return fail('Введіть повідомлення');
    s.supportMessages.push({ id: nextId('supportMessage'), ticket_id: ticket.id, sender_type: 'club', sender_name: DEMO_USER.full_name, message, created_at: nowDatetime() });
    ticket.last_message_at = nowDatetime();
    ticket.unread_by_club = false;
    if (ticket.status === 'resolved' || ticket.status === 'closed') ticket.status = 'open';
    return ok();
  }
  if (action === 'update_status') {
    const ticket = findTicket(s, body.ticket_id);
    if (!ticket) return fail('Звернення не знайдено');
    ticket.status = body.status;
    return ok();
  }
  if (action === 'delete_ticket') {
    const ticket = findTicket(s, body.ticket_id);
    if (!ticket) return fail('Звернення не знайдено');
    s.supportTickets = s.supportTickets.filter((t) => t.id !== ticket.id);
    s.supportMessages = s.supportMessages.filter((m) => m.ticket_id !== ticket.id);
    return ok();
  }
  return fail('Невідома дія');
}

// ---------- client_service (DRIVE SPORT HUB) ----------
function handleClientService(action, body, s) {
  if (action === 'get_stats') {
    const total = s.clients.length;
    const appUsers = Math.round(total * 0.67);
    const appUsersVerified = Math.round(appUsers * 0.71);
    const telegramLinked = Math.round(total * 0.57);
    return ok({
      total_clients: total,
      telegram_linked: telegramLinked,
      hub_connected: true,
      app_users: appUsers,
      app_users_verified: appUsersVerified,
    });
  }
  if (action === 'get_client_status') {
    const id = Number(body.client_id);
    return ok({
      crm_telegram_linked: id % 4 === 0,
      hub_connected: true,
      is_app_user: id % 2 === 0,
      is_verified: id % 2 === 0 && id % 3 !== 0,
      has_hub_telegram: id % 5 === 0,
    });
  }
  return fail('Невідома дія');
}

const HANDLERS = {
  clients: handleClients,
  invoices: handleInvoices,
  tariffs: handleTariffs,
  visits: handleVisits,
  products: handleProducts,
  sales: handleSales,
  sell: handleSell,
  trainers: handleTrainers,
  cash: handleCash,
  finance: handleFinance,
  support: handleSupport,
  client_service: handleClientService,
  payments: handlePayments,
  arrivals: handleArrivals,
  users: handleUsers,
  payroll: handlePayroll,
  settings: handleSettings,
  telegram: handleTelegram,
  permissions: handlePermissions,
  billing: handleBilling,
};

export function demoApi(action, body, apiType) {
  const handler = HANDLERS[apiType];
  const s = getStore();
  let result;
  try {
    result = handler ? handler(action, body || {}, s) : fail('Цей розділ недоступний у демо-версії');
  } catch (e) {
    console.error('[DEMO API]', apiType, action, e);
    result = fail('Помилка демо-режиму');
  }
  persist();
  return delay(result);
}
