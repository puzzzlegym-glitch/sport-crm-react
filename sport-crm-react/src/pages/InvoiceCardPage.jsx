import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import AppLayout from '../components/layout/AppLayout';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import Table from '../components/ui/Table';
import { usePermissions } from '../hooks/usePermissions';
import { useShiftLock } from '../hooks/useShiftLock';
import { useToast } from '../components/ui/ToastProvider';
import {
  getInvoice, updateInvoice, addInvoicePayment, getInvoiceTariffs, createInvoice,
  freezeInvoice, cancelFreezeInvoice, updateFreezeDays, cancelInvoice, restoreInvoice,
} from '../api/invoices';
import { getTrainers } from '../api/trainers';
import { getVisitsList } from '../api/visits';
import { formatMoney, formatDate, formatTime, localToday, localDate } from '../utils/format';
import './InvoiceCardPage.css';

const STATUS_MAP = {
  future: ['info', 'Майбутній'],
  active: ['active', 'Активний'],
  frozen: ['pending', 'Заморожений'],
  finished: ['inactive', 'Завершений'],
  cancelled: ['inactive', 'Скасований'],
};
const SALE_TYPE_LABELS = { renewal: 'Продовження', return: 'Повернення' };
const PAY_METHOD_LABELS = { cash: 'Готівка', card: 'Карта', terminal: 'Термінал', deposit: 'Депозит', transfer: 'Переказ', free: 'Безкоштовно' };
const PAY_METHODS = { cash: 'Готівка', card: 'Карта', terminal: 'Термінал', deposit: 'Депозит', transfer: 'Переказ' };
const FISCAL_STATUS_LABELS = { pending: '⏳ В черзі', sent: '✅ Відправлено', failed: '❌ Помилка', skipped_manual: '— Пропущено вручну' };
const VISIT_METHOD_LABELS = { barcode: '📷 Штрих-код', manual: '✎ Вручну', admin: '🛡 Адміністратор' };
// Інфобокс під деталями абонементу — коротке пояснення поточного статусу.
const INFO_BOX_BY_STATUS = {
  finished:  { tone: 'muted',   icon: 'info',          title: 'Абонемент завершено',   text: 'Термін дії абонемента закінчився.' },
  frozen:    { tone: 'warning', icon: 'clock',         title: 'Абонемент заморожено',  text: 'Дні заморозки не враховуються у термін дії.' },
  cancelled: { tone: 'danger',  icon: 'alertTriangle', title: 'Абонемент скасовано',   text: 'Продаж було скасовано.' },
};

const TABS = [
  { key: 'overview', label: 'Огляд' },
  { key: 'payments', label: 'Платежі' },
  { key: 'visits', label: 'Відвідування' },
  { key: 'actions', label: 'Дії' },
];

function addDaysLocal(dateStr, n) {
  const d = new Date(`${dateStr}T00:00:00`);
  d.setDate(d.getDate() + n);
  return localDate(d);
}

