import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import AppLayout from '../components/layout/AppLayout';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import Table from '../components/ui/Table';
import ClientSearchPicker from '../components/ui/ClientSearchPicker';
import { usePermissions } from '../hooks/usePermissions';
import { useShiftLock } from '../hooks/useShiftLock';
import { useToast } from '../components/ui/ToastProvider';
import {
  getInvoiceGroup, updateInvoiceGroup, addInvoiceGroupMembers, removeInvoiceGroupMember,
  payInvoiceGroup, getInvoiceTariffs,
} from '../api/invoices';
import { getTrainers } from '../api/trainers';
import { formatMoney, formatDate } from '../utils/format';
import './InvoicesPage.css';

const STATUS_MAP = {
  future: ['info', 'Майбутній'],
  active: ['active', 'Активний'],
  pending: ['pending', 'Очікує оплати'],
  frozen: ['pending', 'Заморожений'],
  finished: ['inactive', 'Завершений'],
  cancelled: ['inactive', 'Скасований'],
};
const PAY_METHODS = { cash: 'Готівка', card: 'Карта', terminal: 'Термінал', deposit: 'Депозит', transfer: 'Переказ' };

function StatBox({ label, value, sub, tone }) {
  return (
    <div className="card" style={{ padding: 14, flex: '1 1 160px' }}>
      <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{label}</div>
      <div style={{ fontSize: 20, fontWeight: 600, marginTop: 4, color: tone ? `var(--${tone})` : undefined }}>{value}</div>
      {sub && <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>{sub}</div>}
    </div>
  );
}

