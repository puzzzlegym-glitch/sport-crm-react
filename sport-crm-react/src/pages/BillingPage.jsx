import { useEffect, useState } from 'react';
import { useSearchParams, useNavigate } from 'react-router-dom';
import AppLayout from '../components/layout/AppLayout';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import Icon from '../components/ui/Icon';
import Badge from '../components/ui/Badge';
import { useToast } from '../components/ui/ToastProvider';
import { getBillingPlans, getMyBilling, activateTrial, switchToFree, createPayment, checkPromoCode } from '../api/billing';
import { formatDate } from '../utils/format';
import { isSubscriptionActive, subscriptionReasonLabel } from '../utils/subscriptionStatus';
import './BillingPage.css';

const DISCOUNTS = { 1: 0, 3: 0.05, 6: 0.10, 12: 0.15 };
const planPrice = (p) => (p.discount_active ? +p.price_effective : +p.price_monthly);
const barClass = (pct) => (pct >= 90 ? 'full' : pct >= 70 ? 'warn' : 'ok');

// Рахунок з сумою 0 не потребує оплати — вважається оплаченим одразу при активації тарифу.
// Рахунок зі статусом draft і сумою > 0 — це незавершена/невдала спроба оплати: нова оплата
// завжди створює новий рахунок (create_payment у billing_api.php), повернутись до старого не можна.
function invoiceStatusMeta(inv) {
  if (inv.status === 'paid' || +inv.amount === 0) {
    return { cls: 'paid', label: 'Оплачено', paidAt: inv.paid_at || inv.period_start };
  }
  return { cls: 'void', label: 'Не оплачено', paidAt: null };
}

const TIER_META = {
  free:     { icon: 'leaf',      color: 'var(--success)', label: 'FREE',     desc: 'Базові можливості для старту' },
  starter:  { icon: 'rocket',    color: 'var(--info)',     label: 'STARTER',  desc: 'Клієнти та абонементи під контролем' },
  business: { icon: 'briefcase', color: 'var(--warning)',  label: 'BUSINESS', desc: 'Фінанси, тренери, пріоритетна підтримка' },
  pro:      { icon: 'crown',     color: 'var(--accent)',   label: 'PRO',      desc: 'Максимум можливостей для розвитку клубу' },
};
const tierOf = (slug) => TIER_META[slug] || { icon: 'card', color: 'var(--accent)', label: '', desc: '' };

function daysWord(n) {
  const mod10 = n % 10, mod100 = n % 100;
  if (mod10 === 1 && mod100 !== 11) return 'день';
  if ([2, 3, 4].includes(mod10) && ![12, 13, 14].includes(mod100)) return 'дні';
  return 'днів';
}

function getBanner(sub) {
  const status = sub.status;
  const trialDays = parseInt(sub.trial_days_left) || 0;
  if (status === 'trial') {
    if (trialDays <= 3) {
      return { className: 'trial_warn', icon: 'alertTriangle', iconColor: 'var(--warning)', title: `Тріал закінчується через ${trialDays} ${daysWord(trialDays)}`, text: 'Оберіть план щоб продовжити роботу без переривань', showScrollBtn: true };
    }
    return { className: 'trial', icon: 'info', iconColor: 'var(--accent)', title: 'Тріал активний', text: `Закінчується ${formatDate(sub.trial_ends_at)}`, showScrollBtn: false };
  }
  if (status === 'trial_expired') {
    return { className: 'expired', icon: 'lock', iconColor: 'var(--danger)', title: 'Тріал закінчився', text: 'Лише перегляд. Оберіть план для відновлення доступу.', showScrollBtn: true };
  }
  if (status === 'past_due') {
    return { className: 'expired', icon: 'alertTriangle', iconColor: 'var(--danger)', title: 'Підписка прострочена', text: 'Оновіть спосіб оплати або оберіть інший план.', showScrollBtn: true };
  }
  if (status === 'cancelled') {
    return { className: 'expired', icon: 'ban', iconColor: 'var(--danger)', title: 'Підписку скасовано', text: 'Оберіть план щоб відновити доступ.', showScrollBtn: true };
  }
  if (status === 'active') {
    return { className: 'active', icon: 'checkCircle', iconColor: 'var(--success)', title: 'Підписка активна', text: '', showScrollBtn: false };
  }
  return { className: 'trial', icon: 'info', iconColor: 'var(--accent)', title: subscriptionReasonLabel(status), text: '', showScrollBtn: false };
}

