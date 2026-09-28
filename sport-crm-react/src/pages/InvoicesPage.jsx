import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import AppLayout from '../components/layout/AppLayout';
import Table from '../components/ui/Table';
import CardMenu from '../components/ui/CardMenu';
import Icon from '../components/ui/Icon';
import Pagination from '../components/ui/Pagination';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import Badge from '../components/ui/Badge';
import { usePermissions } from '../hooks/usePermissions';
import { useShiftLock } from '../hooks/useShiftLock';
import { useToast } from '../components/ui/ToastProvider';
import {
  getInvoices, getInvoice, createInvoice, updateInvoice,
  cancelInvoice, getInvoiceTariffs,
} from '../api/invoices';
import { getClients } from '../api/clients';
import { getTrainers } from '../api/trainers';
import { formatMoney, formatDate, localToday, localDate } from '../utils/format';
import './InvoicesPage.css';

const STATUS_MAP = {
  future: ['info', 'Майбутній'],
  active: ['active', 'Активний'],
  frozen: ['pending', 'Заморожений'],
  finished: ['inactive', 'Завершений'],
  cancelled: ['inactive', 'Скасований'],
};
const FILTERS = [
  ['', 'Всі'], ['future', 'Майбутні'], ['active', 'Активні'], ['frozen', 'Заморожені'],
  ['finished', 'Завершені'], ['cancelled', 'Скасовані'],
];
// Тип продажу визначається сервером автоматично при продажу — тут лише відображення.
// 'new' навмисно без бейджа (це більшість продажів, підсвічувати нема сенсу).
const SALE_TYPE_LABELS = { renewal: 'Продовження', return: 'Повернення' };
const PAY_METHODS = { cash: 'Готівка', card: 'Карта', terminal: 'Термінал', deposit: 'Депозит', transfer: 'Переказ' };
const EMPTY_DATA = { invoices: [], pagination: { total: 0, page: 1, pages: 1, per_page: 25 } };

function addDaysLocal(dateStr, n) {
  const d = new Date(`${dateStr}T00:00:00`);
  d.setDate(d.getDate() + n);
  return localDate(d);
}

