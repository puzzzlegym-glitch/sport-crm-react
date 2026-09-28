import { useEffect, useState } from 'react';
import AppLayout from '../components/layout/AppLayout';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import SimplePager from '../components/ui/SimplePager';
import { useToast } from '../components/ui/ToastProvider';
import { getPlans, savePlan, getSubscriptions, updateSubscription, getSaasInvoices, updateSaasInvoice, deleteSaasInvoice, getPromoCodes, savePromoCode, resetPromoCode, deletePromoCode } from '../api/saas';
import { formatMoney, formatDate } from '../utils/format';
import { isSubscriptionActive, subscriptionReasonLabel } from '../utils/subscriptionStatus';
import { NAV_ITEMS, PLAN_GATABLE_SLUGS } from '../components/layout/navItems';
import './SaasPage.css';

// Джерело правди — NAV_ITEMS у navItems.js (поле planGate). Тут нічого не дублюємо вручну,
// щоб нова сторінка не могла "випасти" з перевірки тарифу, як це сталось зі 'Складом'.
const ALL_PAGES = PLAN_GATABLE_SLUGS.map((slug) => ({ slug, label: NAV_ITEMS[slug].label }));
const ALWAYS_AVAILABLE_PAGES = Object.entries(NAV_ITEMS)
  .filter(([slug, v]) => v.planGate === false && ['main', 'manage'].includes(v.section))
  .map(([slug, v]) => ({ slug, label: v.label }));

const SUB_FILTERS = [
  ['', 'Всі'], ['trial', 'Тріал'], ['active', 'Активні'],
  ['trial_expired', 'Прострочені'], ['cancelled', 'Скасовані'],
];
const SUBSCRIPTION_STATUSES = ['trial', 'active', 'trial_expired', 'past_due', 'cancelled', 'deleted'];
const INV_STATUS_MAP = { paid: 'active', draft: 'info', void: 'inactive' };
const INV_STATUS_LABELS = { paid: 'Оплачено', draft: 'Чернетка', void: 'Анульовано' };
const INV_STATUSES = ['draft', 'paid', 'void'];

const EMPTY_PROMO_FORM = {
  id: 0, code: '', discount_type: 'percent', discount_value: '', plan_id: '', valid_until: '', is_active: true, notes: '',
};

const EMPTY_FORM = {
  id: 0, name: '', price_monthly: '', clients_limit: '', users_limit: '', invoices_limit: '',
  is_active: true, trial_days: 0, is_free: false, allowedPages: [],
  discount_percent: 0, discount_label: '', discount_valid_until: '',
};

function planDiscountActive(p) {
  return p.discount_percent > 0 && (!p.discount_valid_until || p.discount_valid_until >= new Date().toISOString().slice(0, 10));
}
function planEffectivePrice(p) {
  return planDiscountActive(p) ? Math.round(p.price_monthly * (1 - p.discount_percent / 100) * 100) / 100 : p.price_monthly;
}


