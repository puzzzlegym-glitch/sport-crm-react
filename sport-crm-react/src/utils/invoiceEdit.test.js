import { describe, it, expect } from 'vitest';
import { previewInvoiceEdit } from './invoiceEdit';

const tariffs = [
  { id: 1, price: 1000, duration_days: 30, visits_limit: 12 },
  { id: 2, price: 2000, duration_days: 60, visits_limit: null },
];
// Продано зі знижкою 10% (900 з 1000), заморожувався 5 днів: 23.09 → 27.10
const orig = { tariff_id: 1, price: 900, start_date: '2026-09-23', end_date: '2026-10-27', visits_total: 12, freeze_days: 5, prolong_days: 0 };

describe('previewInvoiceEdit (1:1 з invoices_api update)', () => {
  it('той самий тариф — ціна зі знижкою і дні заморозки зберігаються', () => {
    expect(previewInvoiceEdit(orig, tariffs, 1, '2026-09-23')).toEqual({ price: 900, visits_total: 12, end_date: '2026-10-27' });
  });

  it('той самий тариф, нова дата початку — кінець зсувається на ту саму кількість днів', () => {
    expect(previewInvoiceEdit(orig, tariffs, 1, '2026-09-25').end_date).toBe('2026-10-29');
  });

  it('інший тариф — та сама знижка і вже надані дні заморозки', () => {
    // 2000 × 0.9 = 1800; 23.09 + 60 − 1 + 5 = 26.11
    expect(previewInvoiceEdit(orig, tariffs, 2, '2026-09-23')).toEqual({ price: 1800, visits_total: '', end_date: '2026-11-26' });
  });
});