export default function InvoicesPage() {
  const { has } = usePermissions();
  const { guard, lockedProps } = useShiftLock();
  const toast = useToast();
  const navigate = useNavigate();

  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);

  const [data, setData] = useState(EMPTY_DATA);
  const [loading, setLoading] = useState(true);

  const [tariffs, setTariffs] = useState([]);
  const [trainers, setTrainers] = useState([]);

  const [sell, setSell] = useState(null); // { clients, clientId, tariffId, startDate, discount, paidNow, notes, error, submitting }
  const [editInv, setEditInv] = useState(null); // { id, tariff_id, start_date, status, trainer_id, notes, loading, error, submitting }
  const [cancelConfirm, setCancelConfirm] = useState(null); // { id, reason, acknowledged, submitting, error }

  useEffect(() => {
    getInvoiceTariffs().then((res) => { if (res.success) setTariffs(res.tariffs); });
    getTrainers().then((res) => { if (res.success) setTrainers(res.trainers || []); });
  }, []);

  async function reload() {
    setLoading(true);
    const res = await getInvoices({ search, status, page });
    setLoading(false);
    if (!res.success) {
      toast(res.error, 'error');
      setData(EMPTY_DATA);
      return;
    }
    setData({ invoices: res.invoices, pagination: res.pagination });
  }

  useEffect(() => {
    const t = setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 350);
    return () => clearTimeout(t);
  }, [searchInput]);

  useEffect(() => {
    reload();
  }, [search, status, page]);

  function selectFilter(s) {
    setStatus(s);
    setPage(1);
  }

  async function openSell(prefill = null) {
    setSell({
      clients: null,
      clientId: prefill?.clientId ?? '', tariffId: prefill?.tariffId ?? '', trainerId: prefill?.trainerId ?? '',
      startDate: prefill?.startDate ?? localToday(),
      discount: 0, paidNow: 0, payMethod: 'cash', notes: '', error: '', submitting: false,
      isRenewal: !!prefill, skipFiscal: false,
    });
    const res = await getClients({ per_page: 200, order: 'full_name', dir: 'asc' });
    setSell((s) => (s ? { ...s, clients: res.success ? res.clients : [] } : s));
  }

  function openRenew(inv) {
    openSell({
      clientId: String(inv.client_id),
      tariffId: inv.tariff_id ? String(inv.tariff_id) : '',
      trainerId: inv.trainer_id ? String(inv.trainer_id) : '',
      startDate: addDaysLocal(inv.end_date, 1),
    });
  }

  async function submitSell() {
    if (!sell.clientId) { setSell({ ...sell, error: 'Оберіть клієнта' }); return; }
    if (!sell.tariffId) { setSell({ ...sell, error: 'Оберіть тариф' }); return; }
    setSell({ ...sell, submitting: true, error: '' });
    const res = await createInvoice({
      client_id: sell.clientId,
      tariff_id: sell.tariffId,
      trainer_id: sell.trainerId || 0,
      start_date: sell.startDate,
      discount: parseFloat(sell.discount) || 0,
      paid_amount: parseFloat(sell.paidNow) || 0,
      payment_method: sell.payMethod,
      notes: sell.notes.trim(),
      manual_skip_fiscal: sell.skipFiscal,
    });
    if (!res.success) {
      setSell({ ...sell, submitting: false, error: res.error });
      return;
    }
    toast('Абонемент продано', 'success');
    setSell(null);
    reload();
  }

  async function openEdit(id) {
    setEditInv({ id, loading: true, error: '' });
    const res = await getInvoice(id);
    if (!res.success) {
      setEditInv({ id, loading: false, error: res.error });
      return;
    }
    const inv = res.invoice;
    setEditInv({
      id, loading: false, error: '', submitting: false,
      tariff_id: inv.tariff_id, start_date: (inv.start_date || '').substring(0, 10),
      end_date: (inv.end_date || '').substring(0, 10), price: inv.price ?? 0,
      visits_total: inv.visits_total ?? '', visits_used: inv.visits_used ?? 0,
      status: inv.status || 'active', trainer_id: inv.trainer_id || '', notes: inv.notes || '',
    });
  }

  function editTariffChange(tariffId) {
    const t = tariffs.find((x) => String(x.id) === String(tariffId));
    setEditInv((s) => {
      const next = { ...s, tariff_id: tariffId };
      if (t) {
        const d = new Date(s.start_date || localToday());
        d.setDate(d.getDate() + parseInt(t.duration_days) - 1);
        next.end_date = localDate(d);
        next.price = t.price;
        next.visits_total = t.visits_limit ?? '';
        if (!t.has_trainer) next.trainer_id = '';
      }
      return next;
    });
  }

  async function submitEditInv() {
    setEditInv({ ...editInv, submitting: true, error: '' });
    const res = await updateInvoice({
      id: editInv.id, tariff_id: editInv.tariff_id, start_date: editInv.start_date,
      trainer_id: editInv.trainer_id || 0, notes: editInv.notes.trim(),
    });
    if (!res.success) {
      setEditInv({ ...editInv, submitting: false, error: res.error });
      return;
    }
    toast('Абонемент оновлено', 'success');
    setEditInv(null);
    reload();
  }

  function openCancelConfirm(id) {
    setCancelConfirm({ id, reason: '', acknowledged: false, submitting: false, error: '' });
  }

  async function submitCancel() {
    if (!cancelConfirm.reason.trim()) {
      setCancelConfirm({ ...cancelConfirm, error: 'Вкажіть причину дострокового скасування' });
      return;
    }
    if (!cancelConfirm.acknowledged) {
      setCancelConfirm({ ...cancelConfirm, error: 'Підтвердіть, що клієнта попереджено' });
      return;
    }
    setCancelConfirm({ ...cancelConfirm, submitting: true, error: '' });
    const res = await cancelInvoice(cancelConfirm.id, cancelConfirm.reason.trim());
    if (!res.success) { setCancelConfirm({ ...cancelConfirm, submitting: false, error: res.error }); return; }
    toast('Абонемент скасовано', 'success');
    setCancelConfirm(null);
    reload();
  }

  const selectedSellTariff = tariffs.find((t) => String(t.id) === String(sell?.tariffId));

  const columns = [
    {
      key: 'client', label: 'Клієнт',
      render: (inv) => (
        <div className="inv-client-info">
          <div className="inv-client-name">{inv.client_name}</div>
          <div className="inv-client-phone">{inv.client_phone || '—'}</div>
        </div>
      ),
    },
    {
      key: 'tariff', label: 'Тариф',
      mobile: 'secondary',
      render: (inv) => (
        <>
          <div className="inv-tariff-name" title={inv.tariff_name}>{inv.tariff_name}</div>
          <div className="inv-tariff-dates">{formatDate(inv.start_date)} — {formatDate(inv.end_date)}</div>
          {SALE_TYPE_LABELS[inv.sale_type] && (
            <span className={`sale-type-tag sale-type-${inv.sale_type}`}>{SALE_TYPE_LABELS[inv.sale_type]}</span>
          )}
        </>
      ),
    },
    {
      key: 'status', label: 'Статус',
      cardTop: true,
      render: (inv) => {
        const [variant, label] = STATUS_MAP[inv.status] || ['info', inv.status];
        return <Badge variant={variant}>{label}</Badge>;
      },
    },
    {
      key: 'days_left', label: 'Залишилось',
      mobile: 'trailing',
      render: (inv) => {
        if (inv.status === 'active') {
          const days = parseInt(inv.days_left);
          const cls = days <= 3 ? 'danger' : days <= 7 ? 'warn' : 'ok';
          const label = days === 0 ? 'Сьогодні' : `${days} дн.`;
          return <div className={`days-left ${cls}`}>{label}</div>;
        }
        if (inv.status === 'future') {
          const untilStart = Math.ceil((new Date(`${inv.start_date}T00:00:00`) - new Date(`${localToday()}T00:00:00`)) / 86400000);
          return <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>з {formatDate(inv.start_date)} ({untilStart} дн.)</div>;
        }
        if (inv.status === 'finished') return <div className="days-left expired">Завершено</div>;
        if (inv.status === 'frozen') return <div style={{ fontSize: 12, color: 'var(--warning)' }}>❄ {inv.freeze_days} дн.</div>;
        return '—';
      },
    },
    {
      key: 'payment', label: 'Оплата',
      render: (inv) => {
        const paid = parseFloat(inv.paid_amount || 0);
        const price = parseFloat(inv.price || 0);
        const debt = parseFloat(inv.debt || 0);
        return (
          <>
            <div style={{ fontSize: 13 }}>{formatMoney(paid)}{price > 0 ? ` / ${formatMoney(price)}` : ''}</div>
            {debt > 0.01 && <span className="debt-badge">борг {formatMoney(debt)}</span>}
          </>
        );
      },
    },
    {
      key: 'visits', label: 'Відвідування',
      render: (inv) => {
        if (!inv.visits_total) return <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>безліміт</span>;
        const pct = Math.min(100, Math.round((inv.visits_used / inv.visits_total) * 100));
        return (
          <div className="visits-progress">
            <span style={{ fontSize: 13 }}>{inv.visits_used}/{inv.visits_total}</span>
            <div className="visits-bar-track"><div className="visits-bar-fill" style={{ width: `${pct}%` }} /></div>
          </div>
        );
      },
    },
    {
      key: 'actions', label: '',
      render: (inv) => (
        <div onClick={(e) => e.stopPropagation()}>
          {/* Десктоп — окремі кнопки (не dropdown): CardMenu тут не підходить, бо його
              випадний список позиціюється всередині .table-wrap, а той має overflow-x:auto,
              що на десктопі обрізає/прогортує список замість показати його поверх таблиці. */}
          <div className="desktop-only-actions" style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
            <button className="btn btn-ghost btn-sm" title="Деталі" onClick={() => navigate(`/invoices/${inv.id}`)}>
              <Icon name="eye" size={14} />
            </button>
            {has('invoices.sell') && inv.status === 'active' && (
              <button className="btn btn-ghost btn-sm" title="Продовжити" onClick={guard(() => openRenew(inv))}>
                <Icon name="refresh" size={14} />
              </button>
            )}
            {has('invoices.sell') && inv.status !== 'cancelled' && (
              <button className="btn btn-ghost btn-sm" title="Редагувати" onClick={guard(() => openEdit(inv.id))}>
                <Icon name="edit" size={14} />
              </button>
            )}
            {has('invoices.cancel') && inv.status === 'active' && (
              <button className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }} title="Скасувати" onClick={guard(() => openCancelConfirm(inv.id))}>
                <Icon name="trash" size={14} />
              </button>
            )}
          </div>
          {/* Мобільна картка — компактне меню "⋮", на мобільному .table-wrap не скролиться,
              тож dropdown показується нормально поверх картки */}
          <div className="mobile-only-actions">
            <CardMenu actions={[
              { label: 'Деталі', icon: 'eye', onClick: () => navigate(`/invoices/${inv.id}`) },
              has('invoices.sell') && inv.status === 'active' && { label: 'Продовжити', icon: 'refresh', onClick: guard(() => openRenew(inv)) },
              has('invoices.sell') && inv.status !== 'cancelled' && { label: 'Редагувати', icon: 'edit', onClick: guard(() => openEdit(inv.id)) },
              has('invoices.cancel') && inv.status === 'active' && { label: 'Скасувати', icon: 'trash', danger: true, onClick: guard(() => openCancelConfirm(inv.id)) },
            ]} />
          </div>
        </div>
      ),
    },
  ];

  return (
    <AppLayout title="Абонементи">
      <div className="inv-toolbar">
        <div className="search-wrap">
          <span className="search-icon">🔍</span>
          <input type="text" placeholder="Ім'я або телефон клієнта..." value={searchInput} onChange={(e) => setSearchInput(e.target.value)} />
        </div>
        {FILTERS.map(([v, l]) => (
          <button key={v} className={`inv-filter-btn ${status === v ? 'active' : ''}`} onClick={() => selectFilter(v)}>{l}</button>
        ))}
        {has('invoices.sell') && (
          <button className="btn btn-primary" style={{ marginLeft: 'auto', ...lockedProps.style }} title={lockedProps.title} onClick={guard(() => openSell())}>
            + Продати абонемент
          </button>
        )}
      </div>

      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <Table
          columns={columns}
          rows={data.invoices}
          loading={loading}
          emptyMessage={has('invoices.sell') ? 'Абонементів не знайдено. Натисніть "+ Продати абонемент"' : 'Абонементів не знайдено'}
          onRowClick={(row) => navigate(`/invoices/${row.id}`)}
        />
      </div>

      <Pagination page={data.pagination.page} pages={data.pagination.pages} total={data.pagination.total} perPage={data.pagination.per_page} onChange={setPage} />

      {/* Продати абонемент */}
      <Modal
        open={!!sell}
        onClose={() => setSell(null)}
        title={sell?.isRenewal ? 'Продовжити абонемент' : 'Продати абонемент'}
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={sell?.submitting} onClick={submitSell}>{sell?.submitting ? 'Зберігаємо...' : (sell?.isRenewal ? 'Продовжити' : 'Продати абонемент')}</button>
            <button className="btn btn-ghost" onClick={() => setSell(null)}>Скасувати</button>
          </div>
        }
      >
        {sell && (
          <>
            {sell.error && <div className="alert alert-error">{sell.error}</div>}
            <FormGroup label="Клієнт *">
              <select value={sell.clientId} onChange={(e) => setSell({ ...sell, clientId: e.target.value })} disabled={!sell.clients}>
                <option value="">{sell.clients === null ? '— Завантаження... —' : '— Оберіть клієнта —'}</option>
                {sell.clients?.map((c) => <option key={c.id} value={c.id}>{c.full_name}{c.phone ? ` · ${c.phone}` : ''}</option>)}
              </select>
            </FormGroup>
            <FormGroup label="Тариф *">
              <select value={sell.tariffId} onChange={(e) => {
                const t = tariffs.find((x) => String(x.id) === String(e.target.value));
                setSell({ ...sell, tariffId: e.target.value, trainerId: t?.has_trainer ? sell.trainerId : '' });
              }}>
                <option value="">{tariffs.length ? '— Оберіть тариф —' : 'Тарифів немає'}</option>
                {tariffs.map((t) => (
                  <option key={t.id} value={t.id}>{t.name} · {t.price} грн · {t.duration_days} дн. · {t.visits_limit ? `${t.visits_limit} відвід.` : 'безліміт'}</option>
                ))}
              </select>
              {selectedSellTariff && (
                <div style={{ marginTop: 8, padding: '10px 12px', background: 'var(--bg-elevated)', border: '1px solid var(--border)', borderRadius: 'var(--radius-sm)', fontSize: 13, color: 'var(--text-secondary)' }}>
                  <strong>{selectedSellTariff.name}</strong> · <span style={{ color: 'var(--accent)' }}>{selectedSellTariff.price} грн</span> · {selectedSellTariff.duration_days} дн.
                  {selectedSellTariff.visits_limit ? ` · ${selectedSellTariff.visits_limit} відвід.` : ' · безліміт'}
                  {selectedSellTariff.description && <div style={{ marginTop: 4, fontSize: 12 }}>{selectedSellTariff.description}</div>}
                </div>
              )}
            </FormGroup>
            {selectedSellTariff?.has_trainer && (
              <FormGroup label="Тренер (рекомендований)">
                <select value={sell.trainerId} onChange={(e) => setSell({ ...sell, trainerId: e.target.value })}>
                  <option value="">— Без тренера —</option>
                  {trainers.map((t) => <option key={t.id} value={t.id}>{t.full_name}</option>)}
                </select>
              </FormGroup>
            )}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Дата початку *">
                <input type="date" value={sell.startDate} onChange={(e) => setSell({ ...sell, startDate: e.target.value })} />
              </FormGroup>
              <FormGroup label="Знижка (%)">
                <input type="number" min="0" max="100" step="1" value={sell.discount} onChange={(e) => setSell({ ...sell, discount: e.target.value })} />
              </FormGroup>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Оплата зараз (грн)">
                <input type="number" min="0" step="1" value={sell.paidNow} onChange={(e) => setSell({ ...sell, paidNow: e.target.value })} />
              </FormGroup>
              <FormGroup label="Спосіб оплати">
                <select value={sell.payMethod} onChange={(e) => setSell({ ...sell, payMethod: e.target.value })} disabled={!(parseFloat(sell.paidNow) > 0)}>
                  {Object.entries(PAY_METHODS).map(([m, l]) => <option key={m} value={m}>{l}</option>)}
                </select>
              </FormGroup>
            </div>
            {parseFloat(sell.paidNow) > 0 && (
              <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: 'var(--text-secondary)' }}>
                <input type="checkbox" checked={sell.skipFiscal} onChange={(e) => setSell({ ...sell, skipFiscal: e.target.checked })} />
                Не проводити фіскальний чек для цієї оплати
              </label>
            )}
            <FormGroup label="Примітка">
              <textarea rows="2" placeholder="Необов'язково..." value={sell.notes} onChange={(e) => setSell({ ...sell, notes: e.target.value })} />
            </FormGroup>

            {selectedSellTariff && (() => {
              const discount = Math.max(0, Math.min(100, parseFloat(sell.discount) || 0));
              const finalPrice = Math.round(selectedSellTariff.price * (1 - discount / 100) * 100) / 100;
              const paidNow = Math.min(finalPrice, parseFloat(sell.paidNow) || 0);
              const debt = Math.max(0, finalPrice - paidNow);
              let endDateLabel = '';
              if (sell.startDate) {
                const d = new Date(sell.startDate);
                d.setDate(d.getDate() + parseInt(selectedSellTariff.duration_days) - 1);
                endDateLabel = d.toLocaleDateString('uk-UA');
              }
              return (
                <div className="sale-summary">
                  <div className="sale-summary-row"><span>Ціна тарифу</span><span>{formatMoney(selectedSellTariff.price)}</span></div>
                  {discount > 0 && (
                    <div className="sale-summary-row"><span>Знижка {discount}%</span><span>−{formatMoney(selectedSellTariff.price - finalPrice)}</span></div>
                  )}
                  <div className="sale-summary-row"><span>Оплата зараз</span><span>{formatMoney(paidNow)}</span></div>
                  {debt > 0 && <div className="sale-summary-row"><span>Залишок боргу</span><span>{formatMoney(debt)}</span></div>}
                  <div className="sale-summary-row"><span>Діє до</span><span>{endDateLabel}</span></div>
                  <div className="sale-summary-row total"><span>Разом</span><span>{formatMoney(finalPrice)}</span></div>
                </div>
              );
            })()}
          </>
        )}
      </Modal>

      {/* Редагувати абонемент */}
      <Modal
        open={!!editInv}
        onClose={() => setEditInv(null)}
        title="Редагувати абонемент"
        footer={
          <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
            <button className="btn btn-ghost" onClick={() => setEditInv(null)}>Скасувати</button>
            <button className="btn btn-primary" disabled={editInv?.submitting || editInv?.loading} onClick={submitEditInv}>{editInv?.submitting ? '...' : 'Зберегти'}</button>
          </div>
        }
      >
        {editInv && (() => {
          const editTariff = tariffs.find((t) => String(t.id) === String(editInv.tariff_id));
          const hasTrainer = !!editTariff?.has_trainer;
          return (
            <div style={{ opacity: editInv.loading ? 0.4 : 1 }}>
              {editInv.error && <div className="alert alert-error">{editInv.error}</div>}
              {!editInv.loading && (
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
                  <FormGroup label="Тариф" fullWidth>
                    <select value={editInv.tariff_id} onChange={(e) => editTariffChange(e.target.value)}>
                      {tariffs.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                    </select>
                  </FormGroup>
                  <FormGroup label="Дата початку">
                    <input type="date" value={editInv.start_date} onChange={(e) => setEditInv({ ...editInv, start_date: e.target.value })} />
                  </FormGroup>
                  <FormGroup label="Дата закінчення">
                    <input type="date" value={editInv.end_date} disabled />
                  </FormGroup>
                  <FormGroup label="Ціна (грн)">
                    <input type="number" value={editInv.price} disabled />
                  </FormGroup>
                  <FormGroup label="Статус">
                    <div style={{ padding: '9px 0' }}>
                      <Badge variant={STATUS_MAP[editInv.status]?.[0] || 'info'}>{STATUS_MAP[editInv.status]?.[1] || editInv.status}</Badge>
                    </div>
                  </FormGroup>
                  <FormGroup label="Ліміт відвідувань">
                    <input type="text" value={editInv.visits_total || 'безліміт'} disabled />
                  </FormGroup>
                  <FormGroup label="Використано відвідувань">
                    <input type="number" value={editInv.visits_used} disabled />
                  </FormGroup>
                  {hasTrainer ? (
                    <FormGroup label="Тренер" fullWidth>
                      <select value={editInv.trainer_id} onChange={(e) => setEditInv({ ...editInv, trainer_id: e.target.value })}>
                        <option value="">— Без тренера —</option>
                        {trainers.map((t) => <option key={t.id} value={t.id}>{t.full_name}</option>)}
                      </select>
                    </FormGroup>
                  ) : (
                    <FormGroup label="Тренер" fullWidth>
                      <div style={{ fontSize: 12, color: 'var(--text-muted)', padding: '9px 0' }}>Тариф не передбачає тренера</div>
                    </FormGroup>
                  )}
                  <FormGroup label="Нотатки" fullWidth>
                    <textarea rows="2" placeholder="Необов'язково..." value={editInv.notes} onChange={(e) => setEditInv({ ...editInv, notes: e.target.value })} />
                  </FormGroup>
                </div>
              )}
              <p style={{ fontSize: 11, color: 'var(--text-muted)', margin: '10px 0 0' }}>
                ℹ️ Дата закінчення, ціна, ліміт та кількість відвідувань розраховуються системою з тарифу. Статус змінюється лише через дії «Заморозити» / «Скасувати» / «Відновити» у картці абонементу.
              </p>
            </div>
          );
        })()}
      </Modal>

      {/* Підтвердження дострокового скасування */}
      <Modal
        open={!!cancelConfirm}
        onClose={() => setCancelConfirm(null)}
        title="Дострокове припинення абонемента"
        footer={
          <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
            <button className="btn btn-ghost" onClick={() => setCancelConfirm(null)}>Відмінити</button>
            <button
              className="btn btn-danger"
              disabled={cancelConfirm?.submitting || !cancelConfirm?.reason.trim() || !cancelConfirm?.acknowledged}
              onClick={submitCancel}
            >
              {cancelConfirm?.submitting ? '...' : '🗑 Скасувати абонемент остаточно'}
            </button>
          </div>
        }
      >
        {cancelConfirm && (
          <div>
            {cancelConfirm.error && <div className="alert alert-error">{cancelConfirm.error}</div>}
            <div className="alert alert-error" style={{ marginBottom: 16 }}>
              Ця дія призначена лише для дострокового припинення абонемента (наприклад, порушення клієнтом правил клубу) — не для звичайного завершення терміну дії. Кошти за невикористаний період <strong>не повертаються автоматично</strong>.
            </div>
            <FormGroup label="Причина дострокового скасування *">
              <textarea
                rows="2"
                placeholder="напр. Порушення правил клубу — систематичні запізнення"
                value={cancelConfirm.reason}
                onChange={(e) => setCancelConfirm({ ...cancelConfirm, reason: e.target.value, error: '' })}
              />
            </FormGroup>
            <label style={{ display: 'flex', alignItems: 'flex-start', gap: 8, fontSize: 13, marginTop: 12, cursor: 'pointer' }}>
              <input
                type="checkbox"
                checked={cancelConfirm.acknowledged}
                onChange={(e) => setCancelConfirm({ ...cancelConfirm, acknowledged: e.target.checked, error: '' })}
                style={{ marginTop: 2 }}
              />
              <span>Я попередив(ла) клієнта, що абонемент скасовується достроково і кошти за невикористаний період не повертаються.</span>
            </label>
          </div>
        )}
      </Modal>
    </AppLayout>
  );
}
