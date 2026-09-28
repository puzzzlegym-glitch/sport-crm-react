import { getArrivalsList } from './arrivals';
import { localToday } from '../utils/format';

const FAR_PAST = '2000-01-01';

/**
 * Нова сторінка «Склад» — не з оригінального PHP-застосунку.
 * Забирає повну історію приходів клубу (arrivals_api.php:get_list пагінує по 30,
 * але коректно повертає pagination.pages — на відміну від products_api.php:get_sales,
 * тому саме приходи вимагають цього перебору сторінок).
 */
export async function getAllArrivalsForClub() {
  const today = localToday();
  let page = 1;
  let all = [];
  while (true) {
    const res = await getArrivalsList({ date_from: FAR_PAST, date_to: today, page });
    if (!res.success) return { success: false, error: res.error };
    all = all.concat(res.arrivals);
    if (page >= res.pagination.pages) break;
    page += 1;
  }
  return { success: true, arrivals: all };
}

/**
 * `stock_qty` сам по собі — надійне джерело правди (веде себе через SQL-тригери
 * trg_sale_insert/update/delete на product_sales, trg_arrival_insert/update/delete
 * на product_arrivals — підтверджено прямим SQL-тестом 2026-08-25). Раніше тут була
 * ще й незалежно "обчислена" звірка (сума приходів мінус total_sold) з попередженням
 * про розбіжність — прибрано: total_sold рахує ВСІ рядки product_sales, включно з
 * тими, що належать поверненим (status='returned') замовленням у sale_orders, тож
 * після кожного повернення звірка показувала фальшиву різницю, хоча stock_qty вже
 * коректно врахував продаж+повернення. Єдине, що ця функція тепер додає до товару —
 * кількість, що очікується (status='pending' у приходах), бо це не сидить у stock_qty.
 */
export function reconcileStock(products, arrivals) {
  const pendingByProduct = new Map();
  for (const a of arrivals) {
    if (a.status !== 'pending') continue;
    const qty = a.expected_qty || a.quantity;
    pendingByProduct.set(a.product_id, (pendingByProduct.get(a.product_id) || 0) + qty);
  }

  return products.map((p) => ({
    ...p,
    pending_qty: pendingByProduct.get(p.id) || 0,
  }));
}
