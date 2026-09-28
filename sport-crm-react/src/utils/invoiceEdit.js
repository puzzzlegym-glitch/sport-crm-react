import { localDate } from './format';

function addDays(dateStr, n) {
  const d = new Date(`${dateStr}T00:00:00`);
  d.setDate(d.getDate() + n);
  return localDate(d);
}

function daysBetween(a, b) {
  return Math.round((new Date(`${b}T00:00:00`) - new Date(`${a}T00:00:00`)) / 86400000);
}

/**
 * Попередній перерахунок абонемента у формі редагування — 1:1 з invoices_api.php:update.
 *  • тариф той самий: ціна (зі знижкою), ліміт і тривалість не змінюються; нова дата
 *    початку зсуває кінець на стільки ж днів (заморозки/продовження зберігаються);
 *  • інший тариф: ціна нового тарифу з тією ж знижкою (частка від ціни старого тарифу),
 *    кінець = початок + тривалість − 1 + уже надані дні заморозки й продовження.
 * @param orig      абонемент як прийшов з сервера (invoices_api get_one)
 * @param tariffs   активні тарифи клубу (get_tariffs)
 * @param tariffId  обраний тариф
 * @param startDate обрана дата початку (YYYY-MM-DD)
 */
export function previewInvoiceEdit(orig, tariffs, tariffId, startDate) {
  const start = startDate || orig.start_date;
  if (String(tariffId) === String(orig.tariff_id)) {
    return {
      price: orig.price,
      visits_total: orig.visits_total ?? '',
      end_date: addDays(orig.end_date, daysBetween(orig.start_date, start)),
    };
  }
  const t = tariffs.find((x) => String(x.id) === String(tariffId));
  if (!t) return {};
  const oldT = tariffs.find((x) => String(x.id) === String(orig.tariff_id));
  const oldPrice = oldT ? Number(oldT.price) : 0;
  const ratio = oldPrice > 0 ? Math.min(1, Number(orig.price) / oldPrice) : 1;
  const extra = (Number(orig.freeze_days) || 0) + (Number(orig.prolong_days) || 0);
  return {
    price: Math.round(Number(t.price) * ratio * 100) / 100,
    visits_total: t.visits_limit ?? '',
    end_date: addDays(start, Number(t.duration_days) - 1 + extra),
  };
}
