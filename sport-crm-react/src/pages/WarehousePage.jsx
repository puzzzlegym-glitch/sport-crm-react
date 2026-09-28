import { useEffect, useMemo, useState } from 'react';
import AppLayout from '../components/layout/AppLayout';
import Table from '../components/ui/Table';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import Icon from '../components/ui/Icon';
import { useToast } from '../components/ui/ToastProvider';
import { usePermissions } from '../hooks/usePermissions';
import { useShiftLock } from '../hooks/useShiftLock';
import { getProducts } from '../api/products';
import { getAllArrivalsForClub, reconcileStock } from '../api/warehouse';
import { sellProduct } from '../api/sell';
import { formatMoney } from '../utils/format';
import './WarehousePage.css';

export default function WarehousePage() {
  const toast = useToast();
  const { has } = usePermissions();
  const { guard, lockedProps } = useShiftLock();

  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [category, setCategory] = useState('');
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [sellModal, setSellModal] = useState(null);

  async function reload() {
    setLoading(true);
    const [prodRes, arrRes] = await Promise.all([getProducts({}), getAllArrivalsForClub()]);
    if (!prodRes.success) { toast(prodRes.error, 'error'); setLoading(false); return; }
    if (!arrRes.success) { toast(arrRes.error, 'error'); setLoading(false); return; }
    setRows(reconcileStock(prodRes.products || [], arrRes.arrivals));
    setLoading(false);
  }

  useEffect(() => { reload(); }, []);

  useEffect(() => {
    const t = setTimeout(() => setSearch(searchInput.trim().toLowerCase()), 350);
    return () => clearTimeout(t);
  }, [searchInput]);

  const inStockRows = useMemo(() => rows.filter((p) => p.stock_qty !== 0), [rows]);

  const categories = useMemo(
    () => [...new Set(inStockRows.map((p) => p.category).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'uk')),
    [inStockRows],
  );

  const filtered = useMemo(() => {
    return inStockRows.filter((p) => {
      if (category && p.category !== category) return false;
      if (!search) return true;
      return p.name.toLowerCase().includes(search) || (p.category || '').toLowerCase().includes(search);
    });
  }, [inStockRows, search, category]);

  const stockValue = rows.reduce((sum, p) => sum + p.stock_qty * (parseFloat(p.purchase_price) || 0), 0);

  // ── Швидкий продаж ────────────────────────────────────────
  function openSell(p) {
    setSellModal({ product: p, qty: '1', method: 'cash', error: '', submitting: false });
  }

  function adjQty(delta) {
    setSellModal((m) => ({ ...m, qty: String(Math.max(1, (parseInt(m.qty) || 1) + delta)) }));
  }

  async function submitSell() {
    const qty = parseInt(sellModal.qty) || 1;
    if (qty > sellModal.product.stock_qty) {
      setSellModal({ ...sellModal, error: `На складі лише ${sellModal.product.stock_qty} шт.` });
      return;
    }
    setSellModal({ ...sellModal, submitting: true, error: '' });
    const res = await sellProduct({
      product_id: sellModal.product.id,
      quantity: qty,
      sale_price: parseFloat(sellModal.product.sale_price) || 0,
      discount: 0,
      payment_method: sellModal.method,
      client_id: null,
      client_name: null,
    });
    if (!res.success) { setSellModal({ ...sellModal, submitting: false, error: res.error || 'Невідома помилка' }); return; }
    toast(res.message, 'success');
    setSellModal(null);
    reload();
  }

  const columns = [
    {
      key: 'product', label: 'Товар',
      render: (p) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <div className="product-img-sm">
            {p.photo_url ? <img src={p.photo_url} onError={(e) => { e.target.style.display = 'none'; }} /> : <Icon name="package" size={18} />}
          </div>
          <div>
            <strong>{p.name}</strong>
            <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{p.category || '—'}</div>
          </div>
        </div>
      ),
    },
    { key: 'stock', label: 'Залишок', cardTop: true, render: (p) => <span className={`stock-badge ${p.stock_status}`}>{p.stock_qty} шт.</span> },
    { key: 'sale', label: 'Ціна продажу', mobile: 'secondary', render: (p) => formatMoney(p.sale_price) },
    {
      key: 'pending', label: 'Очікується',
      mobile: 'trailing',
      render: (p) => (p.pending_qty > 0
        ? <span className="wh-pending"><Icon name="clock" size={13} /> {p.pending_qty} шт.</span>
        : '—'),
    },
    { key: 'value', label: 'Вартість залишку', render: (p) => formatMoney(p.stock_qty * (parseFloat(p.purchase_price) || 0)) },
    {
      key: 'actions', label: '',
      render: (p) => has('sales.create') && (
        <button
          type="button"
          className="btn btn-ghost btn-sm wh-sell-btn"
          {...lockedProps}
          disabled={p.stock_qty <= 0}
          title={p.stock_qty <= 0 ? 'Залишок відсутній' : 'Швидкий продаж'}
          onClick={(e) => { e.stopPropagation(); guard(() => openSell(p))(e); }}
        >
          <Icon name="cart" size={14} /> <span className="action-label-text">Продати</span>
        </button>
      ),
    },
  ];

  return (
    <AppLayout title="Склад">
      <div className="wh-toolbar">
        <div className="wh-summary">
          <div className="wh-stat">
            <span className="wh-stat-icon"><Icon name="package" size={18} /></span>
            <div><div className="wh-stat-val">{inStockRows.length}</div><div className="wh-stat-lbl">Товарів</div></div>
          </div>
          <div className="wh-stat">
            <span className="wh-stat-icon"><Icon name="wallet" size={18} /></span>
            <div><div className="wh-stat-val">{formatMoney(stockValue)}</div><div className="wh-stat-lbl">Вартість складу</div></div>
          </div>
        </div>

        <div className="wh-filters">
          <div className="search-wrap wh-search">
            <span className="search-icon"><Icon name="search" size={14} /></span>
            <input type="text" placeholder="Назва або категорія..." value={searchInput} onChange={(e) => setSearchInput(e.target.value)} />
          </div>
          <select className="wh-cat-filter" value={category} onChange={(e) => setCategory(e.target.value)}>
            <option value="">Всі категорії</option>
            {categories.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        </div>
      </div>

      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <Table
          columns={columns}
          rows={filtered}
          loading={loading}
          emptyMessage="Товарів ще немає"
        />
      </div>

      <Modal
        open={!!sellModal}
        onClose={() => setSellModal(null)}
        title="Швидкий продаж"
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
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 16 }}>
              <div className="product-img-sm">
                {sellModal.product.photo_url ? <img src={sellModal.product.photo_url} onError={(e) => { e.target.style.display = 'none'; }} /> : <Icon name="package" size={18} />}
              </div>
              <div>
                <strong>{sellModal.product.name}</strong>
                <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>Залишок: {sellModal.product.stock_qty} шт. · {formatMoney(sellModal.product.sale_price)}</div>
              </div>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Кількість">
                <div className="qty-control">
                  <button type="button" className="qty-btn" onClick={() => adjQty(-1)}>−</button>
                  <input type="number" className="qty-input" min="1" value={sellModal.qty} onChange={(e) => setSellModal({ ...sellModal, qty: e.target.value })} />
                  <button type="button" className="qty-btn" onClick={() => adjQty(1)}>+</button>
                </div>
              </FormGroup>
              <FormGroup label="Спосіб оплати">
                <select value={sellModal.method} onChange={(e) => setSellModal({ ...sellModal, method: e.target.value })}>
                  <option value="cash">Готівка</option>
                  <option value="card">Карта</option>
                  <option value="terminal">Термінал</option>
                </select>
              </FormGroup>
            </div>
            <div style={{ fontSize: 13, color: 'var(--text-secondary)' }}>
              Разом: <strong>{formatMoney((parseInt(sellModal.qty) || 1) * (parseFloat(sellModal.product.sale_price) || 0))}</strong>
            </div>
          </>
        )}
      </Modal>
    </AppLayout>
  );
}
