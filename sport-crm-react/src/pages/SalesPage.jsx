import { useEffect, useState } from 'react';
import AppLayout from '../components/layout/AppLayout';
import Table from '../components/ui/Table';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import CardMenu from '../components/ui/CardMenu';
import DetailFieldsGrid from '../components/ui/DetailFieldsGrid';
import { usePermissions } from '../hooks/usePermissions';
import { useShiftLock } from '../hooks/useShiftLock';
import { useToast } from '../components/ui/ToastProvider';
import {
  getSaleOrders, getSaleOrder, createSaleOrder, returnSaleOrder,
  confirmSaleReturn, cancelSaleReturn, confirmCancelSaleReturn, updateSaleItem,
} from '../api/sales';
import { getProducts } from '../api/products';
import { searchClients } from '../api/clients';
import { formatMoney, formatDate, formatTime, localDate } from '../utils/format';
import './SalesPage.css';

const METHOD_LABELS = {
  cash: 'Готівка', card: 'Картка', terminal: 'Термінал', deposit: 'Депозит', other: 'Інше',
};
const METHOD_OPTIONS = ['cash', 'card', 'terminal', 'deposit', 'other'];

const STATUS_LABELS = {
  completed: 'Завершено',
  returned: 'Повернення',
  pending_return: 'На підтв. повернення',
  pending_cancel: 'На підтв. скасування',
};
const STATUS_VARIANTS = {
  completed: 'active',
  returned: 'inactive',
  pending_return: 'pending',
  pending_cancel: 'pending',
};
const REFUND_LABELS = { cash: 'Готівкою', deposit: 'На депозит', other: 'Поза системою' };

function addDays(d, n) { const x = new Date(d); x.setDate(x.getDate() + n); return x; }
function startOfWeek(d) { const x = new Date(d); const day = x.getDay() || 7; x.setDate(x.getDate() - day + 1); return x; }

/** Дата/діапазон поточного періоду за значенням фільтра ('all'|'today'|'yesterday'|'week'|'month'|'quarter'|'year'). */
function computePeriodRange(unit) {
  const now = new Date();
  if (unit === 'today') return { date: localDate(now) };
  if (unit === 'yesterday') return { date: localDate(addDays(now, -1)) };
  if (unit === 'week') {
    const start = startOfWeek(now);
    return { date_from: localDate(start), date_to: localDate(addDays(start, 6)) };
  }
  if (unit === 'month') {
    const start = new Date(now.getFullYear(), now.getMonth(), 1);
    const end = new Date(now.getFullYear(), now.getMonth() + 1, 0);
    return { date_from: localDate(start), date_to: localDate(end) };
  }
  if (unit === 'quarter') {
    const start = new Date(now.getFullYear(), Math.floor(now.getMonth() / 3) * 3, 1);
    const end = new Date(start.getFullYear(), start.getMonth() + 3, 0);
    return { date_from: localDate(start), date_to: localDate(end) };
  }
  if (unit === 'year') return { date_from: `${now.getFullYear()}-01-01`, date_to: `${now.getFullYear()}-12-31` };
  return {};
}

function SearchDropdown({ items, renderItem, onPick }) {
  if (items.length === 0) return null;
  return (
    <div className="sales-search-dropdown">
      {items.map((item) => (
        <div key={item.id} className="sales-search-dropdown-item" onClick={() => onPick(item)}>
          {renderItem(item)}
        </div>
      ))}
    </div>
  );
}

