import { useEffect, useState } from 'react';
import AppLayout from '../components/layout/AppLayout';
import Table from '../components/ui/Table';
import Pagination from '../components/ui/Pagination';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import { usePermissions } from '../hooks/usePermissions';
import { useShiftLock } from '../hooks/useShiftLock';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../components/ui/ToastProvider';
import {
  getCashSummary, getCashList, addCashExpense, addEncashment, refillFromSafe, adjustCashBalance, deleteCashRow,
  getCashShift, openCashShift, closeCashShift,
} from '../api/cash';
import { formatMoney, formatDate, localMonthStart, localToday } from '../utils/format';
import './CashPage.css';

const TYPE_LABELS = {
  income: '📈 Надходження', expense: '📉 Витрата', encashment: '⬇ Інкасація',
  transfer_in: '⬆ Переказ', transfer_out: '⬇ Переказ', adjustment: '⚖ Коригування',
};
const TYPE_ACCENT = {
  income: 'var(--success)', expense: 'var(--danger)', encashment: 'var(--warning)',
  transfer_in: 'var(--success)', transfer_out: 'var(--warning)', adjustment: 'var(--text-muted)',
};
const EXPENSE_CATEGORIES = ['Оренда', 'Комунальні', 'Зарплата', 'Закупка товарів', 'Господарські', 'Реклама', 'Інше'];

