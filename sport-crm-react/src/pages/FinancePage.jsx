import { useEffect, useMemo, useState } from 'react';
import AppLayout from '../components/layout/AppLayout';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import Table from '../components/ui/Table';
import Icon from '../components/ui/Icon';
import { usePermissions } from '../hooks/usePermissions';
import { useToast } from '../components/ui/ToastProvider';
import {
  getFinanceSummary, getFinanceIncome, getFinanceExpenses,
  addFinanceExpense, updateFinanceExpense, deleteFinanceExpense,
  getFinanceDeposits, getExpenseCategories, addDeposit, updateDeposit, deleteDeposit,
} from '../api/finance';
import { searchClients } from '../api/clients';
import { formatMoney, formatDate, localMonthStart, localToday, localDate } from '../utils/format';
import './FinancePage.css';

const PAY_LABELS = { cash: 'Готівка', card: 'Карта', terminal: 'Термінал', transfer: 'Переказ', deposit: 'Депозит', other: 'Інше' };
const PAY_ICONS = { cash: 'banknote', card: 'card', terminal: 'atm', transfer: 'link', deposit: 'wallet', other: 'card' };
const DEP_OP_LABELS = { top_up: 'Поповнення', pay_invoice: 'Оплата абонементу', pay_product: 'Оплата товару', refund: 'Повернення', correction: 'Коригування', certificate: 'Сертифікат' };