export default function SalesPage() {
  const { has } = usePermissions();
  const { guard, lockedProps } = useShiftLock();
  const toast = useToast();

  const [period, setPeriod] = useState('all');
  const [method, setMethod] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');

  const [orders, setOrders] = useState([]);
  const [loading, setLoading] = useState(true);

  const [cart, setCart] = useState(null);
  const [ret, setRet] = useState(null);
  const [view, setView] = useState(null);
  const [cancelModal, setCancelModal] = useState(null);
  const [itemEditModal, setItemEditModal] = useState(null);

  const periodRange = computePeriodRange(period);

  async function reload() {
    setLoading(true);
    const res = await getSaleOrders({ date: periodRange.date, date_from: periodRange.date_from, date_to: periodRange.date_to, method, search });
    setLoading(false);
    if (!res.success) { toast(res.error, 'error'); setOrders([]); return; }
    setOrders(res.orders || []);
  }

  useEffect(() => {
    const t = setTimeout(() => setSearch(searchInput.trim()), 350);
    return () => clearTimeout(t);
  }, [searchInput]);

  useEffect(() => { reload(); }, [period, method, search]);

  // ── Перегляд чека ─────────────────────────────────────────
  async function openView(id) {
    setView({ loading: true, order: null });
    const res = await getSaleOrder(id);
    if (!res.success) { toast(res.error, 'error'); setView(null); return; }
    setView({ loading: false, order: res.order });
  }

  // ── Новий продаж (кошик: штрих-код або пошук товару всередині) ──
  function openCart() {
    setCart({
      items: [], clientId: null, clientName: '', clientSearch: '', clientResults: [],
      paymentMethod: 'cash', discount: '0', notes: '',
      productSearch: '', productResults: [], barcode: '',
      error: '', submitting: false,
    });
  }

  useEffect(() => {
    if (!cart || cart.productSearch.trim().length < 2) {
      if (cart?.productResults.length) setCart((c) => (c ? { ...c, productResults: [] } : c));
      return;
    }
    const t = setTimeout(async () => {
      const res = await getProducts({ search: cart.productSearch.trim() });
      setCart((c) => (c ? { ...c, productResults: res.success ? (res.products || []).filter((p) => p.is_active && p.stock_qty > 0) : [] } : c));
    }, 300);
    return () => clearTimeout(t);
  }, [cart?.productSearch]);

  useEffect(() => {
    if (!cart || cart.clientSearch.trim().length < 2) {
      if (cart?.clientResults.length) setCart((c) => (c ? { ...c, clientResults: [] } : c));
      return;
    }
    const t = setTimeout(async () => {
      const res = await searchClients(cart.clientSearch.trim());
      setCart((c) => (c ? { ...c, clientResults: res.success ? (res.results || []) : [] } : c));
    }, 300);
    return () => clearTimeout(t);
  }, [cart?.clientSearch]);

  function addToCart(product) {
    setCart((c) => {
      const existing = c.items.find((i) => i.productId === product.id);
      if (existing) {
        if (existing.qty >= product.stock_qty) {
          toast(`Немає більше на складі: ${product.name}`, 'warning');
          return { ...c, productSearch: '', productResults: [], barcode: '' };
        }
        return {
          ...c, productSearch: '', productResults: [], barcode: '',
          items: c.items.map((i) => (i.productId === product.id ? { ...i, qty: i.qty + 1 } : i)),
        };
      }
      return {
        ...c, productSearch: '', productResults: [], barcode: '',
        items: [...c.items, { key: product.id, productId: product.id, name: product.name, price: product.sale_price, qty: 1, stock: product.stock_qty }],
      };
    });
  }

  async function submitBarcode() {
    const code = cart.barcode.trim();
    if (!code) return;
    const res = await getProducts({ search: code });
    if (!res.success) { toast(res.error, 'error'); return; }
    const list = res.products || [];
    const product = list.find((p) => p.barcode === code) || list[0];
    if (!product) { toast('Товар не знайдено за штрих-кодом', 'error'); return; }
    if (product.stock_qty <= 0) { toast('Товару немає в наявності', 'error'); return; }
    addToCart(product);
  }

  function setItemQty(productId, qty) {
    setCart((c) => ({
      ...c,
      items: c.items.map((i) => (i.productId === productId ? { ...i, qty: Math.max(1, Math.min(parseInt(qty) || 1, i.stock)) } : i)),
    }));
  }

  function removeItem(productId) {
    setCart((c) => ({ ...c, items: c.items.filter((i) => i.productId !== productId) }));
  }

  const cartSubtotal = cart ? cart.items.reduce((s, i) => s + i.qty * i.price, 0) : 0;
  const cartDiscount = cart ? parseFloat(cart.discount) || 0 : 0;
  const cartTotal = Math.max(0, cartSubtotal - cartDiscount);

  async function submitCart() {
    if (cart.items.length === 0) { setCart({ ...cart, error: 'Додайте хоча б один товар' }); return; }
    if (cart.paymentMethod === 'deposit' && !cart.clientId) {
      setCart({ ...cart, error: "При оплаті депозитом клієнт обов'язковий" });
      return;
    }
    setCart({ ...cart, submitting: true, error: '' });
    const res = await createSaleOrder({
      items: cart.items.map((i) => ({ product_id: i.productId, quantity: i.qty, sale_price: i.price })),
      discount: cartDiscount,
      payment_method: cart.paymentMethod,
      client_id: cart.clientId,
      client_name: cart.clientId ? cart.clientName : '',
      notes: cart.notes.trim(),
    });
    if (!res.success) { setCart({ ...cart, submitting: false, error: res.error || 'Невідома помилка' }); return; }
    toast(res.message || 'Продаж оформлено', 'success');
    setCart(null);
    reload();
  }

  // ── Повернення товару ─────────────────────────────────────
  function openReturn() {
    setRet({ query: '', results: [], selected: null, reason: '', refundMethod: 'other', refundLocation: 'register', submitting: false, error: '' });
  }
  function openReturnFor(order) {
    setRet({ query: '', results: [], selected: order, reason: '', refundMethod: 'other', refundLocation: 'register', submitting: false, error: '' });
  }

  useEffect(() => {
    if (!ret || ret.selected || ret.query.trim().length < 2) {
      if (ret?.results.length) setRet((r) => (r ? { ...r, results: [] } : r));
      return;
    }
    const t = setTimeout(async () => {
      const res = await getSaleOrders({ search: ret.query.trim(), status: 'completed' });
      setRet((r) => (r && !r.selected ? { ...r, results: res.success ? (res.orders || []) : [] } : r));
    }, 300);
    return () => clearTimeout(t);
  }, [ret?.query, ret?.selected]);

  async function confirmReturn() {
    setRet({ ...ret, submitting: true, error: '' });
    const res = await returnSaleOrder({
      id: ret.selected.id,
      reason: ret.reason.trim(),
      refund_method: ret.refundMethod,
      refund_location: ret.refundLocation,
    });
    if (!res.success) { setRet({ ...ret, submitting: false, error: res.error || 'Невідома помилка' }); return; }
    toast(res.message || 'Повернення оформлено', 'success');
    const returnedId = ret.selected.id;
    setRet(null);
    reload();
    setView((v) => (v?.order?.id === returnedId ? null : v));
  }

  // ── Підтвердження повернення (двоетапний процес) ────────────
  async function handleConfirmReturn(order) {
    const res = await confirmSaleReturn(order.id);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message || 'Повернення підтверджено', 'success');
    reload();
    setView((v) => (v?.order?.id === order.id ? null : v));
  }

  // ── Скасування повернення ───────────────────────────────────
  function openCancelModal(order) {
    setCancelModal({ order, reason: '', submitting: false, error: '' });
  }

  async function submitCancelReturn() {
    setCancelModal({ ...cancelModal, submitting: true, error: '' });
    const res = await cancelSaleReturn(cancelModal.order.id, cancelModal.reason.trim());
    if (!res.success) { setCancelModal({ ...cancelModal, submitting: false, error: res.error || 'Невідома помилка' }); return; }
    toast(res.message || 'Скасування оформлено', 'success');
    const orderId = cancelModal.order.id;
    setCancelModal(null);
    reload();
    setView((v) => (v?.order?.id === orderId ? null : v));
  }

  async function handleConfirmCancelReturn(order) {
    const res = await confirmCancelSaleReturn(order.id);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message || 'Скасування підтверджено', 'success');
    reload();
    setView((v) => (v?.order?.id === order.id ? null : v));
  }

  // ── Редагування проданої позиції ────────────────────────────
  function openItemEdit(item) {
    setItemEditModal({ item, quantity: String(item.quantity), salePrice: String(item.sale_price), discount: String(item.discount), submitting: false, error: '' });
  }

  async function submitItemEdit() {
    setItemEditModal({ ...itemEditModal, submitting: true, error: '' });
    const res = await updateSaleItem({
      id: itemEditModal.item.id,
      quantity: parseInt(itemEditModal.quantity, 10) || 1,
      sale_price: parseFloat(itemEditModal.salePrice) || 0,
      discount: parseFloat(itemEditModal.discount) || 0,
    });
    if (!res.success) { setItemEditModal({ ...itemEditModal, submitting: false, error: res.error || 'Невідома помилка' }); return; }
    toast(res.message || 'Позицію оновлено', 'success');
    const orderId = view?.order?.id;
    setItemEditModal(null);
    reload();
    if (orderId) openView(orderId);
  }

  // ── Колонки історії ───────────────────────────────────────
  const columns = [
    {
      key: 'product', label: 'Товар',
      render: (o) => (
        <>
          <div style={{ fontSize: 14, fontWeight: 500 }}>
            {o.first_product_name || '—'}{o.items_count > 1 ? ` +${o.items_count - 1}` : ''}
          </div>
          <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>
            #{o.order_number}{o.client_name ? ` · ${o.client_name}` : ''}
          </div>
        </>
      ),
    },
    {
      key: 'date', label: 'Дата', mobile: 'secondary',
      render: (o) => <>{formatDate(o.created_at)} <span style={{ color: 'var(--text-muted)' }}>{formatTime(o.created_at)}</span></>,
    },
    { key: 'items', label: 'Товарів', mobile: 'trailing', render: (o) => `${o.items_count} шт.` },
    { key: 'total', label: 'Сума', cardTop: true, render: (o) => <strong>{formatMoney(o.total_amount)}</strong> },
    { key: 'method', label: 'Оплата', render: (o) => <Badge variant={o.payment_method}>{METHOD_LABELS[o.payment_method] || o.payment_method}</Badge> },
    {
      key: 'status', label: 'Статус',
      render: (o) => <Badge variant={STATUS_VARIANTS[o.status] || 'info'}>{STATUS_LABELS[o.status] || o.status}</Badge>,
    },
    { key: 'admin', label: 'Продавець', render: (o) => o.admin_name || '—' },
    {
      key: 'actions', label: '',
      render: (o) => (
        <div onClick={(e) => e.stopPropagation()}>
          <CardMenu
            actions={[
              { label: 'Переглянути', icon: 'eye', onClick: () => openView(o.id) },
              o.status === 'completed' && has('sales.return')
                ? { label: 'Повернути', icon: 'undo', danger: true, onClick: guard(() => openReturnFor(o)) }
                : null,
              o.status === 'pending_return' && has('sales.return_confirm')
                ? { label: 'Підтвердити повернення', icon: 'check', onClick: () => handleConfirmReturn(o) }
                : null,
              o.status === 'returned' && has('sales.return_cancel')
                ? { label: 'Скасувати повернення', icon: 'undo', onClick: () => openCancelModal(o) }
                : null,
              o.status === 'pending_cancel' && has('sales.return_cancel_confirm')
                ? { label: 'Підтвердити скасування', icon: 'check', onClick: () => handleConfirmCancelReturn(o) }
                : null,
            ]}
          />
        </div>
      ),
    },
  ];

  return (
    <AppLayout title="Продаж товарів">
      <div className="sales-toolbar">
        {has('sales.create') && (
          <button className="btn btn-primary sales-action-btn" {...lockedProps} onClick={guard(openCart)}>
            <Icon name="cart" size={17} /> Новий продаж
          </button>
        )}
        {has('sales.return') && (
          <button className="btn btn-ghost sales-action-btn" {...lockedProps} onClick={guard(openReturn)}>
            <Icon name="undo" size={17} /> Повернення товару
          </button>
        )}
        <select value={period} onChange={(e) => setPeriod(e.target.value)}>
          <option value="all">Всі продажі</option>
          <option value="today">Сьогодні</option>
          <option value="yesterday">Вчора</option>
          <option value="week">Цей тиждень</option>
          <option value="month">Цей місяць</option>
          <option value="quarter">Цей квартал</option>
          <option value="year">Цей рік</option>
        </select>
        <select value={method} onChange={(e) => setMethod(e.target.value)}>
          <option value="">Всі способи оплати</option>
          {METHOD_OPTIONS.map((m) => <option key={m} value={m}>{METHOD_LABELS[m]}</option>)}
        </select>
      </div>

      <div className="input-icon-wrap sales-search-input">
        <Icon name="search" size={15} className="input-icon" />
        <input type="text" placeholder="Пошук продажу..." value={searchInput} onChange={(e) => setSearchInput(e.target.value)} />
      </div>

      <div className="card sales-history-table" style={{ padding: 0, overflow: 'hidden' }}>
        <Table
          columns={columns}
          rows={orders}
          loading={loading}
          emptyMessage="Продажів не знайдено"
          onRowClick={(o) => openView(o.id)}
          rowProps={(o) => ({ style: { '--card-accent': o.status === 'returned' ? 'var(--danger)' : 'var(--border)' } })}
        />
      </div>

      {/* Новий продаж: штрих-код або пошук товару всередині кошика */}
      <Modal
        size="lg"
        open={!!cart}
        onClose={() => setCart(null)}
        title="Новий продаж"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={cart?.submitting || cart?.items.length === 0} onClick={submitCart}>
              {cart?.submitting ? 'Оформлення...' : `Оформити чек · ${formatMoney(cartTotal)}`}
            </button>
            <button className="btn btn-ghost" onClick={() => setCart(null)}>Скасувати</button>
          </div>
        }
      >
        {cart && (
          <>
            {cart.error && <div className="alert alert-error">{cart.error}</div>}

            <FormGroup label="Штрих-код">
              <input
                type="text" placeholder="Скануйте або введіть код і натисніть Enter"
                autoFocus
                value={cart.barcode}
                onChange={(e) => setCart({ ...cart, barcode: e.target.value })}
                onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); submitBarcode(); } }}
              />
            </FormGroup>

            <FormGroup label="Пошук товару (назва, штрих-код)">
              <div style={{ position: 'relative' }}>
                <input
                  type="text" placeholder="Почніть вводити назву..." autoComplete="off"
                  value={cart.productSearch}
                  onChange={(e) => setCart({ ...cart, productSearch: e.target.value })}
                />
                <SearchDropdown
                  items={cart.productResults}
                  onPick={addToCart}
                  renderItem={(p) => (
                    <>
                      <span>{p.name}</span>
                      <span className="text-muted" style={{ fontSize: 12 }}>{formatMoney(p.sale_price)} · {p.stock_qty} шт.</span>
                    </>
                  )}
                />
              </div>
            </FormGroup>

            {cart.items.length === 0 ? (
              <div className="empty-state" style={{ padding: 20, textAlign: 'center', marginBottom: 16 }}>Кошик порожній — відскануйте або знайдіть товар</div>
            ) : (
              <div className="sales-cart-list">
                {cart.items.map((i) => (
                  <div key={i.key} className="sales-cart-row">
                    <div className="sales-cart-row-name">
                      {i.name}
                      <div className="text-muted" style={{ fontSize: 12 }}>{formatMoney(i.price)} / шт. · залишок {i.stock}</div>
                    </div>
                    <div className="qty-control">
                      <button type="button" className="qty-btn" onClick={() => setItemQty(i.productId, i.qty - 1)}>−</button>
                      <input type="number" className="qty-input" min="1" max={i.stock} value={i.qty} onChange={(e) => setItemQty(i.productId, e.target.value)} />
                      <button type="button" className="qty-btn" onClick={() => setItemQty(i.productId, i.qty + 1)}>+</button>
                    </div>
                    <div className="sales-cart-row-sum">{formatMoney(i.qty * i.price)}</div>
                    <button type="button" className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }} onClick={() => removeItem(i.productId)}><Icon name="trash" size={14} /></button>
                  </div>
                ))}
              </div>
            )}

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Знижка (грн)">
                <input type="number" min="0" step="1" value={cart.discount} onChange={(e) => setCart({ ...cart, discount: e.target.value })} />
              </FormGroup>
              <FormGroup label="Спосіб оплати">
                <select value={cart.paymentMethod} onChange={(e) => setCart({ ...cart, paymentMethod: e.target.value })}>
                  {METHOD_OPTIONS.map((m) => <option key={m} value={m}>{METHOD_LABELS[m]}</option>)}
                </select>
              </FormGroup>
            </div>

            <FormGroup label={<>Клієнт {cart.paymentMethod === 'deposit' && <span style={{ color: 'var(--danger)' }}>*</span>} <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>(обов'язково при оплаті депозитом)</span></>}>
              <div style={{ position: 'relative' }}>
                <input type="text" placeholder="Ім'я або телефон..." value={cart.clientSearch} onChange={(e) => setCart({ ...cart, clientSearch: e.target.value })} autoComplete="off" />
                <SearchDropdown
                  items={cart.clientResults}
                  onPick={(c) => setCart({ ...cart, clientId: c.id, clientName: c.full_name, clientSearch: '', clientResults: [] })}
                  renderItem={(c) => (
                    <>
                      <strong>{c.full_name}</strong> <span className="text-muted" style={{ fontSize: 12, marginLeft: 8 }}>{c.phone || ''}</span>
                    </>
                  )}
                />
              </div>
              {cart.clientId && (
                <div style={{ marginTop: 6, fontSize: 13, padding: '6px 10px', background: 'var(--accent-dim)', borderRadius: 'var(--radius-sm)' }}>
                  {cart.clientName}
                  <button onClick={() => setCart({ ...cart, clientId: null, clientName: '' })} style={{ float: 'right', background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)' }}>✕</button>
                </div>
              )}
            </FormGroup>

            <div className="sell-summary">
              <div className="sell-summary-row"><span>Товарів у чеку</span><span>{cart.items.length}</span></div>
              {cartDiscount > 0 && <div className="sell-summary-row"><span>Знижка</span><span style={{ color: 'var(--success)' }}>−{formatMoney(cartDiscount)}</span></div>}
              <div className="sell-summary-row total"><span>Разом</span><span>{formatMoney(cartTotal)}</span></div>
            </div>
          </>
        )}
      </Modal>

      {/* Повернення товару */}
      <Modal
        open={!!ret}
        onClose={() => setRet(null)}
        title="Повернення товару"
        footer={
          ret?.selected ? (
            <div style={{ display: 'flex', gap: 10 }}>
              <button className="btn btn-danger" disabled={ret.submitting} onClick={confirmReturn}>{ret.submitting ? 'Оформлення...' : 'Оформити повернення'}</button>
              <button className="btn btn-ghost" onClick={() => setRet({ ...ret, selected: null })}>Назад до пошуку</button>
            </div>
          ) : (
            <button className="btn btn-ghost" onClick={() => setRet(null)}>Скасувати</button>
          )
        }
      >
        {ret && !ret.selected && (
          <>
            {ret.error && <div className="alert alert-error">{ret.error}</div>}
            <FormGroup label="Номер чека або клієнт">
              <input type="text" placeholder="#1257 або ім'я клієнта..." autoFocus value={ret.query} onChange={(e) => setRet({ ...ret, query: e.target.value })} />
            </FormGroup>
            <div className="sales-return-results">
              {ret.results.map((o) => (
                <div key={o.id} className="sales-return-result-item" onClick={() => setRet({ ...ret, selected: o })}>
                  <div><strong>#{o.order_number}</strong> · {o.client_name || 'Без клієнта'}</div>
                  <div className="text-muted" style={{ fontSize: 12 }}>{formatDate(o.created_at)} {formatTime(o.created_at)} · {o.items_count} шт. · {formatMoney(o.total_amount)}</div>
                </div>
              ))}
              {ret.query.trim().length >= 2 && ret.results.length === 0 && (
                <div className="text-muted" style={{ padding: '8px 4px', fontSize: 13 }}>Нічого не знайдено</div>
              )}
            </div>
          </>
        )}
        {ret?.selected && (
          <>
            {ret.error && <div className="alert alert-error">{ret.error}</div>}
            <div style={{ background: 'var(--bg-elevated)', borderRadius: 'var(--radius-md)', padding: 12, marginBottom: 16 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 4 }}>
                <strong>Чек №{ret.selected.order_number}</strong>
                <span>{formatMoney(ret.selected.total_amount)}</span>
              </div>
              <div className="text-muted" style={{ fontSize: 13 }}>{ret.selected.client_name || 'Без клієнта'} · {formatDate(ret.selected.created_at)} {formatTime(ret.selected.created_at)} · {ret.selected.items_count} шт.</div>
            </div>
            <FormGroup label="Причина повернення" hint="Необов'язково">
              <input type="text" value={ret.reason} onChange={(e) => setRet({ ...ret, reason: e.target.value })} />
            </FormGroup>
            <FormGroup label="Спосіб повернення коштів">
              <select value={ret.refundMethod} onChange={(e) => setRet({ ...ret, refundMethod: e.target.value })}>
                <option value="other">Поза системою (картка/термінал)</option>
                <option value="cash">Готівкою</option>
                <option value="deposit">На депозит клієнта</option>
              </select>
            </FormGroup>
            {ret.refundMethod === 'cash' && (
              <FormGroup label="Звідки готівка">
                <select value={ret.refundLocation} onChange={(e) => setRet({ ...ret, refundLocation: e.target.value })}>
                  <option value="register">Каса</option>
                  <option value="safe">Сейф</option>
                </select>
              </FormGroup>
            )}
          </>
        )}
      </Modal>

      {/* Скасування повернення */}
      <Modal
        open={!!cancelModal}
        onClose={() => setCancelModal(null)}
        title="Скасування повернення"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-danger" disabled={cancelModal?.submitting} onClick={submitCancelReturn}>
              {cancelModal?.submitting ? 'Оформлення...' : 'Скасувати повернення'}
            </button>
            <button className="btn btn-ghost" onClick={() => setCancelModal(null)}>Назад</button>
          </div>
        }
      >
        {cancelModal && (
          <>
            {cancelModal.error && <div className="alert alert-error">{cancelModal.error}</div>}
            <p className="text-muted" style={{ fontSize: 13, marginBottom: 12 }}>
              Чек №{cancelModal.order.order_number} знову стане "Завершено", товар повторно спишеться зі складу,
              а раніше повернені кошти будуть скасовані.
            </p>
            <FormGroup label="Причина скасування" hint="Необов'язково">
              <input type="text" value={cancelModal.reason} onChange={(e) => setCancelModal({ ...cancelModal, reason: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>

      {/* Редагування проданої позиції */}
      <Modal
        open={!!itemEditModal}
        onClose={() => setItemEditModal(null)}
        title="Редагувати позицію"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={itemEditModal?.submitting} onClick={submitItemEdit}>
              {itemEditModal?.submitting ? 'Збереження...' : 'Зберегти'}
            </button>
            <button className="btn btn-ghost" onClick={() => setItemEditModal(null)}>Скасувати</button>
          </div>
        }
      >
        {itemEditModal && (
          <>
            {itemEditModal.error && <div className="alert alert-error">{itemEditModal.error}</div>}
            <p className="text-muted" style={{ fontSize: 13, marginBottom: 12 }}>{itemEditModal.item.product_name}</p>
            <FormGroup label="Кількість">
              <input type="number" min="1" value={itemEditModal.quantity} onChange={(e) => setItemEditModal({ ...itemEditModal, quantity: e.target.value })} />
            </FormGroup>
            <FormGroup label="Ціна за шт. (грн)">
              <input type="number" min="0" step="0.01" value={itemEditModal.salePrice} onChange={(e) => setItemEditModal({ ...itemEditModal, salePrice: e.target.value })} />
            </FormGroup>
            <FormGroup label="Знижка (грн)">
              <input type="number" min="0" step="0.01" value={itemEditModal.discount} onChange={(e) => setItemEditModal({ ...itemEditModal, discount: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>

      {/* Перегляд чека */}
      <Modal open={!!view} onClose={() => setView(null)} title={view?.order ? `Чек №${view.order.order_number}` : 'Чек'}>
        {view?.loading && <div className="loader"><span className="spinner" /> Завантаження...</div>}
        {view?.order && (() => {
          const o = view.order;
          const fields = [
            ['Клієнт', o.client_name || '—'],
            ['Спосіб оплати', METHOD_LABELS[o.payment_method] || o.payment_method],
            ['Продавець', o.admin_name || '—'],
          ];
          if (o.status === 'returned' || o.status === 'pending_return' || o.status === 'pending_cancel') {
            fields.push(['Причина повернення', o.return_reason || '—']);
          }
          if (o.refund_method) fields.push(['Повернення коштів', REFUND_LABELS[o.refund_method] || o.refund_method]);
          if (o.status === 'pending_cancel') fields.push(['Причина скасування', o.cancel_reason || '—']);
          const canEditItems = o.status === 'completed' && has('sales.edit');
          return (
            <>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
                <Badge variant={STATUS_VARIANTS[o.status] || 'info'}>{STATUS_LABELS[o.status] || o.status}</Badge>
                <span className="text-muted" style={{ fontSize: 13 }}>{formatDate(o.created_at)} {formatTime(o.created_at)}</span>
              </div>
              <DetailFieldsGrid fields={fields} />
              <div className="sales-cart-list" style={{ marginTop: 12 }}>
                {o.items.map((it) => (
                  <div key={it.id} className="sales-cart-row sales-cart-row-view">
                    <div className="sales-cart-row-name">
                      {it.product_name}
                      <div className="text-muted" style={{ fontSize: 12 }}>{it.quantity} шт. × {formatMoney(it.sale_price)}</div>
                    </div>
                    <div className="sales-cart-row-sum">{formatMoney(it.total_amount)}</div>
                    {canEditItems && (
                      <button type="button" className="btn btn-ghost btn-sm" onClick={() => openItemEdit(it)}>
                        <Icon name="edit" size={14} />
                      </button>
                    )}
                  </div>
                ))}
              </div>
              <div className="sell-summary" style={{ marginTop: 12 }}>
                <div className="sell-summary-row"><span>Товарів</span><span>{o.items_count}</span></div>
                {o.discount_amount > 0 && <div className="sell-summary-row"><span>Знижка</span><span style={{ color: 'var(--success)' }}>−{formatMoney(o.discount_amount)}</span></div>}
                <div className="sell-summary-row total"><span>Разом</span><span>{formatMoney(o.total_amount)}</span></div>
              </div>
              <div style={{ marginTop: 16, display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                {o.status === 'completed' && has('sales.return') && (
                  <button className="btn btn-danger" {...lockedProps} onClick={guard(() => { setView(null); openReturnFor(o); })}>
                    <Icon name="undo" size={15} /> Повернути
                  </button>
                )}
                {o.status === 'pending_return' && has('sales.return_confirm') && (
                  <button className="btn btn-primary" onClick={() => handleConfirmReturn(o)}>
                    <Icon name="check" size={15} /> Підтвердити повернення
                  </button>
                )}
                {o.status === 'returned' && has('sales.return_cancel') && (
                  <button className="btn btn-ghost" onClick={() => { setView(null); openCancelModal(o); }}>
                    <Icon name="undo" size={15} /> Скасувати повернення
                  </button>
                )}
                {o.status === 'pending_cancel' && has('sales.return_cancel_confirm') && (
                  <button className="btn btn-primary" onClick={() => handleConfirmCancelReturn(o)}>
                    <Icon name="check" size={15} /> Підтвердити скасування
                  </button>
                )}
              </div>
            </>
          );
        })()}
      </Modal>
    </AppLayout>
  );
}
