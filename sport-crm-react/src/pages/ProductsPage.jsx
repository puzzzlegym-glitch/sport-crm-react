import { useEffect, useState } from 'react';
import AppLayout from '../components/layout/AppLayout';
import Table from '../components/ui/Table';
import Modal from '../components/ui/Modal';
import DetailFieldsGrid from '../components/ui/DetailFieldsGrid';
import FormGroup from '../components/ui/FormGroup';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import { usePermissions } from '../hooks/usePermissions';
import { useShiftLock } from '../hooks/useShiftLock';
import { useToast } from '../components/ui/ToastProvider';
import {
  getCategories, getProducts, createProduct, updateProduct,
  toggleProductActive, addArrival, getArrivalsLog, getProductSalesLog,
} from '../api/products';
import { sellProduct } from '../api/sell';
import { searchClients } from '../api/clients';
import { formatMoney, formatDate, localMonthStart, localToday } from '../utils/format';
import './ProductsPage.css';

const PAY_LABELS = { cash: 'Готівка', card: 'Карта', terminal: 'Термінал', deposit: 'Депозит', other: 'Інше' };
const ARR_OPS = [
  ['arrival', '➡️ Прихід'], ['overdue', '🟡 Прострочка'], ['repack', '🍽️ Розфасування'], ['transfer', '🚫 Перенесення склад'],
];
const ARR_OP_LABELS = { arrival: 'Прихід', overdue: 'Прострочка', repack: 'Розфасування', transfer: 'Перенесення' };
const ARR_OP_COLORS = { arrival: 'var(--success)', overdue: 'var(--danger)', repack: 'var(--warning)', transfer: 'var(--text-muted)' };
const ARR_STATUSES = [['paid', '✅ Оплачено'], ['unpaid', '🔴 Не оплачено'], ['pending', '⏱ Замовлено']];

const EMPTY_FORM = { id: null, name: '', category: '', supplier: '', purchase: '', sale: '', barcode: '', stockMin: '0', photo: '' };

/** Текстове поле-комбобокс з іконкою-префіксом і списком підказок (datalist) */
function IconField({ icon, listId, listOptions, ...props }) {
  return (
    <div className="input-icon-wrap">
      <Icon name={icon} size={16} className="input-icon" />
      <input {...props} list={listId} />
      {listId && <datalist id={listId}>{listOptions.map((o) => <option key={o} value={o} />)}</datalist>}
    </div>
  );
}