export default function SaasPage() {
  const toast = useToast();
  const [tab, setTab] = useState('plans');

  const [plans, setPlans] = useState(null);
  const [form, setForm] = useState(null);

  const [subFilter, setSubFilter] = useState('');
  const [subPage, setSubPage] = useState(1);
  const [subs, setSubs] = useState({ rows: [], pagination: { total: 0, page: 1, pages: 1 }, loading: true });

  const [invPage, setInvPage] = useState(1);
  const [invoices, setInvoices] = useState({ rows: [], pagination: { total: 0, page: 1, pages: 1 }, loading: true });

  const [subForm, setSubForm] = useState(null);
  const [invForm, setInvForm] = useState(null);

  const [promoCodes, setPromoCodes] = useState(null);
  const [promoForm, setPromoForm] = useState(null);

  async function loadPlans() {
    const res = await getPlans();
    if (!res.success) { toast(res.error, 'error'); return; }
    setPlans(res.plans);
  }

  useEffect(() => { loadPlans(); }, []);

  async function loadSubs() {
    setSubs((s) => ({ ...s, loading: true }));
    const res = await getSubscriptions({ status: subFilter, page: subPage });
    if (!res.success) { toast(res.error, 'error'); setSubs((s) => ({ ...s, loading: false })); return; }
    setSubs({ rows: res.subscriptions, pagination: res.pagination, loading: false });
  }

  useEffect(() => {
    if (tab !== 'subs') return;
    loadSubs();
  }, [tab, subFilter, subPage]);

  async function loadInvoices() {
    setInvoices((s) => ({ ...s, loading: true }));
    const res = await getSaasInvoices({ page: invPage });
    if (!res.success) { toast(res.error, 'error'); setInvoices((s) => ({ ...s, loading: false })); return; }
    setInvoices({ rows: res.invoices, pagination: res.pagination, loading: false });
  }

  useEffect(() => {
    if (tab !== 'invoices') return;
    loadInvoices();
  }, [tab, invPage]);

  function openCreate() {
    setForm({ ...EMPTY_FORM, error: '', submitting: false });
  }

  function openEdit(p) {
    setForm({
      id: p.id, name: p.name, price_monthly: p.price_monthly,
      clients_limit: p.clients_limit ?? '', users_limit: p.users_limit ?? '', invoices_limit: p.invoices_limit ?? '',
      is_active: !!p.is_active, trial_days: p.trial_days || 0, is_free: !!p.is_free,
      allowedPages: Array.isArray(p.allowed_pages) ? p.allowed_pages : [],
      discount_percent: p.discount_percent || 0, discount_label: p.discount_label || '',
      discount_valid_until: p.discount_valid_until ? p.discount_valid_until.slice(0, 10) : '',
      error: '', submitting: false,
    });
  }

  function toggleAllowedPage(slug) {
    setForm((f) => ({
      ...f,
      allowedPages: f.allowedPages.includes(slug) ? f.allowedPages.filter((s) => s !== slug) : [...f.allowedPages, slug],
    }));
  }

  async function submitPlan() {
    if (!form.name.trim()) {
      setForm({ ...form, error: 'Введіть назву плану' });
      return;
    }
    setForm({ ...form, submitting: true, error: '' });
    const res = await savePlan({
      id: form.id || 0,
      name: form.name.trim(),
      price_monthly: parseFloat(form.price_monthly) || 0,
      clients_limit: form.clients_limit || null,
      users_limit: form.users_limit || null,
      invoices_limit: form.invoices_limit || null,
      trial_days: parseInt(form.trial_days) || 0,
      is_free: form.is_free ? 1 : 0,
      is_active: form.is_active ? 1 : 0,
      allowed_pages: form.allowedPages.length ? form.allowedPages : null,
      discount_percent: parseInt(form.discount_percent) || 0,
      discount_label: form.discount_label.trim(),
      discount_valid_until: form.discount_valid_until,
    });
    if (!res.success) {
      setForm({ ...form, submitting: false, error: res.error });
      return;
    }
    toast('Збережено', 'success');
    setForm(null);
    loadPlans();
  }

  function openSubEdit(s) {
    setSubForm({
      id: s.id,
      club_name: s.club_name,
      plan_id: s.plan_id,
      status: s.status,
      trial_ends_at: s.trial_ends_at ? s.trial_ends_at.slice(0, 10) : '',
      current_period_end: s.current_period_end ? s.current_period_end.slice(0, 10) : '',
      admin_notes: s.admin_notes || '',
      error: '', submitting: false,
    });
  }

  async function submitSub() {
    setSubForm({ ...subForm, submitting: true, error: '' });
    const res = await updateSubscription({
      id: subForm.id,
      plan_id: subForm.plan_id,
      status: subForm.status,
      trial_ends_at: subForm.status === 'trial' ? subForm.trial_ends_at : '',
      current_period_end: subForm.status === 'active' ? subForm.current_period_end : '',
      admin_notes: subForm.admin_notes,
    });
    if (!res.success) {
      setSubForm({ ...subForm, submitting: false, error: res.error });
      return;
    }
    toast('Підписку оновлено', 'success');
    setSubForm(null);
    loadSubs();
  }

  function openInvEdit(i) {
    setInvForm({
      id: i.id,
      club_name: i.club_name,
      amount: i.amount,
      status: i.status,
      period_start: i.period_start ? i.period_start.slice(0, 10) : '',
      period_end: i.period_end ? i.period_end.slice(0, 10) : '',
      notes: i.notes || '',
      error: '', submitting: false,
    });
  }

  async function submitInv() {
    setInvForm({ ...invForm, submitting: true, error: '' });
    const res = await updateSaasInvoice({
      id: invForm.id,
      amount: parseFloat(invForm.amount) || 0,
      status: invForm.status,
      period_start: invForm.period_start,
      period_end: invForm.period_end,
      notes: invForm.notes,
    });
    if (!res.success) {
      setInvForm({ ...invForm, submitting: false, error: res.error });
      return;
    }
    toast('Рахунок оновлено', 'success');
    setInvForm(null);
    loadInvoices();
  }

  async function handleDeleteInvoice(id) {
    if (!confirm(`Видалити рахунок #${id}?`)) return;
    const res = await deleteSaasInvoice(id);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast('Рахунок видалено', 'success');
    loadInvoices();
  }

  async function loadPromoCodes() {
    const res = await getPromoCodes();
    if (!res.success) { toast(res.error, 'error'); return; }
    setPromoCodes(res.promo_codes);
  }

  useEffect(() => {
    if (tab !== 'promo' || promoCodes !== null) return;
    loadPromoCodes();
  }, [tab]);

  function openPromoCreate() {
    setPromoForm({ ...EMPTY_PROMO_FORM, error: '', submitting: false });
  }

  function openPromoEdit(p) {
    setPromoForm({
      id: p.id, code: p.code, discount_type: p.discount_type, discount_value: p.discount_value,
      plan_id: p.plan_id ?? '', valid_until: p.valid_until ? p.valid_until.slice(0, 10) : '',
      is_active: !!p.is_active, notes: p.notes || '',
      error: '', submitting: false,
    });
  }

  async function submitPromo() {
    if (!promoForm.code.trim()) { setPromoForm({ ...promoForm, error: 'Введіть код' }); return; }
    if (!parseFloat(promoForm.discount_value)) { setPromoForm({ ...promoForm, error: 'Вкажіть знижку' }); return; }
    setPromoForm({ ...promoForm, submitting: true, error: '' });
    const res = await savePromoCode({
      id: promoForm.id || 0,
      code: promoForm.code.trim(),
      discount_type: promoForm.discount_type,
      discount_value: parseFloat(promoForm.discount_value) || 0,
      plan_id: promoForm.plan_id || null,
      valid_until: promoForm.valid_until,
      is_active: promoForm.is_active ? 1 : 0,
      notes: promoForm.notes.trim(),
    });
    if (!res.success) {
      setPromoForm({ ...promoForm, submitting: false, error: res.error });
      return;
    }
    toast('Збережено', 'success');
    setPromoForm(null);
    loadPromoCodes();
  }

  async function handleResetPromo(id) {
    if (!confirm('Розблокувати промокод для повторного застосування?')) return;
    const res = await resetPromoCode(id);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast('Промокод розблоковано', 'success');
    loadPromoCodes();
  }

  async function handleDeletePromo(id) {
    if (!confirm('Видалити промокод?')) return;
    const res = await deletePromoCode(id);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast('Промокод видалено', 'success');
    loadPromoCodes();
  }

  return (
    <AppLayout title="Білінг платформи">
      <div className="fin-tabs">
        <button className={`fin-tab-btn ${tab === 'plans' ? 'active' : ''}`} onClick={() => setTab('plans')}><Icon name="card" size={15} />Тарифні плани</button>
        <button className={`fin-tab-btn ${tab === 'subs' ? 'active' : ''}`} onClick={() => setTab('subs')}><Icon name="fileText" size={15} />Підписки клубів</button>
        <button className={`fin-tab-btn ${tab === 'invoices' ? 'active' : ''}`} onClick={() => setTab('invoices')}><Icon name="receipt" size={15} />Рахунки</button>
        <button className={`fin-tab-btn ${tab === 'promo' ? 'active' : ''}`} onClick={() => setTab('promo')}><Icon name="tag" size={15} />Промокоди</button>
      </div>

      {tab === 'plans' && (
        plans === null ? (
          <div className="loader"><span className="spinner" /></div>
        ) : (
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 16 }}>
            {plans.map((p) => (
              <div className="card" key={p.id} style={{ position: 'relative', overflow: 'hidden' }}>
                <div style={{ position: 'absolute', top: 0, left: 0, width: 3, height: '100%', background: p.is_free ? 'var(--success)' : p.is_active ? 'var(--accent)' : 'var(--border)' }} />
                <div style={{ paddingLeft: 8 }}>
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6, gap: 6, flexWrap: 'wrap' }}>
                    <div style={{ fontSize: 16, fontWeight: 700 }}>{p.name}</div>
                    <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
                      {p.is_free && <Badge variant="active">Free</Badge>}
                      {p.trial_days > 0 && <Badge variant="info">Тріал {p.trial_days}д</Badge>}
                      {!p.is_active && <Badge variant="inactive">Вимкнено</Badge>}
                      {planDiscountActive(p) && (
                        <Badge variant="pending">
                          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3 }}>
                            <Icon name="tag" size={11} /> {p.discount_label || `Акція −${p.discount_percent}%`}
                          </span>
                        </Badge>
                      )}
                    </div>
                  </div>
                  <div style={{ marginBottom: 8 }}>
                    {p.price_monthly > 0 ? (
                      planDiscountActive(p) ? (
                        <>
                          <span style={{ fontSize: 14, color: 'var(--text-muted)', textDecoration: 'line-through', marginRight: 8 }}>{p.price_monthly} грн</span>
                          <span style={{ fontSize: 24, fontWeight: 700, color: 'var(--danger)' }}>{planEffectivePrice(p)}</span>
                          <span style={{ fontSize: 13, fontWeight: 400, color: 'var(--text-muted)' }}> грн/міс</span>
                        </>
                      ) : (
                        <span style={{ fontSize: 24, fontWeight: 700, color: 'var(--accent)' }}>{p.price_monthly} <span style={{ fontSize: 13, fontWeight: 400, color: 'var(--text-muted)' }}>грн/міс</span></span>
                      )
                    ) : <span style={{ fontSize: 24, fontWeight: 700, color: 'var(--success)' }}>Безкоштовно</span>}
                  </div>
                  <div style={{ fontSize: 13, color: 'var(--text-secondary)', marginBottom: 4, display: 'flex', alignItems: 'center', gap: 6 }}><Icon name="user" size={13} />{p.clients_limit ? `до ${p.clients_limit} активних клієнтів` : 'Необмежено активних клієнтів'}</div>
                  <div style={{ fontSize: 13, color: 'var(--text-secondary)', marginBottom: 4, display: 'flex', alignItems: 'center', gap: 6 }}><Icon name="users" size={13} />{p.users_limit ? `до ${p.users_limit} учасників команди` : 'Необмежена команда'}</div>
                  <div style={{ fontSize: 13, color: 'var(--text-secondary)', marginBottom: 12, display: 'flex', alignItems: 'center', gap: 6 }}><Icon name="fileText" size={13} />{p.invoices_limit ? `до ${p.invoices_limit} активних абонементів` : 'Необмежено активних абонементів'}</div>
                  {p.features?.length > 0 && (
                    <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>
                      {p.features.map((f, i) => <div key={i}>· {f}</div>)}
                    </div>
                  )}
                  <div style={{ display: 'flex', gap: 6, marginTop: 12, alignItems: 'center' }}>
                    <button className="btn btn-ghost btn-sm" style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }} onClick={() => openEdit(p)}><Icon name="edit" size={13} />Редагувати</button>
                    <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>{p.clubs_count} клубів</span>
                  </div>
                </div>
              </div>
            ))}
            <div className="card" style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: 160, border: '2px dashed var(--border)', background: 'none', cursor: 'pointer' }} onClick={openCreate}>
              <div style={{ textAlign: 'center', color: 'var(--text-muted)' }}>
                <div style={{ fontSize: 28 }}>+</div>
                <div style={{ fontSize: 13, marginTop: 4 }}>Новий план</div>
              </div>
            </div>
          </div>
        )
      )}

      {tab === 'subs' && (
        <>
          <div style={{ display: 'flex', gap: 8, marginBottom: 14, flexWrap: 'wrap' }}>
            {SUB_FILTERS.map(([v, l]) => (
              <button key={v} className={`period-btn ${subFilter === v ? 'active' : ''}`} onClick={() => { setSubFilter(v); setSubPage(1); }}>{l}</button>
            ))}
          </div>
          <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
            <div className="table-wrap subs-table-desktop">
              <table>
                <thead>
                  <tr>
                    <th>Клуб</th><th>Власник</th><th>План</th><th>Статус</th>
                    <th>Тріал / Кінець</th><th>Сплачено</th><th>Оновлено</th><th></th>
                  </tr>
                </thead>
                <tbody>
                  {subs.loading && <tr><td colSpan={8}><div className="loader"><span className="spinner" /></div></td></tr>}
                  {!subs.loading && subs.rows.length === 0 && <tr><td colSpan={8}><div className="empty-state"><p>Підписок не знайдено</p></div></td></tr>}
                  {!subs.loading && subs.rows.map((s) => {
                    const active = isSubscriptionActive(s.status);
                    return (
                      <tr key={s.id}>
                        <td><div style={{ fontWeight: 500 }}>{s.club_name}</div><div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{s.city || '—'}</div></td>
                        <td><div style={{ fontSize: 13 }}>{s.owner_name}</div><div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{s.owner_email}</div></td>
                        <td style={{ fontSize: 13 }}>{s.plan_name}</td>
                        <td>
                          <Badge variant={active ? 'active' : 'inactive'}>{active ? 'Активна' : 'Неактивна'}</Badge>
                          <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 3 }}>{subscriptionReasonLabel(s.status)}</div>
                        </td>
                        <td style={{ fontSize: 13 }}>
                          {s.status === 'trial' && s.trial_ends_at ? `${formatDate(s.trial_ends_at)} (${s.trial_days_left} дн.)` : (s.current_period_end ? formatDate(s.current_period_end) : '—')}
                        </td>
                        <td style={{ fontSize: 13, color: 'var(--success)', fontWeight: 500 }}>{s.total_paid ? formatMoney(s.total_paid) : '—'}</td>
                        <td style={{ fontSize: 13, color: 'var(--text-secondary)' }}>{formatDate(s.updated_at)}</td>
                        <td><button className="btn btn-ghost btn-sm" onClick={() => openSubEdit(s)}><Icon name="edit" size={13} /></button></td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>

            <div className="mobile-record-list">
              {subs.loading && <div className="loader" style={{ padding: '20px 0' }}><span className="spinner" /></div>}
              {!subs.loading && subs.rows.length === 0 && <div className="empty-state"><p>Підписок не знайдено</p></div>}
              {!subs.loading && subs.rows.map((s) => {
                const active = isSubscriptionActive(s.status);
                const dateStr = s.status === 'trial' && s.trial_ends_at
                  ? formatDate(s.trial_ends_at)
                  : (s.current_period_end ? formatDate(s.current_period_end) : '—');
                return (
                  <div className="mobile-record-card" key={s.id} onClick={() => openSubEdit(s)}>
                    <div className="mrc-top">
                      <span className="mrc-title">{s.club_name}</span>
                      <button
                        type="button"
                        className="mrc-menu-btn"
                        aria-label="Дії з підпискою"
                        onClick={(e) => { e.stopPropagation(); openSubEdit(s); }}
                      ><Icon name="moreVertical" size={16} /></button>
                    </div>
                    <div className="mrc-sub">
                      <span className="mrc-meta">
                        {s.plan_name}
                        <span className="mrc-dot">•</span>
                        <Badge variant={active ? 'active' : 'inactive'}>{active ? 'Активна' : 'Неактивна'}</Badge>
                        <span className="mrc-dot">•</span>
                        {subscriptionReasonLabel(s.status)}
                      </span>
                      <span className="mrc-date">{dateStr}</span>
                    </div>
                  </div>
                );
              })}
            </div>

            <div style={{ padding: '14px 20px' }}>
              <SimplePager page={subs.pagination.page} pages={subs.pagination.pages} total={subs.pagination.total} onChange={setSubPage} />
            </div>
          </div>
        </>
      )}

      {tab === 'invoices' && (
        <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
          <div className="table-wrap invoices-table-desktop">
            <table>
              <thead>
                <tr><th>ID</th><th>Клуб</th><th>План</th><th>Сума</th><th>Статус</th><th>Період</th><th>Сплачено</th><th></th></tr>
              </thead>
              <tbody>
                {invoices.loading && <tr><td colSpan={8}><div className="loader"><span className="spinner" /></div></td></tr>}
                {!invoices.loading && invoices.rows.length === 0 && <tr><td colSpan={8}><div className="empty-state"><p>Рахунків не знайдено</p></div></td></tr>}
                {!invoices.loading && invoices.rows.map((i) => (
                  <tr key={i.id}>
                    <td style={{ fontSize: 12, color: 'var(--text-muted)' }}>#{i.id}</td>
                    <td style={{ fontWeight: 500 }}>{i.club_name}</td>
                    <td style={{ fontSize: 13 }}>{i.plan_name}</td>
                    <td style={{ fontWeight: 600, color: 'var(--accent)' }}>{formatMoney(i.amount)}</td>
                    <td><Badge variant={INV_STATUS_MAP[i.status] || 'info'}>{INV_STATUS_LABELS[i.status] || i.status}</Badge></td>
                    <td style={{ fontSize: 12, color: 'var(--text-secondary)' }}>{formatDate(i.period_start)} — {formatDate(i.period_end)}</td>
                    <td style={{ fontSize: 13, color: 'var(--text-secondary)' }}>{i.paid_at ? formatDate(i.paid_at) : '—'}</td>
                    <td>
                      <div style={{ display: 'flex', gap: 4, whiteSpace: 'nowrap' }}>
                        <button className="btn btn-ghost btn-sm" onClick={() => openInvEdit(i)}><Icon name="edit" size={13} /></button>
                        <button className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }} onClick={() => handleDeleteInvoice(i.id)}><Icon name="trash" size={13} /></button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="mobile-record-list">
            {invoices.loading && <div className="loader" style={{ padding: '20px 0' }}><span className="spinner" /></div>}
            {!invoices.loading && invoices.rows.length === 0 && <div className="empty-state"><p>Рахунків не знайдено</p></div>}
            {!invoices.loading && invoices.rows.map((i) => (
              <div className="mobile-record-card" key={i.id} onClick={() => openInvEdit(i)}>
                <div className="mrc-top">
                  <span className="mrc-title">{i.club_name}</span>
                  <span className="mrc-amount">{formatMoney(i.amount)}</span>
                </div>
                <div className="mrc-sub">
                  <span className="mrc-meta">
                    {i.plan_name}
                    <span className="mrc-dot">•</span>
                    <Badge variant={INV_STATUS_MAP[i.status] || 'info'}>{INV_STATUS_LABELS[i.status] || i.status}</Badge>
                  </span>
                  <span className="mrc-date">{i.paid_at ? formatDate(i.paid_at) : formatDate(i.period_end)}</span>
                </div>
              </div>
            ))}
          </div>

          <div style={{ padding: '14px 20px' }}>
            <SimplePager page={invoices.pagination.page} pages={invoices.pagination.pages} total={invoices.pagination.total} onChange={setInvPage} />
          </div>
        </div>
      )}

      {tab === 'promo' && (
        <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
          <div style={{ padding: '14px 20px', borderBottom: '1px solid var(--border)', display: 'flex', justifyContent: 'flex-end' }}>
            <button className="btn btn-primary btn-sm" onClick={openPromoCreate}><Icon name="plus" size={13} style={{ verticalAlign: -2, marginRight: 6 }} />Новий промокод</button>
          </div>
          <div className="table-wrap promo-table-desktop">
            <table>
              <thead>
                <tr><th>Код</th><th>Знижка</th><th>Тариф</th><th>Діє до</th><th>Статус</th><th>Використано</th><th></th></tr>
              </thead>
              <tbody>
                {promoCodes === null && <tr><td colSpan={7}><div className="loader"><span className="spinner" /></div></td></tr>}
                {promoCodes !== null && promoCodes.length === 0 && <tr><td colSpan={7}><div className="empty-state"><p>Промокодів ще немає</p></div></td></tr>}
                {promoCodes !== null && promoCodes.map((p) => (
                  <tr key={p.id}>
                    <td style={{ fontWeight: 600, fontFamily: 'monospace' }}>{p.code}</td>
                    <td style={{ fontSize: 13 }}>{p.discount_type === 'fixed' ? `${p.discount_value} грн` : `${p.discount_value}%`}</td>
                    <td style={{ fontSize: 13 }}>{p.plan_name || 'Будь-який'}</td>
                    <td style={{ fontSize: 13, color: 'var(--text-secondary)' }}>{p.valid_until ? formatDate(p.valid_until) : '—'}</td>
                    <td>
                      {p.is_used ? <Badge variant="inactive">Використано</Badge>
                        : !p.is_active ? <Badge variant="inactive">Вимкнено</Badge>
                        : <Badge variant="active">Активний</Badge>}
                    </td>
                    <td style={{ fontSize: 13, color: 'var(--text-secondary)' }}>{p.is_used ? `${p.used_club_name || '—'} · ${formatDate(p.used_at)}` : '—'}</td>
                    <td>
                      <div style={{ display: 'flex', gap: 4, whiteSpace: 'nowrap' }}>
                        <button className="btn btn-ghost btn-sm" onClick={() => openPromoEdit(p)}><Icon name="edit" size={13} /></button>
                        {p.is_used && <button className="btn btn-ghost btn-sm" title="Розблокувати" onClick={() => handleResetPromo(p.id)}><Icon name="undo" size={13} /></button>}
                        <button className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }} onClick={() => handleDeletePromo(p.id)}><Icon name="trash" size={13} /></button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="mobile-record-list">
            {promoCodes === null && <div className="loader" style={{ padding: '20px 0' }}><span className="spinner" /></div>}
            {promoCodes !== null && promoCodes.length === 0 && <div className="empty-state"><p>Промокодів ще немає</p></div>}
            {promoCodes !== null && promoCodes.map((p) => (
              <div className="mobile-record-card" key={p.id} onClick={() => openPromoEdit(p)}>
                <div className="mrc-top">
                  <span className="mrc-title" style={{ fontFamily: 'monospace' }}>{p.code}</span>
                  <span className="mrc-amount">{p.discount_type === 'fixed' ? `${p.discount_value} грн` : `${p.discount_value}%`}</span>
                </div>
                <div className="mrc-sub">
                  <span className="mrc-meta">
                    {p.plan_name || 'Будь-який тариф'}
                    <span className="mrc-dot">•</span>
                    {p.is_used ? <Badge variant="inactive">Використано</Badge> : !p.is_active ? <Badge variant="inactive">Вимкнено</Badge> : <Badge variant="active">Активний</Badge>}
                  </span>
                  <span className="mrc-date">{p.valid_until ? formatDate(p.valid_until) : ''}</span>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Модалка створення/редагування плану */}
      <Modal size="lg"
        open={!!form}
        onClose={() => setForm(null)}
        title={form?.id ? 'Редагувати план' : 'Новий план'}
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={form?.submitting} onClick={submitPlan}>{form?.submitting ? '...' : 'Зберегти'}</button>
            <button className="btn btn-ghost" onClick={() => setForm(null)}>Скасувати</button>
          </div>
        }
      >
        {form && (
          <>
            <FormGroup label="Назва *">
              <input type="text" placeholder="Business" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </FormGroup>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Ціна (грн/міс)">
                <input type="number" min="0" step="1" placeholder="0 = безкоштовно" value={form.price_monthly} onChange={(e) => setForm({ ...form, price_monthly: e.target.value })} />
              </FormGroup>
              <FormGroup label="Тріал (днів)">
                <input type="number" min="0" step="1" placeholder="0 = без тріалу" value={form.trial_days} onChange={(e) => setForm({ ...form, trial_days: e.target.value })} />
              </FormGroup>
              <FormGroup label="Ліміт активних клієнтів" hint="Клієнти з дійсним абонементом (future/active/frozen). Архів і завершені — не рахуються.">
                <input type="number" min="1" placeholder="порожньо = ∞" value={form.clients_limit} onChange={(e) => setForm({ ...form, clients_limit: e.target.value })} />
              </FormGroup>
              <FormGroup label="Ліміт команди" hint="Усі активні учасники клубу (owner+manager+trainer, по людині). Власник рахується з дня реєстрації; самопризначення тренером собі — безкоштовне.">
                <input type="number" min="1" placeholder="порожньо = ∞" value={form.users_limit} onChange={(e) => setForm({ ...form, users_limit: e.target.value })} />
              </FormGroup>
              <FormGroup label="Ліміт активних абонементів" hint="Абонементи, що зараз діють (не завершені/скасовані). Історія проданих — не рахується.">
                <input type="number" min="1" placeholder="порожньо = ∞" value={form.invoices_limit} onChange={(e) => setForm({ ...form, invoices_limit: e.target.value })} />
              </FormGroup>
              <FormGroup label="Активний">
                <select value={form.is_active ? '1' : '0'} onChange={(e) => setForm({ ...form, is_active: e.target.value === '1' })}>
                  <option value="1">Так</option>
                  <option value="0">Ні</option>
                </select>
              </FormGroup>
            </div>
            <FormGroup>
              <label style={{ fontSize: 13, fontWeight: 600, marginBottom: 8, display: 'block' }}>
                Знижка / акція <span style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 400, marginLeft: 6 }}>(0% = немає акції)</span>
              </label>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: '0 14px', padding: 10, background: 'var(--bg-elevated)', borderRadius: 'var(--radius-sm)' }}>
                <FormGroup label="% знижки">
                  <input type="number" min="0" max="100" step="1" placeholder="0" value={form.discount_percent} onChange={(e) => setForm({ ...form, discount_percent: e.target.value })} />
                </FormGroup>
                <FormGroup label="Назва акції">
                  <input type="text" placeholder="напр. Чорна п'ятниця" value={form.discount_label} onChange={(e) => setForm({ ...form, discount_label: e.target.value })} />
                </FormGroup>
                <FormGroup label="Діє до">
                  <input type="date" value={form.discount_valid_until} onChange={(e) => setForm({ ...form, discount_valid_until: e.target.value })} />
                </FormGroup>
              </div>
              {form.discount_percent > 0 && form.price_monthly > 0 && (
                <div style={{ marginTop: 8, fontSize: 12, color: 'var(--text-secondary)' }}>
                  Акційна ціна: <strong style={{ color: 'var(--danger)' }}>{planEffectivePrice({ price_monthly: form.price_monthly, discount_percent: form.discount_percent })} грн/міс</strong> замість {form.price_monthly} грн/міс
                </div>
              )}
            </FormGroup>
            <FormGroup>
              <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer' }}>
                <input type="checkbox" style={{ width: 16, height: 16, accentColor: 'var(--success)' }} checked={form.is_free} onChange={(e) => setForm({ ...form, is_free: e.target.checked })} />
                <span>
                  Free план
                  <span style={{ fontSize: 12, color: 'var(--text-muted)', display: 'block', fontWeight: 400 }}>Клуби автоматично переходять на цей план після закінчення тріалу</span>
                </span>
              </label>
            </FormGroup>
            <FormGroup>
              <label style={{ fontSize: 13, fontWeight: 600, marginBottom: 8, display: 'block' }}>
                Дозволені сторінки <span style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 400, marginLeft: 6 }}>(порожньо = всі)</span>
              </label>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '4px 14px', padding: 10, background: 'var(--bg-elevated)', borderRadius: 'var(--radius-sm)' }}>
                {ALL_PAGES.map((p) => (
                  <label key={p.slug} style={{ display: 'flex', alignItems: 'center', gap: 6, cursor: 'pointer', fontSize: 13 }}>
                    <input type="checkbox" style={{ width: 14, height: 14, accentColor: 'var(--accent)' }} checked={form.allowedPages.includes(p.slug)} onChange={() => toggleAllowedPage(p.slug)} />
                    {p.label}
                  </label>
                ))}
              </div>
              {ALWAYS_AVAILABLE_PAGES.length > 0 && (
                <div style={{ marginTop: 10, fontSize: 12, color: 'var(--text-muted)' }}>
                  Завжди доступні всім планам: {ALWAYS_AVAILABLE_PAGES.map((p, i) => (
                    <span key={p.slug}>{i > 0 ? ', ' : ''}{p.label}</span>
                  ))}
                </div>
              )}
            </FormGroup>
            {form.error && <div className="alert alert-error">{form.error}</div>}
          </>
        )}
      </Modal>

      {/* Модалка ручного редагування підписки клубу */}
      <Modal
        open={!!subForm}
        onClose={() => setSubForm(null)}
        title={subForm ? `Підписка · ${subForm.club_name}` : ''}
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={subForm?.submitting} onClick={submitSub}>{subForm?.submitting ? '...' : 'Зберегти'}</button>
            <button className="btn btn-ghost" onClick={() => setSubForm(null)}>Скасувати</button>
          </div>
        }
      >
        {subForm && (
          <>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="План">
                <select value={subForm.plan_id} onChange={(e) => setSubForm({ ...subForm, plan_id: parseInt(e.target.value) })}>
                  {(plans || []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </select>
              </FormGroup>
              <FormGroup label="Статус">
                <select value={subForm.status} onChange={(e) => setSubForm({ ...subForm, status: e.target.value })}>
                  {SUBSCRIPTION_STATUSES.map((v) => <option key={v} value={v}>{subscriptionReasonLabel(v)}</option>)}
                </select>
              </FormGroup>
              {subForm.status === 'trial' && (
                <FormGroup label="Тріал діє до">
                  <input type="date" value={subForm.trial_ends_at} onChange={(e) => setSubForm({ ...subForm, trial_ends_at: e.target.value })} />
                </FormGroup>
              )}
              {subForm.status === 'active' && (
                <FormGroup label="Оплачено до">
                  <input type="date" value={subForm.current_period_end} onChange={(e) => setSubForm({ ...subForm, current_period_end: e.target.value })} />
                </FormGroup>
              )}
            </div>
            <FormGroup label="Нотатка адміністратора">
              <input type="text" placeholder="напр. продовжено вручну на прохання клієнта" value={subForm.admin_notes} onChange={(e) => setSubForm({ ...subForm, admin_notes: e.target.value })} />
            </FormGroup>
            {subForm.error && <div className="alert alert-error">{subForm.error}</div>}
          </>
        )}
      </Modal>

      {/* Модалка редагування рахунку */}
      <Modal
        open={!!invForm}
        onClose={() => setInvForm(null)}
        title={invForm ? `Рахунок #${invForm.id} · ${invForm.club_name}` : ''}
        footer={
          <div style={{ display: 'flex', gap: 10, justifyContent: 'space-between' }}>
            <div style={{ display: 'flex', gap: 10 }}>
              <button className="btn btn-primary" disabled={invForm?.submitting} onClick={submitInv}>{invForm?.submitting ? '...' : 'Зберегти'}</button>
              <button className="btn btn-ghost" onClick={() => setInvForm(null)}>Скасувати</button>
            </div>
            <button
              className="btn btn-ghost"
              style={{ color: 'var(--danger)' }}
              onClick={() => { const id = invForm.id; setInvForm(null); handleDeleteInvoice(id); }}
            >
              <Icon name="trash" size={13} style={{ verticalAlign: -2, marginRight: 6 }} />Видалити
            </button>
          </div>
        }
      >
        {invForm && (
          <>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Сума (грн)">
                <input type="number" min="0" step="0.01" value={invForm.amount} onChange={(e) => setInvForm({ ...invForm, amount: e.target.value })} />
              </FormGroup>
              <FormGroup label="Статус">
                <select value={invForm.status} onChange={(e) => setInvForm({ ...invForm, status: e.target.value })}>
                  {INV_STATUSES.map((s) => <option key={s} value={s}>{INV_STATUS_LABELS[s]}</option>)}
                </select>
              </FormGroup>
              <FormGroup label="Початок періоду">
                <input type="date" value={invForm.period_start} onChange={(e) => setInvForm({ ...invForm, period_start: e.target.value })} />
              </FormGroup>
              <FormGroup label="Кінець періоду">
                <input type="date" value={invForm.period_end} onChange={(e) => setInvForm({ ...invForm, period_end: e.target.value })} />
              </FormGroup>
            </div>
            <FormGroup label="Нотатка">
              <input type="text" placeholder="Необов'язково" value={invForm.notes} onChange={(e) => setInvForm({ ...invForm, notes: e.target.value })} />
            </FormGroup>
            {invForm.error && <div className="alert alert-error">{invForm.error}</div>}
          </>
        )}
      </Modal>

      {/* Модалка створення/редагування промокоду */}
      <Modal
        open={!!promoForm}
        onClose={() => setPromoForm(null)}
        title={promoForm?.id ? 'Редагувати промокод' : 'Новий промокод'}
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={promoForm?.submitting} onClick={submitPromo}>{promoForm?.submitting ? '...' : 'Зберегти'}</button>
            <button className="btn btn-ghost" onClick={() => setPromoForm(null)}>Скасувати</button>
          </div>
        }
      >
        {promoForm && (
          <>
            <FormGroup label="Код *" hint="Латиниця, цифри, дефіс/підкреслення">
              <input type="text" placeholder="SALE20" style={{ fontFamily: 'monospace' }} maxLength={32}
                value={promoForm.code} onChange={(e) => setPromoForm({ ...promoForm, code: e.target.value.toUpperCase() })} />
            </FormGroup>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Тип знижки">
                <select value={promoForm.discount_type} onChange={(e) => setPromoForm({ ...promoForm, discount_type: e.target.value })}>
                  <option value="percent">Відсоток</option>
                  <option value="fixed">Фіксована сума (грн)</option>
                </select>
              </FormGroup>
              <FormGroup label={promoForm.discount_type === 'fixed' ? 'Сума (грн)' : 'Відсоток (%)'}>
                <input type="number" min="0" max={promoForm.discount_type === 'percent' ? 100 : undefined} step="1"
                  value={promoForm.discount_value} onChange={(e) => setPromoForm({ ...promoForm, discount_value: e.target.value })} />
              </FormGroup>
              <FormGroup label="Тариф">
                <select value={promoForm.plan_id} onChange={(e) => setPromoForm({ ...promoForm, plan_id: e.target.value })}>
                  <option value="">Будь-який</option>
                  {(plans || []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </select>
              </FormGroup>
              <FormGroup label="Діє до" hint="Порожньо = безстроково">
                <input type="date" value={promoForm.valid_until} onChange={(e) => setPromoForm({ ...promoForm, valid_until: e.target.value })} />
              </FormGroup>
            </div>
            <FormGroup label="Активний">
              <select value={promoForm.is_active ? '1' : '0'} onChange={(e) => setPromoForm({ ...promoForm, is_active: e.target.value === '1' })}>
                <option value="1">Так</option>
                <option value="0">Ні</option>
              </select>
            </FormGroup>
            <FormGroup label="Нотатка">
              <input type="text" placeholder="напр. для кого/навіщо цей код" value={promoForm.notes} onChange={(e) => setPromoForm({ ...promoForm, notes: e.target.value })} />
            </FormGroup>
            <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 10 }}>
              Промокод одноразовий — після застосування автоматично стає недоступним для повторного використання.
            </div>
            {promoForm.error && <div className="alert alert-error">{promoForm.error}</div>}
          </>
        )}
      </Modal>
    </AppLayout>
  );
}