export default function BillingPage() {
  const toast = useToast();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();

  const [billing, setBilling] = useState(undefined); // undefined = loading, null = no subscription
  const [plans, setPlans] = useState([]);
  const [trialSubmitting, setTrialSubmitting] = useState(false);

  const [payModal, setPayModal] = useState(null);
  const [freeModal, setFreeModal] = useState(null);

  async function loadBilling() {
    const [billingRes, plansRes] = await Promise.all([getMyBilling(), getBillingPlans()]);
    setPlans(plansRes.plans || []);
    if (!billingRes.success || billingRes.no_subscription) {
      setBilling(null);
      return;
    }
    setBilling({ subscription: billingRes.subscription, invoices: billingRes.invoices || [] });
  }

  useEffect(() => {
    if (searchParams.get('paid') === '1') {
      toast('Оплату підтверджено! Підписку активовано.', 'success', 5000);
      navigate('/billing', { replace: true });
    }
    loadBilling();
  }, []);

  async function handleActivateTrial() {
    setTrialSubmitting(true);
    const res = await activateTrial();
    setTrialSubmitting(false);
    if (!res.success) {
      toast(res.error || 'Помилка активації', 'error');
      return;
    }
    toast('Тріал активовано на 14 днів!', 'success');
    loadBilling();
  }

  async function activateFreeDirect(plan) {
    const res = await switchToFree(plan?.id);
    if (!res.success) {
      toast(res.error || 'Помилка активації', 'error');
      return;
    }
    toast('Free план активовано!', 'success');
    loadBilling();
  }

  function selectPlan(plan) {
    if (plan.is_free || planPrice(plan) <= 0) {
      if (!billing) { activateFreeDirect(plan); return; }
      openFreeModal(plan);
    } else {
      openPayModal(plan);
    }
  }

  function openFreeModal(plan) {
    const sub = billing?.subscription || {};
    const violations = [];
    if (plan.clients_limit && (sub.clients_used || 0) > plan.clients_limit) violations.push(`Активні клієнти: ${sub.clients_used} > ${plan.clients_limit}`);
    if (plan.users_limit && (sub.users_used || 0) > plan.users_limit) violations.push(`Команда: ${sub.users_used} > ${plan.users_limit}`);
    if (plan.invoices_limit && (sub.invoices_used || 0) > plan.invoices_limit) violations.push(`Активні абонементи: ${sub.invoices_used} > ${plan.invoices_limit}`);
    setFreeModal({ plan, violations, error: '', submitting: false });
  }

  async function confirmSwitchFree() {
    setFreeModal({ ...freeModal, submitting: true });
    const res = await switchToFree(freeModal.plan?.id);
    if (!res.success) {
      setFreeModal({ ...freeModal, submitting: false, error: res.error });
      return;
    }
    toast(res.message || 'Переведено на Free-план', 'success');
    setFreeModal(null);
    loadBilling();
  }

  function openPayModal(plan) {
    setPayModal({
      planId: plan.id, name: plan.name, price: planPrice(plan),
      originalPrice: plan.discount_active ? +plan.price_monthly : null,
      discountLabel: plan.discount_label,
      months: 1, submitting: false, error: '',
      promoInput: '', promoApplied: null, promoChecking: false, promoError: '',
    });
  }

  const payBase = payModal ? Math.round(payModal.price * payModal.months * (1 - (DISCOUNTS[payModal.months] || 0)) * 100) / 100 : 0;
  const promoDiscountAmount = payModal?.promoApplied
    ? (payModal.promoApplied.discount_type === 'fixed'
        ? payModal.promoApplied.discount_value
        : Math.round(payBase * payModal.promoApplied.discount_value) / 100)
    : 0;
  const payTotal = Math.round(Math.max(1, payBase - promoDiscountAmount));

  async function applyPromo() {
    if (!payModal.promoInput.trim()) return;
    setPayModal({ ...payModal, promoChecking: true, promoError: '' });
    const res = await checkPromoCode({ code: payModal.promoInput.trim(), plan_id: payModal.planId });
    if (!res.success) {
      setPayModal((m) => ({ ...m, promoChecking: false, promoError: res.error, promoApplied: null }));
      return;
    }
    setPayModal((m) => ({ ...m, promoChecking: false, promoError: '', promoApplied: res }));
  }

  function removePromo() {
    setPayModal({ ...payModal, promoInput: '', promoApplied: null, promoError: '' });
  }

  async function processPayment() {
    setPayModal({ ...payModal, submitting: true, error: '' });
    const res = await createPayment({
      plan_id: payModal.planId,
      months: payModal.months,
      promo_code: payModal.promoApplied?.code || undefined,
    });
    if (!res.success) {
      setPayModal({ ...payModal, submitting: false, error: res.error });
      toast(res.error, 'error');
      return;
    }
    const div = document.createElement('div');
    div.style.display = 'none';
    div.innerHTML = res.wfp_form.html.replace(/<script[\s\S]*?<\/script>/gi, '');
    document.body.appendChild(div);
    const form = div.querySelector('form');
    if (form) form.submit();
  }

  if (billing === undefined) {
    return <AppLayout title="Підписка"><div className="loader"><span className="spinner" /> Завантаження...</div></AppLayout>;
  }

  const sub = billing?.subscription;
  const banner = sub ? getBanner(sub) : null;

  const clientsUsed = parseInt(sub?.clients_used) || 0;
  const usersUsed = parseInt(sub?.users_used) || 0;
  const invoicesUsed = parseInt(sub?.invoices_used) || 0;
  const clientsLimit = sub?.clients_limit ? parseInt(sub.clients_limit) : null;
  const usersLimit = sub?.users_limit ? parseInt(sub.users_limit) : null;
  const invoicesLimit = sub?.invoices_limit ? parseInt(sub.invoices_limit) : null;
  const clientPct = clientsLimit ? Math.min(100, Math.round((clientsUsed / clientsLimit) * 100)) : 0;
  const userPct = usersLimit ? Math.min(100, Math.round((usersUsed / usersLimit) * 100)) : 0;
  const invoicePct = invoicesLimit ? Math.min(100, Math.round((invoicesUsed / invoicesLimit) * 100)) : 0;

  return (
    <AppLayout title="Підписка">
      {!sub && (
        <>
          <div className="sub-banner trial" style={{ marginBottom: 24 }}>
            <div className="sub-banner-text">
              <h3><Icon name="card" size={16} style={{ verticalAlign: -3, marginRight: 6, color: 'var(--accent)' }} />Підписку ще не оформлено</h3>
              <p>Активуйте безкоштовний тріал або оберіть тарифний план</p>
            </div>
            <button className="btn btn-primary btn-sm" disabled={trialSubmitting} onClick={handleActivateTrial}>
              <Icon name="clock" size={14} style={{ verticalAlign: -2, marginRight: 6 }} />{trialSubmitting ? 'Активація...' : 'Активувати тріал 14 днів'}
            </button>
          </div>
          <div className="card">
            <div className="card-title">Тарифні плани</div>
            <div className="plans-grid">
              {plans.map((p, i) => {
                const tier = tierOf(p.slug);
                const isFeatured = i === plans.length - 1;
                return (
                  <div className={`billing-plan-card ${isFeatured ? 'featured' : ''}`} key={p.id} style={{ '--tier-color': tier.color }}>
                    {p.discount_active && (
                      <div className="plan-discount-ribbon"><Icon name="tag" size={12} /> {p.discount_label || `Акція −${p.discount_percent}%`}</div>
                    )}
                    <div className="bp-icon"><Icon name={tier.icon} size={20} /></div>
                    <div className="bp-name">{p.name}</div>
                    <div className="bp-price">
                      {+p.price_monthly > 0 ? (
                        p.discount_active ? (
                          <>
                            <small style={{ textDecoration: 'line-through', marginRight: 6 }}>{p.price_monthly} грн</small>
                            <span style={{ color: 'var(--danger)' }}>{p.price_effective}</span> <small>грн/міс</small>
                          </>
                        ) : <>{p.price_monthly} <small>грн/міс</small></>
                      ) : <small>Безкоштовно</small>}
                    </div>
                    <div className="bp-features">{(p.features || []).map((f, i) => <div className="bp-feature" key={i}>{f}</div>)}</div>
                    <button className={`btn ${(+p.is_free || planPrice(p) <= 0) ? 'btn-ghost' : (isFeatured ? 'btn-primary' : 'btn-ghost')}`} style={{ width: '100%' }} onClick={() => selectPlan(p)}>
                      {(+p.is_free || planPrice(p) <= 0) ? 'Розпочати безкоштовно' : `Обрати ${p.name} →`}
                    </button>
                  </div>
                );
              })}
            </div>
          </div>
        </>
      )}

      {sub && (
        <>
          <div className={`billing-status-card ${banner.className}`} style={{ '--icon-color': banner.iconColor, '--tier-color': tierOf(sub.plan_slug).color }}>
            <div className="bsc-top">
              <div className="sub-banner-icon"><Icon name={banner.icon} size={20} /></div>
              <div className="sub-banner-text">
                <h3>{banner.title}</h3>
                {banner.text && <p>{banner.text}</p>}
              </div>
              {sub.status === 'active' && sub.current_period_end && (
                <div className="sub-banner-meta">
                  <div className="sbm-item">
                    <span className="sbm-label">Наступне списання</span>
                    <span className="sbm-value"><Icon name="calendar" size={13} />{formatDate(sub.current_period_end)}</span>
                  </div>
                  {(() => {
                    const daysLeft = Math.max(0, Math.ceil((new Date(sub.current_period_end) - new Date()) / 86400000));
                    return <div className="sbm-pill">Залишилось {daysLeft} {daysWord(daysLeft)}</div>;
                  })()}
                </div>
              )}
              {sub.status === 'active' ? (
                <button className="btn btn-ghost btn-sm" onClick={() => document.getElementById('plans-section')?.scrollIntoView({ behavior: 'smooth' })}>
                  <Icon name="gear" size={14} style={{ verticalAlign: -2, marginRight: 6 }} />Керувати підпискою
                </button>
              ) : banner.showScrollBtn && (
                <button className="btn btn-primary btn-sm" onClick={() => document.getElementById('plans-section')?.scrollIntoView({ behavior: 'smooth' })}>Обрати план →</button>
              )}
            </div>

            <div className="bsc-bottom">
              <div className="current-plan-card-v2">
                <div className="cp-icon"><Icon name={tierOf(sub.plan_slug).icon} size={22} /></div>
                <div className="cp-info">
                  <div className="cp-name-row">
                    <span className="cp-name">{sub.plan_name || '—'}</span>
                    {tierOf(sub.plan_slug).label && <span className="cp-tier-badge">{tierOf(sub.plan_slug).label}</span>}
                    <Badge variant={isSubscriptionActive(sub.status) ? 'active' : 'inactive'}>
                      {isSubscriptionActive(sub.status) ? 'Активна' : 'Неактивна'}
                    </Badge>
                  </div>
                  <div className="cp-desc">{tierOf(sub.plan_slug).desc || subscriptionReasonLabel(sub.status)}</div>
                </div>
                <div className="cp-stats">
                  <div className="cp-stat">
                    <div className="cp-stat-icon"><Icon name="fileText" size={15} /></div>
                    <div>
                      <div className="cp-stat-value">{invoicesUsed} / {invoicesLimit ?? '∞'}</div>
                      <div className="cp-stat-label">Активні абонементи</div>
                      {invoicesLimit && <div className="cp-mini-bar"><div className={`cp-mini-bar-fill ${barClass(invoicePct)}`} style={{ width: `${invoicePct}%` }} /></div>}
                    </div>
                  </div>
                  <div className="cp-stat">
                    <div className="cp-stat-icon"><Icon name="user" size={15} /></div>
                    <div>
                      <div className="cp-stat-value">{clientsUsed} / {clientsLimit ?? '∞'}</div>
                      <div className="cp-stat-label">Активні клієнти</div>
                      {clientsLimit && <div className="cp-mini-bar"><div className={`cp-mini-bar-fill ${barClass(clientPct)}`} style={{ width: `${clientPct}%` }} /></div>}
                    </div>
                  </div>
                  <div className="cp-stat">
                    <div className="cp-stat-icon"><Icon name="users" size={15} /></div>
                    <div>
                      <div className="cp-stat-value">{usersUsed} / {usersLimit ?? '∞'}</div>
                      <div className="cp-stat-label">Команда</div>
                      {usersLimit && <div className="cp-mini-bar"><div className={`cp-mini-bar-fill ${barClass(userPct)}`} style={{ width: `${userPct}%` }} /></div>}
                    </div>
                  </div>
                </div>
                <div className="cp-price">{sub.price_monthly} <small>грн / міс</small></div>
              </div>
              {sub.status === 'trial' && (
                <div className="cp-trial-note"><Icon name="clock" size={13} /> Тріал діє до {formatDate(sub.trial_ends_at)}</div>
              )}
              {invoicesLimit && invoicePct >= 90 && (
                <div className="cp-warning"><Icon name="alertTriangle" size={13} /> Залишилось лише {invoicesLimit - invoicesUsed} абонементів до ліміту плану</div>
              )}
            </div>
          </div>

          <div id="plans-section" className="card" style={{ marginBottom: 24 }}>
            <div className="card-title">Тарифні плани</div>
            <div className="plans-grid">
              {plans.map((p, i) => {
                const isCurrent = p.slug === sub.plan_slug;
                const isCurrentInactive = isCurrent && !isSubscriptionActive(sub.status);
                const tier = tierOf(p.slug);
                const isFeatured = !isCurrent && i === plans.length - 1;
                return (
                  <div className={`billing-plan-card ${isCurrent ? 'current' : ''} ${isFeatured ? 'featured' : ''}`} key={p.id} style={{ '--tier-color': tier.color }}>
                    {isCurrent ? (
                      <div className="plan-current-label">Поточний план</div>
                    ) : p.discount_active && (
                      <div className="plan-discount-ribbon"><Icon name="tag" size={12} /> {p.discount_label || `Акція −${p.discount_percent}%`}</div>
                    )}
                    <div className="bp-icon"><Icon name={tier.icon} size={20} /></div>
                    <div className="bp-name">{p.name}</div>
                    <div className="bp-price">
                      {p.discount_active ? (
                        <>
                          <small style={{ textDecoration: 'line-through', marginRight: 6 }}>{p.price_monthly} грн</small>
                          <span style={{ color: 'var(--danger)' }}>{p.price_effective}</span> <small>грн/міс</small>
                        </>
                      ) : <>{p.price_monthly} <small>грн/міс</small></>}
                    </div>
                    <div className="bp-features">{(p.features || []).map((f, i) => <div className="bp-feature" key={i}>{f}</div>)}</div>
                    {isCurrentInactive ? (
                      <button className="btn btn-primary" style={{ width: '100%' }} onClick={() => selectPlan(p)}>
                        {(p.is_free || planPrice(p) <= 0) ? 'Активувати' : 'Оплатити'} →
                      </button>
                    ) : isCurrent ? (
                      <button className="btn btn-ghost" style={{ width: '100%', opacity: 0.5 }} disabled>Активний</button>
                    ) : (
                      <button className={`btn ${isFeatured ? 'btn-primary' : 'btn-ghost'}`} style={{ width: '100%' }} onClick={() => selectPlan(p)}>
                        {(p.is_free || planPrice(p) <= 0) ? 'Перейти безкоштовно' : (planPrice(p) > (sub.price_monthly || 0) ? 'Підвищити' : 'Перейти')} →
                      </button>
                    )}
                  </div>
                );
              })}
            </div>
          </div>

          <div className="card">
            <div className="card-title">Історія платежів</div>
            {billing.invoices.length === 0 ? (
              <div className="empty-state" style={{ padding: '30px 0' }}><p>Рахунків ще немає</p></div>
            ) : (
              <>
                <div className="table-wrap billing-invoices-desktop">
                  <table>
                    <thead><tr><th>Рахунок</th><th>Період</th><th>Сума</th><th>Статус</th><th>Оплачено</th></tr></thead>
                    <tbody>
                      {billing.invoices.map((inv) => {
                        const st = invoiceStatusMeta(inv);
                        return (
                          <tr key={inv.id}>
                            <td style={{ fontSize: 13 }}><span className="inv-id"><Icon name="fileText" size={13} />#{inv.id}</span></td>
                            <td style={{ fontSize: 13 }}>{formatDate(inv.period_start)} — {formatDate(inv.period_end)}</td>
                            <td style={{ fontWeight: 500 }}>{inv.amount} грн</td>
                            <td><span className={`invoice-status-pill ${st.cls}`}>{st.cls === 'paid' && <Icon name="check" size={11} style={{ verticalAlign: -1, marginRight: 3 }} />}{st.label}</span></td>
                            <td style={{ fontSize: 13, color: 'var(--text-muted)' }}>{st.paidAt ? formatDate(st.paidAt) : '—'}</td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>

                <div className="mobile-record-list">
                  {billing.invoices.map((inv) => {
                    const st = invoiceStatusMeta(inv);
                    return (
                    <div className="mobile-record-card" key={inv.id} style={{ cursor: 'default' }}>
                      <div className="mrc-top">
                        <span className="mrc-title">{formatDate(inv.period_start)} — {formatDate(inv.period_end)}</span>
                        <span className={`invoice-status-pill ${st.cls}`} style={{ flexShrink: 0 }}>{st.cls === 'paid' && <Icon name="check" size={11} style={{ verticalAlign: -1, marginRight: 3 }} />}{st.label}</span>
                      </div>
                      <div className="mrc-sub">
                        <span className="mrc-meta">#{inv.id}{st.paidAt ? ` · ${formatDate(st.paidAt)}` : ''}</span>
                        <span className="mrc-amount">{inv.amount} грн</span>
                      </div>
                    </div>
                    );
                  })}
                </div>
              </>
            )}
          </div>
        </>
      )}

      {/* Оплата підписки */}
      <Modal open={!!payModal} onClose={() => setPayModal(null)} title="Оплата підписки">
        {payModal && (
          <>
            {payModal.error && <div className="alert alert-error">{payModal.error}</div>}
            <div style={{ marginBottom: 20, padding: 14, background: 'var(--bg-elevated)', borderRadius: 'var(--radius-md)' }}>
              <div style={{ fontSize: 15, fontWeight: 600 }}>{payModal.name}</div>
              <div style={{ fontSize: 13, color: 'var(--text-secondary)', marginTop: 2 }}>
                {payModal.originalPrice && <span style={{ textDecoration: 'line-through', marginRight: 6 }}>{payModal.originalPrice} грн</span>}
                {payModal.price} грн / місяць
                {payModal.originalPrice && (
                  <span style={{ color: 'var(--danger)', marginLeft: 6, display: 'inline-flex', alignItems: 'center', gap: 3 }}>
                    <Icon name="tag" size={12} /> {payModal.discountLabel || 'Акція'}
                  </span>
                )}
              </div>
            </div>
            <FormGroup label="Кількість місяців">
              <select value={payModal.months} onChange={(e) => setPayModal({ ...payModal, months: parseInt(e.target.value) })}>
                <option value="1">1 місяць</option>
                <option value="3">3 місяці (−5%)</option>
                <option value="6">6 місяців (−10%)</option>
                <option value="12">12 місяців (−15%)</option>
              </select>
            </FormGroup>
            <FormGroup label="Промокод">
              {payModal.promoApplied ? (
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '8px 12px', background: 'var(--bg-elevated)', borderRadius: 'var(--radius-sm)' }}>
                  <span style={{ fontSize: 13, color: 'var(--success)', display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                    <Icon name="checkCircle" size={14} /> {payModal.promoApplied.code} застосовано
                  </span>
                  <button type="button" className="btn btn-ghost btn-sm" onClick={removePromo}>Прибрати</button>
                </div>
              ) : (
                <div style={{ display: 'flex', gap: 8 }}>
                  <input
                    type="text" placeholder="Введіть код" style={{ flex: 1 }}
                    value={payModal.promoInput}
                    onChange={(e) => setPayModal({ ...payModal, promoInput: e.target.value, promoError: '' })}
                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); applyPromo(); } }}
                  />
                  <button type="button" className="btn btn-ghost" disabled={payModal.promoChecking || !payModal.promoInput.trim()} onClick={applyPromo}>
                    {payModal.promoChecking ? '...' : 'Застосувати'}
                  </button>
                </div>
              )}
              {payModal.promoError && <div style={{ fontSize: 12, color: 'var(--danger)', marginTop: 6 }}>{payModal.promoError}</div>}
            </FormGroup>
            <div style={{ marginBottom: 20, padding: '12px 0', borderTop: '1px solid var(--border)' }}>
              {promoDiscountAmount > 0 && (
                <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, color: 'var(--success)', marginBottom: 6 }}>
                  <span>Знижка за промокодом</span><span>−{promoDiscountAmount} грн</span>
                </div>
              )}
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <span style={{ fontSize: 14, color: 'var(--text-secondary)' }}>До сплати:</span>
                <span style={{ fontSize: 22, fontWeight: 700, color: 'var(--accent)' }}>{payTotal} грн</span>
              </div>
            </div>
            <button className="btn btn-primary" style={{ width: '100%', padding: 13, fontSize: 15 }} disabled={payModal.submitting} onClick={processPayment}>
              {payModal.submitting ? 'Перенаправлення...' : 'Оплатити через WayForPay'}
            </button>
            <p style={{ textAlign: 'center', fontSize: 12, color: 'var(--text-muted)', marginTop: 12 }}>Безпечна оплата · WayForPay · Visa / Mastercard</p>
          </>
        )}
      </Modal>

      {/* Перейти на Free */}
      <Modal
        open={!!freeModal}
        onClose={() => setFreeModal(null)}
        title={freeModal?.plan?.is_free ? 'Перейти на Free-план?' : `Активувати "${freeModal?.plan?.name}" безкоштовно?`}
        footer={
          freeModal?.violations.length === 0 ? (
            <div style={{ display: 'flex', gap: 10 }}>
              <button className="btn btn-primary" disabled={freeModal?.submitting} onClick={confirmSwitchFree}>{freeModal?.submitting ? 'Збереження...' : 'Підтвердити'}</button>
              <button className="btn btn-ghost" onClick={() => setFreeModal(null)}>Скасувати</button>
            </div>
          ) : (
            <button className="btn btn-ghost" onClick={() => setFreeModal(null)}>Скасувати</button>
          )
        }
      >
        {freeModal && (
          <>
            {freeModal.error && <div className="alert alert-error" style={{ marginBottom: 14 }}>{freeModal.error}</div>}
            <div style={{ padding: 14, background: 'var(--bg-elevated)', borderRadius: 'var(--radius-md)', fontSize: 13, color: 'var(--text-secondary)' }}>
              {freeModal.violations.length > 0 ? (
                <>
                  <div style={{ fontWeight: 600, fontSize: 14, marginBottom: 8, color: 'var(--text-primary)', display: 'flex', alignItems: 'center', gap: 6 }}>
                    <Icon name="alertTriangle" size={16} style={{ color: 'var(--danger)', flexShrink: 0 }} /> Неможливо перейти на {freeModal.plan.name}
                  </div>
                  <div style={{ color: 'var(--danger)', marginBottom: 6 }}>Перевищено ліміти плану:</div>
                  {freeModal.violations.map((v, i) => <div key={i} style={{ fontSize: 13, padding: '2px 0' }}>· {v}</div>)}
                  <div style={{ marginTop: 10, fontSize: 12, color: 'var(--text-muted)' }}>Зменшіть кількість перед переходом на цей план.</div>
                </>
              ) : (
                <>
                  <div style={{ fontWeight: 600, fontSize: 14, marginBottom: 6, color: 'var(--text-primary)' }}>{freeModal.plan.name} · Безкоштовно</div>
                  <div style={{ fontSize: 13, marginBottom: 4, display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><Icon name="user" size={14} />до {freeModal.plan.clients_limit || '∞'} активних клієнтів</span>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><Icon name="users" size={14} />до {freeModal.plan.users_limit || '∞'} учасників команди</span>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><Icon name="fileText" size={14} />до {freeModal.plan.invoices_limit || '∞'} активних абонементів</span>
                  </div>
                  <div style={{ marginTop: 8, color: 'var(--danger)', fontSize: 12 }}>
                    {(+sub.price_monthly > 0 && sub.status === 'active')
                      ? 'Платна підписка буде скасована. Дані збережуться.'
                      : 'Поточний план буде замінено на обраний. Дані збережуться.'}
                  </div>
                </>
              )}
            </div>
          </>
        )}
      </Modal>
    </AppLayout>
  );
}