export default function InvoiceCardPage() {
  const { id } = useParams();
  const invoiceId = Number(id);
  const { has } = usePermissions();
  const { guard, lockedProps } = useShiftLock();
  const toast = useToast();
  const navigate = useNavigate();

  const [view, setView] = useState(null); // { loading, notFound, invoice, payments }
  const [visitsTab, setVisitsTab] = useState(null); // { loading, rows }
  const [tab, setTab] = useState('overview');

  const [tariffs, setTariffs] = useState([]);
  const [trainers, setTrainers] = useState([]);

  const [editInv, setEditInv] = useState(null);
  const [cancelConfirm, setCancelConfirm] = useState(null);
  const [addPay, setAddPay] = useState(null); // { amount, method, skipFiscal, cardStep, cardTxn, submitting }
  const [renew, setRenew] = useState(null);

  const [freezeStart, setFreezeStart] = useState('');
  const [freezeDays, setFreezeDays] = useState(7);

  const [actionsOpen, setActionsOpen] = useState(false);
  const actionsRef = useRef(null);

  const load = useCallback(async () => {
    setView({ loading: true });
    const res = await getInvoice(invoiceId);
    if (!res.success) {
      toast(res.error, 'error');
      setView({ loading: false, notFound: true });
      return;
    }
    const inv = res.invoice;
    setView({ loading: false, invoice: inv, payments: res.payments || [] });
    setVisitsTab(null);
    setFreezeStart('');
    setFreezeDays(inv.freeze_start ? (inv.freeze_current_days ?? inv.freeze_days) : (inv.tariff_freeze_min || 7));
  }, [invoiceId, toast]);

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    getInvoiceTariffs().then((res) => { if (res.success) setTariffs(res.tariffs || []); });
    getTrainers().then((res) => { if (res.success) setTrainers(res.trainers || []); });
  }, []);

  useEffect(() => {
    if (tab === 'visits' && !visitsTab) {
      setVisitsTab({ loading: true, rows: [] });
      getVisitsList({ invoice_id: invoiceId }).then((r) => setVisitsTab({ loading: false, rows: r.success ? r.visits : [] }));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab, invoiceId]);

  useEffect(() => {
    if (!actionsOpen) return;
    function onDocClick(e) { if (actionsRef.current && !actionsRef.current.contains(e.target)) setActionsOpen(false); }
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, [actionsOpen]);

  function openEdit() {
    const inv = view.invoice;
    setEditInv({
      tariff_id: inv.tariff_id, start_date: (inv.start_date || '').substring(0, 10),
      end_date: (inv.end_date || '').substring(0, 10), price: inv.price ?? 0,
      visits_total: inv.visits_total ?? '', visits_used: inv.visits_used ?? 0,
      status: inv.status || 'active', trainer_id: inv.trainer_id || '', notes: inv.notes || '',
      submitting: false, error: '',
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
      id: invoiceId, tariff_id: editInv.tariff_id, start_date: editInv.start_date,
      trainer_id: editInv.trainer_id || 0, notes: editInv.notes.trim(),
    });
    if (!res.success) {
      setEditInv({ ...editInv, submitting: false, error: res.error });
      return;
    }
    toast('Абонемент оновлено', 'success');
    setEditInv(null);
    load();
  }

  function openAddPayment() {
    setAddPay({ amount: '', method: 'cash', skipFiscal: false, cardStep: 'idle', cardTxn: null, submitting: false });
  }

  async function submitAddPayment() {
    const amount = parseFloat(addPay.amount);
    if (!amount || amount <= 0) { toast('Введіть суму', 'error'); return; }
    setAddPay({ ...addPay, submitting: true });
    const res = await addInvoicePayment({ invoice_id: invoiceId, amount, payment_method: addPay.method, manual_skip_fiscal: addPay.skipFiscal });
    if (!res.success) { setAddPay({ ...addPay, submitting: false }); toast(res.error, 'error'); return; }
    toast('Платіж записано', 'success');
    setAddPay(null);
    load();
  }

  // ── Заглушка оплати карткою через POS-термінал (реальної інтеграції поки немає) ──
  function startCardPayment() {
    const amount = parseFloat(addPay.amount);
    if (!amount || amount <= 0) { toast('Введіть суму', 'error'); return; }
    setAddPay((s) => ({ ...s, cardStep: 'waiting' }));
    setTimeout(() => {
      setAddPay((s) => (s && s.cardStep === 'waiting' ? {
        ...s,
        cardStep: 'success',
        cardTxn: { id: String(Math.floor(100000 + Math.random() * 900000)), time: formatTime(new Date()) },
      } : s));
    }, 1600);
  }

  function cancelCardPayment() {
    setAddPay((s) => ({ ...s, cardStep: 'idle', cardTxn: null }));
  }

  function openRenew() {
    const inv = view.invoice;
    setRenew({
      tariffId: inv.tariff_id ? String(inv.tariff_id) : '',
      trainerId: inv.trainer_id ? String(inv.trainer_id) : '',
      startDate: addDaysLocal(inv.end_date, 1),
      discount: 0, paidNow: 0, payMethod: 'cash', skipFiscal: false, notes: '', error: '', submitting: false,
    });
  }

  async function submitRenew() {
    if (!renew.tariffId) { setRenew({ ...renew, error: 'Оберіть тариф' }); return; }
    setRenew({ ...renew, submitting: true, error: '' });
    const res = await createInvoice({
      client_id: view.invoice.client_id,
      tariff_id: renew.tariffId,
      trainer_id: renew.trainerId || 0,
      start_date: renew.startDate,
      discount: parseFloat(renew.discount) || 0,
      paid_amount: parseFloat(renew.paidNow) || 0,
      payment_method: renew.payMethod,
      notes: renew.notes.trim(),
      manual_skip_fiscal: renew.skipFiscal,
    });
    if (!res.success) {
      setRenew({ ...renew, submitting: false, error: res.error });
      return;
    }
    toast('Абонемент продовжено', 'success');
    setRenew(null);
    if (res.id) navigate(`/invoices/${res.id}`);
    else { navigate('/invoices'); }
  }

  async function submitFreeze() {
    const res = await freezeInvoice({ id: invoiceId, days: freezeDays, freeze_start: freezeStart });
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message || 'Збережено', 'success');
    load();
  }

  async function submitCancelFreeze() {
    const res = await cancelFreezeInvoice(invoiceId);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message || 'Заморозку відмінено', 'success');
    load();
  }

  async function submitUpdateFreezeDays() {
    const res = await updateFreezeDays(invoiceId, freezeDays);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message || 'Збережено', 'success');
    load();
  }

  function openCancelConfirm() {
    setCancelConfirm({ reason: '', acknowledged: false, submitting: false, error: '' });
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
    const res = await cancelInvoice(invoiceId, cancelConfirm.reason.trim());
    if (!res.success) { setCancelConfirm({ ...cancelConfirm, submitting: false, error: res.error }); return; }
    toast('Абонемент скасовано', 'success');
    setCancelConfirm(null);
    load();
  }

  async function handleRestore() {
    const res = await restoreInvoice(invoiceId);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast('Абонемент відновлено', 'success');
    load();
  }

  if (!view || view.loading) {
    return (
      <AppLayout title="Абонемент">
        <div className="loader"><span className="spinner" /> Завантаження...</div>
      </AppLayout>
    );
  }

  if (view.notFound || !view.invoice) {
    return (
      <AppLayout title="Абонемент">
        <div className="empty-state"><p>Абонемент не знайдено</p></div>
      </AppLayout>
    );
  }

  const inv = view.invoice;
  const statusVariant = STATUS_MAP[inv.status]?.[0] || 'info';
  const statusLabel = STATUS_MAP[inv.status]?.[1] || inv.status;
  const paid = parseFloat(inv.paid_amount || 0);
  const price = parseFloat(inv.price || 0);
  const debt = Math.max(0, price - paid);
  const daysLeft = inv.status === 'active' ? Math.ceil((new Date(`${inv.end_date}T00:00:00`) - new Date()) / 86400000) : null;
  const visitsPct = inv.visits_total ? Math.min(100, Math.round((inv.visits_used / inv.visits_total) * 100)) : null;

  // Заморозка можлива лише в межах дії абонементу: з 2-го дня по передостанній
  // Заморозка — не раніше наступного дня після заявки (сьогоднішній день уже почався)
  const freezeMinDate = [addDaysLocal(localToday(), 1), addDaysLocal(inv.start_date, 1)].sort().pop();
  const isFreezeScheduled = inv.status === 'active' && !!inv.freeze_start;
  const hasFreeze = inv.status === 'frozen' || isFreezeScheduled;
  const curFreezeDays = Number(inv.freeze_current_days ?? inv.freeze_days) || 0;
  const freezeLastDay = inv.freeze_start ? addDaysLocal(inv.freeze_start, curFreezeDays - 1) : null;
  const freezeMaxDate = addDaysLocal(inv.end_date, -1);
  const canFreeze = inv.status === 'active' && !inv.freeze_start && freezeMinDate && freezeMaxDate && freezeMinDate <= freezeMaxDate;
  const freezeDaysMin = inv.tariff_freeze_min > 0 ? inv.tariff_freeze_min : 1;
  const freezeDaysMax = inv.tariff_freeze_max > 0 ? inv.tariff_freeze_max : 90;

  const editTariff = editInv ? tariffs.find((t) => String(t.id) === String(editInv.tariff_id)) : null;
  const editHasTrainer = !!editTariff?.has_trainer;
  const renewTariff = renew ? tariffs.find((t) => String(t.id) === String(renew.tariffId)) : null;

  const detailRows = [
    ['calendar', 'Дата початку', formatDate(inv.start_date)],
    ['calendar', 'Дата кінця', formatDate(inv.end_date)],
    ['tag', 'Ціна', <span key="price" className="icard-detail-price">{formatMoney(price)}</span>],
    ['card', 'Оплачено', formatMoney(paid)],
    ['alertTriangle', 'Борг', debt > 0.01 ? <span key="debt" className="icard-detail-debt">{formatMoney(debt)}</span> : '0 грн'],
    ['users', 'Відвідування', inv.visits_total ? `${inv.visits_used} / ${inv.visits_total}` : 'Безліміт'],
  ];
  const infoRows = [
    ['cart', 'Тип продажу', <Badge key="sale-type" variant="info">{SALE_TYPE_LABELS[inv.sale_type] || 'Новий клієнт'}</Badge>],
    inv.trainer_name ? ['user', 'Тренер', inv.trainer_name] : null,
    inv.freeze_start ? ['clock', inv.status === 'frozen' ? 'Заморожено' : 'Заплановано заморозку', `${curFreezeDays} дн.: ${formatDate(inv.freeze_start)} — ${formatDate(freezeLastDay)}`] : null,
    inv.freeze_days > 0 ? ['clock', 'Всього днів заморозки', `${inv.freeze_days} дн.`] : null,
    ['briefcase', 'Менеджер', inv.admin_name || '—'],
    ['user', 'Клієнт', <Link key="client" to={`/clients/${inv.client_id}`}>{inv.client_name}</Link>],
    ['calendar', 'Створено', `${formatDate(inv.created_at)} ${formatTime(inv.created_at)}`],
  ].filter(Boolean);
  const infoBox = INFO_BOX_BY_STATUS[inv.status];

  const paymentColumns = [
    { key: 'date', label: 'Дата', render: (p) => <span style={{ fontSize: 13, whiteSpace: 'nowrap' }}>{formatDate(p.created_at)} {formatTime(p.created_at)}</span> },
    { key: 'method', label: 'Тип', cardTop: true, render: (p) => <Badge variant="info">{PAY_METHOD_LABELS[p.payment_method] || p.payment_method}</Badge> },
    { key: 'status', label: 'Статус', render: (p) => p.fiscal_status ? <span style={{ fontSize: 12 }}>{FISCAL_STATUS_LABELS[p.fiscal_status] || p.fiscal_status}</span> : <span className="text-muted">—</span> },
    { key: 'comment', label: 'Коментар', render: (p) => <span style={{ fontSize: 13, color: 'var(--text-secondary)' }}>{p.notes || '—'}</span> },
    { key: 'amount', label: 'Сума', mobile: 'trailing', render: (p) => <span style={{ fontWeight: 600, color: 'var(--success)' }}>{formatMoney(p.amount)}</span> },
  ];
  const visitColumns = [
    { key: 'date', label: 'Дата', render: (v) => <span style={{ fontSize: 13, whiteSpace: 'nowrap' }}>{formatDate(v.visited_at)} {formatTime(v.visited_at)}</span> },
    { key: 'method', label: 'Спосіб', cardTop: true, render: (v) => <Badge variant="info">{VISIT_METHOD_LABELS[v.method] || v.method || '—'}</Badge> },
    { key: 'trainer', label: 'Тренер', render: (v) => <span style={{ fontSize: 13 }}>{v.trainer_name || '—'}</span> },
    { key: 'admin', label: 'Адміністратор', render: (v) => <span style={{ fontSize: 13, color: 'var(--text-secondary)' }}>{v.admin_name || '—'}</span> },
    { key: 'notes', label: 'Коментар', render: (v) => <span style={{ fontSize: 13 }}>{v.notes || '—'}</span> },
  ];

  const menuActions = [
    has('invoices.sell') && inv.status !== 'cancelled' && { label: 'Редагувати', icon: 'edit', onClick: guard(openEdit) },
    has('invoices.sell') && inv.status === 'active' && { label: 'Продовжити абонемент', icon: 'refresh', onClick: guard(openRenew) },
    has('invoices.cancel') && inv.status === 'active' && canFreeze && { label: 'Заморозити абонемент', icon: 'clock', onClick: () => setTab('actions') },
    has('invoices.cancel') && inv.status === 'frozen' && { label: 'Керувати заморозкою', icon: 'clock', onClick: () => setTab('actions') },
    has('invoices.cancel') && inv.status === 'active' && { label: 'Скасувати абонемент', icon: 'trash', danger: true, onClick: guard(openCancelConfirm) },
    has('invoices.cancel') && inv.status === 'cancelled' && { label: 'Відновити абонемент', icon: 'refresh', onClick: handleRestore },
  ].filter(Boolean);

  const quickActions = [
    has('payments.create') && inv.status !== 'cancelled' && {
      key: 'pay', icon: 'wallet', title: 'Прийняти оплату', desc: 'Додати оплату за абонемент',
      highlight: debt > 0.01, onClick: openAddPayment,
    },
    has('invoices.cancel') && inv.status === 'active' && canFreeze && {
      key: 'freeze', icon: 'clock', title: 'Заморозити', desc: 'Тимчасово призупинити дію', onClick: () => setTab('actions'),
    },
    has('invoices.cancel') && inv.status === 'frozen' && {
      key: 'unfreeze', icon: 'clock', title: 'Керувати заморозкою', desc: 'Розморозити або змінити термін', onClick: () => setTab('actions'),
    },
    has('invoices.sell') && inv.status === 'active' && {
      key: 'renew', icon: 'refresh', title: 'Продовжити', desc: 'Збільшити термін дії', onClick: guard(openRenew),
    },
    has('invoices.sell') && inv.status !== 'cancelled' && {
      key: 'edit', icon: 'edit', title: 'Редагувати', desc: 'Змінити дані абонемента', onClick: guard(openEdit),
    },
  ].filter(Boolean);

  return (
    <AppLayout title="Абонементи">
      <div className="icard-breadcrumb">
        <Link to="/invoices">Абонементи</Link>
        <Icon name="chevronRight" size={14} />
        <span>{inv.tariff_name}</span>
      </div>

      <div className="icard-header">
        <div className="icard-header-main">
          <div className="icard-avatar"><Icon name={inv.trainer_name ? 'dumbbell' : 'card'} size={26} /></div>
          <div>
            <div className="icard-name-row">
              <span className="icard-name">{inv.tariff_name}</span>
              <Badge variant={statusVariant}>{statusLabel}</Badge>
              {SALE_TYPE_LABELS[inv.sale_type] && <Badge variant="info">{SALE_TYPE_LABELS[inv.sale_type]}</Badge>}
            </div>
            <div className="icard-contact-row">
              <span><Icon name="user" size={13} /> <Link className="icard-client-link" to={`/clients/${inv.client_id}`}>{inv.client_name}</Link></span>
              {inv.client_phone && <span><Icon name="phone" size={13} /> {inv.client_phone}</span>}
              <span><Icon name="calendar" size={13} /> {formatDate(inv.start_date)} — {formatDate(inv.end_date)}</span>
            </div>
          </div>
        </div>
        <div className="icard-header-actions">
          {has('payments.create') && inv.status !== 'cancelled' && (
            <button className="btn btn-primary" onClick={openAddPayment}><Icon name="card" size={15} /> Оплата</button>
          )}
          {menuActions.length > 0 && (
            <div className="icard-actions-menu" ref={actionsRef}>
              <button className="btn btn-ghost" {...lockedProps} onClick={() => setActionsOpen((v) => !v)}>
                Дії <Icon name="chevronDown" size={13} />
              </button>
              {actionsOpen && (
                <div className="icard-actions-menu-list">
                  {menuActions.map((a, i) => (
                    <button
                      key={i}
                      className={`icard-actions-menu-item ${a.danger ? 'danger' : ''}`}
                      onClick={() => { setActionsOpen(false); a.onClick(); }}
                    >
                      <Icon name={a.icon} size={14} /> {a.label}
                    </button>
                  ))}
                </div>
              )}
            </div>
          )}
        </div>
      </div>

      <div className="icard-stats-grid">
        <div className="icard-stat" style={{ '--icard-accent': 'var(--accent)' }}>
          <div className="icard-stat-icon"><Icon name="banknote" size={18} /></div>
          <div>
            <div className="icard-stat-label">Ціна</div>
            <div className="icard-stat-value">{formatMoney(price)}</div>
          </div>
        </div>
        <div className="icard-stat" style={{ '--icard-accent': '#8b6cf9' }}>
          <div className="icard-stat-icon"><Icon name="card" size={18} /></div>
          <div className="icard-stat-split">
            <div className="icard-stat-col">
              <div className="icard-stat-label">Оплачено</div>
              <div className="icard-stat-value">{formatMoney(paid)}</div>
            </div>
            <div className="icard-stat-col">
              <div className="icard-stat-label">Борг</div>
              <div className={`icard-stat-value ${debt > 0.01 ? 'danger' : 'success'}`}>{debt > 0.01 ? formatMoney(debt) : '0 грн'}</div>
            </div>
          </div>
        </div>
        <div className="icard-stat" style={{ '--icard-accent': 'var(--info)' }}>
          <div className="icard-stat-icon"><Icon name="calendar" size={18} /></div>
          <div>
            <div className="icard-stat-label">Закінчується</div>
            <div className="icard-stat-value">{formatDate(inv.end_date)}</div>
            {daysLeft != null && <div className={`icard-stat-delta ${daysLeft <= 7 ? 'down' : ''}`}>{daysLeft <= 0 ? 'Прострочено' : `через ${daysLeft} дн.`}</div>}
            {inv.status === 'frozen' && <div className="icard-stat-delta">❄ заморожено до {formatDate(freezeLastDay)}</div>}
            {isFreezeScheduled && <div className="icard-stat-delta">❄ заморозка з {formatDate(inv.freeze_start)}</div>}
          </div>
        </div>
        <div className="icard-stat" style={{ '--icard-accent': 'var(--success)' }}>
          <div className="icard-stat-icon"><Icon name="users" size={18} /></div>
          <div>
            <div className="icard-stat-label">Відвідування</div>
            <div className="icard-stat-value">{inv.visits_total ? `${inv.visits_used}/${inv.visits_total}` : 'Безліміт'}</div>
            {visitsPct != null && <div className="icard-stat-delta">{visitsPct}% використано</div>}
          </div>
        </div>
      </div>

      <div className="modal-tabs icard-tabs">
        {TABS.map((t) => (
          <button key={t.key} className={`modal-tab-btn ${tab === t.key ? 'active' : ''}`} onClick={() => setTab(t.key)}>{t.label}</button>
        ))}
      </div>

      {tab === 'overview' && (
        <div className="icard-main-grid">
          <div className="icard-main-col">
            <div className="card">
              <div className="card-title">Деталі абонемента</div>
              <div className="icard-detail-list">
                {detailRows.map(([icon, label, value]) => (
                  <div className="icard-detail-item" key={label}>
                    <span className="icard-detail-item-label"><Icon name={icon} size={15} className="icard-detail-item-icon" />{label}</span>
                    <span className="icard-detail-item-value">{value}</span>
                  </div>
                ))}
              </div>
              {infoBox && (
                <div className={`icard-info-box icard-info-box-${infoBox.tone}`}>
                  <span className="icard-info-box-icon"><Icon name={infoBox.icon} size={16} /></span>
                  <div>
                    <div className="icard-info-box-title">{infoBox.title}</div>
                    <div className="icard-info-box-text">{infoBox.text}</div>
                  </div>
                </div>
              )}
              {inv.notes && (
                <div className="icard-detail-item icard-detail-notes">
                  <span className="icard-detail-item-label"><Icon name="fileText" size={15} className="icard-detail-item-icon" />Примітки</span>
                  <span className="icard-detail-item-value">{inv.notes}</span>
                </div>
              )}
            </div>

            {inv.visits_total > 0 && (
              <div className="card">
                <div className="card-title">Прогрес абонемента</div>
                <div className="icard-progress-track"><div className="icard-progress-fill" style={{ width: `${visitsPct}%` }} /></div>
                <div className="icard-progress-summary">
                  <span className="dot" />
                  {inv.visits_used >= inv.visits_total ? 'Ліміт занять вичерпано' : `Ще ${inv.visits_total - inv.visits_used} відвідувань доступно`}
                </div>
                <div className="icard-progress-dots">
                  {Array.from({ length: inv.visits_total }).map((_, i) => {
                    const n = i + 1;
                    const cls = n <= inv.visits_used ? 'done' : (n === inv.visits_used + 1 ? 'next' : '');
                    return <div key={n} className={`icard-progress-dot ${cls}`}>{n}</div>;
                  })}
                </div>
              </div>
            )}

            <div className="card">
              <div className="icard-card-header">
                <div className="card-title" style={{ marginBottom: 0 }}>Останні операції</div>
                {has('payments.create') && inv.status !== 'cancelled' && (
                  <button className="btn btn-primary btn-sm" onClick={openAddPayment}><Icon name="plus" size={13} /> Додати оплату</button>
                )}
              </div>
              <Table columns={paymentColumns} rows={view.payments.slice(0, 5)} emptyMessage="Операцій ще немає" />
              {view.payments.length > 5 && (
                <button className="btn btn-ghost btn-sm" style={{ marginTop: 12 }} onClick={() => setTab('payments')}>
                  Усі платежі ({view.payments.length}) <Icon name="chevronRight" size={12} />
                </button>
              )}
            </div>
          </div>

          <div className="icard-side-col">
            <div className="card">
              <div className="card-title">Інформація</div>
              <div className="icard-info-list">
                {infoRows.map(([icon, label, value]) => (
                  <div className="icard-info-item" key={label}>
                    <span className="icard-info-label"><Icon name={icon} size={14} />{label}</span>
                    <span className="icard-info-value">{value}</span>
                  </div>
                ))}
              </div>
            </div>

            {quickActions.length > 0 && (
              <div className="card">
                <div className="card-title">Швидкі дії</div>
                <div className="icard-qa-list">
                  {quickActions.map((a) => (
                    <button key={a.key} className={`icard-qa-item ${a.highlight ? 'highlight' : ''}`} onClick={a.onClick}>
                      <div className="icard-qa-icon"><Icon name={a.icon} size={15} /></div>
                      <div className="icard-qa-body">
                        <div className="icard-qa-title">{a.title}</div>
                        <div className="icard-qa-desc">{a.desc}</div>
                      </div>
                      <Icon name="chevronRight" size={14} className="icard-qa-arrow" />
                    </button>
                  ))}
                </div>
              </div>
            )}
          </div>
        </div>
      )}

      {tab === 'payments' && (
        <div className="card">
          <div className="icard-card-header">
            <div className="card-title" style={{ marginBottom: 0 }}>Усі платежі</div>
            {has('payments.create') && inv.status !== 'cancelled' && (
              <button className="btn btn-primary btn-sm" onClick={openAddPayment}><Icon name="plus" size={13} /> Додати оплату</button>
            )}
          </div>
          <Table columns={paymentColumns} rows={view.payments} emptyMessage="Платежів ще немає" />
        </div>
      )}

      {tab === 'visits' && (
        <div className="card">
          <div className="card-title">Відвідування за цим абонементом</div>
          <Table columns={visitColumns} rows={visitsTab?.rows || []} loading={!visitsTab || visitsTab.loading} emptyMessage="Відвідувань ще немає" />
        </div>
      )}

      {tab === 'actions' && (
        <div className="card">
          {inv.status === 'cancelled' && !has('invoices.cancel') && <div style={{ fontSize: 13, color: 'var(--text-muted)' }}>Немає доступних дій</div>}
          {inv.status !== 'cancelled' && !has('invoices.sell') && !has('invoices.cancel') && <div style={{ fontSize: 13, color: 'var(--text-muted)' }}>Немає доступних дій</div>}
          {inv.status === 'cancelled' && has('invoices.cancel') && (
            <div style={{ padding: 14, background: 'var(--bg-elevated)', borderRadius: 'var(--radius-sm)' }}>
              <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 8 }}>Відновити абонемент</div>
              <div style={{ fontSize: 12, color: 'var(--text-secondary)', marginBottom: 10 }}>Статус зміниться на «Активний». Дата кінця залишиться незмінною.</div>
              <button className="btn btn-primary btn-sm" onClick={handleRestore}>Відновити</button>
            </div>
          )}
          {has('invoices.cancel') && inv.status !== 'cancelled' && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
              <div style={{ padding: 14, background: 'var(--bg-elevated)', borderRadius: 'var(--radius-sm)' }}>
                {hasFreeze ? (
                  <>
                    <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 4 }}>
                      {isFreezeScheduled ? 'Заплановано заморозку' : 'Заморожено'} на {curFreezeDays} дн.: {formatDate(inv.freeze_start)} — {formatDate(freezeLastDay)}
                    </div>
                    <div style={{ fontSize: 12, color: 'var(--text-secondary)', marginBottom: 10 }}>
                      {isFreezeScheduled
                        ? 'До першого дня заморозки клієнт може відвідувати клуб. «Відмінити заморозку» — прибере її, термін абонементу повернеться до попередньої дати.'
                        : '«Розморозити» — зарахує використані дні (до вчора включно, сьогодні клієнт уже активний) і скоротить термін лише на невикористані. «Відмінити заморозку» — повністю скасує заморозку, ніби її не було.'}
                    </div>
                    <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 12 }}>
                      {!isFreezeScheduled && <button className="btn btn-primary btn-sm" onClick={submitFreeze}>Розморозити</button>}
                      <button className="btn btn-ghost btn-sm" onClick={submitCancelFreeze}>Відмінити заморозку</button>
                    </div>
                    <div style={{ paddingTop: 12, borderTop: '1px solid var(--border)' }}>
                      <div style={{ fontSize: 12, color: 'var(--text-secondary)', marginBottom: 8 }}>
                        Змінити кількість днів заморозки (напр. на прохання клієнта) — термін дії перерахується автоматично{(inv.tariff_freeze_min > 0 || inv.tariff_freeze_max > 0) ? `, дозволено ${freezeDaysMin}–${inv.tariff_freeze_max > 0 ? freezeDaysMax : '∞'} дн.` : ''}.
                      </div>
                      <div style={{ display: 'flex', gap: 10, alignItems: 'flex-end', flexWrap: 'wrap' }}>
                        <FormGroup label="Днів заморозки">
                          <input type="number" min={freezeDaysMin} max={freezeDaysMax} style={{ maxWidth: 80 }} value={freezeDays} onChange={(e) => setFreezeDays(e.target.value)} />
                        </FormGroup>
                        <FormGroup label=" ">
                          <button className="btn btn-ghost btn-sm" onClick={submitUpdateFreezeDays}>Зберегти</button>
                        </FormGroup>
                      </div>
                    </div>
                  </>
                ) : !canFreeze ? (
                  <>
                    <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 8 }}>Заморозити абонемент</div>
                    <div style={{ fontSize: 12, color: 'var(--text-secondary)' }}>
                      Заморозка можлива лише для активного абонементу — не раніше наступного дня після заявки і не пізніше передостаннього дня його дії.
                    </div>
                  </>
                ) : (
                  <>
                    <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 10 }}>Заморозити абонемент</div>
                    <div style={{ display: 'flex', gap: 10, alignItems: 'flex-end', flexWrap: 'wrap' }}>
                      <FormGroup label="Дата початку *">
                        <input type="date" min={freezeMinDate} max={freezeMaxDate} value={freezeStart} onChange={(e) => setFreezeStart(e.target.value)} />
                      </FormGroup>
                      <FormGroup label={`Днів${(inv.tariff_freeze_min > 0 || inv.tariff_freeze_max > 0) ? ` (${freezeDaysMin}–${inv.tariff_freeze_max > 0 ? freezeDaysMax : '∞'})` : ''}`}>
                        <input type="number" min={freezeDaysMin} max={freezeDaysMax} style={{ maxWidth: 80 }} value={freezeDays} onChange={(e) => setFreezeDays(e.target.value)} />
                      </FormGroup>
                      <FormGroup label=" ">
                        <button className="btn btn-primary btn-sm" disabled={!freezeStart || freezeStart < freezeMinDate || freezeStart > freezeMaxDate} onClick={submitFreeze}>Заморозити</button>
                      </FormGroup>
                    </div>
                  </>
                )}
              </div>
              {has('invoices.cancel') && inv.status === 'active' && (
                <div style={{ padding: 14, background: 'rgba(248,113,113,.06)', border: '1px solid rgba(248,113,113,.2)', borderRadius: 'var(--radius-sm)' }}>
                  <div style={{ fontSize: 13, fontWeight: 600, color: 'var(--danger)', marginBottom: 6 }}>Небезпечна зона — дострокове припинення</div>
                  <div style={{ fontSize: 12, color: 'var(--text-secondary)', marginBottom: 10 }}>
                    Використовується лише для дострокового припинення абонемента (напр. порушення клієнтом правил клубу). Кошти за невикористаний період НЕ повертаються автоматично.
                  </div>
                  <button className="btn btn-danger btn-sm" onClick={openCancelConfirm}>🗑 Скасувати абонемент</button>
                </div>
              )}
            </div>
          )}
        </div>
      )}

      {/* Додати оплату */}
      <Modal open={!!addPay} onClose={() => setAddPay(null)} title="Додати оплату">
        {addPay && (
          <>
            {(addPay.method === 'card' || addPay.method === 'terminal') && addPay.cardStep === 'waiting' && (
              <div className="card-pos-box">
                <div className="card-pos-row">
                  <span className="spinner" />
                  <span>Очікуємо оплату на терміналі...</span>
                  <strong className="card-pos-sum">{formatMoney(parseFloat(addPay.amount) || 0)}</strong>
                </div>
                <button className="btn btn-ghost btn-sm" onClick={cancelCardPayment}>Скасувати</button>
              </div>
            )}
            {(addPay.method === 'card' || addPay.method === 'terminal') && addPay.cardStep === 'success' && (
              <div className="card-pos-box card-pos-success">
                <div className="card-pos-row">
                  <Icon name="check" size={15} />
                  <span>Оплату отримано</span>
                  <strong className="card-pos-sum">{formatMoney(parseFloat(addPay.amount) || 0)}</strong>
                </div>
                <div className="card-pos-meta">
                  {PAY_METHOD_LABELS[addPay.method]} · ПриватБанк · Транзакція: {addPay.cardTxn?.id} · {addPay.cardTxn?.time}
                </div>
                <button className="btn btn-primary btn-sm" onClick={submitAddPayment}>Закрити продаж</button>
              </div>
            )}
            {(!(addPay.method === 'card' || addPay.method === 'terminal') || addPay.cardStep === 'idle') && (
              <>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
                  <FormGroup label="Сума *">
                    <input type="number" min="1" step="1" placeholder="0" value={addPay.amount} onChange={(e) => setAddPay({ ...addPay, amount: e.target.value })} />
                  </FormGroup>
                  <FormGroup label="Спосіб">
                    <select value={addPay.method} onChange={(e) => setAddPay({ ...addPay, method: e.target.value })}>
                      <option value="cash">Готівка</option>
                      <option value="card">Карта</option>
                      <option value="terminal">Термінал</option>
                      <option value="deposit">З депозиту</option>
                    </select>
                  </FormGroup>
                </div>
                <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12, color: 'var(--text-muted)', marginTop: 4, marginBottom: 16 }}>
                  <input type="checkbox" checked={addPay.skipFiscal} onChange={(e) => setAddPay({ ...addPay, skipFiscal: e.target.checked })} />
                  Не проводити фіскальний чек для цієї оплати
                </label>
                <div style={{ display: 'flex', gap: 10 }}>
                  {(addPay.method === 'card' || addPay.method === 'terminal') ? (
                    <button className="btn btn-primary" onClick={startCardPayment}>Оплатити карткою</button>
                  ) : (
                    <button className="btn btn-primary" disabled={addPay.submitting} onClick={submitAddPayment}>{addPay.submitting ? 'Збереження...' : 'Записати'}</button>
                  )}
                  <button className="btn btn-ghost" onClick={() => setAddPay(null)}>Скасувати</button>
                </div>
              </>
            )}
          </>
        )}
      </Modal>

      {/* Продовжити абонемент */}
      <Modal
        open={!!renew}
        onClose={() => setRenew(null)}
        title="Продовжити абонемент"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={renew?.submitting} onClick={submitRenew}>{renew?.submitting ? 'Зберігаємо...' : 'Продовжити'}</button>
            <button className="btn btn-ghost" onClick={() => setRenew(null)}>Скасувати</button>
          </div>
        }
      >
        {renew && (
          <>
            {renew.error && <div className="alert alert-error">{renew.error}</div>}
            <FormGroup label="Тариф *">
              <select value={renew.tariffId} onChange={(e) => {
                const t = tariffs.find((x) => String(x.id) === String(e.target.value));
                setRenew({ ...renew, tariffId: e.target.value, trainerId: t?.has_trainer ? renew.trainerId : '' });
              }}>
                <option value="">{tariffs.length ? '— Оберіть тариф —' : 'Тарифів немає'}</option>
                {tariffs.map((t) => (
                  <option key={t.id} value={t.id}>{t.name} · {t.price} грн · {t.duration_days} дн. · {t.visits_limit ? `${t.visits_limit} відвід.` : 'безліміт'}</option>
                ))}
              </select>
              {renewTariff && (
                <div style={{ marginTop: 8, padding: '10px 12px', background: 'var(--bg-elevated)', border: '1px solid var(--border)', borderRadius: 'var(--radius-sm)', fontSize: 13, color: 'var(--text-secondary)' }}>
                  <strong>{renewTariff.name}</strong> · <span style={{ color: 'var(--accent)' }}>{renewTariff.price} грн</span> · {renewTariff.duration_days} дн.
                  {renewTariff.visits_limit ? ` · ${renewTariff.visits_limit} відвід.` : ' · безліміт'}
                  {renewTariff.description && <div style={{ marginTop: 4, fontSize: 12 }}>{renewTariff.description}</div>}
                </div>
              )}
            </FormGroup>
            {renewTariff?.has_trainer && (
              <FormGroup label="Тренер (рекомендований)">
                <select value={renew.trainerId} onChange={(e) => setRenew({ ...renew, trainerId: e.target.value })}>
                  <option value="">— Без тренера —</option>
                  {trainers.map((t) => <option key={t.id} value={t.id}>{t.full_name}</option>)}
                </select>
              </FormGroup>
            )}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Дата початку *">
                <input type="date" value={renew.startDate} onChange={(e) => setRenew({ ...renew, startDate: e.target.value })} />
              </FormGroup>
              <FormGroup label="Знижка (%)">
                <input type="number" min="0" max="100" step="1" value={renew.discount} onChange={(e) => setRenew({ ...renew, discount: e.target.value })} />
              </FormGroup>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Оплата зараз (грн)">
                <input type="number" min="0" step="1" value={renew.paidNow} onChange={(e) => setRenew({ ...renew, paidNow: e.target.value })} />
              </FormGroup>
              <FormGroup label="Спосіб оплати">
                <select value={renew.payMethod} onChange={(e) => setRenew({ ...renew, payMethod: e.target.value })} disabled={!(parseFloat(renew.paidNow) > 0)}>
                  {Object.entries(PAY_METHODS).map(([m, l]) => <option key={m} value={m}>{l}</option>)}
                </select>
              </FormGroup>
            </div>
            {parseFloat(renew.paidNow) > 0 && (
              <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: 'var(--text-secondary)' }}>
                <input type="checkbox" checked={renew.skipFiscal} onChange={(e) => setRenew({ ...renew, skipFiscal: e.target.checked })} />
                Не проводити фіскальний чек для цієї оплати
              </label>
            )}
            <FormGroup label="Примітка">
              <textarea rows="2" placeholder="Необов'язково..." value={renew.notes} onChange={(e) => setRenew({ ...renew, notes: e.target.value })} />
            </FormGroup>

            {renewTariff && (() => {
              const discount = Math.max(0, Math.min(100, parseFloat(renew.discount) || 0));
              const finalPrice = Math.round(renewTariff.price * (1 - discount / 100) * 100) / 100;
              const paidNow = Math.min(finalPrice, parseFloat(renew.paidNow) || 0);
              const debtAfter = Math.max(0, finalPrice - paidNow);
              let endDateLabel = '';
              if (renew.startDate) {
                const d = new Date(renew.startDate);
                d.setDate(d.getDate() + parseInt(renewTariff.duration_days) - 1);
                endDateLabel = d.toLocaleDateString('uk-UA');
              }
              return (
                <div className="sale-summary">
                  <div className="sale-summary-row"><span>Ціна тарифу</span><span>{formatMoney(renewTariff.price)}</span></div>
                  {discount > 0 && (
                    <div className="sale-summary-row"><span>Знижка {discount}%</span><span>−{formatMoney(renewTariff.price - finalPrice)}</span></div>
                  )}
                  <div className="sale-summary-row"><span>Оплата зараз</span><span>{formatMoney(paidNow)}</span></div>
                  {debtAfter > 0 && <div className="sale-summary-row"><span>Залишок боргу</span><span>{formatMoney(debtAfter)}</span></div>}
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
            <button className="btn btn-primary" disabled={editInv?.submitting} onClick={submitEditInv}>{editInv?.submitting ? '...' : 'Зберегти'}</button>
          </div>
        }
      >
        {editInv && (
          <div>
            {editInv.error && <div className="alert alert-error">{editInv.error}</div>}
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
              {editHasTrainer ? (
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
            <p style={{ fontSize: 11, color: 'var(--text-muted)', margin: '10px 0 0' }}>
              ℹ️ Дата закінчення, ціна, ліміт та кількість відвідувань розраховуються системою з тарифу. Статус змінюється лише через дії «Заморозити» / «Скасувати» / «Відновити» у вкладці «Дії».
            </p>
          </div>
        )}
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