export default function ProductsPage() {
  const { has } = usePermissions();
  const { guard, lockedProps } = useShiftLock();
  const toast = useToast();

  const [tab, setTab] = useState('catalog');

  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [category, setCategory] = useState('');
  const [lowStock, setLowStock] = useState(false);
  const [categories, setCategories] = useState([]);
  const [suppliers, setSuppliers] = useState([]);
  const [products, setProducts] = useState([]);
  const [productsLoading, setProductsLoading] = useState(true);

  const [archive, setArchive] = useState({ rows: [], loading: true });

  const [salesFrom, setSalesFrom] = useState(localMonthStart());
  const [salesTo, setSalesTo] = useState(localToday());
  const [salesLog, setSalesLog] = useState({ rows: [], summary: null, loading: true });

  const [arrFrom, setArrFrom] = useState(localMonthStart());
  const [arrTo, setArrTo] = useState(localToday());
  const [arrivalsLog, setArrivalsLog] = useState({ rows: [], loading: true });

  const [viewModal, setViewModal] = useState(null);
  const [formModal, setFormModal] = useState(null);
  const [arrivalModal, setArrivalModal] = useState(null);
  const [sellModal, setSellModal] = useState(null);

  async function reloadProducts() {
    setProductsLoading(true);
    const res = await getProducts({ search, category, low_stock: lowStock });
    setProductsLoading(false);
    if (!res.success) { toast(res.error, 'error'); return; }
    setProducts(res.products || []);
  }

  useEffect(() => {
    getCategories().then((res) => { if (res.success) setCategories(res.categories || []); });
    getProducts({}).then((res) => {
      if (!res.success) return;
      const names = new Set((res.products || []).map((p) => p.supplier).filter(Boolean));
      setSuppliers([...names].sort((a, b) => a.localeCompare(b, 'uk')));
    });
  }, []);

  useEffect(() => {
    const t = setTimeout(() => setSearch(searchInput.trim()), 350);
    return () => clearTimeout(t);
  }, [searchInput]);

  useEffect(() => { reloadProducts(); }, [search, category, lowStock]);

  async function reloadArchive() {
    setArchive((a) => ({ ...a, loading: true }));
    const res = await getProducts({ inactive: true });
    if (!res.success) { toast(res.error, 'error'); setArchive({ rows: [], loading: false }); return; }
    setArchive({ rows: (res.products || []).filter((p) => !p.is_active), loading: false });
  }

  async function reloadSales() {
    setSalesLog((s) => ({ ...s, loading: true }));
    const res = await getProductSalesLog({ date_from: salesFrom, date_to: salesTo });
    if (!res.success) { setSalesLog({ rows: [], summary: null, loading: false }); return; }
    setSalesLog({ rows: res.sales, summary: res.summary, loading: false });
  }

  async function reloadArrivalsLog() {
    setArrivalsLog((a) => ({ ...a, loading: true }));
    const res = await getArrivalsLog({ date_from: arrFrom, date_to: arrTo });
    if (!res.success) { setArrivalsLog({ rows: [], loading: false }); return; }
    setArrivalsLog({ rows: res.arrivals, loading: false });
  }

  useEffect(() => { if (tab === 'archive') reloadArchive(); }, [tab]);
  useEffect(() => { if (tab === 'sales') reloadSales(); }, [tab, salesFrom, salesTo]);
  useEffect(() => { if (tab === 'arrivals') reloadArrivalsLog(); }, [tab, arrFrom, arrTo]);

  function refreshAfterChange() {
    reloadProducts();
    if (tab === 'arrivals') reloadArrivalsLog();
    if (tab === 'sales') reloadSales();
    if (tab === 'archive') reloadArchive();
  }

  // ── Перегляд товару ───────────────────────────────────────
  function openView(p) {
    setViewModal({ product: p });
  }

  function canArchive(p) { return p.stock_qty <= 0; }

  async function handleToggleActive(p) {
    if (p.is_active && !canArchive(p)) {
      toast(`Спочатку реалізуйте або спишіть залишок (${p.stock_qty} шт.)`, 'error');
      return;
    }
    const res = await toggleProductActive(p.id);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message, 'success');
    setViewModal(null);
    refreshAfterChange();
  }

  // ── Форма товару ──────────────────────────────────────────
  function openCreate() {
    setFormModal({ ...EMPTY_FORM, error: '', submitting: false });
  }

  function openEdit(p) {
    setFormModal({
      id: p.id, name: p.name || '', category: p.category || '', supplier: p.supplier || '',
      purchase: p.purchase_price ?? '', sale: p.sale_price ?? '', barcode: p.barcode || '',
      stockMin: p.stock_min ?? '0', photo: p.photo_url || '', error: '', submitting: false,
    });
  }

  async function submitForm() {
    if (!formModal.name.trim()) { setFormModal({ ...formModal, error: 'Введіть назву товару' }); return; }
    setFormModal({ ...formModal, submitting: true, error: '' });
    const payload = {
      id: formModal.id,
      name: formModal.name.trim(),
      category: formModal.category.trim(),
      supplier: formModal.supplier.trim(),
      purchase_price: parseFloat(formModal.purchase) || 0,
      sale_price: parseFloat(formModal.sale) || 0,
      barcode: formModal.barcode.trim(),
      stock_min: parseInt(formModal.stockMin) || 0,
      photo_url: formModal.photo.trim(),
    };
    const res = formModal.id ? await updateProduct(payload) : await createProduct(payload);
    if (!res.success) { setFormModal({ ...formModal, submitting: false, error: res.error }); return; }
    toast(formModal.id ? 'Збережено' : 'Товар додано', 'success');
    setFormModal(null);
    setViewModal(null);
    refreshAfterChange();
  }

  const margin = (() => {
    const purchase = parseFloat(formModal?.purchase) || 0;
    const sale = parseFloat(formModal?.sale) || 0;
    if (purchase > 0 && sale > 0) {
      return `Маржа: ${Math.round(((sale - purchase) / sale) * 100)}% · Прибуток: ${(sale - purchase).toFixed(2)} грн`;
    }
    return '';
  })();

  // ── Прихід ────────────────────────────────────────────────
  function openArrival(preselectId) {
    const p = preselectId ? products.find((x) => x.id === preselectId) : null;
    setArrivalModal({
      productId: preselectId ?? '', operation: 'arrival', status: 'paid', method: 'cash', qty: '1',
      purchase: p?.purchase_price ?? '', sale: p?.sale_price ?? '', supplier: p?.supplier ?? '',
      notes: '', error: '', submitting: false,
    });
  }

  function onArrivalProductChange(id) {
    const p = products.find((x) => x.id === Number(id));
    setArrivalModal((m) => ({ ...m, productId: id, purchase: p?.purchase_price ?? '', sale: p?.sale_price ?? '', supplier: p?.supplier ?? '' }));
  }

  function selectArrOp(op) {
    setArrivalModal((m) => ({ ...m, operation: op, status: op === 'arrival' ? m.status : 'paid' }));
  }

  const arrIsMinus = ['overdue', 'repack', 'transfer'].includes(arrivalModal?.operation);
  const arrIsPending = arrivalModal?.status === 'pending' && arrivalModal?.operation === 'arrival';
  const arrTotal = (() => {
    if (!arrivalModal || arrIsPending) return arrIsPending ? 'Товар буде зафіксовано як замовлений, на склад не додається' : '';
    const qty = parseInt(arrivalModal.qty) || 0;
    const purchase = parseFloat(arrivalModal.purchase) || 0;
    if (!qty || !purchase) return '';
    return `Сума: ${arrIsMinus ? '−' : ''}${formatMoney(purchase * qty)}`;
  })();

  async function submitArrival() {
    if (!arrivalModal.productId) { setArrivalModal({ ...arrivalModal, error: 'Оберіть товар' }); return; }
    const qty = parseInt(arrivalModal.qty) || 0;
    setArrivalModal({ ...arrivalModal, submitting: true, error: '' });
    const res = await addArrival({
      product_id: parseInt(arrivalModal.productId),
      quantity: qty,
      operation: arrivalModal.operation,
      status: arrivalModal.status,
      payment_method: arrivalModal.method,
      expected_qty: arrivalModal.status === 'pending' ? qty : null,
      purchase_price: parseFloat(arrivalModal.purchase) || 0,
      sale_price: parseFloat(arrivalModal.sale) || 0,
      supplier: arrivalModal.supplier.trim(),
      notes: arrivalModal.notes.trim(),
    });
    if (!res.success) { setArrivalModal({ ...arrivalModal, submitting: false, error: res.error }); return; }
    toast(res.message, 'success');
    setArrivalModal(null);
    setViewModal(null);
    refreshAfterChange();
  }

  // ── Продаж ────────────────────────────────────────────────
  function openSell(preselectId) {
    const p = preselectId ? products.find((x) => x.id === preselectId) : null;
    setSellModal({
      productId: preselectId ?? '', qty: '1', price: p?.sale_price ?? '', discount: '0', method: 'cash',
      clientId: null, clientName: '', clientSearch: '', clientResults: [],
      error: '', submitting: false,
    });
  }

  function onSellProductChange(id) {
    const p = products.find((x) => x.id === Number(id));
    setSellModal((m) => ({ ...m, productId: id, price: p?.sale_price ?? '' }));
  }

  function adjQty(delta) {
    setSellModal((m) => ({ ...m, qty: String(Math.max(1, (parseInt(m.qty) || 1) + delta)) }));
  }

  useEffect(() => {
    if (!sellModal || sellModal.clientSearch.trim().length < 2) {
      if (sellModal) setSellModal((m) => ({ ...m, clientResults: [] }));
      return;
    }
    const t = setTimeout(async () => {
      const res = await searchClients(sellModal.clientSearch.trim());
      setSellModal((m) => (m ? { ...m, clientResults: res.success ? (res.results || []) : [] } : m));
    }, 300);
    return () => clearTimeout(t);
  }, [sellModal?.clientSearch]);

  const sellProductObj = products.find((x) => x.id === Number(sellModal?.productId));
  const sellCalc = (() => {
    if (!sellModal) return null;
    const qty = parseInt(sellModal.qty) || 1;
    const price = parseFloat(sellModal.price) || 0;
    const discount = parseFloat(sellModal.discount) || 0;
    const total = Math.max(0, qty * price - discount);
    const cost = parseFloat(sellProductObj?.purchase_price) || 0;
    const profit = total - cost * qty;
    return { subtotal: qty * price, total, profit, discount };
  })();

  async function submitSell() {
    if (!sellModal.productId) { setSellModal({ ...sellModal, error: 'Оберіть товар' }); return; }
    if (sellModal.method === 'deposit' && !sellModal.clientId) {
      setSellModal({ ...sellModal, error: "При оплаті депозитом клієнт обов'язковий" });
      return;
    }
    setSellModal({ ...sellModal, submitting: true, error: '' });
    const res = await sellProduct({
      product_id: parseInt(sellModal.productId),
      quantity: parseInt(sellModal.qty) || 1,
      sale_price: parseFloat(sellModal.price) || 0,
      discount: parseFloat(sellModal.discount) || 0,
      payment_method: sellModal.method,
      client_id: sellModal.clientId,
      client_name: sellModal.clientId ? sellModal.clientName : null,
    });
    if (!res.success) { setSellModal({ ...sellModal, submitting: false, error: res.error || 'Невідома помилка' }); return; }
    toast(res.message, 'success');
    setSellModal(null);
    setViewModal(null);
    refreshAfterChange();
  }

  // ── Колонки ───────────────────────────────────────────────
  const catalogColumns = [
    {
      key: 'product', label: 'Товар',
      render: (p) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <div className="product-img-sm">{p.photo_url ? <img src={p.photo_url} onError={(e) => { e.target.style.display = 'none'; }} /> : '📦'}</div>
          <strong>{p.name}</strong>
        </div>
      ),
    },
    { key: 'category', label: 'Категорія', mobile: 'secondary', render: (p) => p.category || '—' },
    { key: 'supplier', label: 'Постачальник', render: (p) => p.supplier || '—' },
    { key: 'purchase', label: 'Ціна закупки', render: (p) => `${p.purchase_price} грн` },
    { key: 'sale', label: 'Ціна продажу', mobile: 'trailing', render: (p) => <span style={{ fontWeight: 700, color: 'var(--accent)' }}>{p.sale_price} грн</span> },
    { key: 'stock', label: 'Залишок', cardTop: true, render: (p) => <span className={`stock-badge ${p.stock_status}`}>{p.stock_qty} шт.</span> },
  ];

  const archiveColumns = [
    ...catalogColumns,
    {
      key: 'actions', label: '',
      render: (p) => has('products.delete') && (
        <button className="btn btn-ghost btn-sm" style={{ color: 'var(--success)' }} onClick={(e) => { e.stopPropagation(); handleToggleActive(p); }}>✅ <span className="action-label-text">Відновити</span></button>
      ),
    },
  ];

  const salesColumns = [
    { key: 'product', label: 'Товар', render: (s) => <><div className="log-row-product">{s.product_name}</div><div className="log-row-meta">{s.category || '—'}</div></> },
    { key: 'qty', label: 'К-сть', render: (s) => s.quantity },
    { key: 'sum', label: 'Сума', mobile: 'trailing', render: (s) => <>{formatMoney(s.total_amount)}{parseFloat(s.discount) > 0 && <div style={{ fontSize: 11, color: 'var(--success)' }}>знижка {formatMoney(s.discount)}</div>}</> },
    { key: 'client', label: 'Клієнт', mobile: 'secondary', render: (s) => s.client_name || '—' },
    { key: 'method', label: 'Спосіб', cardTop: true, render: (s) => <span className="badge badge-info" style={{ fontSize: 10 }}>{PAY_LABELS[s.payment_method] || s.payment_method}</span> },
    { key: 'manager', label: 'Менеджер', render: (s) => s.admin_name || '—' },
    { key: 'date', label: 'Дата', render: (s) => formatDate(s.created_at) },
  ];

  const arrivalsColumns = [
    { key: 'product', label: 'Товар', render: (a) => <><div className="log-row-product">{a.product_name}</div><div className="log-row-meta">{a.category || '—'}</div></> },
    { key: 'op', label: 'Операція', cardTop: true, render: (a) => <span style={{ fontSize: 13, fontWeight: 500, color: ARR_OP_COLORS[a.operation] }}>{ARR_OP_LABELS[a.operation] || a.operation}</span> },
    { key: 'status', label: 'Статус', render: (a) => (a.operation === 'arrival' ? (a.status === 'paid' ? '✅ Оплачено' : a.status === 'unpaid' ? '🔴 Не оплачено' : '⏱ Замовлено') : '—') },
    {
      key: 'qty', label: 'К-сть',
      mobile: 'secondary',
      render: (a) => {
        const isMinus = ['overdue', 'repack', 'transfer'].includes(a.operation);
        if (a.status === 'pending') return <span style={{ color: 'var(--text-muted)' }}>{a.expected_qty || a.quantity} (очік.)</span>;
        return <span style={{ color: isMinus ? 'var(--danger)' : 'var(--success)' }}>{isMinus ? '−' : '+'}{a.quantity}</span>;
      },
    },
    { key: 'price', label: 'Ціна закупки', render: (a) => (a.status === 'pending' ? '—' : formatMoney(a.purchase_price)) },
    {
      key: 'sum', label: 'Сума',
      mobile: 'trailing',
      render: (a) => {
        if (a.status === 'pending') return '—';
        const isMinus = ['overdue', 'repack', 'transfer'].includes(a.operation);
        return <span style={{ color: isMinus ? 'var(--danger)' : 'inherit' }}>{isMinus ? '−' : ''}{formatMoney(Math.abs(a.total_cost))}</span>;
      },
    },
    { key: 'supplier', label: 'Постачальник', render: (a) => a.supplier || '—' },
    { key: 'manager', label: 'Менеджер', render: (a) => a.admin_name || '—' },
    { key: 'date', label: 'Дата', render: (a) => formatDate(a.created_at) },
  ];

  const activeProducts = products.filter((p) => p.is_active);
  const sellableProducts = activeProducts.filter((p) => p.stock_qty > 0);

  return (
    <AppLayout title="Товари">
      <div className="page-header prod-page-header">
        <div className="prod-header-top">
          <div className="prod-toolbar">
            <div className="search-wrap">
              <span className="search-icon">🔍</span>
              <input type="text" placeholder="Назва, штрих-код, постачальник..." value={searchInput} onChange={(e) => setSearchInput(e.target.value)} />
            </div>
            <select id="cat-filter" value={category} onChange={(e) => setCategory(e.target.value)}>
              <option value="">Всі категорії</option>
              {categories.map((c) => <option key={c} value={c}>{c}</option>)}
            </select>
          </div>
          <div className="prod-header-actions">
            {has('arrivals.create') && <button className="btn btn-ghost" {...lockedProps} onClick={guard(() => openArrival())}>+ Прихід</button>}
            {has('sales.create') && <button className="btn btn-primary" {...lockedProps} onClick={guard(() => openSell())}>🛒 Продати</button>}
            {has('products.edit') && <button className="btn btn-ghost" {...lockedProps} onClick={guard(openCreate)}>+ Товар</button>}
            {has('products.view') && <button className={`btn btn-ghost btn-low-stock ${lowStock ? 'active' : ''}`} onClick={() => setLowStock((v) => !v)}>⚠ Мало</button>}
          </div>
        </div>
      </div>

      <div className="prod-tabs">
        <button className={`prod-tab-btn ${tab === 'catalog' ? 'active' : ''}`} onClick={() => setTab('catalog')}>📦 Каталог</button>
        <button className={`prod-tab-btn ${tab === 'sales' ? 'active' : ''}`} onClick={() => setTab('sales')}>🛒 Продажі</button>
        <button className={`prod-tab-btn ${tab === 'arrivals' ? 'active' : ''}`} onClick={() => setTab('arrivals')}>🚚 Приходи</button>
        <button className={`prod-tab-btn ${tab === 'archive' ? 'active' : ''}`} onClick={() => setTab('archive')}>🗄 Архів</button>
      </div>

      {tab === 'catalog' && (
        <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
          <Table
            columns={catalogColumns}
            rows={products}
            loading={productsLoading}
            emptyMessage={has('products.edit') ? 'Товарів ще немає. Натисніть "+ Товар" щоб додати перший' : 'Товарів ще немає'}
            onRowClick={(p) => openView(p)}
            rowProps={(p) => ({ className: p.is_active ? '' : 'inactive', style: p.is_active ? undefined : { opacity: 0.55 } })}
          />
        </div>
      )}

      {tab === 'sales' && (
        <>
          <div style={{ display: 'flex', gap: 12, marginBottom: 16, flexWrap: 'wrap', alignItems: 'flex-end' }}>
            <FormGroup label="Від"><input type="date" value={salesFrom} onChange={(e) => setSalesFrom(e.target.value)} /></FormGroup>
            <FormGroup label="До"><input type="date" value={salesTo} onChange={(e) => setSalesTo(e.target.value)} /></FormGroup>
          </div>
          {salesLog.summary && (
            <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap', fontSize: 14, padding: '12px 16px', background: 'var(--bg-elevated)', borderRadius: 'var(--radius-sm)', marginBottom: 16 }}>
              <span>Продажів: <strong>{salesLog.summary.cnt || 0}</strong></span>
              <span>Виручка: <strong style={{ color: 'var(--accent)' }}>{formatMoney(salesLog.summary.revenue || 0)}</strong></span>
              <span>Прибуток: <strong style={{ color: 'var(--success)' }}>{formatMoney(salesLog.summary.profit || 0)}</strong></span>
            </div>
          )}
          <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
            <Table columns={salesColumns} rows={salesLog.rows} loading={salesLog.loading} emptyMessage="Продажів за цей період немає" />
          </div>
        </>
      )}

      {tab === 'arrivals' && (
        <>
          <div style={{ display: 'flex', gap: 12, marginBottom: 16, flexWrap: 'wrap', alignItems: 'flex-end' }}>
            <FormGroup label="Від"><input type="date" value={arrFrom} onChange={(e) => setArrFrom(e.target.value)} /></FormGroup>
            <FormGroup label="До"><input type="date" value={arrTo} onChange={(e) => setArrTo(e.target.value)} /></FormGroup>
          </div>
          <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
            <Table columns={arrivalsColumns} rows={arrivalsLog.rows} loading={arrivalsLog.loading} emptyMessage="Операцій за цей період немає" />
          </div>
        </>
      )}

      {tab === 'archive' && (
        <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
          <Table columns={archiveColumns} rows={archive.rows} loading={archive.loading} emptyMessage="Архів порожній" />
        </div>
      )}

      {/* Перегляд товару */}
      <Modal size="lg" open={!!viewModal} onClose={() => setViewModal(null)}>
        {viewModal && (() => {
          const p = viewModal.product;
          const hasMargin = parseFloat(p.purchase_price) > 0 && parseFloat(p.sale_price) > 0;
          const marginPct = hasMargin ? Math.round(((p.sale_price - p.purchase_price) / p.sale_price) * 100) : null;
          const marginSum = hasMargin ? p.sale_price - p.purchase_price : null;
          const stockLow = p.stock_qty > 0 && p.stock_qty <= p.stock_min;
          const stockLabel = p.stock_qty <= 0 ? 'Немає в наявності' : stockLow ? 'Низький залишок' : 'В наявності';

          const fields = [
            ['Ціна закупки', formatMoney(p.purchase_price)],
            ['Постачальник', p.supplier || '—'],
            ['Штрих-код', p.barcode || '—'],
            ['Мін. залишок', `${p.stock_min} шт.`],
            ['Продано всього', p.total_sold > 0 ? `${p.total_sold} шт.` : '—'],
            ['Маржа', hasMargin ? `${marginPct}% · ${formatMoney(marginSum)}` : '—'],
          ];

          return (
            <>
              <div className="product-detail-header">
                <div className="product-detail-photo">
                  {p.photo_url ? <img src={p.photo_url} alt={p.name} onError={(e) => { e.target.style.display = 'none'; }} /> : <span>📦</span>}
                </div>
                <div className="product-detail-heading">
                  <div className="product-detail-name">{p.name}</div>
                  <div className="product-detail-meta">
                    {p.category && <span className="tariff-badge">{p.category}</span>}
                    <Badge variant={p.is_active ? 'active' : 'inactive'}>{p.is_active ? 'Активний' : 'В архіві'}</Badge>
                  </div>
                  <div className="product-detail-price">{formatMoney(p.sale_price)}</div>
                </div>
              </div>

              <div className={`product-stock-strip ${p.stock_status}`}>
                <span className="product-stock-qty">{p.stock_qty} шт.</span>
                <span className="product-stock-label">{stockLow && '⚠ '}{stockLabel}</span>
              </div>

              <DetailFieldsGrid fields={fields} />

              <div className="client-actions-grid" style={{ marginTop: 16 }}>
                {has('sales.create') && (
                  <button className="btn btn-primary" disabled={p.stock_qty <= 0} title={p.stock_qty <= 0 ? 'Залишок відсутній' : ''} onClick={() => { setViewModal(null); openSell(p.id); }}>🛒 Продати</button>
                )}
                {has('arrivals.create') && <button className="btn btn-ghost" onClick={() => { setViewModal(null); openArrival(p.id); }}>🚚 Прихід</button>}
                {has('products.edit') && <button className="btn btn-ghost" onClick={() => { setViewModal(null); openEdit(p); }}>✎ Редагувати</button>}
                {has('products.delete') && (
                  p.is_active
                    ? <button className="btn btn-danger" disabled={!canArchive(p)} title={canArchive(p) ? 'Перенести в архів' : `Залишок ${p.stock_qty} шт. — спочатку обнуліть`} onClick={() => handleToggleActive(p)}>🚫 В архів{canArchive(p) ? '' : ` (${p.stock_qty})`}</button>
                    : <button className="btn btn-success" onClick={() => handleToggleActive(p)}>✅ Відновити</button>
                )}
              </div>
            </>
          );
        })()}
      </Modal>

      {/* Новий / редагування товару */}
      <Modal
        open={!!formModal}
        onClose={() => setFormModal(null)}
        title={formModal?.id ? 'Редагування товару' : 'Новий товар'}
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={formModal?.submitting} onClick={submitForm}>{formModal?.submitting ? 'Збереження...' : 'Зберегти'}</button>
            <button className="btn btn-ghost" onClick={() => setFormModal(null)}>Скасувати</button>
          </div>
        }
      >
        {formModal && (
          <>
            {formModal.error && <div className="alert alert-error">{formModal.error}</div>}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Назва *" fullWidth>
                <input type="text" maxLength={180} placeholder="Протеїн Power Pro 1кг" value={formModal.name} onChange={(e) => setFormModal({ ...formModal, name: e.target.value })} />
              </FormGroup>
              <FormGroup label="Категорія">
                <IconField
                  icon="tag" type="text" listId="cat-list" listOptions={categories}
                  placeholder="Протеїн, Вода..." value={formModal.category}
                  onChange={(e) => setFormModal({ ...formModal, category: e.target.value })}
                />
              </FormGroup>
              <FormGroup label="Постачальник">
                <IconField
                  icon="building" type="text" listId="supplier-list" listOptions={suppliers}
                  placeholder="Power Pro" value={formModal.supplier}
                  onChange={(e) => setFormModal({ ...formModal, supplier: e.target.value })}
                />
              </FormGroup>
              <FormGroup label="Ціна закупки (грн)">
                <input type="number" min="0" step="0.01" placeholder="0" value={formModal.purchase} onChange={(e) => setFormModal({ ...formModal, purchase: e.target.value })} />
              </FormGroup>
              <FormGroup label="Ціна продажу (грн)">
                <input type="number" min="0" step="0.01" placeholder="0" value={formModal.sale} onChange={(e) => setFormModal({ ...formModal, sale: e.target.value })} />
              </FormGroup>
              <FormGroup label="Штрих-код">
                <input type="text" placeholder="4820..." value={formModal.barcode} onChange={(e) => setFormModal({ ...formModal, barcode: e.target.value })} />
              </FormGroup>
              <FormGroup label="Мін. залишок (сповіщення)">
                <input type="number" min="0" placeholder="5" value={formModal.stockMin} onChange={(e) => setFormModal({ ...formModal, stockMin: e.target.value })} />
              </FormGroup>
              <FormGroup label="Фото (URL)" fullWidth>
                <div className="photo-url-row">
                  <div className="input-icon-wrap" style={{ flex: 1 }}>
                    <Icon name="link" size={16} className="input-icon" />
                    <input type="url" placeholder="https://example.com/image.jpg" value={formModal.photo} onChange={(e) => setFormModal({ ...formModal, photo: e.target.value })} />
                  </div>
                  <div className="photo-url-preview">
                    {formModal.photo
                      ? <img src={formModal.photo} onError={(e) => { e.target.style.display = 'none'; }} />
                      : <Icon name="image" size={20} />}
                  </div>
                </div>
              </FormGroup>
            </div>
            <div style={{ fontSize: 12, color: margin.includes('-') ? 'var(--danger)' : 'var(--success)', margin: '-8px 0 12px' }}>{margin}</div>
          </>
        )}
      </Modal>

      {/* Прихід товару */}
      <Modal
        open={!!arrivalModal}
        onClose={() => setArrivalModal(null)}
        title={arrivalModal ? { arrival: 'Прихід товару', overdue: 'Прострочка', repack: 'Розфасування', transfer: 'Перенесення склад' }[arrivalModal.operation] : ''}
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={arrivalModal?.submitting} onClick={submitArrival}>{arrivalModal?.submitting ? 'Збереження...' : 'Підтвердити'}</button>
            <button className="btn btn-ghost" onClick={() => setArrivalModal(null)}>Скасувати</button>
          </div>
        }
      >
        {arrivalModal && (
          <>
            {arrivalModal.error && <div className="alert alert-error">{arrivalModal.error}</div>}
            <FormGroup label="Товар *">
              <select value={arrivalModal.productId} onChange={(e) => onArrivalProductChange(e.target.value)}>
                <option value="">— Оберіть товар —</option>
                {activeProducts.map((p) => <option key={p.id} value={p.id}>{p.name} ({p.stock_qty} шт.)</option>)}
              </select>
            </FormGroup>
            <FormGroup label="Операція *">
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                {ARR_OPS.map(([op, label]) => (
                  <button key={op} type="button" className={`arr-op-btn ${arrivalModal.operation === op ? 'active' : ''}`} onClick={() => selectArrOp(op)}>{label}</button>
                ))}
              </div>
            </FormGroup>
            {arrivalModal.operation === 'arrival' && (
              <FormGroup label="Статус *">
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                  {ARR_STATUSES.map(([st, label]) => (
                    <button key={st} type="button" data-status={st} className={`arr-status-btn ${arrivalModal.status === st ? 'active' : ''}`} onClick={() => setArrivalModal({ ...arrivalModal, status: st })}>{label}</button>
                  ))}
                </div>
              </FormGroup>
            )}
            {arrivalModal.operation === 'arrival' && arrivalModal.status === 'paid' && (
              <FormGroup label="Спосіб оплати *">
                <select value={arrivalModal.method} onChange={(e) => setArrivalModal({ ...arrivalModal, method: e.target.value })}>
                  <option value="cash">Готівка</option>
                  <option value="card">Карта</option>
                  <option value="terminal">Термінал</option>
                  <option value="transfer">Переказ</option>
                </select>
              </FormGroup>
            )}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label={arrIsPending ? 'Очікувана к-сть *' : 'Кількість *'}>
                <input type="number" min="1" step="1" value={arrivalModal.qty} onChange={(e) => setArrivalModal({ ...arrivalModal, qty: e.target.value })} />
              </FormGroup>
              <FormGroup label="Постачальник">
                <input type="text" value={arrivalModal.supplier} onChange={(e) => setArrivalModal({ ...arrivalModal, supplier: e.target.value })} />
              </FormGroup>
              <FormGroup label="Ціна закупки (грн)">
                <input type="number" min="0" step="0.01" placeholder="0" value={arrivalModal.purchase} onChange={(e) => setArrivalModal({ ...arrivalModal, purchase: e.target.value })} />
              </FormGroup>
              {arrivalModal.operation === 'arrival' && !arrIsPending && (
                <FormGroup label="Ціна продажу (грн)">
                  <input type="number" min="0" step="0.01" placeholder="0" value={arrivalModal.sale} onChange={(e) => setArrivalModal({ ...arrivalModal, sale: e.target.value })} />
                </FormGroup>
              )}
            </div>
            <FormGroup label="Примітка">
              <input type="text" placeholder="Необов'язково" value={arrivalModal.notes} onChange={(e) => setArrivalModal({ ...arrivalModal, notes: e.target.value })} />
            </FormGroup>
            <div style={{ fontSize: 13, color: 'var(--text-secondary)', marginBottom: 16 }}>{arrTotal}</div>
          </>
        )}
      </Modal>

      {/* Продаж товару */}
      <Modal
        open={!!sellModal}
        onClose={() => setSellModal(null)}
        title="Продаж товару"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={sellModal?.submitting} onClick={submitSell}>{sellModal?.submitting ? 'Продаємо...' : 'Продати'}</button>
            <button className="btn btn-ghost" onClick={() => setSellModal(null)}>Скасувати</button>
          </div>
        }
      >
        {sellModal && (
          <>
            {sellModal.error && <div className="alert alert-error">{sellModal.error}</div>}
            <FormGroup label="Товар *">
              <select value={sellModal.productId} onChange={(e) => onSellProductChange(e.target.value)}>
                <option value="">— Оберіть товар —</option>
                {sellableProducts.map((p) => <option key={p.id} value={p.id}>{p.name} ({p.stock_qty} шт.)</option>)}
              </select>
            </FormGroup>
            {sellProductObj && <div style={{ fontSize: 12, color: 'var(--text-muted)', margin: '-8px 0 12px' }}>Залишок: {sellProductObj.stock_qty} шт.</div>}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Кількість">
                <div className="qty-control">
                  <button type="button" className="qty-btn" onClick={() => adjQty(-1)}>−</button>
                  <input type="number" className="qty-input" min="1" value={sellModal.qty} onChange={(e) => setSellModal({ ...sellModal, qty: e.target.value })} />
                  <button type="button" className="qty-btn" onClick={() => adjQty(1)}>+</button>
                </div>
              </FormGroup>
              <FormGroup label="Ціна продажу">
                <input type="number" min="0" step="0.01" value={sellModal.price} onChange={(e) => setSellModal({ ...sellModal, price: e.target.value })} />
              </FormGroup>
              <FormGroup label="Знижка (грн)">
                <input type="number" min="0" step="1" value={sellModal.discount} onChange={(e) => setSellModal({ ...sellModal, discount: e.target.value })} />
              </FormGroup>
              <FormGroup label="Спосіб оплати">
                <select value={sellModal.method} onChange={(e) => setSellModal({ ...sellModal, method: e.target.value })}>
                  <option value="cash">Готівка</option>
                  <option value="card">Карта</option>
                  <option value="terminal">Термінал</option>
                  <option value="deposit">З депозиту</option>
                </select>
              </FormGroup>
            </div>
            <FormGroup label={<>Клієнт {sellModal.method === 'deposit' && <span style={{ color: 'var(--danger)' }}>*</span>} <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>(обов'язково при оплаті депозитом)</span></>}>
              <div style={{ position: 'relative' }}>
                <input type="text" placeholder="Введіть ім'я або телефон..." value={sellModal.clientSearch} onChange={(e) => setSellModal({ ...sellModal, clientSearch: e.target.value })} autoComplete="off" />
                {sellModal.clientResults.length > 0 && (
                  <div style={{ position: 'absolute', top: '100%', left: 0, right: 0, background: 'var(--bg-elevated)', border: '1px solid var(--border-light)', borderRadius: 'var(--radius-sm)', zIndex: 100, maxHeight: 180, overflowY: 'auto', boxShadow: 'var(--shadow-md)', marginTop: 4 }}>
                    {sellModal.clientResults.map((c) => (
                      <div key={c.id} style={{ padding: '9px 12px', cursor: 'pointer', fontSize: 14, borderBottom: '1px solid var(--border)' }}
                        onClick={() => setSellModal({ ...sellModal, clientId: c.id, clientName: c.full_name, clientSearch: '', clientResults: [] })}>
                        <strong>{c.full_name}</strong> <span style={{ color: 'var(--text-muted)', fontSize: 12, marginLeft: 8 }}>{c.phone || ''}</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
              {sellModal.clientId && (
                <div style={{ marginTop: 6, fontSize: 13, padding: '6px 10px', background: 'var(--accent-dim)', borderRadius: 'var(--radius-sm)' }}>
                  {sellModal.clientName}
                  <button onClick={() => setSellModal({ ...sellModal, clientId: null, clientName: '' })} style={{ float: 'right', background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)' }}>✕</button>
                </div>
              )}
            </FormGroup>
            {sellCalc && parseFloat(sellModal.price) > 0 && (
              <div className="sell-summary">
                <div className="sell-summary-row"><span>Ціна × к-сть</span><span>{formatMoney(sellCalc.subtotal)}</span></div>
                {sellCalc.discount > 0 && <div className="sell-summary-row"><span>Знижка</span><span style={{ color: 'var(--success)' }}>−{formatMoney(sellCalc.discount)}</span></div>}
                <div className="sell-summary-row total"><span>Разом</span><span>{formatMoney(sellCalc.total)}</span></div>
                <div className="sell-summary-row" style={{ color: 'var(--text-secondary)' }}><span>Прибуток</span><span style={{ color: 'var(--success)' }}>{formatMoney(sellCalc.profit)}</span></div>
              </div>
            )}
          </>
        )}
      </Modal>
    </AppLayout>
  );
}