function todayRange() {
  const t = localToday();
  return { date_from: t, date_to: t };
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
function yearRange() {
  const now = new Date();
  return { date_from: `${now.getFullYear()}-01-01`, date_to: localToday() };
}

const PERIODS = [
  ['month', 'Цей місяць', () => ({ date_from: localMonthStart(), date_to: localToday() })],
  ['prev_month', 'Минулий місяць', prevMonthRange],
  ['week', 'Цей тиждень', weekRange],
  ['today', 'Сьогодні', todayRange],
  ['year', 'Цей рік', yearRange],
];

const OPS_TABS = [
  ['all', 'Всі'],
  ['income', 'Надходження'],
  ['expenses', 'Витрати'],
  ['deposits', 'Депозити'],
];

function dateOfRaw(row) {
  return row.__type === 'expense' ? row.expense_date : row.created_at;
}

function normalizeRow(row) {
  const date = dateOfRaw(row);
  if (row.__type === 'income') {
    const isInvoice = row.source_type === 'invoice';
    return {
      key: `income-${row.source_type}-${row.id}`,
      date,
      mainText: isInvoice ? 'Оплата абонементу' : 'Продаж товару',
      subText: [row.description, row.client_name].filter(Boolean).join(' · ') || '—',
      categoryLabel: isInvoice ? 'Абонементи' : 'Товари',
      categoryClass: isInvoice ? 'invoice' : 'product',
      method: row.payment_method,
      displayAmount: '+' + formatMoney(row.amount),
      amountClass: 'pos',
      raw: null,
    };
  }
  if (row.__type === 'expense') {
    return {
      key: `expense-${row.id}`,
      date,
      mainText: row.description,
      subText: row.notes || '',
      categoryLabel: row.category || 'Без категорії',
      categoryClass: 'expense',
      method: row.payment_method,
      displayAmount: '−' + formatMoney(row.amount),
      amountClass: 'neg',
      raw: { ...row, __rowType: 'expense' },
    };
  }
  const amt = parseFloat(row.amount) || 0;
  return {
    key: `deposit-${row.id}`,
    date,
    mainText: row.client_name || '—',
    subText: [DEP_OP_LABELS[row.operation] || row.operation, row.client_phone].filter(Boolean).join(' · '),
    categoryLabel: DEP_OP_LABELS[row.operation] || row.operation || 'Депозит',
    categoryClass: 'deposit',
    method: row.payment_method,
    displayAmount: (amt >= 0 ? '+' : '−') + formatMoney(Math.abs(amt)),
    amountClass: amt >= 0 ? 'pos' : 'neg',
    raw: { ...row, __rowType: 'deposit' },
  };
}

function csvCell(v) {
  const s = String(v ?? '');
  return /[;"\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

export default function FinancePage() {
  const { has } = usePermissions();
  const canWrite = has('finance.manage');
  const isOwner = has('finance.delete');
  const toast = useToast();

  const [dateFrom, setDateFrom] = useState(localMonthStart());
  const [dateTo, setDateTo] = useState(localToday());
  const [activePeriod, setActivePeriod] = useState('month');
  const [opsTab, setOpsTab] = useState('all');

  const [summary, setSummary] = useState(null);
  const [expensesByCategory, setExpensesByCategory] = useState([]);

  const [income, setIncome] = useState({ rows: [], loading: true });
  const [expCatFilter, setExpCatFilter] = useState('');
  const [expenses, setExpenses] = useState({ rows: [], total: 0, loading: true });
  const [allExpenses, setAllExpenses] = useState({ rows: [], loading: true });
  const [categories, setCategories] = useState([]);
  const [deposits, setDeposits] = useState({ rows: [], summary: {}, loading: true });

  const [expenseModal, setExpenseModal] = useState(null);
  const [depositModal, setDepositModal] = useState(null);
  const [deleteExpenseModal, setDeleteExpenseModal] = useState(null);
  const [depositEditModal, setDepositEditModal] = useState(null);
  const [deleteDepositModal, setDeleteDepositModal] = useState(null);

  function getDates() {
    return { date_from: dateFrom, date_to: dateTo };
  }

  async function reloadSummary() {
    const res = await getFinanceSummary(getDates());
    if (!res.success) { toast(res.error, 'error'); return; }
    setSummary(res.summary);
    setExpensesByCategory(res.expenses_by_category || []);
  }

  async function reloadIncome() {
    setIncome((s) => ({ ...s, loading: true }));
    const res = await getFinanceIncome(getDates());
    if (!res.success) { toast(res.error, 'error'); setIncome({ rows: [], loading: false }); return; }
    setIncome({ rows: res.income || [], loading: false });
  }

  async function reloadExpenses() {
    setExpenses((s) => ({ ...s, loading: true }));
    const res = await getFinanceExpenses({ ...getDates(), category: expCatFilter });
    if (!res.success) { toast(res.error, 'error'); setExpenses({ rows: [], total: 0, loading: false }); return; }
    setExpenses({ rows: res.expenses || [], total: parseFloat(res.total) || 0, loading: false });
  }

  async function reloadAllExpenses() {
    setAllExpenses((s) => ({ ...s, loading: true }));
    const res = await getFinanceExpenses({ ...getDates(), category: '' });
    if (!res.success) { setAllExpenses({ rows: [], loading: false }); return; }
    setAllExpenses({ rows: res.expenses || [], loading: false });
  }

  async function reloadDeposits() {
    setDeposits((s) => ({ ...s, loading: true }));
    const res = await getFinanceDeposits(getDates());
    if (!res.success) { toast(res.error, 'error'); setDeposits({ rows: [], summary: {}, loading: false }); return; }
    setDeposits({ rows: res.deposits || [], summary: res.summary || {}, loading: false });
  }

  useEffect(() => {
    getExpenseCategories().then((res) => { if (res.success) setCategories(res.categories || []); });
  }, []);

  useEffect(() => { reloadSummary(); }, [dateFrom, dateTo]);
  useEffect(() => { if (opsTab === 'income' || opsTab === 'all') reloadIncome(); }, [opsTab, dateFrom, dateTo]);
  useEffect(() => { if (opsTab === 'expenses') reloadExpenses(); }, [opsTab, dateFrom, dateTo, expCatFilter]);
  useEffect(() => { if (opsTab === 'all') reloadAllExpenses(); }, [opsTab, dateFrom, dateTo]);
  useEffect(() => { if (opsTab === 'deposits' || opsTab === 'all') reloadDeposits(); }, [opsTab, dateFrom, dateTo]);

  function selectPeriod(key, calc) {
    setActivePeriod(key);
    const { date_from, date_to } = calc();
    setDateFrom(date_from);
    setDateTo(date_to);
  }

  // ── Витрата ───────────────────────────────────────────────
  function openCreateExpense() {
    setExpenseModal({ editId: null, category: '', description: '', amount: '', date: localToday(), method: 'cash', notes: '', error: '', submitting: false });
  }

  function openEditExpense(e) {
    setExpenseModal({
      editId: e.id, category: e.category || '', description: e.description || '',
      amount: String(e.amount ?? ''), date: e.expense_date || localToday(),
      method: e.payment_method || 'cash', notes: e.notes || '', error: '', submitting: false,
    });
  }

  async function submitExpense() {
    if (!expenseModal.description.trim()) { setExpenseModal({ ...expenseModal, error: "Введіть опис" }); return; }
    const amount = parseFloat(expenseModal.amount) || 0;
    if (amount <= 0) { setExpenseModal({ ...expenseModal, error: 'Сума має бути більше 0' }); return; }
    setExpenseModal({ ...expenseModal, submitting: true, error: '' });
    const payload = {
      id: expenseModal.editId,
      category: expenseModal.category.trim(),
      description: expenseModal.description.trim(),
      amount,
      expense_date: expenseModal.date,
      payment_method: expenseModal.method,
      notes: expenseModal.notes.trim(),
    };
    const res = expenseModal.editId ? await updateFinanceExpense(payload) : await addFinanceExpense(payload);
    if (!res.success) { setExpenseModal({ ...expenseModal, submitting: false, error: res.error }); return; }
    toast(expenseModal.editId ? 'Збережено' : 'Витрату записано', 'success');
    setExpenseModal(null);
    reloadSummary();
    reloadExpenses();
    reloadAllExpenses();
    getExpenseCategories().then((r) => { if (r.success) setCategories(r.categories || []); });
  }

  function openDeleteExpense(e) {
    setDeleteExpenseModal({ id: e.id, description: e.description });
  }

  async function confirmDeleteExpense() {
    const res = await deleteFinanceExpense(deleteExpenseModal.id);
    setDeleteExpenseModal(null);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast('Видалено', 'success');
    reloadSummary();
    reloadExpenses();
    reloadAllExpenses();
  }

  // ── Депозит ───────────────────────────────────────────────
  function openDepositModal() {
    setDepositModal({ search: '', results: [], clientId: null, clientName: '', clientBalance: 0, amount: '', method: 'cash', notes: '', error: '', submitting: false });
  }

  useEffect(() => {
    if (!depositModal || depositModal.search.trim().length < 2) {
      if (depositModal) setDepositModal((m) => ({ ...m, results: [] }));
      return;
    }
    const t = setTimeout(async () => {
      const res = await searchClients(depositModal.search.trim());
      setDepositModal((m) => (m ? { ...m, results: res.success ? (res.results || []) : [] } : m));
    }, 300);
    return () => clearTimeout(t);
  }, [depositModal?.search]);

  async function submitDeposit() {
    if (!depositModal.clientId) { setDepositModal({ ...depositModal, error: 'Оберіть клієнта' }); return; }
    const amount = parseFloat(depositModal.amount) || 0;
    if (amount <= 0) { setDepositModal({ ...depositModal, error: 'Сума має бути більше 0' }); return; }
    setDepositModal({ ...depositModal, submitting: true, error: '' });
    const res = await addDeposit({
      client_id: depositModal.clientId,
      amount,
      payment_method: depositModal.method,
      notes: depositModal.notes.trim(),
    });
    if (!res.success) { setDepositModal({ ...depositModal, submitting: false, error: res.error }); return; }
    toast(res.message, 'success');
    setDepositModal(null);
    reloadSummary();
    reloadDeposits();
  }

  function openEditDeposit(d) {
    setDepositEditModal({
      id: d.id, amount: String(d.amount ?? ''), method: d.payment_method || 'cash',
      notes: d.notes || '', error: '', submitting: false,
    });
  }

  async function submitEditDeposit() {
    const amount = parseFloat(depositEditModal.amount) || 0;
    if (amount <= 0) { setDepositEditModal({ ...depositEditModal, error: 'Сума має бути більше 0' }); return; }
    setDepositEditModal({ ...depositEditModal, submitting: true, error: '' });
    const res = await updateDeposit({
      id: depositEditModal.id, amount,
      payment_method: depositEditModal.method,
      notes: depositEditModal.notes.trim(),
    });
    if (!res.success) { setDepositEditModal({ ...depositEditModal, submitting: false, error: res.error }); return; }
    toast('Збережено', 'success');
    setDepositEditModal(null);
    reloadSummary();
    reloadDeposits();
  }

  function openDeleteDeposit(d) {
    setDeleteDepositModal({ id: d.id, description: d.client_name || 'Депозит' });
  }

  async function confirmDeleteDeposit() {
    const res = await deleteDeposit(deleteDepositModal.id);
    setDeleteDepositModal(null);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast('Видалено', 'success');
    reloadSummary();
    reloadDeposits();
  }

  const profit = summary?.net_profit ?? 0;
  const maxCat = expensesByCategory.length ? Math.max(...expensesByCategory.map((c) => parseFloat(c.total))) : 0;

  // ── Структура надходжень (донат) ─────────────────────────
  const donutSegments = summary ? [
    { label: 'Абонементи', value: parseFloat(summary.invoices_income) || 0, color: 'var(--success)', sub: `${summary.invoices_count} платежів` },
    { label: 'Продаж товарів', value: parseFloat(summary.products_income) || 0, color: 'var(--warning)', sub: `${summary.products_count} продажів` },
    { label: 'Поповнення депозитів', value: parseFloat(summary.deposits_top_up) || 0, color: 'var(--accent)', sub: 'поповнення' },
  ] : [];
  const donutTotal = donutSegments.reduce((s, x) => s + x.value, 0);
  const R = 80, C = 2 * Math.PI * R;
  let donutCursor = 0;

  // ── Останні операції: об'єднані/фільтровані рядки ────────
  const mergedRawRows = useMemo(() => {
    const inc = income.rows.map((r) => ({ ...r, __type: 'income' }));
    const exp = allExpenses.rows.map((r) => ({ ...r, __type: 'expense' }));
    const dep = deposits.rows.map((r) => ({ ...r, __type: 'deposit' }));
    return [...inc, ...exp, ...dep].sort((a, b) => new Date(dateOfRaw(b)) - new Date(dateOfRaw(a)));
  }, [income.rows, allExpenses.rows, deposits.rows]);

  const rawRowsByTab = useMemo(() => {
    if (opsTab === 'income') return income.rows.map((r) => ({ ...r, __type: 'income' }));
    if (opsTab === 'expenses') return expenses.rows.map((r) => ({ ...r, __type: 'expense' }));
    if (opsTab === 'deposits') return deposits.rows.map((r) => ({ ...r, __type: 'deposit' }));
    return mergedRawRows;
  }, [opsTab, income.rows, expenses.rows, deposits.rows, mergedRawRows]);

  const visibleRows = useMemo(() => rawRowsByTab.map(normalizeRow), [rawRowsByTab]);
  const visibleLoading = opsTab === 'all' ? (income.loading || allExpenses.loading || deposits.loading)
    : opsTab === 'income' ? income.loading
    : opsTab === 'expenses' ? expenses.loading
    : deposits.loading;

  function exportCsv() {
    if (!visibleRows.length) { toast('Немає даних для експорту', 'error'); return; }
    const header = ['Дата', 'Операція', 'Категорія', 'Метод оплати', 'Сума', 'Статус'];
    const lines = visibleRows.map((r) => [
      formatDate(r.date), r.mainText, r.categoryLabel, PAY_LABELS[r.method] || r.method, r.displayAmount, 'Завершено',
    ]);
    const csv = [header, ...lines].map((row) => row.map(csvCell).join(';')).join('\r\n');
    const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `finance_${dateFrom}_${dateTo}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }

  const opsColumns = [
    {
      key: 'op', label: 'Операція', render: (r) => (
        <div>
          <div className="fin-op-main">{r.mainText}</div>
          {r.subText && <div className="fin-op-sub">{r.subText}</div>}
        </div>
      ),
    },
    { key: 'date', label: 'Дата', render: (r) => formatDate(r.date) },
    { key: 'category', label: 'Категорія', cardTop: true, render: (r) => <span className={`fin-badge ${r.categoryClass}`}>{r.categoryLabel}</span> },
    {
      key: 'method', label: 'Метод оплати',
      mobile: 'secondary',
      render: (r) => (
        <span className="fin-method"><Icon name={PAY_ICONS[r.method] || 'card'} size={15} />{PAY_LABELS[r.method] || r.method}</span>
      ),
    },
    { key: 'amount', label: 'Сума', mobile: 'trailing', render: (r) => <span className={`fin-amount ${r.amountClass}`}>{r.displayAmount}</span> },
    { key: 'status', label: 'Статус', render: () => <span className="fin-status"><span className="fin-status-dot" />Завершено</span> },
    {
      key: 'actions', label: '', render: (r) => {
        if (!r.raw || !canWrite) return null;
        if (r.raw.__rowType === 'deposit') {
          if (r.raw.operation !== 'top_up') {
            return <span title="Системний запис — створено автоматично, редагування/видалення вручну недоступне" style={{ color: 'var(--text-muted)' }}><Icon name="lock" size={14} /></span>;
          }
          return (
            <div className="fin-row-actions">
              <button className="btn btn-ghost btn-sm" title="Редагувати" onClick={() => openEditDeposit(r.raw)}><Icon name="edit" size={14} /></button>
              <button className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }} title="Видалити" onClick={() => openDeleteDeposit(r.raw)}><Icon name="trash" size={14} /></button>
            </div>
          );
        }
        if (r.raw.source && r.raw.source !== 'manual') {
          return <span title="Системний запис — створено автоматично, редагування/видалення вручну недоступне" style={{ color: 'var(--text-muted)' }}><Icon name="lock" size={14} /></span>;
        }
        return (
          <div className="fin-row-actions">
            <button className="btn btn-ghost btn-sm" title="Редагувати" onClick={() => openEditExpense(r.raw)}><Icon name="edit" size={14} /></button>
            {isOwner && <button className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }} title="Видалити" onClick={() => openDeleteExpense(r.raw)}><Icon name="trash" size={14} /></button>}
          </div>
        );
      },
    },
  ];

  return (
    <AppLayout title="Фінанси" subtitle="Повна інформація про доходи, витрати та рух коштів">
      <div className="fin-toolbar">
        <div className="fin-periods">
          {PERIODS.map(([key, label, calc]) => (
            <button key={key} className={`fin-period-btn ${activePeriod === key ? 'active' : ''}`} onClick={() => selectPeriod(key, calc)}>{label}</button>
          ))}
        </div>

        <select
          className="fin-periods-select"
          value={activePeriod}
          onChange={(e) => {
            const found = PERIODS.find(([key]) => key === e.target.value);
            if (found) selectPeriod(found[0], found[2]);
          }}
        >
          {!activePeriod && <option value="">Період…</option>}
          {PERIODS.map(([key, label]) => <option key={key} value={key}>{label}</option>)}
        </select>

        <div className="fin-daterange">
          <Icon name="calendar" size={15} />
          <input type="date" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setActivePeriod(''); }} />
          <span className="fin-daterange-sep">–</span>
          <input type="date" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setActivePeriod(''); }} />
        </div>

        <div className="fin-toolbar-actions">
          <button className="btn btn-ghost" onClick={exportCsv}><Icon name="download" size={15} /> Експорт</button>
          {canWrite && <button className="btn btn-primary" onClick={openDepositModal}><Icon name="plus" size={15} /> Депозит</button>}
        </div>
      </div>

      <div className="fin-kpi-grid">
        <div className="fin-kpi-card">
          <div className="fin-kpi-top"><span className="fin-kpi-icon success"><Icon name="wallet" size={18} /></span></div>
          <div className="fin-kpi-label">Надходження</div>
          <div className="fin-kpi-value pos">{summary ? formatMoney(summary.total_income) : <span className="spinner" />}</div>
          <div className="fin-kpi-sub">{summary ? `абонементи ${formatMoney(summary.invoices_income)} · товари ${formatMoney(summary.products_income)}` : '—'}</div>
        </div>
        <div className="fin-kpi-card">
          <div className="fin-kpi-top"><span className="fin-kpi-icon danger"><Icon name="receipt" size={18} /></span></div>
          <div className="fin-kpi-label">Витрати</div>
          <div className="fin-kpi-value neg">{summary ? formatMoney(summary.total_expense) : '—'}</div>
          <div className="fin-kpi-sub">{summary ? `${summary.expenses_count} записів` : '—'}</div>
        </div>
        <div className="fin-kpi-card">
          <div className="fin-kpi-top"><span className="fin-kpi-icon purple"><Icon name="trendingUp" size={18} /></span></div>
          <div className="fin-kpi-label">Чистий прибуток</div>
          <div className={`fin-kpi-value ${profit >= 0 ? 'pos' : 'neg'}`}>{summary ? `${profit < 0 ? '−' : ''}${formatMoney(Math.abs(profit))}` : '—'}</div>
          <div className="fin-kpi-sub">надходження − витрати</div>
        </div>
        <div className="fin-kpi-card">
          <div className="fin-kpi-top"><span className="fin-kpi-icon accent"><Icon name="card" size={18} /></span></div>
          <div className="fin-kpi-label">Поповнено депозитів</div>
          <div className="fin-kpi-value">{summary ? formatMoney(summary.deposits_top_up) : '—'}</div>
          <div className="fin-kpi-sub">клієнтські баланси</div>
        </div>
      </div>

      {summary && (
        <div className="fin-methods-card">
          <div className="fin-method-col">
            <span className="fin-method-icon success"><Icon name="banknote" size={17} /></span>
            <div><div className="fin-method-label">Готівка</div><div className="fin-method-value">{formatMoney(summary.by_method.cash)}</div></div>
          </div>
          <div className="fin-method-col">
            <span className="fin-method-icon accent"><Icon name="card" size={17} /></span>
            <div><div className="fin-method-label">Карта</div><div className="fin-method-value">{formatMoney(summary.by_method.card)}</div></div>
          </div>
          <div className="fin-method-col">
            <span className="fin-method-icon purple"><Icon name="atm" size={17} /></span>
            <div><div className="fin-method-label">Термінал</div><div className="fin-method-value">{formatMoney(summary.by_method.terminal)}</div></div>
          </div>
        </div>
      )}

      {summary && (
        <div className="fin-grid-2">
          <div className="card">
            <div className="card-title">Структура надходжень</div>
            {donutTotal <= 0 ? (
              <div className="fin-empty"><Icon name="wallet" size={36} /><span>Надходжень за цей період немає</span></div>
            ) : (
              <>
                <div className="fin-donut-wrap">
                  <svg viewBox="0 0 200 200" className="fin-donut-svg">
                    <circle cx="100" cy="100" r={R} fill="none" stroke="var(--border)" strokeWidth="24" />
                    {donutSegments.filter((s) => s.value > 0).map((s) => {
                      const pct = (s.value / donutTotal) * 100;
                      const dash = (pct / 100) * C;
                      const offset = -(donutCursor / 100) * C;
                      donutCursor += pct;
                      return (
                        <circle key={s.label} cx="100" cy="100" r={R} fill="none" stroke={s.color} strokeWidth="24"
                          strokeDasharray={`${dash} ${C - dash}`} strokeDashoffset={offset} transform="rotate(-90 100 100)" />
                      );
                    })}
                  </svg>
                  <div className="fin-donut-center">
                    <div className="fin-donut-total">{formatMoney(donutTotal)}</div>
                    <div className="fin-donut-total-label">Всього</div>
                  </div>
                </div>
                <div className="fin-donut-legend">
                  {donutSegments.map((s) => (
                    <div className="fin-legend-row" key={s.label}>
                      <span className="fin-legend-dot" style={{ background: s.color }} />
                      <div className="fin-legend-info">
                        <div className="fin-legend-name">{s.label}</div>
                        <div className="fin-legend-sub">{s.sub}</div>
                      </div>
                      <div className="fin-legend-nums">
                        <div className="fin-legend-val">{formatMoney(s.value)}</div>
                        <div className="fin-legend-pct">{donutTotal > 0 ? ((s.value / donutTotal) * 100).toFixed(1) : '0'}%</div>
                      </div>
                    </div>
                  ))}
                </div>
              </>
            )}
          </div>

          <div className="card">
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
              <div className="card-title" style={{ margin: 0 }}>Витрати по категоріях</div>
              {canWrite && <button className="btn btn-ghost btn-sm" onClick={openCreateExpense}><Icon name="plus" size={14} /> Додати витрату</button>}
            </div>
            {expensesByCategory.length === 0 ? (
              <div className="fin-empty"><Icon name="receipt" size={36} /><span>Витрат за цей період немає</span></div>
            ) : (
              expensesByCategory.map((c) => (
                <div className="cat-bar-row" key={c.category || '—'}>
                  <div className="cat-bar-name">{c.category || 'Без категорії'}</div>
                  <div className="cat-bar-track"><div className="cat-bar-fill" style={{ width: `${maxCat > 0 ? Math.round((parseFloat(c.total) / maxCat) * 100) : 0}%` }} /></div>
                  <div className="cat-bar-val">{formatMoney(c.total)}</div>
                </div>
              ))
            )}
          </div>
        </div>
      )}

      <div className="card">
        <div className="fin-ops-header">
          <div className="card-title" style={{ margin: 0 }}>Останні операції</div>
          <div className="fin-ops-tabs">
            {OPS_TABS.map(([key, label]) => (
              <button key={key} className={opsTab === key ? 'active' : ''} onClick={() => setOpsTab(key)}>{label}</button>
            ))}
          </div>
        </div>

        {opsTab === 'expenses' && (
          <div className="fin-ops-filters">
            <select value={expCatFilter} onChange={(e) => setExpCatFilter(e.target.value)}>
              <option value="">Всі категорії</option>
              {categories.map((c) => <option key={c} value={c}>{c}</option>)}
            </select>
            {canWrite && <button className="btn btn-ghost btn-sm" onClick={openCreateExpense}><Icon name="plus" size={14} /> Витрата</button>}
          </div>
        )}
        {opsTab === 'deposits' && !deposits.loading && (
          <div className="fin-ops-filters">
            <span className="fin-chip success">Поповнень: {formatMoney(deposits.summary.top_up || 0)}</span>
            <span className="fin-chip danger">Списань: {formatMoney(Math.abs(deposits.summary.write_off || 0))}</span>
          </div>
        )}
        <Table columns={opsColumns} rows={visibleRows} keyField="key" loading={visibleLoading} emptyMessage="Операцій за цей період немає" />

        {opsTab === 'expenses' && !expenses.loading && expenses.rows.length > 0 && (
          <div className="fin-ops-total">Разом за період: <span className="neg">{formatMoney(expenses.total)}</span></div>
        )}
      </div>

      {/* Витрата */}
      <Modal
        open={!!expenseModal}
        onClose={() => setExpenseModal(null)}
        title={expenseModal?.editId ? 'Редагування витрати' : 'Нова витрата'}
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={expenseModal?.submitting} onClick={submitExpense}>Зберегти</button>
            <button className="btn btn-ghost" onClick={() => setExpenseModal(null)}>Скасувати</button>
          </div>
        }
      >
        {expenseModal && (
          <>
            {expenseModal.error && <div className="alert alert-error">{expenseModal.error}</div>}
            <FormGroup label="Категорія">
              <input type="text" list="fin-exp-cat-list" placeholder="Оренда, Комунальні, Зарплата..." value={expenseModal.category} onChange={(e) => setExpenseModal({ ...expenseModal, category: e.target.value })} />
              <datalist id="fin-exp-cat-list">{categories.map((c) => <option key={c} value={c} />)}</datalist>
            </FormGroup>
            <FormGroup label="Опис *">
              <input type="text" maxLength={255} placeholder="Оплата оренди за вересень" value={expenseModal.description} onChange={(e) => setExpenseModal({ ...expenseModal, description: e.target.value })} />
            </FormGroup>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Сума (грн) *">
                <input type="number" placeholder="0" min="0.01" step="0.01" value={expenseModal.amount} onChange={(e) => setExpenseModal({ ...expenseModal, amount: e.target.value })} />
              </FormGroup>
              <FormGroup label="Дата">
                <input type="date" value={expenseModal.date} onChange={(e) => setExpenseModal({ ...expenseModal, date: e.target.value })} />
              </FormGroup>
            </div>
            <FormGroup label="Спосіб оплати">
              <select value={expenseModal.method} onChange={(e) => setExpenseModal({ ...expenseModal, method: e.target.value })}>
                <option value="cash">Готівка</option>
                <option value="card">Карта</option>
                <option value="terminal">Термінал</option>
                <option value="transfer">Переказ</option>
              </select>
            </FormGroup>
            <FormGroup label="Примітка">
              <textarea rows={2} placeholder="Необов'язково" value={expenseModal.notes} onChange={(e) => setExpenseModal({ ...expenseModal, notes: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>

      {/* Депозит */}
      <Modal
        open={!!depositModal}
        onClose={() => setDepositModal(null)}
        title="Поповнення депозиту"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={depositModal?.submitting} onClick={submitDeposit}>Поповнити</button>
            <button className="btn btn-ghost" onClick={() => setDepositModal(null)}>Скасувати</button>
          </div>
        }
      >
        {depositModal && (
          <>
            {depositModal.error && <div className="alert alert-error">{depositModal.error}</div>}
            <FormGroup label="Клієнт *">
              <div style={{ position: 'relative' }}>
                <input type="text" placeholder="Введіть ім'я або телефон..." autoComplete="off" value={depositModal.search} onChange={(e) => setDepositModal({ ...depositModal, search: e.target.value })} />
                {depositModal.results.length > 0 && (
                  <div style={{ position: 'absolute', top: '100%', left: 0, right: 0, background: 'var(--bg-elevated)', border: '1px solid var(--border-light)', borderRadius: 'var(--radius-sm)', zIndex: 100, maxHeight: 180, overflowY: 'auto', boxShadow: 'var(--shadow-md)', marginTop: 4 }}>
                    {depositModal.results.map((c) => (
                      <div key={c.id} style={{ padding: '9px 12px', cursor: 'pointer', fontSize: 14, borderBottom: '1px solid var(--border)' }}
                        onClick={() => setDepositModal({ ...depositModal, clientId: c.id, clientName: c.full_name, clientBalance: c.balance || 0, search: '', results: [] })}>
                        <strong>{c.full_name}</strong> <span style={{ color: 'var(--text-muted)', fontSize: 12, marginLeft: 8 }}>{c.phone || ''}</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
              {depositModal.clientId && (
                <div style={{ marginTop: 8, padding: '10px 12px', background: 'var(--accent-dim)', borderRadius: 'var(--radius-sm)', fontSize: 14 }}>
                  <strong>{depositModal.clientName}</strong>
                  <span style={{ marginLeft: 8, color: 'var(--text-secondary)', fontSize: 12 }}>Баланс: {formatMoney(depositModal.clientBalance)}</span>
                  <button onClick={() => setDepositModal({ ...depositModal, clientId: null, clientName: '' })} style={{ float: 'right', background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)' }}>✕</button>
                </div>
              )}
            </FormGroup>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Сума поповнення (грн) *">
                <input type="number" placeholder="0" min="1" step="1" value={depositModal.amount} onChange={(e) => setDepositModal({ ...depositModal, amount: e.target.value })} />
              </FormGroup>
              <FormGroup label="Спосіб оплати">
                <select value={depositModal.method} onChange={(e) => setDepositModal({ ...depositModal, method: e.target.value })}>
                  <option value="cash">Готівка</option>
                  <option value="card">Карта</option>
                  <option value="terminal">Термінал</option>
                  <option value="transfer">Переказ</option>
                </select>
              </FormGroup>
            </div>
            <FormGroup label="Примітка">
              <input type="text" placeholder="Необов'язково" value={depositModal.notes} onChange={(e) => setDepositModal({ ...depositModal, notes: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>

      {/* Підтвердження видалення витрати */}
      <Modal
        size="sm"
        open={!!deleteExpenseModal}
        onClose={() => setDeleteExpenseModal(null)}
        title="Видалити витрату?"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-danger" onClick={confirmDeleteExpense}>Видалити</button>
            <button className="btn btn-ghost" onClick={() => setDeleteExpenseModal(null)}>Скасувати</button>
          </div>
        }
      >
        {deleteExpenseModal && <p style={{ fontSize: 14, color: 'var(--text-secondary)' }}>{deleteExpenseModal.description}</p>}
      </Modal>

      {/* Редагування депозиту (лише ручні поповнення) */}
      <Modal
        open={!!depositEditModal}
        onClose={() => setDepositEditModal(null)}
        title="Редагування депозиту"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={depositEditModal?.submitting} onClick={submitEditDeposit}>Зберегти</button>
            <button className="btn btn-ghost" onClick={() => setDepositEditModal(null)}>Скасувати</button>
          </div>
        }
      >
        {depositEditModal && (
          <>
            {depositEditModal.error && <div className="alert alert-error">{depositEditModal.error}</div>}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Сума (грн) *">
                <input type="number" placeholder="0" min="1" step="1" value={depositEditModal.amount} onChange={(e) => setDepositEditModal({ ...depositEditModal, amount: e.target.value })} />
              </FormGroup>
              <FormGroup label="Спосіб оплати">
                <select value={depositEditModal.method} onChange={(e) => setDepositEditModal({ ...depositEditModal, method: e.target.value })}>
                  <option value="cash">Готівка</option>
                  <option value="card">Карта</option>
                  <option value="terminal">Термінал</option>
                  <option value="transfer">Переказ</option>
                </select>
              </FormGroup>
            </div>
            <FormGroup label="Примітка">
              <input type="text" placeholder="Необов'язково" value={depositEditModal.notes} onChange={(e) => setDepositEditModal({ ...depositEditModal, notes: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>

      {/* Підтвердження видалення депозиту */}
      <Modal
        size="sm"
        open={!!deleteDepositModal}
        onClose={() => setDeleteDepositModal(null)}
        title="Видалити депозит?"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-danger" onClick={confirmDeleteDeposit}>Видалити</button>
            <button className="btn btn-ghost" onClick={() => setDeleteDepositModal(null)}>Скасувати</button>
          </div>
        }
      >
        {deleteDepositModal && <p style={{ fontSize: 14, color: 'var(--text-secondary)' }}>{deleteDepositModal.description}</p>}
      </Modal>
    </AppLayout>
  );
}
