import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import QRCode from 'qrcode';
import AppLayout from '../components/layout/AppLayout';
import Table from '../components/ui/Table';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import CardMenu from '../components/ui/CardMenu';
import ClientFormModal from '../components/ui/ClientFormModal';
import VisitsCalendar from '../components/ui/VisitsCalendar';
import DetailFieldsGrid from '../components/ui/DetailFieldsGrid';
import { usePermissions } from '../hooks/usePermissions';
import { useShiftLock } from '../hooks/useShiftLock';
import { useToast } from '../components/ui/ToastProvider';
import { getClient, getClientActivity } from '../api/clients';
import { getInvoiceTariffs, createInvoice, addInvoicePayment, cancelInvoice, restoreInvoice } from '../api/invoices';
import { addDeposit, getFinanceDeposits, deleteDeposit } from '../api/finance';
import { checkIn, deleteVisitEntry } from '../api/visits';
import { getPayments, deletePayment } from '../api/payments';
import { getSaleOrders, returnSaleOrder, confirmSaleReturn, cancelSaleReturn, confirmCancelSaleReturn } from '../api/sales';
import { getCertificates, cancelCertificateRedemption } from '../api/certificates';
import { generateTelegramLink, unlinkTelegram } from '../api/telegram';
import { getClientHubStatus } from '../api/clientService';
import { formatMoney, formatDate, formatTime, formatRelativeDate, localToday, localDate, getInitials } from '../utils/format';
import './ClientCardPage.css';

const STATUS_MAP = {
  regular: ['info', 'Звичайний'],
  premium: ['owner', 'Преміум'],
  blocked: ['inactive', 'Заблокований'],
};
const INVOICE_STATUS_MAP = {
  future: ['info', 'Майбутній'],
  active: ['active', 'Активний'],
  frozen: ['pending', 'Заморожений'],
  finished: ['inactive', 'Завершений'],
  cancelled: ['inactive', 'Скасований'],
};
const PAYMENT_METHODS = { cash: 'Готівка', card: 'Карта', terminal: 'Термінал', deposit: 'Депозит', transfer: 'Переказ' };
const DEPOSIT_METHODS = ['cash', 'card', 'terminal', 'transfer'];
const ADD_PAYMENT_METHODS = ['cash', 'card', 'terminal', 'deposit', 'transfer'];
const SALE_STATUS_LABELS = { completed: 'Завершено', returned: 'Повернення', pending_return: 'На підтв. повернення', pending_cancel: 'На підтв. скасування' };
const SALE_STATUS_VARIANTS = { completed: 'active', returned: 'inactive', pending_return: 'pending', pending_cancel: 'pending' };
const ACTIVITY_LABELS = { visit: 'Відвідування', payment: 'Оплата', sale: 'Продаж' };
const DEPOSIT_OPERATION_LABELS = {
  top_up: 'Поповнення', pay_invoice: 'Оплата абонемента', pay_product: 'Оплата товару',
  refund: 'Повернення коштів', correction: 'Коригування', certificate: 'Сертифікат',
};
const CERT_SALE_STATUS_LABELS = { redeemed: 'Активовано', cancelled: 'Скасовано' };
const CERT_SALE_STATUS_VARIANTS = { redeemed: 'active', cancelled: 'inactive' };
const DRIVE_HUB_URL = 'https://ds-hub.pp.ua/';
const DRIVE_HUB_BOT_URL = 'https://t.me/DriveSportHub_bot';

const TABS = [
  { key: 'main', label: 'Основне' },
  { key: 'invoices', label: 'Абонементи' },
  { key: 'visits', label: 'Відвідування' },
  { key: 'payments', label: 'Оплати', permission: 'payments.view' },
  { key: 'sales', label: 'Продажі', permission: 'sales.view' },
  { key: 'deposits', label: 'Депозити', permission: 'finance.manage' },
  { key: 'certificates', label: 'Сертифікати', permission: 'certificates.view' },
  { key: 'app', label: 'Додаток' },
];

function activityDescription(a) {
  if (a.type === 'visit') return a.notes || 'Тренування';
  if (a.type === 'payment') {
    const method = PAYMENT_METHODS[a.payment_method] || a.payment_method;
    return a.tariff_name ? `Оплата абонемента «${a.tariff_name}» (${method})` : `Оплата (${method})`;
  }
  if (a.type === 'sale') return a.notes || 'Продаж товару';
  return '—';
}

function currentInvoiceOf(invoices) {
  const today = localToday();
  const active = invoices.filter((i) => i.status === 'active' && i.start_date <= today && i.end_date >= today);
  if (active.length === 0) return null;
  return active.sort((a, b) => b.end_date.localeCompare(a.end_date))[0];
}