export default function InvoiceGroupCardPage() {
  const { id } = useParams();
  const groupId = Number(id);
  const { has } = usePermissions();
  const { guard, lockedProps } = useShiftLock();
  const toast = useToast();
  const navigate = useNavigate();

  const [view, setView] = useState(null); // { loading, group, owner, members }
  const [tariffs, setTariffs] = useState([]);
  const [trainers, setTrainers] = useState([]);
  const [addForm, setAddForm] = useState(null);
  const [payForm, setPayForm] = useState(null);
  const [editForm, setEditForm] = useState(null);

  const load = useCallback(async () => {
    const res = await getInvoiceGroup(groupId);
    if (!res.success) { toast(res.error, 'error'); setView({ loading: false, notFound: true }); return; }
    setView({ loading: false, group: res.group, owner: res.owner, members: res.members || [] });
  }, [groupId, toast]);

  useEffect(() => { setView({ loading: true }); load(); }, [load]);
  useEffect(() => {
    getInvoiceTariffs().then((r) => { if (r.success) setTariffs(r.tariffs || []); });
    getTrainers().then((r) => { if (r.success) setTrainers(r.trainers || []); });
  }, []);

  if (!view || view.loading) {
    return <AppLayout title="Абонементи"><div className="loader"><span className="spinner" /> Завантаження...</div></AppLayout>;
  }
  if (view.notFound) {
    return <AppLayout title="Абонементи"><div className="empty-state"><p>Групу не знайдено</p></div></AppLayout>;
  }

  const { group, owner, members } = view;
  const activeMembers = members.filter((m) => m.status !== 'cancelled');
  const totalPrice = activeMembers.reduce((s, m) => s + parseFloat(m.price || 0), 0);
  const totalPaid = activeMembers.reduce((s, m) => s + parseFloat(m.paid_amount || 0), 0);
  const activated = activeMembers.filter((m) => parseFloat(m.paid_amount) >= parseFloat(m.min_paid_to_activate || 0)).length;
  const maxMembers = group.max_members ? parseInt(group.max_members) : null;
  const freeSeats = maxMembers === null ? null : Math.max(0, maxMembers - activeMembers.length);
  const isOpen = group.status === 'active';
  const groupTariff = tariffs.find((t) => String(t.id) === String(group.tariff_id));

  // ── Додати учасників ──
  function openAdd() {
    setAddForm({ clients: [], trainerId: '', error: '', submitting: false });
  }
  async function submitAdd() {
    if (!addForm.clients.length) { setAddForm({ ...addForm, error: 'Оберіть учасників' }); return; }
    if (freeSeats !== null && addForm.clients.length > freeSeats) { setAddForm({ ...addForm, error: `Вільних місць: ${freeSeats}` }); return; }
    setAddForm({ ...addForm, submitting: true, error: '' });
    const res = await addInvoiceGroupMembers({ group_id: groupId, client_ids: addForm.clients.map((c) => c.id), trainer_id: addForm.trainerId || 0 });
    if (!res.success) { setAddForm({ ...addForm, submitting: false, error: res.error }); return; }
    toast(res.message || 'Учасників додано', 'success');
    setAddForm(null);
    load();
  }

  // ── Оплата ──
  function openPay(onlyInvoiceId = null) {
    const rows = activeMembers
      .filter((m) => parseFloat(m.debt) > 0.009)
      .map((m) => ({
        invoiceId: m.id, name: m.client_name, debt: parseFloat(m.debt),
        needMin: Math.max(0, parseFloat(m.min_paid_to_activate || 0) - parseFloat(m.paid_amount || 0)),
        checked: onlyInvoiceId ? m.id === onlyInvoiceId : true,
        amount: String(parseFloat(m.debt)),
      }));
    if (!rows.length) { toast('Усі учасники вже повністю оплатили', 'info'); return; }
    setPayForm({
      mode: owner && !onlyInvoiceId ? 'payer' : 'self',
      payer: owner ? { id: Number(group.owner_client_id), full_name: owner.full_name } : null,
      method: 'cash', skipFiscal: false, rows, error: '', submitting: false,
    });
  }
  function setRow(i, patch) {
    setPayForm((f) => ({ ...f, rows: f.rows.map((r, j) => (j === i ? { ...r, ...patch } : r)) }));
  }
  function fillAll(kind) {
    setPayForm((f) => ({ ...f, rows: f.rows.map((r) => ({ ...r, amount: String(kind === 'min' ? (r.needMin || r.debt) : r.debt) })) }));
  }
  const payTotal = payForm ? payForm.rows.filter((r) => r.checked).reduce((s, r) => s + (parseFloat(r.amount) || 0), 0) : 0;
  async function submitPay() {
    const items = payForm.rows.filter((r) => r.checked && parseFloat(r.amount) > 0).map((r) => ({ invoice_id: r.invoiceId, amount: parseFloat(r.amount) }));
    if (!items.length) { setPayForm({ ...payForm, error: 'Оберіть учасників і вкажіть суми' }); return; }
    const over = payForm.rows.find((r) => r.checked && parseFloat(r.amount) > r.debt + 0.001);
    if (over) { setPayForm({ ...payForm, error: `Сума для «${over.name}» більша за залишок (${formatMoney(over.debt)})` }); return; }
    if (payForm.mode === 'payer' && !payForm.payer) { setPayForm({ ...payForm, error: 'Оберіть, хто платить за всіх' }); return; }
    setPayForm({ ...payForm, submitting: true, error: '' });
    const res = await payInvoiceGroup({
      group_id: groupId, items, payment_method: payForm.method,
      payer_client_id: payForm.mode === 'payer' ? payForm.payer.id : 0,
      manual_skip_fiscal: payForm.skipFiscal,
    });
    if (!res.success) { setPayForm({ ...payForm, submitting: false, error: res.error }); return; }
    toast('Оплату внесено', 'success');
    setPayForm(null);
    load();
  }

  // ── Редагування / закриття ──
  function openEdit() {
    setEditForm({
      name: group.name, startDate: group.start_date?.substring(0, 10),
      memberPrice: String(group.member_price), minPayment: String(group.min_payment),
      limited: !!group.max_members, maxMembers: group.max_members ? String(group.max_members) : '',
      owner: owner ? { id: Number(group.owner_client_id), full_name: owner.full_name } : null,
      notes: group.notes || '', error: '', submitting: false,
    });
  }
  async function submitEdit() {
    const memberPrice = parseFloat(editForm.memberPrice) || 0;
    const minPayment = parseFloat(editForm.minPayment) || 0;
    if (minPayment > memberPrice) { setEditForm({ ...editForm, error: 'Мінімальна оплата не може перевищувати вартість абонемента учасника' }); return; }
    setEditForm({ ...editForm, submitting: true, error: '' });
    const res = await updateInvoiceGroup({
      id: groupId, name: editForm.name.trim(), start_date: editForm.startDate,
      member_price: memberPrice, min_payment: minPayment,
      max_members: editForm.limited ? parseInt(editForm.maxMembers) || 0 : 0,
      owner_client_id: editForm.owner?.id || 0, notes: editForm.notes.trim(),
    });
    if (!res.success) { setEditForm({ ...editForm, submitting: false, error: res.error }); return; }
    toast('Групу оновлено', 'success');
    setEditForm(null);
    load();
  }
  async function toggleClosed() {
    const res = await updateInvoiceGroup({ id: groupId, status: isOpen ? 'closed' : 'active' });
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(isOpen ? 'Групу закрито' : 'Групу відкрито', 'success');
    load();
  }
  async function removeMember(m) {
    if (!window.confirm(`Прибрати «${m.client_name}» з групи?`)) return;
    const res = await removeInvoiceGroupMember(m.id);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast('Учасника прибрано', 'success');
    load();
  }

  const columns = [
    {
      key: 'client', label: 'Учасник',
      render: (m) => (
        <div className="inv-client-info">
          <div className="inv-client-name">{m.client_name}</div>
          <div className="inv-client-phone">{m.client_phone || '—'}</div>
        </div>
      ),
    },
    {
      key: 'status', label: 'Статус', cardTop: true,
      render: (m) => {
        const [variant, label] = STATUS_MAP[m.status] || ['info', m.status];
        return <Badge variant={variant}>{label}</Badge>;
      },
    },
    {
      key: 'payment', label: 'Оплата', mobile: 'trailing',
      render: (m) => {
        const need = Math.max(0, parseFloat(m.min_paid_to_activate || 0) - parseFloat(m.paid_amount || 0));
        return (
          <>
            <div style={{ fontSize: 13 }}>{formatMoney(m.paid_amount)} / {formatMoney(m.price)}</div>
            {m.status === 'pending' && need > 0 && <span className="debt-badge">до активації {formatMoney(need)}</span>}
            {m.status !== 'pending' && parseFloat(m.debt) > 0.01 && <span className="debt-badge">борг {formatMoney(m.debt)}</span>}
          </>
        );
      },
    },
    {
      key: 'visits', label: 'Відвідування',
      render: (m) => <span style={{ fontSize: 13 }}>{m.visits_total ? `${m.visits_used}/${m.visits_total}` : `${m.visits_used} · безліміт`}</span>,
    },
    {
      key: 'actions', label: '',
      render: (m) => (
        <div style={{ display: 'flex', gap: 4 }} onClick={(e) => e.stopPropagation()}>
          <button className="btn btn-ghost btn-sm" title="Абонемент учасника" onClick={() => navigate(`/invoices/${m.id}`)}><Icon name="eye" size={14} /></button>
          {has('payments.create') && m.status !== 'cancelled' && parseFloat(m.debt) > 0.009 && (
            <button className="btn btn-ghost btn-sm" title="Оплата" onClick={guard(() => openPay(m.id))}><Icon name="card" size={14} /></button>
          )}
          {has('invoices.sell') && parseFloat(m.paid_amount) === 0 && parseInt(m.visits_used) === 0 && (
            <button className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }} title="Прибрати з групи" onClick={guard(() => removeMember(m))}><Icon name="trash" size={14} /></button>
          )}
        </div>
      ),
    },
  ];

  return (
    <AppLayout title="Абонементи">
      <div className="icard-breadcrumb" style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, marginBottom: 14 }}>
        <Link to="/invoices">Абонементи</Link>
        <Icon name="chevronRight" size={14} />
        <Link to="/invoices?tab=groups">Групові</Link>
        <Icon name="chevronRight" size={14} />
        <span>{group.name}</span>
      </div>

      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap', marginBottom: 16 }}>
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
            <span style={{ fontSize: 20, fontWeight: 600 }}>{group.name}</span>
            <Badge variant={isOpen ? 'active' : 'inactive'}>{isOpen ? 'Діюча' : 'Закрита'}</Badge>
          </div>
          <div style={{ fontSize: 13, color: 'var(--text-secondary)', marginTop: 6, display: 'flex', gap: 14, flexWrap: 'wrap' }}>
            <span><Icon name="tag" size={13} /> {group.tariff_name}</span>
            <span><Icon name="calendar" size={13} /> {formatDate(group.start_date)} — {formatDate(group.end_date)}</span>
            {owner && <span><Icon name="user" size={13} /> <Link to={`/clients/${group.owner_client_id}`}>{owner.full_name}</Link>{owner.phone ? ` · ${owner.phone}` : ''}</span>}
          </div>
          {group.notes && <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 6 }}>{group.notes}</div>}
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-start' }}>
          {has('invoices.sell') && isOpen && (
            <button className="btn btn-primary" {...lockedProps} onClick={guard(openAdd)} disabled={freeSeats === 0}>
              + Додати учасників
            </button>
          )}
          {has('payments.create') && activeMembers.length > 0 && (
            <button className="btn btn-ghost" {...lockedProps} onClick={guard(() => openPay())}><Icon name="card" size={15} /> Внести оплату</button>
          )}
          {has('invoices.sell') && (
            <>
              <button className="btn btn-ghost" onClick={guard(openEdit)}><Icon name="edit" size={15} /> Редагувати</button>
              <button className="btn btn-ghost" onClick={guard(toggleClosed)}>{isOpen ? 'Закрити групу' : 'Відкрити групу'}</button>
            </>
          )}
        </div>
      </div>

      <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 16 }}>
        <StatBox label="Учасники" value={maxMembers ? `${activeMembers.length} / ${maxMembers}` : activeMembers.length} sub={maxMembers ? `вільних місць: ${freeSeats}` : 'без обмеження'} />
        <StatBox label="Активовано" value={`${activated} з ${activeMembers.length}`} sub={`мін. оплата ${formatMoney(group.min_payment)}`} />
        <StatBox label="Оплачено" value={formatMoney(totalPaid)} sub={`з ${formatMoney(totalPrice)} · ${formatMoney(group.member_price)} за учасника`} tone="success" />
        <StatBox label="Борг" value={formatMoney(Math.max(0, totalPrice - totalPaid))} tone={totalPrice - totalPaid > 0.01 ? 'danger' : undefined} />
      </div>

      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <Table
          columns={columns}
          rows={members}
          emptyMessage="У групі ще немає учасників. Натисніть «+ Додати учасників»"
          onRowClick={(m) => navigate(`/invoices/${m.id}`)}
        />
      </div>

      {/* Додати учасників */}
      <Modal
        open={!!addForm}
        onClose={() => setAddForm(null)}
        title="Додати учасників"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={addForm?.submitting} onClick={submitAdd}>{addForm?.submitting ? 'Зберігаємо...' : `Додати${addForm?.clients.length ? ` (${addForm.clients.length})` : ''}`}</button>
            <button className="btn btn-ghost" onClick={() => setAddForm(null)}>Скасувати</button>
          </div>
        }
      >
        {addForm && (
          <>
            {addForm.error && <div className="alert alert-error">{addForm.error}</div>}
            <p style={{ fontSize: 13, color: 'var(--text-secondary)', marginTop: 0 }}>
              Кожен учасник отримає абонемент «{group.tariff_name}» на {formatMoney(group.member_price)} з
              датами групи {formatDate(group.start_date)} — {formatDate(group.end_date)}.
              Абонемент почне діяти після оплати щонайменше {formatMoney(group.min_payment)}.
              {freeSeats !== null && <> Вільних місць: <strong>{freeSeats}</strong>.</>}
            </p>
            <FormGroup label="Учасники *">
              <ClientSearchPicker
                multiple
                value={addForm.clients}
                excludeIds={activeMembers.map((m) => m.client_id)}
                onChange={(clients) => setAddForm({ ...addForm, clients })}
              />
            </FormGroup>
            {!!groupTariff?.has_trainer && (
              <FormGroup label="Тренер (рекомендований)">
                <select value={addForm.trainerId} onChange={(e) => setAddForm({ ...addForm, trainerId: e.target.value })}>
                  <option value="">— Без тренера —</option>
                  {trainers.map((t) => <option key={t.id} value={t.id}>{t.full_name}</option>)}
                </select>
              </FormGroup>
            )}
          </>
        )}
      </Modal>

      {/* Оплата */}
      <Modal
        open={!!payForm}
        onClose={() => setPayForm(null)}
        title="Оплата за учасників групи"
        size="lg"
        footer={
          <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
            <button className="btn btn-primary" disabled={payForm?.submitting} onClick={submitPay}>{payForm?.submitting ? 'Зберігаємо...' : `Оплатити ${formatMoney(payTotal)}`}</button>
            <button className="btn btn-ghost" onClick={() => setPayForm(null)}>Скасувати</button>
          </div>
        }
      >
        {payForm && (
          <>
            {payForm.error && <div className="alert alert-error">{payForm.error}</div>}
            <FormGroup label="Хто платить">
              <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap', fontSize: 13 }}>
                <label style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                  <input type="radio" checked={payForm.mode === 'self'} onChange={() => setPayForm({ ...payForm, mode: 'self' })} />
                  Кожен за себе
                </label>
                <label style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                  <input type="radio" checked={payForm.mode === 'payer'} onChange={() => setPayForm({ ...payForm, mode: 'payer' })} />
                  Один платить за всіх
                </label>
              </div>
            </FormGroup>
            {payForm.mode === 'payer' && (
              <FormGroup label="Платник *">
                <ClientSearchPicker value={payForm.payer} onChange={(c) => setPayForm({ ...payForm, payer: c })} />
              </FormGroup>
            )}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Спосіб оплати">
                <select value={payForm.method} onChange={(e) => setPayForm({ ...payForm, method: e.target.value })}>
                  {Object.entries(PAY_METHODS).map(([m, l]) => <option key={m} value={m}>{l}</option>)}
                </select>
              </FormGroup>
              <FormGroup label="Заповнити суми">
                <div style={{ display: 'flex', gap: 6 }}>
                  <button type="button" className="btn btn-ghost btn-sm" onClick={() => fillAll('min')}>Мінімум</button>
                  <button type="button" className="btn btn-ghost btn-sm" onClick={() => fillAll('full')}>Повністю</button>
                </div>
              </FormGroup>
            </div>
            {payForm.method === 'deposit' && (
              <p style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: -4 }}>
                {payForm.mode === 'payer' ? 'Кошти спишуться з депозиту платника.' : 'Кошти спишуться з депозиту кожного учасника.'}
              </p>
            )}
            <div style={{ border: '1px solid var(--border)', borderRadius: 'var(--radius-sm)', maxHeight: 300, overflowY: 'auto' }}>
              {payForm.rows.map((r, i) => (
                <div key={r.invoiceId} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '8px 10px', borderBottom: '1px solid var(--border)', fontSize: 13 }}>
                  <input type="checkbox" checked={r.checked} onChange={(e) => setRow(i, { checked: e.target.checked })} />
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontWeight: 500 }}>{r.name}</div>
                    <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>
                      залишок {formatMoney(r.debt)}{r.needMin > 0 ? ` · до активації ${formatMoney(r.needMin)}` : ''}
                    </div>
                  </div>
                  <input type="number" min="0" step="1" style={{ width: 110 }} disabled={!r.checked} value={r.amount} onChange={(e) => setRow(i, { amount: e.target.value })} />
                </div>
              ))}
            </div>
            <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: 'var(--text-secondary)', marginTop: 12 }}>
              <input type="checkbox" checked={payForm.skipFiscal} onChange={(e) => setPayForm({ ...payForm, skipFiscal: e.target.checked })} />
              Не проводити фіскальний чек для цієї оплати
            </label>
          </>
        )}
      </Modal>

      {/* Редагувати групу */}
      <Modal
        open={!!editForm}
        onClose={() => setEditForm(null)}
        title="Редагувати групу"
        footer={
          <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
            <button className="btn btn-ghost" onClick={() => setEditForm(null)}>Скасувати</button>
            <button className="btn btn-primary" disabled={editForm?.submitting} onClick={submitEdit}>{editForm?.submitting ? '...' : 'Зберегти'}</button>
          </div>
        }
      >
        {editForm && (
          <>
            {editForm.error && <div className="alert alert-error">{editForm.error}</div>}
            <FormGroup label="Назва групи">
              <input type="text" value={editForm.name} onChange={(e) => setEditForm({ ...editForm, name: e.target.value })} />
            </FormGroup>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Дата початку (для всіх)">
                <input type="date" value={editForm.startDate} onChange={(e) => setEditForm({ ...editForm, startDate: e.target.value })} />
              </FormGroup>
              <FormGroup label="Тариф">
                <input type="text" value={group.tariff_name} disabled />
              </FormGroup>
              <FormGroup label="Вартість для учасника (грн)">
                <input type="number" min="0" step="1" value={editForm.memberPrice} onChange={(e) => setEditForm({ ...editForm, memberPrice: e.target.value })} />
              </FormGroup>
              <FormGroup label="Мінімальна оплата (грн)">
                <input type="number" min="0" step="1" value={editForm.minPayment} onChange={(e) => setEditForm({ ...editForm, minPayment: e.target.value })} />
              </FormGroup>
            </div>
            <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '-4px 0 12px' }}>
              Вартість і мінімальна оплата змінюються для всіх учасників. Дату початку можна змінити, лише поки ніхто з групи не відвідував клуб.
            </p>
            <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, marginBottom: 10 }}>
              <input type="checkbox" checked={editForm.limited} onChange={(e) => setEditForm({ ...editForm, limited: e.target.checked })} />
              Обмежити кількість учасників
            </label>
            {editForm.limited && (
              <FormGroup label="Максимум учасників">
                <input type="number" min="1" step="1" value={editForm.maxMembers} onChange={(e) => setEditForm({ ...editForm, maxMembers: e.target.value })} />
              </FormGroup>
            )}
            <FormGroup label="Контактна особа / платник">
              <ClientSearchPicker value={editForm.owner} onChange={(c) => setEditForm({ ...editForm, owner: c })} />
            </FormGroup>
            <FormGroup label="Примітка">
              <textarea rows="2" value={editForm.notes} onChange={(e) => setEditForm({ ...editForm, notes: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>
    </AppLayout>
  );
}
