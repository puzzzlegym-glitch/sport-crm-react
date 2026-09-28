import { useEffect, useState } from 'react';
import AppLayout from '../components/layout/AppLayout';
import Table from '../components/ui/Table';
import CardMenu from '../components/ui/CardMenu';
import Pagination from '../components/ui/Pagination';
import Modal from '../components/ui/Modal';
import DetailFieldsGrid from '../components/ui/DetailFieldsGrid';
import FormGroup from '../components/ui/FormGroup';
import { usePermissions } from '../hooks/usePermissions';
import { useShiftLock } from '../hooks/useShiftLock';
import { useToast } from '../components/ui/ToastProvider';
import { getArrivalsList, confirmArrival, updateArrivalEntry, deleteArrivalEntry } from '../api/arrivals';
import { getProducts, addArrival } from '../api/products';
import { formatMoney, formatDate, localMonthStart, localToday, localDate } from '../utils/format';
import './ArrivalsPage.css';

const ARR_OPS = [
  ['arrival', '📥 Прихід'], ['overdue', '🔴 Прострочка'], ['repack', '🟡 Розфасування'], ['transfer', '⬛ Перенесення'],
];
const OP_LABELS = { arrival: 'Прихід', overdue: 'Прострочка', repack: 'Розфасування', transfer: 'Перенесення' };
const OP_FILTERS = [
  ['', 'Всі'], ['arrival', '📥 Прихід'], ['overdue', '🔴 Прострочка'], ['repack', '🟡 Розфасування'], ['transfer', '⬛ Перенесення'],
];
const STATUS_LABELS = { paid: '✅ Оплачено', unpaid: '🔴 Не оплачено', pending: '⏳ Замовлено' };
const PAY_LABELS = { cash: 'Готівка', card: 'Карта', terminal: 'Термінал', transfer: 'Переказ' };

function yesterdayRange() {
  const d = new Date();
  d.setDate(d.getDate() - 1);
  const s = localDate(d);
  return { date_from: s, date_to: s };
}
function weekRange() {
  const now = new Date();
  const day = now.getDay() || 7;
  const monday = new Date(now);
  monday.setDate(now.getDate() - day + 1);
  return { date_from: localDate(monday), date_to: localToday() };
}
function prevMonthRange() {
  const now = new Date();
  const first = new Date(now.getFullYear(), now.getMonth() - 1, 1);
  const last = new Date(now.getFullYear(), now.getMonth(), 0);
  return { date_from: localDate(first), date_to: localDate(last) };
}
function quarterRange(q) {
  const year = new Date().getFullYear();
  const startMonth = (q - 1) * 3;
  const first = new Date(year, startMonth, 1);
  const last = new Date(year, startMonth + 3, 0);
  return { date_from: localDate(first), date_to: localDate(last) };
}
function yearRange() {
  const now = new Date();
  return { date_from: `${now.getFullYear()}-01-01`, date_to: localToday() };
}
function prevYearRange() {
  const y = new Date().getFullYear() - 1;
  return { date_from: `${y}-01-01`, date_to: `${y}-12-31` };
}

const PERIODS = [
  ['all', 'Всі', () => ({ date_from: '', date_to: '' })],
  ['today', 'Сьогодні', () => ({ date_from: localToday(), date_to: localToday() })],
  ['yesterday', 'Вчора', yesterdayRange],
  ['week', 'Тиждень', weekRange],
  ['month', 'Поточний місяць', () => ({ date_from: localMonthStart(), date_to: localToday() })],
  ['prev_month', 'Минулий місяць', prevMonthRange],
  ['q1', '1 квартал', () => quarterRange(1)],
  ['q2', '2 квартал', () => quarterRange(2)],
  ['q3', '3 квартал', () => quarterRange(3)],
  ['q4', '4 квартал', () => quarterRange(4)],
  ['year', 'Поточний рік', yearRange],
  ['prev_year', 'Минулий рік', prevYearRange],
];

