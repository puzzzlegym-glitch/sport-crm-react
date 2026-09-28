import { useEffect, useMemo, useRef, useState } from 'react';
import { Navigate } from 'react-router-dom';
import AppLayout from '../components/layout/AppLayout';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import { useAuth } from '../context/AuthContext';
import { usePermissions } from '../hooks/usePermissions';
import { useToast } from '../components/ui/ToastProvider';
import { getDashboard, getDashboardTrend } from '../api/finance';
import { getCashShift, openCashShift, closeCashShift } from '../api/cash';
import { formatMoney, formatDate } from '../utils/format';
import './DashboardPage.css';

const PERIODS = [
  ['today', 'Сьогодні'], ['yesterday', 'Вчора'], ['week', 'Тиждень'], ['month', 'Місяць'],
  ['quarter', 'Квартал'], ['year', 'Рік'], ['lastyear', 'Минулий рік'],
];

// Порядок карток — 1:1 з оригіналом. Бекенд (get_dashboard/get_dashboard_trend)
// реально розрізняє лише today/yesterday/week — місяць/квартал/рік/минулий рік
// мовчки трактуються як "сьогодні" (той самий default у switch), це вже так у PHP.
const CARDS = {
  cash: { label: 'Каса', icon: '🏧', href: '/cash', fmt: 'money', field: 'amount' },
  invoices: { label: 'Абонементи', icon: '📄', href: '/invoices', fmt: 'count', field: 'count' },
  visits: { label: 'Відвідування', icon: '👣', href: '/visits', fmt: 'count', field: 'count' },
  payments: { label: 'Оплати', icon: '💳', href: '/payments', fmt: 'count', field: 'count' },
  sales: { label: 'Продажі', icon: '🧾', href: '/sales', fmt: 'count', field: 'count' },
  arrivals: { label: 'Прихід', icon: '📦', href: '/arrivals', fmt: 'money', field: 'amount' },
};
const CARD_ORDER = ['cash', 'invoices', 'visits', 'payments', 'sales', 'arrivals'];

function sumField(days, metric, field) {
  return (days || []).reduce((s, d) => s + (d[metric]?.[field] || 0), 0);
}

function computeDelta(trend, metric, field) {
  if (!trend) return null;
  const cur = sumField(trend.current, metric, field);
  const prev = sumField(trend.previous, metric, field);
  if (prev === 0 && cur === 0) return null;
  if (prev === 0) return { text: '+100%', dir: 'up' };
  const pct = Math.round(((cur - prev) / prev) * 100);
  return { text: (pct >= 0 ? '+' : '') + pct + '%', dir: pct >= 0 ? 'up' : 'down' };
}

function daysWord(n) {
  const mod10 = n % 10, mod100 = n % 100;
  if (mod10 === 1 && mod100 !== 11) return 'день';
  if (mod10 >= 2 && mod10 <= 4 && !(mod100 >= 12 && mod100 <= 14)) return 'дні';
  return 'днів';
}

function sparkPoints(vals) {
  if (!vals.length) return '';
  const W = 80, H = 24, pad = 2;
  const max = Math.max(...vals, 1);
  return vals
    .map((v, i) => {
      const x = pad + (i / Math.max(vals.length - 1, 1)) * (W - pad * 2);
      const y = H - pad - (v / max) * (H - pad * 2);
      return `${x.toFixed(1)},${y.toFixed(1)}`;
    })
    .join(' ');
}

