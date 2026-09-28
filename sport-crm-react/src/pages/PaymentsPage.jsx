import { useEffect, useState } from 'react';
import AppLayout from '../components/layout/AppLayout';
import Table from '../components/ui/Table';
import Pagination from '../components/ui/Pagination';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import { usePermissions } from '../hooks/usePermissions';
import { useShiftLock } from '../hooks/useShiftLock';
import { useToast } from '../components/ui/ToastProvider';
import { getPayments, getPaymentTariffs, updatePayment, deletePayment, refundPayment } from '../api/payments';
import { formatMoney, formatDate, formatTime, localMonthStart, localToday } from '../utils/format';
import './PaymentsPage.css';

const PAY_METHODS = {
  cash: 'Готівка', card: 'Карта', terminal: 'Термінал',
  deposit: 'Депозит', transfer: 'Переказ', free: 'Безкоштовно', other: 'Інше',
};
const FISCAL_STATUS_LABELS = { pending: '⏳ В черзі', sent: '✅ Відправлено', failed: '❌ Помилка', skipped_manual: '— Пропущено вручну', return_manual: '⚠ Потрібен чек повернення (Checkbox)' };
const METHOD_OPTIONS = ['cash', 'card', 'terminal', 'deposit', 'transfer', 'free'];
const EDIT_METHOD_OPTIONS = [...METHOD_OPTIONS, 'other'];

const EMPTY_DATA = { payments: [], summary: [], pagination: { total: 0, page: 1, pages: 1, per_page: 30 } };