export default function ClientCardPage() {
  const { id } = useParams();
  const clientId = Number(id);
  const { has, isOwner } = usePermissions();
  const { guard, lockedProps } = useShiftLock();
  const toast = useToast();

  const [view, setView] = useState(null); // { loading, notFound, client, totalDebt, invoices, visits }
  const [activity, setActivity] = useState(null);
  const [hubStatus, setHubStatus] = useState(null);
  const [tab, setTab] = useState('main');

  const [payTab, setPayTab] = useState(null); // { loading, rows }
  const [saleTab, setSaleTab] = useState(null);
  const [depositTab, setDepositTab] = useState(null);
  const [certTab, setCertTab] = useState(null);

  const [editOpen, setEditOpen] = useState(false);
  const [sell, setSell] = useState(null);
  const [addPay, setAddPay] = useState(null);
  const [deposit, setDeposit] = useState(null);
  const [checkin, setCheckin] = useState(null);
  const [tgLink, setTgLink] = useState(null);

  const [visitDeleteConfirm, setVisitDeleteConfirm] = useState(null); // { id, paidAmount, trainerName, submitting, error }
  const [invoiceCancelConfirm, setInvoiceCancelConfirm] = useState(null); // { invoice, reason, acknowledged, submitting, error }
  const [saleReturnModal, setSaleReturnModal] = useState(null); // { order, reason, refundMethod, refundLocation, submitting, error }
  const [saleCancelReturnModal, setSaleCancelReturnModal] = useState(null); // { order, reason, submitting, error }
  const [certCancelConfirm, setCertCancelConfirm] = useState(null); // { sale, reason }

  const load = useCallback(async () => {
    setView({ loading: true });
    const res = await getClient(clientId);
    if (!res.success) {
      toast(res.error, 'error');
      setView({ loading: false, notFound: true });
      return;
    }
    setView({ loading: false, client: res.client, totalDebt: res.total_debt, invoices: res.invoices, visits: res.visits });
    setHubStatus({ loading: true });
    getClientHubStatus(clientId).then((r) => setHubStatus(r.success ? { loading: false, ...r } : { loading: false, hub_connected: false }));
    setActivity(null);
    getClientActivity(clientId).then((r) => setActivity(r.success ? r.activity : []));
  }, [clientId, toast]);

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    if (tab === 'payments' && !payTab && has('payments.view')) {
      setPayTab({ loading: true, rows: [] });
      getPayments({ client_id: clientId, date_from: '2000-01-01', date_to: localToday(), page: 1 }).then((r) => {
        setPayTab({ loading: false, rows: r.success ? r.payments : [] });
      });
    }
    if (tab === 'sales' && !saleTab && has('sales.view')) {
      setSaleTab({ loading: true, rows: [] });
      getSaleOrders({ client_id: clientId }).then((r) => {
        setSaleTab({ loading: false, rows: r.success ? r.orders : [] });
      });
    }
    if (tab === 'deposits' && !depositTab && has('finance.manage')) {
      setDepositTab({ loading: true, rows: [] });
      getFinanceDeposits({ client_id: clientId, date_from: '2000-01-01', date_to: localToday(), page: 1 }).then((r) => {
        setDepositTab({ loading: false, rows: r.success ? r.deposits : [] });
      });
    }
    if (tab === 'certificates' && !certTab && has('certificates.view')) {
      setCertTab({ loading: true, rows: [] });
      getCertificates({ client_id: clientId }).then((r) => {
        setCertTab({ loading: false, rows: r.success ? r.certificates : [] });
      });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab, clientId]);

  function reloadDeposits() {
    setDepositTab({ loading: true, rows: [] });
    getFinanceDeposits({ client_id: clientId, date_from: '2000-01-01', date_to: localToday(), page: 1 }).then((r) => {
      setDepositTab({ loading: false, rows: r.success ? r.deposits : [] });
    });
  }

  function reloadCertificates() {
    setCertTab({ loading: true, rows: [] });
    getCertificates({ client_id: clientId }).then((r) => {
      setCertTab({ loading: false, rows: r.success ? r.certificates : [] });
    });
  }

  function reloadSales() {
    setSaleTab({ loading: true, rows: [] });
    getSaleOrders({ client_id: clientId }).then((r) => {
      setSaleTab({ loading: false, rows: r.success ? r.orders : [] });
    });
  }

  async function openTelegramLink() {
    setTgLink({ loading: true, link: '', qrDataUrl: '' });
    const res = await generateTelegramLink(clientId);
    if (!res.success) {
      toast(res.error, 'error');
      setTgLink(null);
      return;
    }
    const qrDataUrl = await QRCode.toDataURL(res.link, { width: 220, margin: 1 });
    setTgLink({ loading: false, link: res.link, qrDataUrl });
  }

  async function handleUnlinkTelegram() {
    if (!window.confirm("Відв'язати Telegram у цього клієнта?")) return;
    const res = await unlinkTelegram(clientId);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast("Telegram відв'язано", 'success');
    load();
  }

  async function openSell() {
    setSell({ tariffs: null, tariffId: '', startDate: localToday(), discount: 0, notes: '', error: '', submitting: false });
    const res = await getInvoiceTariffs();
    setSell((s) => (s ? { ...s, tariffs: res.tariffs || [] } : s));
  }

  async function submitSell() {
    if (!sell.tariffId) {
      setSell({ ...sell, error: 'Оберіть тариф' });
      return;
    }
    setSell({ ...sell, submitting: true, error: '' });
    const res = await createInvoice({
      client_id: clientId,
      tariff_id: sell.tariffId,
      start_date: sell.startDate,
      discount: parseFloat(sell.discount) || 0,
      paid_amount: 0,
      notes: sell.notes.trim(),
    });
    if (!res.success) {
      setSell({ ...sell, submitting: false, error: res.error });
      return;
    }
    toast('Абонемент продано', 'success');
    setSell(null);
    load();
  }

  function openAddPayment() {
    setAddPay({ invoiceId: '', amount: '', method: 'cash', submitting: false });
  }

  async function submitAddPayment() {
    const invoiceId = parseInt(addPay.invoiceId);
    const amount = parseFloat(addPay.amount);
    if (!invoiceId) { toast('Оберіть абонемент', 'error'); return; }
    if (!amount || amount <= 0) { toast('Введіть суму', 'error'); return; }

    setAddPay({ ...addPay, submitting: true });
    const res = await addInvoicePayment({ invoice_id: invoiceId, amount, payment_method: addPay.method });
    if (!res.success) {
      setAddPay({ ...addPay, submitting: false });
      toast(res.error, 'error');
      return;
    }
    toast('Платіж записано', 'success');
    setAddPay(null);
    load();
  }

  function openDeposit() {
    setDeposit({ amount: '', method: 'cash', submitting: false });
  }

  async function submitDeposit() {
    const amount = parseFloat(deposit.amount);
    if (!amount || amount <= 0) { toast('Введіть суму', 'error'); return; }

    setDeposit({ ...deposit, submitting: true });
    const res = await addDeposit({ client_id: clientId, amount, payment_method: deposit.method });
    if (!res.success) {
      setDeposit({ ...deposit, submitting: false });
      toast(res.error, 'error');
      return;
    }
    toast('Депозит поповнено', 'success');
    setDeposit(null);
    load();
  }

  function openCheckin() {
    const today = localToday();
    const alreadyToday = view.visits.find((v) => v.visited_at.slice(0, 10) === today) || null;
    setCheckin({ notes: '', submitting: false, alreadyToday });
  }

  async function submitCheckin() {
    setCheckin({ ...checkin, submitting: true });
    const res = await checkIn({ client_id: clientId, notes: checkin.notes.trim() });
    if (!res.success) {
      setCheckin({ ...checkin, submitting: false });
      toast(res.error, 'error');
      return;
    }
    toast('Відвідування записано', 'success');
    setCheckin(null);
    load();
  }

  // ── Видалення оплати (власник) ──────────────────────────────
  async function handleDeletePayment(id) {
    if (!window.confirm('Видалити цю оплату?')) return;
    const res = await deletePayment(id);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast('Оплату видалено', 'success');
    setPayTab(null);
    load();
  }

  // ── Видалення відвідування (власник, з поверненням виплати тренеру) ──
  async function handleDeleteVisit(id) {
    if (!window.confirm('Видалити це відвідування?')) return;
    const res = await deleteVisitEntry(id);
    if (!res.success) {
      if (res.requires_confirmation) {
        setVisitDeleteConfirm({ id, paidAmount: res.paid_amount, trainerName: res.trainer_name, submitting: false, error: '' });
        return;
      }
      toast(res.error || 'Помилка', 'error');
      return;
    }
    toast('Відмітку видалено', 'success');
    load();
  }

  async function confirmDeleteVisitReversal() {
    setVisitDeleteConfirm((s) => ({ ...s, submitting: true, error: '' }));
    const res = await deleteVisitEntry(visitDeleteConfirm.id, true);
    if (!res.success) { setVisitDeleteConfirm((s) => ({ ...s, submitting: false, error: res.error })); return; }
    toast('Відмітку видалено, кошти повернено', 'success');
    setVisitDeleteConfirm(null);
    load();
  }

  // ── Скасування / відновлення абонемента (власник) ───────────
  function openInvoiceCancel(invoice) {
    setInvoiceCancelConfirm({ invoice, reason: '', acknowledged: false, submitting: false, error: '' });
  }

  async function submitInvoiceCancel() {
    if (!invoiceCancelConfirm.reason.trim()) {
      setInvoiceCancelConfirm({ ...invoiceCancelConfirm, error: 'Вкажіть причину дострокового скасування' });
      return;
    }
    if (!invoiceCancelConfirm.acknowledged) {
      setInvoiceCancelConfirm({ ...invoiceCancelConfirm, error: 'Підтвердіть, що клієнта попереджено' });
      return;
    }
    setInvoiceCancelConfirm({ ...invoiceCancelConfirm, submitting: true, error: '' });
    const res = await cancelInvoice(invoiceCancelConfirm.invoice.id, invoiceCancelConfirm.reason.trim());
    if (!res.success) { setInvoiceCancelConfirm({ ...invoiceCancelConfirm, submitting: false, error: res.error }); return; }
    toast('Абонемент скасовано', 'success');
    setInvoiceCancelConfirm(null);
    load();
  }

  async function handleRestoreInvoice(invoice) {
    if (!window.confirm(`Відновити абонемент «${invoice.tariff_name}»?`)) return;
    const res = await restoreInvoice(invoice.id);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast('Абонемент відновлено', 'success');
    load();
  }

  // ── Продажі: повернення / скасування повернення (власник) ───
  function openSaleReturn(order) {
    setSaleReturnModal({ order, reason: '', refundMethod: 'other', refundLocation: 'register', submitting: false, error: '' });
  }

  async function submitSaleReturn() {
    setSaleReturnModal({ ...saleReturnModal, submitting: true, error: '' });
    const res = await returnSaleOrder({
      id: saleReturnModal.order.id,
      reason: saleReturnModal.reason.trim(),
      refund_method: saleReturnModal.refundMethod,
      refund_location: saleReturnModal.refundLocation,
    });
    if (!res.success) { setSaleReturnModal({ ...saleReturnModal, submitting: false, error: res.error || 'Невідома помилка' }); return; }
    toast(res.message || 'Повернення оформлено', 'success');
    setSaleReturnModal(null);
    reloadSales();
  }

  async function handleConfirmSaleReturn(order) {
    const res = await confirmSaleReturn(order.id);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message || 'Повернення підтверджено', 'success');
    reloadSales();
  }

  function openSaleCancelReturn(order) {
    setSaleCancelReturnModal({ order, reason: '', submitting: false, error: '' });
  }

  async function submitSaleCancelReturn() {
    setSaleCancelReturnModal({ ...saleCancelReturnModal, submitting: true, error: '' });
    const res = await cancelSaleReturn(saleCancelReturnModal.order.id, saleCancelReturnModal.reason.trim());
    if (!res.success) { setSaleCancelReturnModal({ ...saleCancelReturnModal, submitting: false, error: res.error || 'Невідома помилка' }); return; }
    toast(res.message || 'Скасування оформлено', 'success');
    setSaleCancelReturnModal(null);
    reloadSales();
  }

  async function handleConfirmSaleCancelReturn(order) {
    const res = await confirmCancelSaleReturn(order.id);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message || 'Скасування підтверджено', 'success');
    reloadSales();
  }

  // ── Депозити: видалення (власник — будь-який запис) ─────────
  async function handleDeleteDeposit(d) {
    if (!window.confirm('Видалити цей запис депозиту?')) return;
    const res = await deleteDeposit(d.id);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast('Депозит видалено', 'success');
    reloadDeposits();
    load();
  }

  // ── Сертифікати: скасування вже активованого (власник) ──────
  function openCertCancel(sale) {
    setCertCancelConfirm({ sale, reason: '' });
  }

  async function submitCertCancel() {
    const res = await cancelCertificateRedemption({ sale_id: certCancelConfirm.sale.sale_id, reason: certCancelConfirm.reason.trim() });
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message || 'Активацію скасовано', 'success');
    setCertCancelConfirm(null);
    reloadCertificates();
    load();
  }

  if (!view || view.loading) {
    return (
      <AppLayout title="Клієнт">
        <div className="loader"><span className="spinner" /> Завантаження...</div>
      </AppLayout>
    );
  }

  if (view.notFound || !view.client) {
    return (
      <AppLayout title="Клієнт">
        <div className="empty-state"><p>Клієнта не знайдено</p></div>
      </AppLayout>
    );
  }

  const c = view.client;
  const statusVariant = STATUS_MAP[c.status]?.[0] || 'info';
  const statusLabel = STATUS_MAP[c.status]?.[1] || c.status;
  const isFrozenNow = view.invoices.some((i) => i.status === 'frozen');
  const isBlocked = c.status === 'blocked';
  const curInvoice = currentInvoiceOf(view.invoices);
  const daysLeft = curInvoice ? Math.ceil((new Date(curInvoice.end_date) - new Date()) / 86400000) : null;
  const lastVisit = view.visits[0] || null;

  const visibleTabs = TABS.filter((t) => !t.permission || has(t.permission));

  const appPanel = (
    <div className="card client-app-panel">
      <div className="client-app-panel-header">
        <div className="card-title" style={{ marginBottom: 0, display: 'flex', alignItems: 'center', gap: 8 }}>
          <Icon name="smartphone" size={16} /> Додаток
        </div>
        {!hubStatus?.loading && hubStatus?.hub_connected && (
          <Badge variant={hubStatus?.is_app_user ? 'active' : 'inactive'}>
            {hubStatus?.is_app_user ? '🟢 Підключено' : '— Не підключено'}
          </Badge>
        )}
      </div>

      {hubStatus?.loading && <div className="loader"><span className="spinner" /></div>}

      {!hubStatus?.loading && !hubStatus?.hub_connected && (
        <>
          <div className="client-app-panel-icon"><Icon name="smartphone" size={28} /></div>
          <div className="client-app-panel-title">Додаток не підключено</div>
          <div className="client-app-panel-subtitle">Клієнт поки що не використовує мобільний застосунок клубу</div>
          {has('telegram.manage') && (
            <button
              className="btn btn-primary"
              style={{ width: '100%', justifyContent: 'center', marginTop: 12 }}
              onClick={() => {
                navigator.clipboard?.writeText(`Привіт! Керуй тренуваннями та абонементом у застосунку DRIVE SPORT HUB: ${DRIVE_HUB_URL}`);
                toast('Текст запрошення скопійовано', 'success');
              }}
            >
              <Icon name="send" size={14} /> Надіслати запрошення
            </button>
          )}
          <ul className="client-app-panel-features">
            <li><Icon name="check" size={13} /> Зручний вхід у клуб</li>
            <li><Icon name="check" size={13} /> Перегляд розкладу</li>
            <li><Icon name="check" size={13} /> Запис на тренування</li>
            <li><Icon name="check" size={13} /> Особистий кабінет</li>
          </ul>
        </>
      )}

      {!hubStatus?.loading && hubStatus?.hub_connected && (
        <>
          <div style={{ fontSize: 12, color: 'var(--text-secondary)', display: 'grid', gap: 4, marginBottom: 12 }}>
            <div>Акаунт застосунку: {hubStatus.is_app_user ? '✅ Підключено' : '— Не підключено'}</div>
            {hubStatus.is_app_user && <div>Telegram застосунку: {hubStatus.has_hub_telegram ? '✅ Підключено' : '— Не підключено'}</div>}
          </div>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <a className="btn btn-ghost btn-sm" href={DRIVE_HUB_URL} target="_blank" rel="noreferrer">Відкрити застосунок</a>
            <a className="btn btn-ghost btn-sm" href={DRIVE_HUB_BOT_URL} target="_blank" rel="noreferrer">Telegram</a>
          </div>
        </>
      )}

      {has('telegram.manage') && (
        <div style={{ marginTop: 14, paddingTop: 14, borderTop: '1px solid var(--border)' }}>
          {c.telegram_id
            ? <button className="btn btn-ghost btn-sm" style={{ width: '100%', justifyContent: 'center' }} onClick={handleUnlinkTelegram}>📲 Відв'язати Telegram</button>
            : <button className="btn btn-ghost btn-sm" style={{ width: '100%', justifyContent: 'center' }} onClick={openTelegramLink}>📲 Прив'язати Telegram</button>}
        </div>
      )}
    </div>
  );

  return (
    <AppLayout title="Клієнти">
      <div className="ccard-breadcrumb">
        <Link to="/clients">Клієнти</Link>
        <Icon name="chevronRight" size={14} />
        <span>{c.full_name}</span>
      </div>

      <div className="ccard-header">
        <div className="ccard-header-main">
          <div className="ccard-avatar">{getInitials(c.full_name)}</div>
          <div>
            <div className="ccard-name-row">
              <span className="ccard-name">{c.full_name}</span>
              <Badge variant={statusVariant}>{statusLabel}</Badge>
              {isFrozenNow && <Badge variant="pending">🧊 Абонемент заморожено</Badge>}
            </div>
            <div className="ccard-contact-row">
              {c.phone && <span><Icon name="phone" size={13} /> {c.phone}</span>}
              {c.email && <span><Icon name="mail" size={13} /> {c.email}</span>}
              {c.birthday && <span><Icon name="calendar" size={13} /> {formatDate(c.birthday)}</span>}
            </div>
            {isBlocked && (c.status_reason || c.status_changed_at) && (
              <div className="ccard-blocked-note">
                {c.status_reason && <>Причина: {c.status_reason}. </>}
                {c.status_changed_at && <>{formatRelativeDate(c.status_changed_at)}</>}
              </div>
            )}
          </div>
        </div>
        <div className="ccard-header-actions">
          {has('invoices.sell') && !isBlocked && <button className="btn btn-primary" {...lockedProps} onClick={guard(openSell)}><Icon name="fileText" size={15} /> Продаж</button>}
          {has('visits.checkin') && !isBlocked && <button className="btn btn-ghost" onClick={openCheckin}><Icon name="calendar" size={15} /> Додати відвідування</button>}
          {has('payments.create') && <button className="btn btn-success" onClick={openAddPayment}><Icon name="card" size={15} /> Оплата</button>}
          {has('finance.manage') && <button className="btn btn-ghost" onClick={openDeposit}><Icon name="wallet" size={15} /> Депозит</button>}
          {has('clients.edit') && <button className="btn btn-ghost" onClick={() => setEditOpen(true)}><Icon name="edit" size={15} /> Редагувати</button>}
        </div>
      </div>

      {isBlocked && (
        <div className="alert alert-error" style={{ marginBottom: 20 }}>
          Клієнт заблокований — продаж абонементів/товарів і відвідування недоступні. Платежі та поповнення депозиту дозволені.
        </div>
      )}

      <div className="stats-grid ccard-stats">
        <div className="stat-card">
          <div className="stat-label">Поточний абонемент</div>
          <div className="stat-value" style={{ fontSize: 18 }}>{curInvoice?.tariff_name || '—'}</div>
          {curInvoice && <div className="stat-delta">{curInvoice.visits_total ? `${curInvoice.visits_used}/${curInvoice.visits_total} занять` : 'Безліміт занять'}</div>}
        </div>
        <div className="stat-card">
          <div className="stat-label">Закінчується</div>
          <div className="stat-value" style={{ fontSize: 18 }}>{curInvoice ? formatDate(curInvoice.end_date) : '—'}</div>
          {curInvoice && <div className={`stat-delta ${daysLeft <= 7 ? 'down' : ''}`}>{daysLeft <= 0 ? 'Прострочено' : `через ${daysLeft} дн.`}</div>}
        </div>
        <div className="stat-card">
          <div className="stat-label">Останнє відвідування</div>
          <div className="stat-value" style={{ fontSize: 18 }}>{lastVisit ? formatDate(lastVisit.visited_at) : '—'}</div>
          {lastVisit && <div className="stat-delta">{formatTime(lastVisit.visited_at)}</div>}
        </div>
        <div className="stat-card">
          <div className="stat-label">Заборгованість</div>
          <div className="stat-value" style={{ fontSize: 18, color: view.totalDebt > 0.01 ? 'var(--danger)' : 'var(--success)' }}>
            {view.totalDebt > 0.01 ? formatMoney(view.totalDebt) : '0 грн'}
          </div>
          <div className={`stat-delta ${view.totalDebt > 0.01 ? 'down' : ''}`}>{view.totalDebt > 0.01 ? 'Є заборгованість' : 'Немає боргу'}</div>
        </div>
      </div>

      <div className="modal-tabs ccard-tabs">
        {visibleTabs.map((t) => (
          <button key={t.key} className={`modal-tab-btn ${tab === t.key ? 'active' : ''}`} onClick={() => setTab(t.key)}>{t.label}</button>
        ))}
      </div>

      {tab === 'main' && (
        <div className="ccard-main-grid">
          <div className="ccard-main-col">
            <div className="card" style={{ marginBottom: 20 }}>
              <div className="card-title"><Icon name="fileText" size={15} style={{ marginRight: 6, verticalAlign: -2 }} />Поточний абонемент</div>
              {curInvoice ? (
                <DetailFieldsGrid fields={[
                  ['Тариф', <span key="tariff">{curInvoice.tariff_name} <Badge variant="active">Активний</Badge></span>],
                  ['Занять', curInvoice.visits_total ? `${curInvoice.visits_used}/${curInvoice.visits_total}` : 'Безліміт'],
                  ['Закінчується', formatDate(curInvoice.end_date)],
                  ['Останнє відвідування', lastVisit ? `${formatDate(lastVisit.visited_at)} ${formatTime(lastVisit.visited_at)}` : '—'],
                  ['Баланс депозиту', parseFloat(c.balance || 0) !== 0 ? formatMoney(c.balance) : '—'],
                  ['Борг за абонемент', view.totalDebt > 0.01 ? <span key="debt" style={{ color: 'var(--danger)' }}>{formatMoney(view.totalDebt)}</span> : '0 грн'],
                ]} />
              ) : (
                <div className="empty-state"><div className="icon">📄</div><p>Активного абонемента немає</p></div>
              )}
            </div>

            <div className="card">
              <div className="card-title"><Icon name="clock" size={15} style={{ marginRight: 6, verticalAlign: -2 }} />Останні операції</div>
              <Table
                columns={[
                  { key: 'date', label: 'Дата', render: (a) => <span style={{ fontSize: 13, whiteSpace: 'nowrap' }}>{formatDate(a.event_at)} {formatTime(a.event_at)}</span> },
                  { key: 'type', label: 'Тип', cardTop: true, render: (a) => <Badge variant={a.type === 'payment' ? 'active' : a.type === 'sale' ? 'info' : 'pending'}>{ACTIVITY_LABELS[a.type] || a.type}</Badge> },
                  { key: 'desc', label: 'Опис', render: (a) => <span style={{ fontSize: 13 }}>{activityDescription(a)}</span> },
                  { key: 'amount', label: 'Сума', mobile: 'trailing', render: (a) => a.amount != null ? <span style={{ fontWeight: 600 }}>{formatMoney(a.amount)}</span> : <span className="text-muted">—</span> },
                ]}
                rows={activity || []}
                loading={activity === null}
                emptyMessage="Операцій ще немає"
              />
            </div>
          </div>

          <div className="ccard-side-col">
            {appPanel}
          </div>
        </div>
      )}

      {tab === 'invoices' && (
        <div className="card">
          {view.invoices.length === 0 && <div className="empty-state"><div className="icon">📄</div><p>Абонементів ще немає</p></div>}
          {view.invoices.map((inv) => {
            const [variant, label] = INVOICE_STATUS_MAP[inv.status] || ['inactive', inv.status];
            const debt = parseFloat(inv.debt || 0);
            return (
              <div key={inv.id} className="ccard-invoice-row" style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <Link to={`/invoices/${inv.id}`} style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    <div>
                      <div style={{ fontWeight: 500, fontSize: 14 }}>{inv.tariff_name}</div>
                      <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>{formatDate(inv.start_date)} — {formatDate(inv.end_date)}</div>
                    </div>
                    <div style={{ textAlign: 'right' }}>
                      <div style={{ fontSize: 14, fontWeight: 500 }}>{formatMoney(inv.price)}</div>
                      {debt > 0.01 && <div style={{ fontSize: 12, color: 'var(--danger)' }}>борг {formatMoney(debt)}</div>}
                      <Badge variant={variant}>{label}</Badge>
                    </div>
                  </div>
                </Link>
                {has('invoices.cancel') && inv.status === 'active' && (
                  <button
                    className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }}
                    title="Скасувати абонемент" onClick={() => openInvoiceCancel(inv)}
                  >
                    <Icon name="trash" size={14} />
                  </button>
                )}
                {has('invoices.cancel') && inv.status === 'cancelled' && (
                  <button className="btn btn-ghost btn-sm" title="Відновити абонемент" onClick={() => handleRestoreInvoice(inv)}>
                    <Icon name="refresh" size={14} />
                  </button>
                )}
              </div>
            );
          })}
        </div>
      )}

      {tab === 'visits' && (
        <div className="card">
          {view.visits.length === 0
            ? <div className="empty-state"><div className="icon">📅</div><p>Відвідувань ще немає</p></div>
            : <VisitsCalendar visits={view.visits} onDelete={has('visits.delete') ? handleDeleteVisit : undefined} />}
        </div>
      )}

      {tab === 'payments' && (
        <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
          <Table
            columns={[
              { key: 'date', label: 'Дата', render: (p) => <span style={{ fontSize: 13, whiteSpace: 'nowrap' }}>{formatDate(p.created_at)} {formatTime(p.created_at)}</span> },
              { key: 'tariff', label: 'Абонемент', render: (p) => <span style={{ fontSize: 13 }}>{p.tariff_name || '—'}</span> },
              { key: 'method', label: 'Спосіб', cardTop: true, render: (p) => <Badge variant="info">{PAYMENT_METHODS[p.payment_method] || p.payment_method}</Badge> },
              { key: 'admin', label: 'Менеджер', render: (p) => <span style={{ fontSize: 13, color: 'var(--text-secondary)' }}>{p.admin_name || '—'}</span> },
              { key: 'amount', label: 'Сума', mobile: 'trailing', render: (p) => <span style={{ fontWeight: 600, color: 'var(--success)' }}>{formatMoney(p.amount)}</span> },
              {
                key: 'actions', label: '',
                render: (p) => has('payments.delete') && (
                  <button
                    className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }}
                    onClick={(e) => { e.stopPropagation(); handleDeletePayment(p.id); }}
                  >🗑</button>
                ),
              },
            ]}
            rows={payTab?.rows || []}
            loading={!payTab || payTab.loading}
            emptyMessage="Оплат ще немає"
          />
        </div>
      )}

      {tab === 'sales' && (
        <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
          <Table
            columns={[
              { key: 'date', label: 'Дата', render: (o) => <span style={{ fontSize: 13, whiteSpace: 'nowrap' }}>{formatDate(o.created_at)} {formatTime(o.created_at)}</span> },
              { key: 'order', label: 'Чек', render: (o) => <span style={{ fontSize: 13 }}>№{o.order_number}{o.first_product_name ? ` · ${o.first_product_name}` : ''}{o.items_count > 1 ? ` +${o.items_count - 1}` : ''}</span> },
              { key: 'status', label: 'Статус', cardTop: true, render: (o) => <Badge variant={SALE_STATUS_VARIANTS[o.status] || 'info'}>{SALE_STATUS_LABELS[o.status] || o.status}</Badge> },
              { key: 'amount', label: 'Сума', mobile: 'trailing', render: (o) => <span style={{ fontWeight: 600 }}>{formatMoney(o.total_amount)}</span> },
              {
                key: 'actions', label: '',
                render: (o) => (
                  <div onClick={(e) => e.stopPropagation()}>
                    <CardMenu
                      actions={[
                        o.status === 'completed' && has('sales.return')
                          ? { label: 'Повернути', icon: 'undo', danger: true, onClick: () => openSaleReturn(o) }
                          : null,
                        o.status === 'pending_return' && has('sales.return_confirm')
                          ? { label: 'Підтвердити повернення', icon: 'check', onClick: () => handleConfirmSaleReturn(o) }
                          : null,
                        o.status === 'returned' && has('sales.return_cancel')
                          ? { label: 'Скасувати повернення', icon: 'undo', onClick: () => openSaleCancelReturn(o) }
                          : null,
                        o.status === 'pending_cancel' && has('sales.return_cancel_confirm')
                          ? { label: 'Підтвердити скасування', icon: 'check', onClick: () => handleConfirmSaleCancelReturn(o) }
                          : null,
                      ]}
                    />
                  </div>
                ),
              },
            ]}
            rows={saleTab?.rows || []}
            loading={!saleTab || saleTab.loading}
            emptyMessage="Продажів ще немає"
          />
        </div>
      )}

      {tab === 'deposits' && (
        <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
          <Table
            columns={[
              { key: 'date', label: 'Дата', render: (d) => <span style={{ fontSize: 13, whiteSpace: 'nowrap' }}>{formatDate(d.created_at)} {formatTime(d.created_at)}</span> },
              { key: 'operation', label: 'Операція', cardTop: true, render: (d) => <Badge variant={parseFloat(d.amount) >= 0 ? 'active' : 'inactive'}>{d.operation === 'correction' && parseFloat(d.amount) < 0 ? 'Списання' : (DEPOSIT_OPERATION_LABELS[d.operation] || d.operation)}</Badge> },
              { key: 'method', label: 'Спосіб', render: (d) => <span style={{ fontSize: 13 }}>{PAYMENT_METHODS[d.payment_method] || d.payment_method || '—'}</span> },
              { key: 'admin', label: 'Менеджер', render: (d) => <span style={{ fontSize: 13, color: 'var(--text-secondary)' }}>{d.admin_name || '—'}</span> },
              { key: 'notes', label: 'Нотатки', render: (d) => <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>{d.notes || '—'}</span> },
              {
                key: 'amount', label: 'Сума', mobile: 'trailing',
                render: (d) => <span style={{ fontWeight: 600, color: parseFloat(d.amount) >= 0 ? 'var(--success)' : 'var(--danger)' }}>{formatMoney(d.amount)}</span>,
              },
              {
                key: 'actions', label: '',
                render: (d) => {
                  const canDeleteThis = isOwner || (d.operation === 'top_up' && localDate(d.created_at) === localToday());
                  return canDeleteThis && has('finance.manage') && (
                    <button className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }} onClick={() => handleDeleteDeposit(d)}>🗑</button>
                  );
                },
              },
            ]}
            rows={depositTab?.rows || []}
            loading={!depositTab || depositTab.loading}
            emptyMessage="Депозитних операцій ще немає"
          />
        </div>
      )}

      {tab === 'certificates' && (
        <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
          <Table
            columns={[
              { key: 'code', label: 'Код', render: (c) => <span style={{ fontSize: 13, fontWeight: 500 }}>{c.code}</span> },
              { key: 'date', label: 'Активовано', render: (c) => <span style={{ fontSize: 13, whiteSpace: 'nowrap' }}>{c.redeemed_at ? `${formatDate(c.redeemed_at)} ${formatTime(c.redeemed_at)}` : '—'}</span> },
              { key: 'status', label: 'Статус', cardTop: true, render: (c) => <Badge variant={CERT_SALE_STATUS_VARIANTS[c.sale_status] || 'info'}>{CERT_SALE_STATUS_LABELS[c.sale_status] || c.sale_status}</Badge> },
              { key: 'admin', label: 'Адміністратор', render: (c) => <span style={{ fontSize: 13, color: 'var(--text-secondary)' }}>{c.redeemed_admin_name || '—'}</span> },
              { key: 'amount', label: 'Сума', mobile: 'trailing', render: (c) => <span style={{ fontWeight: 600 }}>{formatMoney(c.amount)}</span> },
              {
                key: 'actions', label: '',
                render: (c) => c.sale_status === 'redeemed' && has('certificates.cancel') && (
                  <button className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }} onClick={() => openCertCancel(c)}>🗑</button>
                ),
              },
            ]}
            rows={certTab?.rows || []}
            loading={!certTab || certTab.loading}
            emptyMessage="Активованих сертифікатів ще немає"
          />
        </div>
      )}

      {tab === 'app' && (
        <div className="ccard-app-tab">
          {appPanel}
        </div>
      )}

      {/* Редагування клієнта */}
      <ClientFormModal clientId={clientId} open={editOpen} onClose={() => setEditOpen(false)} onSaved={(msg) => { toast(msg, 'success'); load(); }} />

      {/* Прив'язка Telegram — посилання + QR */}
      <Modal size="sm" open={!!tgLink} onClose={() => setTgLink(null)} title="Прив'язка Telegram">
        {tgLink?.loading && <div className="loader"><span className="spinner" /> Створення посилання...</div>}
        {tgLink && !tgLink.loading && (
          <div style={{ textAlign: 'center' }}>
            <p style={{ color: 'var(--text-muted)', fontSize: 13, marginBottom: 16 }}>
              Покажіть QR-код клієнту або надішліть посилання — дійсне 24 год.
            </p>
            {tgLink.qrDataUrl && <img src={tgLink.qrDataUrl} alt="QR-код прив'язки Telegram" style={{ width: 200, height: 200, margin: '0 auto 16px', display: 'block' }} />}
            <FormGroup label="Посилання">
              <input type="text" readOnly value={tgLink.link} onFocus={(e) => e.target.select()} />
            </FormGroup>
            <button
              className="btn btn-ghost"
              style={{ marginTop: 8 }}
              onClick={() => { navigator.clipboard?.writeText(tgLink.link); toast('Скопійовано', 'success'); }}
            >
              📋 Скопіювати посилання
            </button>
          </div>
        )}
      </Modal>

      {/* Продати абонемент */}
      <Modal
        open={!!sell}
        onClose={() => setSell(null)}
        title="Продати абонемент"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={sell?.submitting || view.totalDebt > 0.01} onClick={submitSell}>{sell?.submitting ? 'Збереження...' : 'Продати'}</button>
            <button className="btn btn-ghost" onClick={() => setSell(null)}>Скасувати</button>
          </div>
        }
      >
        {sell && (
          <>
            {sell.error && <div className="alert alert-error">{sell.error}</div>}
            <div style={{ padding: '10px 12px', background: 'var(--accent-dim)', border: '1px solid rgba(79,156,249,.3)', borderRadius: 'var(--radius-sm)', marginBottom: 16 }}>
              <span style={{ fontWeight: 500 }}>{c.full_name}{c.phone ? ` · ${c.phone}` : ''}</span>
            </div>
            {view.totalDebt > 0.01 && (
              <div className="alert alert-error" style={{ marginBottom: 16 }}>
                У клієнта непогашений борг {formatMoney(view.totalDebt)} за попередній абонемент. Продаж нового абонемента недоступний до повного розрахунку.
              </div>
            )}
            <FormGroup label="Тариф *">
              <select value={sell.tariffId} onChange={(e) => setSell({ ...sell, tariffId: e.target.value })} disabled={!sell.tariffs || view.totalDebt > 0.01}>
                <option value="">{sell.tariffs === null ? 'Завантаження...' : (sell.tariffs.length ? '— Оберіть тариф —' : 'Тарифів немає')}</option>
                {sell.tariffs?.map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.name} · {t.price} грн · {t.duration_days} дн. · {t.visits_limit ? `${t.visits_limit} відвід.` : 'безліміт'}
                  </option>
                ))}
              </select>
            </FormGroup>
            {(() => {
              const t = sell.tariffs?.find((x) => String(x.id) === String(sell.tariffId));
              if (!t) return null;
              return (
                <div style={{ marginTop: -8, marginBottom: 16, padding: '10px 12px', background: 'var(--bg-elevated)', border: '1px solid var(--border)', borderRadius: 'var(--radius-sm)', fontSize: 13, color: 'var(--text-secondary)' }}>
                  <strong>{t.name}</strong> · <span style={{ color: 'var(--accent)' }}>{t.price} грн</span> · {t.duration_days} дн.
                  {t.visits_limit ? ` · ${t.visits_limit} відвід.` : ' · безліміт'}
                  {t.description && <div style={{ marginTop: 4, fontSize: 12 }}>{t.description}</div>}
                </div>
              );
            })()}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Дата початку *">
                <input type="date" value={sell.startDate} onChange={(e) => setSell({ ...sell, startDate: e.target.value })} />
              </FormGroup>
              <FormGroup label="Знижка (%)">
                <input type="number" min="0" max="100" step="1" value={sell.discount} onChange={(e) => setSell({ ...sell, discount: e.target.value })} />
              </FormGroup>
            </div>
            <FormGroup label="Примітка">
              <textarea rows="2" placeholder="Необов'язково..." value={sell.notes} onChange={(e) => setSell({ ...sell, notes: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>

      {/* Додати оплату */}
      <Modal
        open={!!addPay}
        onClose={() => setAddPay(null)}
        title="Додати оплату"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={addPay?.submitting} onClick={submitAddPayment}>{addPay?.submitting ? 'Збереження...' : 'Записати'}</button>
            <button className="btn btn-ghost" onClick={() => setAddPay(null)}>Скасувати</button>
          </div>
        }
      >
        {addPay && (
          <>
            <div style={{ padding: '8px 12px', background: 'var(--accent-dim)', border: '1px solid rgba(79,156,249,.3)', borderRadius: 'var(--radius-sm)', marginBottom: 14 }}>
              <span style={{ fontWeight: 500 }}>{c.full_name}</span>
            </div>
            <FormGroup label="Абонемент *">
              <select value={addPay.invoiceId} onChange={(e) => setAddPay({ ...addPay, invoiceId: e.target.value })}>
                <option value="">— Оберіть абонемент —</option>
                {view.invoices.filter((i) => i.status !== 'cancelled' && (i.status === 'active' || parseFloat(i.debt || 0) > 0.01)).map((i) => {
                  const debt = Math.max(0, parseFloat(i.debt || 0));
                  return <option key={i.id} value={i.id}>{i.tariff_name}{debt > 0 ? ` (борг ${formatMoney(debt)})` : ' (сплачено)'}</option>;
                })}
              </select>
            </FormGroup>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Сума *">
                <input type="number" min="1" step="1" placeholder="0" value={addPay.amount} onChange={(e) => setAddPay({ ...addPay, amount: e.target.value })} />
              </FormGroup>
              <FormGroup label="Спосіб">
                <select value={addPay.method} onChange={(e) => setAddPay({ ...addPay, method: e.target.value })}>
                  {ADD_PAYMENT_METHODS.map((m) => <option key={m} value={m}>{PAYMENT_METHODS[m]}</option>)}
                </select>
              </FormGroup>
            </div>
          </>
        )}
      </Modal>

      {/* Поповнити депозит */}
      <Modal
        open={!!deposit}
        onClose={() => setDeposit(null)}
        title="Поповнити депозит"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={deposit?.submitting} onClick={submitDeposit}>{deposit?.submitting ? 'Збереження...' : 'Поповнити'}</button>
            <button className="btn btn-ghost" onClick={() => setDeposit(null)}>Скасувати</button>
          </div>
        }
      >
        {deposit && (
          <>
            <div style={{ padding: '8px 12px', background: 'var(--accent-dim)', border: '1px solid rgba(79,156,249,.3)', borderRadius: 'var(--radius-sm)', marginBottom: 14 }}>
              <span style={{ fontWeight: 500 }}>{c.full_name}</span>
              <span style={{ marginLeft: 10, fontSize: 12, color: 'var(--text-muted)' }}>Баланс: <strong>{formatMoney(c.balance)}</strong></span>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Сума *">
                <input type="number" min="1" step="1" placeholder="0" value={deposit.amount} onChange={(e) => setDeposit({ ...deposit, amount: e.target.value })} />
              </FormGroup>
              <FormGroup label="Спосіб">
                <select value={deposit.method} onChange={(e) => setDeposit({ ...deposit, method: e.target.value })}>
                  {DEPOSIT_METHODS.map((m) => <option key={m} value={m}>{PAYMENT_METHODS[m]}</option>)}
                </select>
              </FormGroup>
            </div>
          </>
        )}
      </Modal>

      {/* Відмітити відвідування */}
      <Modal
        open={!!checkin}
        onClose={() => setCheckin(null)}
        title="Відмітити відвідування"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={checkin?.submitting} onClick={submitCheckin}>{checkin?.submitting ? 'Збереження...' : 'Відмітити'}</button>
            <button className="btn btn-ghost" onClick={() => setCheckin(null)}>Скасувати</button>
          </div>
        }
      >
        {checkin && (
          <>
            <div style={{ padding: '8px 12px', background: 'var(--accent-dim)', border: '1px solid rgba(79,156,249,.3)', borderRadius: 'var(--radius-sm)', marginBottom: 14 }}>
              <span style={{ fontWeight: 500 }}>{c.full_name}</span>
            </div>
            {checkin.alreadyToday && (
              <div className="alert alert-info" style={{ marginBottom: 14 }}>
                ℹ️ Цей клієнт вже відмічений сьогодні о {formatTime(checkin.alreadyToday.visited_at)}
              </div>
            )}
            <FormGroup label="Примітка">
              <input type="text" placeholder="Необов'язково..." value={checkin.notes} onChange={(e) => setCheckin({ ...checkin, notes: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>

      {/* Видалити відвідування — підтвердження повернення виплати тренеру */}
      <Modal
        open={!!visitDeleteConfirm}
        onClose={() => setVisitDeleteConfirm(null)}
        title="Тренеру вже виплачено"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-ghost" onClick={() => setVisitDeleteConfirm(null)}>Скасувати</button>
            <button className="btn btn-danger" disabled={visitDeleteConfirm?.submitting} onClick={confirmDeleteVisitReversal}>
              {visitDeleteConfirm?.submitting ? '...' : '🗑 Видалити і повернути кошти'}
            </button>
          </div>
        }
      >
        {visitDeleteConfirm && (
          <>
            {visitDeleteConfirm.error && <div className="alert alert-error">{visitDeleteConfirm.error}</div>}
            <p>
              Тренеру <strong>{visitDeleteConfirm.trainerName}</strong> вже виплачено{' '}
              <strong>{formatMoney(visitDeleteConfirm.paidAmount)}</strong> за це відвідування.
              Видалити відмітку і повернути ці кошти (зменшити нараховану суму тренера)?
            </p>
          </>
        )}
      </Modal>

      {/* Скасувати абонемент */}
      <Modal
        open={!!invoiceCancelConfirm}
        onClose={() => setInvoiceCancelConfirm(null)}
        title="Скасувати абонемент"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-ghost" onClick={() => setInvoiceCancelConfirm(null)}>Відмінити</button>
            <button
              className="btn btn-danger"
              disabled={invoiceCancelConfirm?.submitting || !invoiceCancelConfirm?.reason.trim() || !invoiceCancelConfirm?.acknowledged}
              onClick={submitInvoiceCancel}
            >
              {invoiceCancelConfirm?.submitting ? '...' : '🗑 Скасувати абонемент остаточно'}
            </button>
          </div>
        }
      >
        {invoiceCancelConfirm && (
          <>
            {invoiceCancelConfirm.error && <div className="alert alert-error">{invoiceCancelConfirm.error}</div>}
            <div style={{ padding: '8px 12px', background: 'var(--accent-dim)', border: '1px solid rgba(79,156,249,.3)', borderRadius: 'var(--radius-sm)', marginBottom: 14 }}>
              <span style={{ fontWeight: 500 }}>{invoiceCancelConfirm.invoice.tariff_name}</span>
            </div>
            <FormGroup label="Причина дострокового скасування *">
              <textarea
                rows="2"
                value={invoiceCancelConfirm.reason}
                onChange={(e) => setInvoiceCancelConfirm({ ...invoiceCancelConfirm, reason: e.target.value, error: '' })}
              />
            </FormGroup>
            <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, marginTop: 8 }}>
              <input
                type="checkbox"
                checked={invoiceCancelConfirm.acknowledged}
                onChange={(e) => setInvoiceCancelConfirm({ ...invoiceCancelConfirm, acknowledged: e.target.checked, error: '' })}
              />
              Клієнта попереджено про скасування абонемента
            </label>
          </>
        )}
      </Modal>

      {/* Продажі: повернення товару */}
      <Modal
        open={!!saleReturnModal}
        onClose={() => setSaleReturnModal(null)}
        title="Повернення товару"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-danger" disabled={saleReturnModal?.submitting} onClick={submitSaleReturn}>
              {saleReturnModal?.submitting ? 'Оформлення...' : 'Оформити повернення'}
            </button>
            <button className="btn btn-ghost" onClick={() => setSaleReturnModal(null)}>Скасувати</button>
          </div>
        }
      >
        {saleReturnModal && (
          <>
            {saleReturnModal.error && <div className="alert alert-error">{saleReturnModal.error}</div>}
            <div style={{ background: 'var(--bg-elevated)', borderRadius: 'var(--radius-md)', padding: 12, marginBottom: 16 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                <strong>Чек №{saleReturnModal.order.order_number}</strong>
                <span>{formatMoney(saleReturnModal.order.total_amount)}</span>
              </div>
            </div>
            <FormGroup label="Причина повернення" hint="Необов'язково">
              <input type="text" value={saleReturnModal.reason} onChange={(e) => setSaleReturnModal({ ...saleReturnModal, reason: e.target.value })} />
            </FormGroup>
            <FormGroup label="Спосіб повернення коштів">
              <select value={saleReturnModal.refundMethod} onChange={(e) => setSaleReturnModal({ ...saleReturnModal, refundMethod: e.target.value })}>
                <option value="other">Поза системою (картка/термінал)</option>
                <option value="cash">Готівкою</option>
                <option value="deposit">На депозит клієнта</option>
              </select>
            </FormGroup>
            {saleReturnModal.refundMethod === 'cash' && (
              <FormGroup label="Звідки готівка">
                <select value={saleReturnModal.refundLocation} onChange={(e) => setSaleReturnModal({ ...saleReturnModal, refundLocation: e.target.value })}>
                  <option value="register">Каса</option>
                  <option value="safe">Сейф</option>
                </select>
              </FormGroup>
            )}
          </>
        )}
      </Modal>

      {/* Продажі: скасування повернення */}
      <Modal
        open={!!saleCancelReturnModal}
        onClose={() => setSaleCancelReturnModal(null)}
        title="Скасування повернення"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-danger" disabled={saleCancelReturnModal?.submitting} onClick={submitSaleCancelReturn}>
              {saleCancelReturnModal?.submitting ? 'Оформлення...' : 'Скасувати повернення'}
            </button>
            <button className="btn btn-ghost" onClick={() => setSaleCancelReturnModal(null)}>Назад</button>
          </div>
        }
      >
        {saleCancelReturnModal && (
          <>
            {saleCancelReturnModal.error && <div className="alert alert-error">{saleCancelReturnModal.error}</div>}
            <p>Чек №{saleCancelReturnModal.order.order_number} знову стане «Завершено», товар повторно спишеться зі складу.</p>
            <FormGroup label="Причина" hint="Необов'язково">
              <input type="text" value={saleCancelReturnModal.reason} onChange={(e) => setSaleCancelReturnModal({ ...saleCancelReturnModal, reason: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>

      {/* Сертифікати: скасувати вже активований */}
      <Modal
        open={!!certCancelConfirm}
        onClose={() => setCertCancelConfirm(null)}
        title={`Скасувати активацію сертифіката «${certCancelConfirm?.sale.code}»?`}
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-danger" onClick={submitCertCancel}>🗑 Скасувати і зняти депозит</button>
            <button className="btn btn-ghost" onClick={() => setCertCancelConfirm(null)}>Відмінити</button>
          </div>
        }
      >
        {certCancelConfirm && (
          <>
            <p>
              З клієнта буде знято депозит <strong>{formatMoney(certCancelConfirm.sale.amount)}</strong>,
              нарахований при активації, а код сертифіката знову стане доступним до продажу.
            </p>
            <FormGroup label="Причина" hint="Необов'язково">
              <input type="text" value={certCancelConfirm.reason} onChange={(e) => setCertCancelConfirm({ ...certCancelConfirm, reason: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>
    </AppLayout>
  );
}