export default function DashboardPage() {
  const { has, isSuperAdmin } = usePermissions();
  const canWrite = has('cash.record');
  const { inClubMode, refresh } = useAuth();
  const toast = useToast();
  const chartInnerRef = useRef(null);

  const [period, setPeriod] = useState('today');
  const [data, setData] = useState(null);
  const [trend, setTrend] = useState(null);
  const [activeKey, setActiveKey] = useState('visits');
  const [chartWidth, setChartWidth] = useState(600);

  const [shift, setShift] = useState(null);
  const [shiftLoaded, setShiftLoaded] = useState(false);

  const [openShiftModal, setOpenShiftModal] = useState(null);
  const [closeShiftModal, setCloseShiftModal] = useState(null);

  async function reloadShift() {
    const res = await getCashShift();
    if (res.success) { setShift(res.shift); setShiftLoaded(true); }
  }

  const showDashboard = !(isSuperAdmin && !inClubMode);

  useEffect(() => {
    if (canWrite && showDashboard) reloadShift();
  }, [canWrite, showDashboard]);

  useEffect(() => {
    if (!showDashboard) return;
    (async () => {
      const [r1, r2] = await Promise.all([getDashboard(period), getDashboardTrend(period)]);
      if (r1.success) setData(r1.data);
      if (r2.success) setTrend(r2.trend);
    })();
  }, [period, showDashboard]);

  useEffect(() => {
    const measure = () => setChartWidth(chartInnerRef.current?.offsetWidth || 600);
    measure();
    window.addEventListener('resize', measure);
    return () => window.removeEventListener('resize', measure);
  }, [trend, activeKey]);

  async function handleShiftClick() {
    if (shift) {
      const res = await getCashShift();
      setCloseShiftModal({
        openedName: res.shift?.opened_name, openedAt: res.shift?.opened_at,
        balanceOpen: res.shift?.balance_open, balanceNow: res.balance,
        notes: '', error: '', submitting: false,
      });
    } else {
      const res = await getCashShift();
      setOpenShiftModal({ balance: res.success ? res.balance : null, notes: '', error: '', submitting: false });
    }
  }

  async function submitOpenShift() {
    setOpenShiftModal({ ...openShiftModal, submitting: true, error: '' });
    const res = await openCashShift(openShiftModal.notes.trim());
    if (!res.success) { setOpenShiftModal({ ...openShiftModal, submitting: false, error: res.error }); return; }
    toast('Зміну відкрито', 'success');
    setOpenShiftModal(null);
    reloadShift();
    refresh();
  }

  async function submitCloseShift() {
    setCloseShiftModal({ ...closeShiftModal, submitting: true, error: '' });
    const res = await closeCashShift(shift.id, closeShiftModal.notes.trim());
    if (!res.success) { setCloseShiftModal({ ...closeShiftModal, submitting: false, error: res.error }); return; }
    toast(`Зміну закрито. Початок: ${formatMoney(res.balance_open)} → Кінець: ${formatMoney(res.balance_close)}`, 'success');
    setCloseShiftModal(null);
    reloadShift();
    refresh();
  }

  const cardsView = useMemo(() => {
    if (!data) return null;
    // Без права на фінанси сервер повертає лише visits/invoices — інші картки не показуємо
    return CARD_ORDER.filter((key) => data[key]).map((key) => {
      const meta = CARDS[key];
      const d = data[key] || {};
      const isMoney = meta.fmt === 'money';
      const value = isMoney ? formatMoney(d.amount ?? 0) : (d.count ?? 0);
      let sub = '';
      if (key === 'cash') sub = (d.amount ?? 0) >= 0 ? 'готівка' : 'витрати > надходжень';
      else if (key === 'visits') sub = 'чол.';
      else sub = d.amount === undefined ? '' : formatMoney(d.amount);
      const neg = key === 'cash' && (d.amount ?? 0) < 0;
      const delta = computeDelta(trend, key, meta.field);
      const spark = trend?.current ? sparkPoints(trend.current.map((day) => day[key]?.[meta.field] || 0)) : '';
      return { key, meta, value, sub, neg, delta, spark };
    });
  }, [data, trend]);

  const chart = useMemo(() => {
    if (!trend?.current) return null;
    const meta = CARDS[activeKey];
    const days = trend.current;
    const field = meta.field;
    const W = chartWidth, H = 160, pt = 16, pb = 28, pl = 44, pr = 12;
    const vals = days.map((d) => d[activeKey]?.[field] || 0);
    // Для лічильників верх шкали кратний 4 — інакше 4 поділки дають дробові значення,
    // а після округлення підписи повторюються ("1, 1, 1, 0, 0").
    const rawMax = Math.max(...vals, 1);
    const maxVal = meta.fmt === 'money' ? rawMax : Math.ceil(rawMax / 4) * 4;
    const step = (W - pl - pr) / days.length;
    const bW = Math.max(8, Math.min(64, Math.floor(step * 0.6)));
    const isMoney = meta.fmt === 'money';

    const gridLines = [];
    for (let i = 0; i <= 4; i++) {
      const v = (maxVal * i) / 4;
      const y = pt + (H - pt - pb) * (1 - i / 4);
      const lbl = isMoney ? (v >= 1000 ? (v / 1000).toFixed(0) + 'k' : v.toFixed(0)) : Math.round(v);
      gridLines.push({ y, lbl });
    }

    const bars = days.map((d, i) => {
      const v = d[activeKey]?.[field] || 0;
      const bH = Math.max(2, (v / maxVal) * (H - pt - pb));
      const x = pl + step * i + step / 2 - bW / 2;
      const y = H - pb - bH;
      const lbl = d.date ? d.date.slice(5) : '';
      return { x, y, bW, bH, lbl };
    });

    return { W, H, pl, pr, title: `${meta.label} · динаміка за ${days.length} ${daysWord(days.length)}`, gridLines, bars };
  }, [trend, activeKey, chartWidth]);

  // SuperAdmin без обраного клубу не має club-scoped даних для показу —
  // його "дашборд" це платформний огляд клубів (вже побудований на /clubs)
  if (!showDashboard) {
    return <Navigate to="/clubs" replace />;
  }

  return (
    <AppLayout title="Дашборд">
      <div className="db-top-bar">
        <select className="db-period-select" value={period} onChange={(e) => setPeriod(e.target.value)}>
          {PERIODS.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
        </select>
        {canWrite && shiftLoaded && (
          <button className={`db-shift-banner ${shift ? 'db-shift-open' : 'db-shift-closed'}`} onClick={handleShiftClick}>
            <span className="db-shift-dot" />
            <span className="db-shift-text">{shift ? 'Зміна відкрита' : 'Зміна не відкрита'}</span>
            <span className="db-shift-action">{shift ? 'Закрити ■' : 'Відкрити ▶'}</span>
          </button>
        )}
      </div>

      <div className="db-grid">
        {(cardsView || CARD_ORDER.map((key) => ({ key, meta: CARDS[key], value: null }))).map((c) => (
          <a
            key={c.key}
            href={c.meta.href}
            className={`db-card ${activeKey === c.key ? 'db-card-active' : ''}`}
            onClick={(e) => { e.preventDefault(); setActiveKey(c.key); }}
          >
            <div className="db-card-top">
              <div className="db-card-icon">{c.meta.icon}</div>
              {c.delta && <div className={`db-card-delta ${c.delta.dir}`}>{c.delta.text}</div>}
            </div>
            <div className="db-card-label">{c.meta.label}</div>
            <div className={`db-card-value ${c.neg ? 'neg' : ''}`}>{c.value === null ? <span className="spinner" /> : c.value}</div>
            <div className="db-card-sub">{c.sub || ' '}</div>
            <svg className="db-spark" viewBox="0 0 80 24" preserveAspectRatio="none">
              {c.spark && <polyline points={c.spark} fill="none" stroke="var(--accent)" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />}
            </svg>
          </a>
        ))}
      </div>

      <div className="db-chart-wrap">
        <div className="db-chart-title">{chart?.title || 'Динаміка'}</div>
        <div className="db-chart-inner" ref={chartInnerRef}>
          {chart && (
            <svg className="db-chart-svg" width={chart.W} height={chart.H} viewBox={`0 0 ${chart.W} ${chart.H}`} preserveAspectRatio="xMidYMid meet">
              {chart.gridLines.map((g, i) => (
                <g key={i}>
                  <line x1={chart.pl} y1={g.y.toFixed(1)} x2={chart.W - chart.pr} y2={g.y.toFixed(1)} stroke="var(--border)" strokeWidth="1" />
                  <text x={chart.pl - 6} y={(g.y + 4).toFixed(1)} textAnchor="end" className="db-axis-lbl">{g.lbl}</text>
                </g>
              ))}
              {chart.bars.map((b, i) => (
                <g key={i}>
                  <rect x={b.x.toFixed(1)} y={b.y.toFixed(1)} width={b.bW} height={b.bH.toFixed(1)} rx="3" className="db-bar" />
                  <text x={(b.x + b.bW / 2).toFixed(1)} y={(chart.H - 28 + 14).toFixed(1)} textAnchor="middle" className="db-axis-lbl">{b.lbl}</text>
                </g>
              ))}
            </svg>
          )}
        </div>
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
              Залишок на початок: <strong>{openShiftModal.balance !== null ? formatMoney(openShiftModal.balance) : '—'}</strong>
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
              <div>Зараз у касі: <strong>{closeShiftModal.balanceNow !== undefined ? formatMoney(closeShiftModal.balanceNow) : '—'}</strong></div>
            </div>
            <FormGroup label="Коментар (необов'язково)">
              <input type="text" placeholder="Підсумок зміни..." value={closeShiftModal.notes} onChange={(e) => setCloseShiftModal({ ...closeShiftModal, notes: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>
    </AppLayout>
  );
}