export default function ArrivalsPage() {
  const { has } = usePermissions();
  const canWrite = has('arrivals.edit');
  const { guard, lockedProps } = useShiftLock();
  const toast = useToast();

  const [period, setPeriod] = useState('all');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [opFilter, setOpFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [page, setPage] = useState(1);

  const [log, setLog] = useState({ rows: [], pagination: { total: 0, page: 1, pages: 1, per_page: 30 }, loading: true });

  const [arrivalModal, setArrivalModal] = useState(null);
  const [confirmModal, setConfirmModal] = useState(null);
  const [editModal, setEditModal] = useState(null);
  const [viewModal, setViewModal] = useState(null);

  async function reload() {
    setLog((l) => ({ ...l, loading: true }));
    const res = await getArrivalsList({
      date_from: dateFrom, date_to: dateTo, operation: opFilter, status: statusFilter, search, page,
    });
    if (!res.success) { toast(res.error, 'error'); setLog((l) => ({ ...l, loading: false })); return; }
    setLog({ rows: res.arrivals, pagination: res.pagination, loading: false });
  }

  useEffect(() => {
    const t = setTimeout(() => { setSearch(searchInput.trim()); setPage(1); }, 350);
    return () => clearTimeout(t);
  }, [searchInput]);

  useEffect(() => { reload(); }, [dateFrom, dateTo, search, opFilter, statusFilter, page]);

  function selectPeriod(key) {
    setPeriod(key);
    const entry = PERIODS.find(([k]) => k === key);
    if (!entry) return;
    const { date_from, date_to } = entry[2]();
    setDateFrom(date_from);
    setDateTo(date_to);
    setPage(1);
  }

  function selectOpFilter(op) {
    setOpFilter(op);
    setStatusFilter('');
    setPage(1);
  }

  function selectStatusFilter(st) {
    setStatusFilter(st);
    setOpFilter(st === 'pending' || st === 'unpaid' ? 'arrival' : '');
    setPage(1);
  }

  // ── Новий прихід (той самий product_arrivals endpoint, що й ProductsPage) ──
  async function openArrivalModal() {
    const res = await getProducts({});
    setArrivalModal({
      products: res.success ? (res.products || []) : [],
      productId: '', operation: 'arrival', status: 'paid', method: 'cash', qty: '1',
      purchase: '', sale: '', supplier: '', notes: '', error: '', submitting: false,
    });
  }

  function onArrProductChange(id) {
    setArrivalModal((m) => {
      const p = m.products.find((x) => x.id === Number(id));
      return { ...m, productId: id, purchase: p?.purchase_price ?? '', sale: p?.sale_price ?? '', supplier: p?.supplier ?? '' };
    });
  }

  function selectArrOp(op) {
    setArrivalModal((m) => ({ ...m, operation: op, status: op === 'arrival' ? m.status : 'paid' }));
  }

  const arrIsMinus = ['overdue', 'repack', 'transfer'].includes(arrivalModal?.operation);
  const arrIsPending = arrivalModal?.status === 'pending' && arrivalModal?.operation === 'arrival';
  const arrTotal = (() => {
    if (!arrivalModal) return '';
    if (arrIsPending) return '';
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
    toast(res.message || 'Збережено', 'success');
    setArrivalModal(null);
    reload();
  }

  // ── Підтвердження pending ────────────────────────────────
  function openConfirm(a) {
    const expected = a.expected_qty || a.quantity;
    setConfirmModal({ id: a.id, name: a.product_name, expected, qty: String(expected), status: 'paid', method: a.payment_method || 'cash', error: '', submitting: false });
  }

  async function submitConfirm() {
    setConfirmModal({ ...confirmModal, submitting: true, error: '' });
    const res = await confirmArrival({ id: confirmModal.id, quantity: parseInt(confirmModal.qty) || 0, status: confirmModal.status, payment_method: confirmModal.method });
    if (!res.success) { setConfirmModal({ ...confirmModal, submitting: false, error: res.error }); return; }
    toast(res.message, 'success');
    setConfirmModal(null);
    reload();
  }

  // ── Редагування запису ───────────────────────────────────
  function openEdit(a) {
    setEditModal({
      id: a.id, name: a.product_name, supplier: a.supplier || '',
      qty: String(a.quantity), price: String(a.purchase_price),
      operation: a.operation, status: a.status, method: a.payment_method || 'cash', notes: a.notes || '',
      error: '', submitting: false,
    });
  }

  const editTotal = (() => {
    if (!editModal) return 0;
    return (parseFloat(editModal.qty) || 0) * (parseFloat(editModal.price) || 0);
  })();

  async function submitEdit() {
    const qty = parseInt(editModal.qty) || 0;
    if (qty < 1) { setEditModal({ ...editModal, error: 'Кількість має бути > 0' }); return; }
    setEditModal({ ...editModal, submitting: true, error: '' });
    const res = await updateArrivalEntry({
      id: editModal.id,
      quantity: qty,
      purchase_price: parseFloat(editModal.price) || 0,
      total_cost: editTotal,
      operation: editModal.operation,
      status: editModal.operation === 'arrival' ? editModal.status : 'paid',
      payment_method: editModal.method,
      notes: editModal.notes.trim(),
    });
    if (!res.success) { setEditModal({ ...editModal, submitting: false, error: res.error }); return; }
    toast(res.message, 'success');
    setEditModal(null);
    reload();
  }

  // ── Видалення ─────────────────────────────────────────────
  async function handleDelete(a) {
    if (!confirm(`Видалити запис "${a.product_name}"?\nСклад буде перераховано автоматично.`)) return;
    const res = await deleteArrivalEntry(a.id);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message, 'success');
    reload();
  }

  // ── Колонки ───────────────────────────────────────────────
  const columns = [
    {
      key: 'product', label: 'Товар',
      render: (a) => <><div className="log-row-product">{a.product_name}</div><div className="log-row-meta">{a.category || '—'}</div></>,
    },
    { key: 'op', label: 'Операція', cardTop: true, render: (a) => <span className={`op-badge ${a.operation}`}>{OP_LABELS[a.operation] || a.operation}</span> },
    { key: 'status', label: 'Статус', render: (a) => (a.operation === 'arrival' ? <span className={`status-badge ${a.status}`}>{STATUS_LABELS[a.status]}</span> : '—') },
    {
      key: 'qty', label: 'К-сть',
      mobile: 'secondary',
      render: (a) => {
        const isMinus = ['overdue', 'repack', 'transfer'].includes(a.operation);
        if (a.status === 'pending') return <span className="qty-wait">⏳ {a.expected_qty || a.quantity} (очік.)</span>;
        return <span className={isMinus ? 'qty-minus' : 'qty-plus'}>{isMinus ? '−' : '+'}{a.quantity}</span>;
      },
    },
    { key: 'price', label: 'Ціна закупки', render: (a) => (a.purchase_price > 0 ? formatMoney(a.purchase_price) : '—') },
    {
      key: 'sum', label: 'Сума',
      mobile: 'trailing',
      render: (a) => {
        const isMinus = ['overdue', 'repack', 'transfer'].includes(a.operation);
        if (a.status === 'pending') return a.total_cost > 0 ? <span style={{ color: 'var(--warning)' }}>{formatMoney(Math.abs(a.total_cost))}</span> : <span style={{ color: 'var(--text-muted)' }}>—</span>;
        return <span className={isMinus ? 'qty-minus' : ''}>{isMinus ? '−' : ''}{formatMoney(Math.abs(a.total_cost))}</span>;
      },
    },
    { key: 'supplier', label: 'Постачальник', render: (a) => a.supplier || '—' },
    { key: 'stock', label: 'Поточний залишок', render: (a) => (a.current_stock !== null ? `${a.current_stock} шт.` : '—') },
    { key: 'manager', label: 'Менеджер', render: (a) => a.admin_name || '—' },
    { key: 'date', label: 'Дата', render: (a) => formatDate(a.created_at) },
    {
      key: 'actions', label: '',
      render: (a) => (
        <div onClick={(e) => e.stopPropagation()}>
          {/* Desktop — окремі кнопки, як і було */}
          <div className="desktop-only-actions" style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
            {a.status === 'pending' && canWrite && <button className="confirm-btn" onClick={() => openConfirm(a)}>✓ Підтвердити</button>}
            {canWrite && <button className="confirm-btn" onClick={() => openEdit(a)}>✏️</button>}
            {canWrite && <button className="confirm-btn danger" onClick={() => handleDelete(a)}>🗑</button>}
          </div>
          {/* Mobile — картка вже тапабельна (перегляд), тож лишається меню "⋮" з рештою дій */}
          <div className="mobile-only-actions">
            <CardMenu actions={[
              a.status === 'pending' && canWrite && { label: 'Підтвердити', icon: 'check', onClick: () => openConfirm(a) },
              canWrite && { label: 'Редагувати', icon: 'edit', onClick: () => openEdit(a) },
              canWrite && { label: 'Видалити', icon: 'trash', danger: true, onClick: () => handleDelete(a) },
            ]} />
          </div>
        </div>
      ),
    },
  ];

  return (
    <AppLayout title="Журнал приходів">
      <div className="arr-toolbar">
        <div className="arr-search-col">
          <FormGroup label="Пошук">
            <input type="text" placeholder="Назва товару..." value={searchInput} onChange={(e) => setSearchInput(e.target.value)} />
          </FormGroup>
        </div>

        {canWrite && (
          <button className="btn btn-primary arr-add-btn" {...lockedProps} onClick={guard(openArrivalModal)}>+ Прихід</button>
        )}

        <div className="arr-period-col">
          <FormGroup label="Період">
            <select value={period} onChange={(e) => selectPeriod(e.target.value)}>
              {PERIODS.map(([key, label]) => <option key={key} value={key}>{label}</option>)}
            </select>
          </FormGroup>
        </div>

        <div className="arr-filters-col">
          {OP_FILTERS.map(([op, label]) => (
            <button key={op || 'all'} className={`op-filter-btn ${!statusFilter && opFilter === op ? 'active' : ''}`} onClick={() => selectOpFilter(op)}>{label}</button>
          ))}
          <button className={`op-filter-btn ${statusFilter === 'pending' ? 'active' : ''}`} style={{ borderStyle: 'dashed' }} onClick={() => selectStatusFilter('pending')}>⏳ Очікується</button>
          <button className={`op-filter-btn ${statusFilter === 'unpaid' ? 'active' : ''}`} style={{ borderStyle: 'dashed' }} onClick={() => selectStatusFilter('unpaid')}>🔴 Не оплачено</button>
        </div>
      </div>

      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <Table columns={columns} rows={log.rows} loading={log.loading} emptyMessage="Записів за цей період немає" onRowClick={(a) => setViewModal(a)} />
      </div>
      <div style={{ padding: '12px 4px' }}>
        <Pagination page={log.pagination.page} pages={log.pagination.pages} total={log.pagination.total} perPage={log.pagination.per_page} onChange={setPage} />
      </div>

      {/* Новий прихід */}
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
              <select value={arrivalModal.productId} onChange={(e) => onArrProductChange(e.target.value)}>
                <option value="">— Оберіть товар —</option>
                {arrivalModal.products.map((p) => <option key={p.id} value={p.id}>{p.name} ({p.stock_qty} шт.)</option>)}
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
                  {[['paid', '✅ Оплачено'], ['unpaid', '🔴 Не оплачено'], ['pending', '⏳ Замовлено']].map(([st, label]) => (
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
              {!arrIsPending && (
                <FormGroup label="Ціна закупки (грн)">
                  <input type="number" min="0" step="0.01" placeholder="0" value={arrivalModal.purchase} onChange={(e) => setArrivalModal({ ...arrivalModal, purchase: e.target.value })} />
                </FormGroup>
              )}
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

      {/* Підтвердити pending */}
      <Modal
        open={!!confirmModal}
        onClose={() => setConfirmModal(null)}
        title="Підтвердити прихід"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={confirmModal?.submitting} onClick={submitConfirm}>Підтвердити</button>
            <button className="btn btn-ghost" onClick={() => setConfirmModal(null)}>Скасувати</button>
          </div>
        }
      >
        {confirmModal && (
          <>
            {confirmModal.error && <div className="alert alert-error">{confirmModal.error}</div>}
            <div style={{ padding: '10px 14px', background: 'var(--bg-elevated)', borderRadius: 'var(--radius-sm)', marginBottom: 16 }}>
              <div style={{ fontSize: 13, color: 'var(--text-muted)' }}>Товар</div>
              <div style={{ fontSize: 15, fontWeight: 600, marginTop: 2 }}>{confirmModal.name}</div>
              <div style={{ fontSize: 13, color: 'var(--text-muted)', marginTop: 4 }}>Очікувалось: <strong>{confirmModal.expected}</strong> шт.</div>
            </div>
            <FormGroup label="Фактична кількість *">
              <input type="number" min="1" step="1" value={confirmModal.qty} onChange={(e) => setConfirmModal({ ...confirmModal, qty: e.target.value })} />
            </FormGroup>
            <FormGroup label="Статус оплати *">
              <div className="confirm-status-btns">
                {[['paid', '✅ Оплачено'], ['unpaid', '🔴 Не оплачено']].map(([s, label]) => (
                  <button key={s} type="button" data-s={s} className={`csb ${confirmModal.status === s ? 'active' : ''}`} onClick={() => setConfirmModal({ ...confirmModal, status: s })}>{label}</button>
                ))}
              </div>
            </FormGroup>
            {confirmModal.status === 'paid' && (
              <FormGroup label="Спосіб оплати *">
                <select value={confirmModal.method} onChange={(e) => setConfirmModal({ ...confirmModal, method: e.target.value })}>
                  <option value="cash">Готівка</option>
                  <option value="card">Карта</option>
                  <option value="terminal">Термінал</option>
                  <option value="transfer">Переказ</option>
                </select>
              </FormGroup>
            )}
          </>
        )}
      </Modal>

      {/* Редагування запису */}
      <Modal
        open={!!editModal}
        onClose={() => setEditModal(null)}
        title="Редагування запису"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={editModal?.submitting} onClick={submitEdit}>Зберегти</button>
            <button className="btn btn-ghost" onClick={() => setEditModal(null)}>Скасувати</button>
          </div>
        }
      >
        {editModal && (
          <>
            {editModal.error && <div className="alert alert-error">{editModal.error}</div>}
            <div style={{ background: 'var(--bg-elevated)', borderRadius: 'var(--radius-sm)', padding: '10px 14px', marginBottom: 16, fontSize: 13 }}>
              <div style={{ color: 'var(--text-muted)', marginBottom: 2 }}>Товар</div>
              <div style={{ fontWeight: 600, fontSize: 15 }}>{editModal.name}</div>
              <div style={{ color: 'var(--text-muted)', marginTop: 6, marginBottom: 2 }}>Постачальник</div>
              <div>{editModal.supplier || '—'}</div>
            </div>
            <FormGroup label="Тип операції *">
              <select value={editModal.operation} onChange={(e) => setEditModal({ ...editModal, operation: e.target.value })}>
                <option value="arrival">📥 Прихід</option>
                <option value="overdue">🔴 Прострочка</option>
                <option value="repack">🟡 Розфасування</option>
                <option value="transfer">⬛ Перенесення</option>
              </select>
            </FormGroup>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
              <FormGroup label="Кількість *">
                <input type="number" min="1" step="1" value={editModal.qty} onChange={(e) => setEditModal({ ...editModal, qty: e.target.value })} />
              </FormGroup>
              <FormGroup label="Ціна закупки">
                <input type="number" min="0" step="0.01" value={editModal.price} onChange={(e) => setEditModal({ ...editModal, price: e.target.value })} />
              </FormGroup>
            </div>
            <FormGroup label={<>Сума <span style={{ color: 'var(--text-muted)', fontSize: 11 }}>(авто)</span></>}>
              <input type="number" readOnly value={editTotal.toFixed(2)} style={{ background: 'var(--bg-elevated)', color: 'var(--text-secondary)' }} />
            </FormGroup>
            {editModal.operation === 'arrival' && (
              <FormGroup label="Статус оплати">
                <select value={editModal.status} onChange={(e) => setEditModal({ ...editModal, status: e.target.value })}>
                  <option value="paid">✅ Оплачено</option>
                  <option value="unpaid">🔴 Не оплачено</option>
                  <option value="pending">⏳ Замовлено</option>
                </select>
              </FormGroup>
            )}
            {editModal.operation === 'arrival' && editModal.status === 'paid' && (
              <FormGroup label="Спосіб оплати">
                <select value={editModal.method} onChange={(e) => setEditModal({ ...editModal, method: e.target.value })}>
                  <option value="cash">Готівка</option>
                  <option value="card">Карта</option>
                  <option value="terminal">Термінал</option>
                  <option value="transfer">Переказ</option>
                </select>
              </FormGroup>
            )}
            <FormGroup label="Примітка">
              <input type="text" value={editModal.notes} onChange={(e) => setEditModal({ ...editModal, notes: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>

      {/* Перегляд запису */}
      <Modal size="lg" open={!!viewModal} onClose={() => setViewModal(null)}>
        {viewModal && (() => {
          const v = viewModal;
          const isMinus = ['overdue', 'repack', 'transfer'].includes(v.operation);
          const isPending = v.status === 'pending';
          const opLabel = { arrival: '📥 Прихід', overdue: '🔴 Прострочка', repack: '🟡 Розфасування', transfer: '⬛ Перенесення' }[v.operation] || v.operation;

          const fields = [
            ['Постачальник', v.supplier || '—'],
            ['Ціна закупки', v.purchase_price > 0 ? formatMoney(v.purchase_price) : '—'],
            ...(v.operation === 'arrival' && v.status === 'paid' ? [['Спосіб оплати', PAY_LABELS[v.payment_method] || v.payment_method || '—']] : []),
            ['Поточний залишок', v.current_stock !== null ? `${v.current_stock} шт.` : '—'],
            ['Менеджер', v.admin_name || '—'],
            ['Дата', formatDate(v.created_at)],
          ];

          return (
            <>
              <div className="arr-detail-header">
                <div className="arr-detail-name">{v.product_name}</div>
                <div className="arr-detail-badges">
                  <span className={`op-badge ${v.operation}`}>{opLabel}</span>
                  {v.operation === 'arrival' && <span className={`status-badge ${v.status}`}>{STATUS_LABELS[v.status]}</span>}
                </div>
              </div>

              <div className={`arr-sum-strip ${isPending ? 'wait' : isMinus ? 'minus' : 'plus'}`}>
                <span className="arr-sum-qty">{isPending ? '⏳ ' : isMinus ? '−' : '+'}{v.quantity} шт.</span>
                <span className="arr-sum-total">{isPending ? 'Очікується' : `${isMinus ? '−' : ''}${formatMoney(Math.abs(v.total_cost))}`}</span>
              </div>

              <DetailFieldsGrid fields={fields} />

              {v.notes && (
                <div className="detail-field" style={{ marginTop: 4 }}>
                  <span className="detail-label">Примітка</span>
                  <span className="detail-value">{v.notes}</span>
                </div>
              )}

              <div style={{ marginTop: 16 }}>
                <button className="btn btn-ghost" style={{ width: '100%', justifyContent: 'center' }} onClick={() => setViewModal(null)}>Закрити</button>
              </div>
            </>
          );
        })()}
      </Modal>
    </AppLayout>
  );
}
