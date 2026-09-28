export function formatMoney(val) {
  const n = parseFloat(val) || 0;
  return n.toLocaleString('uk-UA', { minimumFractionDigits: 0 }) + ' грн';
}

export function formatDate(d) {
  if (!d) return '—';
  try {
    return new Date(d).toLocaleDateString('uk-UA');
  } catch {
    return d;
  }
}

export function formatRelativeDate(d) {
  if (!d) return '—';
  const date = new Date(d);
  const days = Math.floor((Date.now() - date) / 86400000);
  if (days === 0) return 'сьогодні';
  if (days === 1) return 'вчора';
  if (days < 7) return `${days} дн. тому`;
  if (days < 30) return `${Math.floor(days / 7)} тиж. тому`;
  return formatDate(d);
}

/** Локальна дата 'YYYY-MM-DD' (без UTC-зсуву, на відміну від toISOString) */
export function localDate(d) {
  const date = d ? new Date(d) : new Date();
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

export function localToday() {
  return localDate();
}

/** Перший день поточного місяця: '2026-04-01' */
export function localMonthStart() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}

/** Перший день поточного тижня (Пн): '2026-04-27' */
export function localWeekStart() {
  const d = new Date();
  const day = d.getDay() || 7;
  d.setDate(d.getDate() - day + 1);
  return localDate(d);
}

export function formatTime(d) {
  if (!d) return '';
  return new Date(d).toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit' });
}

export function getInitials(name) {
  return (name || '')
    .split(' ')
    .slice(0, 2)
    .map((w) => w.charAt(0).toUpperCase())
    .join('');
}