export default function CashPage() {
  const { has } = usePermissions();
  const canWrite = has('cash.record');
  const isOwner = has('cash.delete');
  const { guard, lockedProps } = useShiftLock();
  const { refresh } = useAuth();
  const toast = useToast();

  const [dateFrom, setDateFrom] = useState(localMonthStart());
  const [dateTo, setDateTo] = useState(localToday());
  const [typeFilter, setTypeFilter] = useState('');
  const [page, setPage] = useState(1);

  const [summary, setSummary] = useState(null);
  const [shift, setShift] = useState(null);
  const [list, setList] = useState({ rows: [], pagination: { total: 0, page: 1, pages: 1, per_page: 50 }, loading: true });

  const [openShiftModal, setOpenShiftModal] = useState(null);
  const [closeShiftModal, setCloseShiftModal] = useState(null);
  const [expenseModal, setExpenseModal] = useState(null);
  const [encashmentModal, setEncashmentModal] = useState(null);
  const [refillModal, setRefillModal] = useState(null);
  const [adjustModal, setAdjustModal] = useState(null);

  async function reloadSummary() {
    const res = await getCashSummary({ date_from: dateFrom, date_to: dateTo });
    if (!res.success) { toast(res.error, 'error'); return; }
    setSummary(res);
    setShift(res.shift);
  }

  async function reloadList() {
    setList((l) => ({ ...l, loading: true }));
    const res = await getCashList({ date_from: dateFrom, date_to: dateTo, type: typeFilter, page });
    if (!res.success) { toast(res.error, 'error'); setList((l) => ({ ...l, loading: false })); return; }
    setList({ rows: res.rows, pagination: res.pagination, loading: false });
  }

  useEffect(() => { reloadSummary(); }, [dateFrom, dateTo]);
  useEffect(() => { reloadList(); }, [dateFrom, dateTo, typeFilter, page]);

  async function reloadAll() {
    await reloadSummary();
    setPage(1);
    reloadList();
  }

  function selectTypeFilter(t) {
    setTypeFilter(t);
    setPage(1);
  }

  // ── Зміна ─────────────────────────────────────────────────
  async function handleOpenShiftModal() {
    const res = await getCashShift();
    setOpenShiftModal({ balance: res.success ? res.balance : null, notes: '', error: '', submitting: false });
  }

  async function submitOpenShift() {
    setOpenShiftModal({ ...openShiftModal, submitting: true, error: '' });
    const res = await openCashShift(openShiftModal.notes.trim());
    if (!res.success) { setOpenShiftModal({ ...openShiftModal, submitting: false, error: res.error }); return; }
    toast('Зміну відкрито', 'success');
    setOpenShiftModal(null);
    await reloadSummary();
    refresh();
  }

  async function handleCloseShiftModal() {
    if (!shift) return;
    const res = await getCashShift();
    setCloseShiftModal({
      openedName: res.shift?.opened_name, openedAt: res.shift?.opened_at,
      balanceOpen: res.shift?.balance_open, balanceNow: res.balance,
      counted: '', notes: '', error: '', submitting: false,
    });
  }

  async function submitCloseShift() {
    if (closeShiftModal.counted === '' || Number(closeShiftModal.counted) < 0) {
      setCloseShiftModal({ ...closeShiftModal, error: 'Перерахуйте готівку і вкажіть, скільки фактично в касі' });
      return;
    }
    setCloseShiftModal({ ...closeShiftModal, submitting: true, error: '' });
    const res = await closeCashShift(shift.id, closeShiftModal.notes.trim(), parseFloat(closeShiftModal.counted));
    if (!res.success) { setCloseShiftModal({ ...closeShiftModal, submitting: false, error: res.error }); return; }
    toast(res.message, Math.abs(res.discrepancy || 0) >= 0.01 ? 'warning' : 'success', 6000);
    setCloseShiftModal(null);
    await reloadSummary();
    refresh();
  }

  // ── Витрата ───────────────────────────────────────────────
  function handleOpenExpense() {
    setExpenseModal({ amount: '', category: '', description: '', error: '', submitting: false });
  }

  async function submitExpense() {
    setExpenseModal({ ...expenseModal, submitting: true, error: '' });
    const res = await addCashExpense({
      amount: parseFloat(expenseModal.amount) || 0,
      description: expenseModal.description.trim(),
      category: expenseModal.category,
    });
    if (!res.success) { setExpenseModal({ ...expenseModal, submitting: false, error: res.error }); return; }
    toast(res.message, 'success');
    setExpenseModal(null);
    reloadAll();
  }

  // ── Інкасація ─────────────────────────────────────────────
  async function handleOpenEncashment() {
    const res = await getCashShift();
    setEncashmentModal({ balance: res.success ? res.balance : null, amount: '', description: '', error: '', submitting: false });
  }

  async function submitEncashment() {
    setEncashmentModal({ ...encashmentModal, submitting: true, error: '' });
    const res = await addEncashment({
      amount: parseFloat(encashmentModal.amount) || 0,
      description: encashmentModal.description.trim() || 'Інкасація в сейф',
    });
    if (!res.success) { setEncashmentModal({ ...encashmentModal, submitting: false, error: res.error }); return; }
    toast(res.message, 'success');
    setEncashmentModal(null);
    reloadAll();
  }

  // ── Поповнення каси з сейфа ─────────────────────────────────
  async function handleOpenRefill() {
    const res = await getCashShift();
    setRefillModal({ safeBalance: res.success ? res.safe_balance : null, amount: '', description: '', error: '', submitting: false });
  }

  async function submitRefill() {
    setRefillModal({ ...refillModal, submitting: true, error: '' });
    const res = await refillFromSafe({
      amount: parseFloat(refillModal.amount) || 0,
      description: refillModal.description.trim() || 'Поповнення каси з сейфа',
    });
    if (!res.success) { setRefillModal({ ...refillModal, submitting: false, error: res.error }); return; }
    toast(res.message, 'success');
    setRefillModal(null);
    reloadAll();
  }

  // ── Коригування балансу ─────────────────────────────────────
  function handleOpenAdjust() {
    setAdjustModal({ location: 'register', amount: '', notes: '', error: '', submitting: false });
  }

  async function submitAdjust() {
    setAdjustModal({ ...adjustModal, submitting: true, error: '' });
    const res = await adjustCashBalance({
      location: adjustModal.location,
      amount: parseFloat(adjustModal.amount) || 0,
      notes: adjustModal.notes.trim(),
    });
    if (!res.success) { setAdjustModal({ ...adjustModal, submitting: false, error: res.error }); return; }
    toast(res.message, 'success');
    setAdjustModal(null);
    reloadAll();
  }

  // ── Видалення ─────────────────────────────────────────────
  async function handleDelete(row) {
    if (!confirm(`Видалити запис "${row.description}"?`)) return;
    const res = await deleteCashRow(row.id);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message, 'success');
    reloadAll();
  }

  const columns = [
    { key: 'description', label: 'Опис' },
    { key: 'type', label: 'Тип', cardTop: true, render: (r) => <span className={`cash-type-badge ${r.type}`}>{TYPE_LABELS[r.type] || r.type}</span> },
    { key: 'category', label: 'Категорія', mobile: 'secondary', render: (r) => r.category || '—' },
    {
      key: 'amount', label: 'Сума', mobile: 'trailing',
      render: (r) => (
        <span className={`cash-amount-${r.type}`}>
          {r.type === 'adjustment' ? formatMoney(r.amount) : `${['income', 'transfer_in'].includes(r.type) ? '+' : '−'}${formatMoney(r.amount)}`}
        </span>
      ),
    },
    { key: 'balance', label: 'Залишок', render: (r) => <strong>{formatMoney(r.running_balance)}</strong> },
    { key: 'manager', label: 'Менеджер', render: (r) => r.admin_name || '—' },
    { key: 'date', label: 'Дата', render: (r) => formatDate(r.created_at) },
    {
      key: 'actions', label: '',
      render: (r) => (isOwner && ['manual', 'expense', 'transfer', 'adjustment'].includes(r.source)
        ? <button className="confirm-btn" style={{ color: 'var(--danger)' }} onClick={(e) => { e.stopPropagation(); handleDelete(r); }}>🗑</button>
        : null),
    },
  ];

  return (
    <AppLayout title="Каса">
      <div className={`shift-panel ${shift ? 'shift-open' : 'shift-closed'}`}>
        <div className="shift-panel-info">
          <span>{shift ? '🟢' : '🔴'}</span>
          <span>{shift ? 'Зміна відкрита' : 'Зміна не відкрита'}</span>
          {shift && <span style={{ color: 'var(--text-muted)', fontSize: 13 }}>{shift.opened_name} · з {formatDate(shift.opened_at)}</span>}
        </div>
        {shift && summary && (
          <div className="shift-panel-balances">
            <span>Початок: <strong>{formatMoney(shift.balance_open)}</strong></span>
            <span>Поточний залишок: <strong>{formatMoney(summary.balance)}</strong></span>
          </div>
        )}
        {canWrite && (
          <div className="shift-panel-actions">
            {!shift && <button className="btn btn-success btn-sm" onClick={handleOpenShiftModal}>▶ Відкрити зміну</button>}
            {shift && <button className="btn btn-warning btn-sm" onClick={handleCloseShiftModal}>■ Закрити зміну</button>}
          </div>
        )}
      </div>

      <div className="cash-summary">
        <div className="cash-card balance">
          <div className="cash-card-label">💰 Залишок в касі</div>
          <div className="cash-card-value">{summary ? formatMoney(summary.balance) : '—'}</div>
          <div className="cash-card-sub">За весь час</div>
        </div>
        <div className="cash-card safe">
          <div className="cash-card-label">🔒 Сейф</div>
          <div className="cash-card-value">{summary ? formatMoney(summary.safe_balance) : '—'}</div>
          <div className="cash-card-sub">За весь час</div>
        </div>
        <div className="cash-card income">
          <div className="cash-card-label">📈 Надходження</div>
          <div className="cash-card-value">{summary ? formatMoney(summary.period_income) : '—'}</div>
          <div className="cash-card-sub">За період</div>
        </div>
        <div className="cash-card expense">
          <div className="cash-card-label">📉 Витрати + інкасації</div>
          <div className="cash-card-value">{summary ? formatMoney(summary.period_expenses) : '—'}</div>
          <div className="cash-card-sub">За період</div>
        </div>
        <div className="cash-card profit">
          <div className="cash-card-label">⚖️ Різниця за період</div>
          <div className="cash-card-value" style={{ color: summary && summary.period_profit < 0 ? 'var(--danger)' : 'var(--success)' }}>
            {summary ? formatMoney(summary.period_profit) : '—'}
          </div>
          <div className="cash-card-sub">Надходження − Витрати</div>
        </div>
      </div>

      <div className="cash-toolbar">
        <FormGroup label="Від"><input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} /></FormGroup>
        <FormGroup label="До"><input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} /></FormGroup>
        {canWrite && (
          <div className="cash-toolbar-actions">
            <button className="btn btn-ghost" {...lockedProps} onClick={guard(handleOpenExpense)}>+ Витрата</button>
            <button className="btn btn-ghost" {...lockedProps} onClick={guard(handleOpenEncashment)}>⬇ Інкасація в сейф</button>
            <button className="btn btn-ghost" {...lockedProps} onClick={guard(handleOpenRefill)}>⬆ Поповнити з сейфа</button>
            {isOwner && <button className="btn btn-ghost" onClick={handleOpenAdjust}>⚖ Коригування</button>}
          </div>
        )}
      </div>

      <div className="cash-filter-bar">
        <button className={`cash-filter-btn ${typeFilter === '' ? 'active' : ''}`} onClick={() => selectTypeFilter('')}>Всі</button>
        <button className={`cash-filter-btn ${typeFilter === 'income' ? 'active' : ''}`} onClick={() => selectTypeFilter('income')}>📈 Надходження</button>
        <button className={`cash-filter-btn ${typeFilter === 'expense' ? 'active' : ''}`} onClick={() => selectTypeFilter('expense')}>📉 Витрати</button>
        <button className={`cash-filter-btn ${typeFilter === 'transfer' ? 'active' : ''}`} onClick={() => selectTypeFilter('transfer')}>🔁 Перекази</button>
        <button className={`cash-filter-btn ${typeFilter === 'adjustment' ? 'active' : ''}`} onClick={() => selectTypeFilter('adjustment')}>⚖ Коригування</button>
      </div>

      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <Table
          columns={columns}
          rows={list.rows}
          loading={list.loading}
          emptyMessage="Операцій за цей період немає"
          rowProps={(r) => ({ className: `cash-row-${r.type}`, style: { '--card-accent': TYPE_ACCENT[r.type] } })}
        />
      </div>
      <div style={{ padding: '12px 4px' }}>
        <Pagination page={list.pagination.page} pages={list.pagination.pages} total={list.pagination.total} perPage={list.pagination.per_page} onChange={setPage} />
      </div>

      {/* Відкрити зміну */}
      <Modal
        open={!!openShiftModal}
        onClose={() => setOpenShiftModal(null)}
        title="▶ Відкрити зміну"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-success" disabled={openShiftModal?.submitting} onClick={submitOpenShift}>Відкрити</button>
            <button className="btn btn-ghost" onClick={() => setOpenShiftModal(null)}>Скасувати</button>
          </div>
        }
      >
        {openShiftModal && (
          <>
            {openShiftModal.error && <div className="alert alert-error">{openShiftModal.error}</div>}
            <p style={{ fontSize: 13, color: 'var(--text-secondary)', marginBottom: 12 }}>
              Залишок на початок зміни: <strong>{openShiftModal.balance !== null ? formatMoney(openShiftModal.balance) : '—'}</strong>
            </p>
            <FormGroup label="Коментар (необов'язково)">
              <input type="text" placeholder="Відкриття зміни..." value={openShiftModal.notes} onChange={(e) => setOpenShiftModal({ ...openShiftModal, notes: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>

      {/* Закрити зміну */}
      <Modal
        open={!!closeShiftModal}
        onClose={() => setCloseShiftModal(null)}
        title="■ Закрити зміну"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-danger" disabled={closeShiftModal?.submitting} onClick={submitCloseShift}>Закрити зміну</button>
            <button className="btn btn-ghost" onClick={() => setCloseShiftModal(null)}>Скасувати</button>
          </div>
        }
      >
        {closeShiftModal && (
          <>
            {closeShiftModal.error && <div className="alert alert-error">{closeShiftModal.error}</div>}
            <div style={{ background: 'var(--bg-surface)', borderRadius: 'var(--radius)', padding: 14, marginBottom: 16, fontSize: 13, display: 'grid', gap: 6 }}>
              <div>Відкрив: <strong>{closeShiftModal.openedName || '—'}</strong></div>
              <div>Час відкриття: <strong>{closeShiftModal.openedAt ? formatDate(closeShiftModal.openedAt) : '—'}</strong></div>
              <div>На початок: <strong>{closeShiftModal.balanceOpen !== undefined ? formatMoney(closeShiftModal.balanceOpen) : '—'}</strong></div>
              {/* Сліпа звірка: адміністратор рахує готівку, не бачачи очікуваної суми; власник бачить */}
              {isOwner && <div>Очікувано в касі: <strong>{closeShiftModal.balanceNow !== undefined ? formatMoney(closeShiftModal.balanceNow) : '—'}</strong></div>}
            </div>
            <FormGroup label="Фактично в касі (перерахуйте готівку), грн *">
              <input type="number" step="0.01" min="0" autoFocus placeholder="напр. 1250" value={closeShiftModal.counted} onChange={(e) => setCloseShiftModal({ ...closeShiftModal, counted: e.target.value })} />
            </FormGroup>
            {isOwner && closeShiftModal.counted !== '' && closeShiftModal.balanceNow !== undefined && (() => {
              const diff = Math.round((parseFloat(closeShiftModal.counted) - Number(closeShiftModal.balanceNow)) * 100) / 100;
              if (Math.abs(diff) < 0.01) return <div className="alert alert-success" style={{ marginBottom: 16 }}>Каса зійшлася</div>;
              return <div className="alert alert-error" style={{ marginBottom: 16 }}>{diff < 0 ? 'Недостача' : 'Надлишок'}: {formatMoney(Math.abs(diff))} — буде записано в журнал каси</div>;
            })()}
            <FormGroup label="Коментар (необов'язково)">
              <input type="text" placeholder="Підсумок зміни..." value={closeShiftModal.notes} onChange={(e) => setCloseShiftModal({ ...closeShiftModal, notes: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>

      {/* Витрата */}
      <Modal
        open={!!expenseModal}
        onClose={() => setExpenseModal(null)}
        title="Витрата з каси"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={expenseModal?.submitting} onClick={submitExpense}>Записати</button>
            <button className="btn btn-ghost" onClick={() => setExpenseModal(null)}>Скасувати</button>
          </div>
        }
      >
        {expenseModal && (
          <>
            {expenseModal.error && <div className="alert alert-error">{expenseModal.error}</div>}
            <FormGroup label="Сума (грн) *">
              <input type="number" min="0.01" step="0.01" placeholder="0.00" value={expenseModal.amount} onChange={(e) => setExpenseModal({ ...expenseModal, amount: e.target.value })} />
            </FormGroup>
            <FormGroup label="Категорія">
              <select value={expenseModal.category} onChange={(e) => setExpenseModal({ ...expenseModal, category: e.target.value })}>
                <option value="">— Оберіть —</option>
                {EXPENSE_CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
              </select>
            </FormGroup>
            <FormGroup label="Опис *">
              <input type="text" placeholder="Оплата оренди за квітень..." value={expenseModal.description} onChange={(e) => setExpenseModal({ ...expenseModal, description: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>

      {/* Інкасація */}
      <Modal
        open={!!encashmentModal}
        onClose={() => setEncashmentModal(null)}
        title="⬇ Інкасація в сейф"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={encashmentModal?.submitting} onClick={submitEncashment}>Провести</button>
            <button className="btn btn-ghost" onClick={() => setEncashmentModal(null)}>Скасувати</button>
          </div>
        }
      >
        {encashmentModal && (
          <>
            {encashmentModal.error && <div className="alert alert-error">{encashmentModal.error}</div>}
            <p style={{ fontSize: 13, color: 'var(--text-secondary)', marginBottom: 16 }}>
              Залишок у касі: <strong>{encashmentModal.balance !== null ? formatMoney(encashmentModal.balance) : '—'}</strong>
            </p>
            <FormGroup label="Сума виїмки (грн) *">
              <input type="number" min="0.01" step="0.01" placeholder="0.00" value={encashmentModal.amount} onChange={(e) => setEncashmentModal({ ...encashmentModal, amount: e.target.value })} />
            </FormGroup>
            <FormGroup label="Коментар">
              <input type="text" placeholder="Інкасація за день..." value={encashmentModal.description} onChange={(e) => setEncashmentModal({ ...encashmentModal, description: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>

      {/* Поповнення каси з сейфа */}
      <Modal
        open={!!refillModal}
        onClose={() => setRefillModal(null)}
        title="⬆ Поповнити касу з сейфа"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={refillModal?.submitting} onClick={submitRefill}>Провести</button>
            <button className="btn btn-ghost" onClick={() => setRefillModal(null)}>Скасувати</button>
          </div>
        }
      >
        {refillModal && (
          <>
            {refillModal.error && <div className="alert alert-error">{refillModal.error}</div>}
            <p style={{ fontSize: 13, color: 'var(--text-secondary)', marginBottom: 16 }}>
              Залишок у сейфі: <strong>{refillModal.safeBalance !== null ? formatMoney(refillModal.safeBalance) : '—'}</strong>
            </p>
            <FormGroup label="Сума поповнення (грн) *">
              <input type="number" min="0.01" step="0.01" placeholder="0.00" value={refillModal.amount} onChange={(e) => setRefillModal({ ...refillModal, amount: e.target.value })} />
            </FormGroup>
            <FormGroup label="Коментар">
              <input type="text" placeholder="Поповнення каси..." value={refillModal.description} onChange={(e) => setRefillModal({ ...refillModal, description: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>

      {/* Коригування балансу */}
      <Modal
        open={!!adjustModal}
        onClose={() => setAdjustModal(null)}
        title="⚖ Коригування балансу"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={adjustModal?.submitting} onClick={submitAdjust}>Скоригувати</button>
            <button className="btn btn-ghost" onClick={() => setAdjustModal(null)}>Скасувати</button>
          </div>
        }
      >
        {adjustModal && (
          <>
            {adjustModal.error && <div className="alert alert-error">{adjustModal.error}</div>}
            <p style={{ fontSize: 13, color: 'var(--text-secondary)', marginBottom: 16 }}>
              Для стартового залишку або виправлення помилки. Сума додається до балансу — від'ємне число зменшить його. Розбіжність при здачі зміни фіксується автоматично («Фактично в касі» при закритті зміни). Про кожне коригування надходить сповіщення в Telegram.
            </p>
            <FormGroup label="Локація">
              <select value={adjustModal.location} onChange={(e) => setAdjustModal({ ...adjustModal, location: e.target.value })}>
                <option value="register">Каса</option>
                <option value="safe">Сейф</option>
              </select>
            </FormGroup>
            <FormGroup label="Сума (грн, зі знаком) *">
              <input type="number" step="0.01" placeholder="напр. 500 або -120" value={adjustModal.amount} onChange={(e) => setAdjustModal({ ...adjustModal, amount: e.target.value })} />
            </FormGroup>
            <FormGroup label="Причина *">
              <input type="text" placeholder="Стартовий залишок / виправлення помилки..." value={adjustModal.notes} onChange={(e) => setAdjustModal({ ...adjustModal, notes: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>
    </AppLayout>
  );
}