export default function PaymentsPage() {
  const { has } = usePermissions();
  const { guard, lockedProps } = useShiftLock();
  const toast = useToast();

  const [dateFrom, setDateFrom] = useState(localMonthStart());
  const [dateTo, setDateTo] = useState(localToday());
  const [method, setMethod] = useState('');
  const [tariffId, setTariffId] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);

  const [tariffs, setTariffs] = useState([]);
  const [data, setData] = useState(EMPTY_DATA);
  const [loading, setLoading] = useState(true);

  const [edit, setEdit] = useState(null); // { id, amount, payment_method, notes }
  const [editError, setEditError] = useState('');
  // Повернення (сторно): для оплат закритих змін — замість редагування/видалення
  const [refund, setRefund] = useState(null); // { id, max, amount, reason, clientName, error, submitting }
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    getPaymentTariffs().then((res) => {
      if (res.success) setTariffs(res.tariffs ?? []);
    });
  }, []);

  useEffect(() => {
    const t = setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 350);
    return () => clearTimeout(t);
  }, [searchInput]);

  async function reload() {
    setLoading(true);
    const res = await getPayments({ date_from: dateFrom, date_to: dateTo, method, tariff_id: tariffId || 0, search, page });
    setLoading(false);
    if (!res.success) {
      toast(res.error, 'error');
      setData(EMPTY_DATA);
      return;
    }
    setData({ payments: res.payments, summary: res.summary, pagination: res.pagination });
  }

  useEffect(() => {
    reload();
  }, [dateFrom, dateTo, method, tariffId, search, page]);

  function onFilterChange(setter) {
    return (e) => {
      setter(e.target.value);
      setPage(1);
    };
  }

  function openEdit(p) {
    setEdit({ id: p.id, amount: p.amount, payment_method: p.payment_method, notes: p.notes || '' });
    setEditError('');
  }

  async function submitEdit() {
    setSubmitting(true);
    setEditError('');
    const res = await updatePayment(edit);
    setSubmitting(false);
    if (!res.success) {
      setEditError(res.error);
      return;
    }
    toast('Оплату оновлено', 'success');
    setEdit(null);
    reload();
  }

  async function handleDelete(id) {
    if (!confirm('Видалити цю оплату?')) return;
    const res = await deletePayment(id);
    if (!res.success) {
      toast(res.error, 'error');
      return;
    }
    toast('Оплату видалено', 'success');
    reload();
  }

  function openRefund(p) {
    const max = Math.round((parseFloat(p.amount) - parseFloat(p.refunded_amount || 0)) * 100) / 100;
    setRefund({ id: p.id, max, amount: String(max), reason: '', clientName: p.client_name, method: p.payment_method, error: '', submitting: false });
  }

  async function submitRefund() {
    if (refund.reason.trim().length < 3) { setRefund({ ...refund, error: 'Вкажіть причину повернення' }); return; }
    setRefund({ ...refund, submitting: true, error: '' });
    const res = await refundPayment({ id: refund.id, amount: parseFloat(refund.amount), reason: refund.reason.trim() });
    if (!res.success) { setRefund({ ...refund, submitting: false, error: res.error }); return; }
    toast(res.message, res.needs_fiscal_return ? 'warning' : 'success', res.needs_fiscal_return ? 8000 : 3000);
    setRefund(null);
    reload();
  }

  const summaryTotal = data.summary.reduce((a, s) => a + parseFloat(s.total || 0), 0);

  const columns = [
    {
      key: 'client',
      label: 'Клієнт',
      render: (p) => (
        <>
          <div style={{ fontWeight: 500, fontSize: 14 }}>{p.client_name}</div>
          <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{p.client_phone || '—'}</div>
        </>
      ),
    },
    {
      key: 'tariff',
      label: 'Абонемент',
      mobile: 'secondary',
      render: (p) => (
        <>
          <div style={{ fontSize: 13 }}>{p.tariff_name || '—'}</div>
          {p.invoice_id && <a href="/invoices" style={{ fontSize: 11, color: 'var(--accent)' }}>#{p.invoice_id}</a>}
        </>
      ),
    },
    {
      key: 'method',
      label: 'Спосіб',
      cardTop: true,
      render: (p) => (
        <span className={`pay-method-badge pay-method-${p.payment_method}`}>
          {PAY_METHODS[p.payment_method] || p.payment_method}
        </span>
      ),
    },
    {
      key: 'fiscal',
      label: 'Чек',
      render: (p) => (
        p.fiscal_status
          ? (p.fiscal_receipt_url
              ? <a href={p.fiscal_receipt_url} target="_blank" rel="noreferrer" style={{ fontSize: 12 }}>{FISCAL_STATUS_LABELS[p.fiscal_status] || p.fiscal_status}</a>
              : <span style={{ fontSize: 12 }}>{FISCAL_STATUS_LABELS[p.fiscal_status] || p.fiscal_status}</span>)
          : <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>— Не потрібен</span>
      ),
    },
    { key: 'trainer', label: 'Тренер', render: (p) => <span style={{ fontSize: 13, color: 'var(--text-secondary)' }}>{p.trainer_name || '—'}</span> },
    { key: 'manager', label: 'Менеджер', render: (p) => <span style={{ fontSize: 13, color: 'var(--text-secondary)' }}>{p.admin_name || '—'}</span> },
    {
      key: 'amount',
      label: 'Сума',
      mobile: 'trailing',
      render: (p) => (parseFloat(p.amount) < 0 ? (
        <span style={{ fontWeight: 600, color: 'var(--danger)', whiteSpace: 'nowrap' }} title={p.refund_reason || ''}>
          ↩ {formatMoney(p.amount)}<br /><span style={{ fontSize: 11, fontWeight: 400, color: 'var(--text-muted)' }}>Повернення</span>
        </span>
      ) : (
        <span style={{ fontWeight: 600, color: 'var(--success)', whiteSpace: 'nowrap' }}>
          {formatMoney(p.amount)}
          {parseFloat(p.refunded_amount) > 0 && <><br /><span style={{ fontSize: 11, fontWeight: 400, color: 'var(--danger)' }}>повернено {formatMoney(p.refunded_amount)}</span></>}
        </span>
      )),
    },
    {
      key: 'date',
      label: 'Дата',
      mobile: 'trailing',
      render: (p) => (
        <span style={{ fontSize: 12, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>
          {formatDate(p.created_at)}<br /><span style={{ fontSize: 11 }}>{formatTime(p.created_at)}</span>
        </span>
      ),
    },
    {
      key: 'actions',
      label: '',
      render: (p) => (
        <div style={{ display: 'flex', gap: 4, whiteSpace: 'nowrap' }} onClick={(e) => e.stopPropagation()}>
          {/* Оплата закритої зміни (або з поверненнями) незмінна — лише повернення */}
          {!Number(p.is_locked) && !(parseFloat(p.refunded_amount) > 0) && parseFloat(p.amount) > 0 && has('payments.edit') && (
            <button className="btn btn-ghost btn-sm" {...lockedProps} onClick={guard(() => openEdit(p))}>✎</button>
          )}
          {!Number(p.is_locked) && !(parseFloat(p.refunded_amount) > 0) && has('payments.delete') && (
            <button className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }} {...lockedProps} onClick={guard(() => handleDelete(p.id))}>🗑</button>
          )}
          {parseFloat(p.amount) > 0 && parseFloat(p.amount) - parseFloat(p.refunded_amount || 0) > 0.009 && has('payments.delete') && (
            <button className="btn btn-ghost btn-sm" title="Повернення (сторно) — проводиться в поточній зміні" onClick={() => openRefund(p)}>↩</button>
          )}
        </div>
      ),
    },
  ];

  return (
    <AppLayout title="Оплати">
      <div className="pay-toolbar">
        <FormGroup label="Від"><input type="date" value={dateFrom} onChange={onFilterChange(setDateFrom)} /></FormGroup>
        <FormGroup label="До"><input type="date" value={dateTo} onChange={onFilterChange(setDateTo)} /></FormGroup>
        <FormGroup label="Спосіб">
          <select value={method} onChange={onFilterChange(setMethod)}>
            <option value="">Всі способи</option>
            {METHOD_OPTIONS.map((m) => <option key={m} value={m}>{PAY_METHODS[m]}</option>)}
          </select>
        </FormGroup>
        <FormGroup label="Тариф">
          <select value={tariffId} onChange={onFilterChange(setTariffId)}>
            <option value="">Всі тарифи</option>
            {tariffs.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
          </select>
        </FormGroup>
        <FormGroup label="Клієнт" fullWidth={false}>
          <input type="text" placeholder="Ім'я клієнта..." value={searchInput} onChange={(e) => setSearchInput(e.target.value)} />
        </FormGroup>
      </div>

      {data.summary.length > 0 && (
        <div className="pay-summary-row">
          <div className="pay-sum-total">Разом: <strong>{formatMoney(summaryTotal)}</strong></div>
          <div className="pay-sum-chips">
            {data.summary.map((s) => (
              <span className="pay-sum-chip" key={s.payment_method}>
                <span className={`pay-method-badge pay-method-${s.payment_method}`}>{PAY_METHODS[s.payment_method] || s.payment_method}</span>
                <strong>{formatMoney(s.total)}</strong>
                <span style={{ color: 'var(--text-muted)', fontSize: 11 }}>({s.cnt})</span>
              </span>
            ))}
          </div>
        </div>
      )}

      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <Table
          columns={columns}
          rows={data.payments}
          loading={loading}
          emptyMessage="Оплат не знайдено"
        />
      </div>

      <Pagination
        page={data.pagination.page}
        pages={data.pagination.pages}
        total={data.pagination.total}
        perPage={data.pagination.per_page}
        onChange={setPage}
      />

      <Modal
        open={!!edit}
        onClose={() => setEdit(null)}
        title="Редагувати оплату"
        footer={
          <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
            <button className="btn btn-ghost" onClick={() => setEdit(null)}>Скасувати</button>
            <button className="btn btn-primary" disabled={submitting} onClick={submitEdit}>{submitting ? '...' : 'Зберегти'}</button>
          </div>
        }
      >
        {edit && (
          <>
            {editError && <div className="alert alert-error">{editError}</div>}
            <FormGroup label="Сума (грн)">
              <input
                type="number" min="0.01" step="0.01"
                value={edit.amount}
                onChange={(e) => setEdit({ ...edit, amount: e.target.value })}
              />
            </FormGroup>
            <FormGroup label="Спосіб оплати">
              <select
                value={edit.payment_method}
                onChange={(e) => setEdit({ ...edit, payment_method: e.target.value })}
              >
                {EDIT_METHOD_OPTIONS.map((m) => <option key={m} value={m}>{PAY_METHODS[m]}</option>)}
              </select>
            </FormGroup>
            <FormGroup label="Нотатки">
              <input
                type="text" placeholder="Необов'язково"
                value={edit.notes}
                onChange={(e) => setEdit({ ...edit, notes: e.target.value })}
              />
            </FormGroup>
          </>
        )}
      </Modal>

      <Modal
        open={!!refund}
        onClose={() => setRefund(null)}
        title="↩ Повернення оплати"
        footer={
          <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
            <button className="btn btn-ghost" onClick={() => setRefund(null)}>Скасувати</button>
            <button className="btn btn-danger" disabled={refund?.submitting} onClick={submitRefund}>{refund?.submitting ? '...' : 'Провести повернення'}</button>
          </div>
        }
      >
        {refund && (
          <>
            {refund.error && <div className="alert alert-error">{refund.error}</div>}
            <p style={{ fontSize: 13, color: 'var(--text-secondary)', marginBottom: 16 }}>
              Клієнт: <strong>{refund.clientName}</strong>, спосіб: <strong>{PAY_METHODS[refund.method] || refund.method}</strong>.
              Повернення проводиться в <strong>поточній</strong> зміні — закрита зміна не змінюється.
              {refund.method === 'cash' && ' Готівку видають з каси (потрібна відкрита зміна).'}
              {refund.method === 'deposit' && ' Сума повернеться на депозит клієнта.'}
            </p>
            <FormGroup label={`Сума, грн (не більше ${formatMoney(refund.max)})`}>
              <input type="number" min="0.01" step="0.01" max={refund.max} value={refund.amount} onChange={(e) => setRefund({ ...refund, amount: e.target.value })} />
            </FormGroup>
            <FormGroup label="Причина *">
              <input type="text" placeholder="Помилкова оплата / клієнт відмовився..." value={refund.reason} onChange={(e) => setRefund({ ...refund, reason: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>
    </AppLayout>
  );
}
