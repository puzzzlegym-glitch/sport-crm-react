import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { formatMoney, formatDate, formatRelativeDate, localDate, localToday, getInitials } from './format';

const money = (n) => (parseFloat(n) || 0).toLocaleString('uk-UA', { minimumFractionDigits: 0 }) + ' грн';

describe('formatMoney', () => {
  it('форматує число з роздільниками тисяч і додає "грн"', () => {
    expect(formatMoney(1500)).toBe(money(1500));
  });

  it('нечислове значення трактує як 0', () => {
    expect(formatMoney('abc')).toBe(money(0));
  });

  it('приймає рядок з дробовим числом (без округлення до цілого)', () => {
    expect(formatMoney('250.5')).toBe(money('250.5'));
  });
});

describe('formatDate', () => {
  it('порожнє значення повертає тире', () => {
    expect(formatDate(null)).toBe('—');
    expect(formatDate('')).toBe('—');
  });

  it('форматує ISO-дату у uk-UA локаль', () => {
    expect(formatDate('2026-01-15')).toBe(new Date('2026-01-15').toLocaleDateString('uk-UA'));
  });
});

describe('formatRelativeDate', () => {
  const NOW = new Date('2026-04-28T12:00:00');

  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(NOW);
  });
  afterEach(() => vi.useRealTimers());

  it('порожнє значення повертає тире', () => {
    expect(formatRelativeDate(null)).toBe('—');
  });

  it('сьогодні', () => {
    expect(formatRelativeDate('2026-04-28T08:00:00')).toBe('сьогодні');
  });

  it('вчора', () => {
    expect(formatRelativeDate('2026-04-27T08:00:00')).toBe('вчора');
  });

  it('кілька днів тому (< 7)', () => {
    expect(formatRelativeDate('2026-04-25T08:00:00')).toBe('3 дн. тому');
  });

  it('кілька тижнів тому (< 30 днів)', () => {
    expect(formatRelativeDate('2026-04-10T08:00:00')).toBe('2 тиж. тому');
  });

  it('понад 30 днів — повертає звичайну дату', () => {
    expect(formatRelativeDate('2026-01-01')).toBe(formatDate('2026-01-01'));
  });
});

describe('localDate / localToday (без UTC-зсуву, критичне правило проєкту)', () => {
  it('localDate без аргументу дорівнює localToday', () => {
    expect(localDate()).toBe(localToday());
  });

  it('форматує у YYYY-MM-DD за локальним часом, а не toISOString (UTC)', () => {
    // 23:30 за локальним часом 2026-01-15 — toISOString() у західніших часових поясах
    // здатен "перестрибнути" на наступний день (UTC-зсув). localDate має лишитись на 15-му.
    const d = new Date(2026, 0, 15, 23, 30);
    expect(localDate(d)).toBe('2026-01-15');
  });
});

describe('getInitials', () => {
  it('бере перші літери перших двох слів, у верхньому регістрі', () => {
    expect(getInitials('Іван Петренко')).toBe('ІП');
  });

  it('одне слово — одна літера', () => {
    expect(getInitials('Admin')).toBe('A');
  });

  it('порожнє/відсутнє значення — порожній рядок', () => {
    expect(getInitials('')).toBe('');
    expect(getInitials(undefined)).toBe('');
  });
});
