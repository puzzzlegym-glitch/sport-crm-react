import { useEffect, useState } from 'react';
import AppLayout from '../components/layout/AppLayout';
import Table from '../components/ui/Table';
import Pagination from '../components/ui/Pagination';
import Modal from '../components/ui/Modal';
import ModalTabs from '../components/ui/ModalTabs';
import DetailFieldsGrid from '../components/ui/DetailFieldsGrid';
import FormGroup from '../components/ui/FormGroup';
import Badge from '../components/ui/Badge';
import { useToast } from '../components/ui/ToastProvider';
import {
  getClubStats, getClubs, getClub, toggleClubActive,
  extendClubTrial, cancelClubTrial, recordClubPayment, getClubPayments,
  requestDeleteClub, confirmDeleteClub,
} from '../api/clubs';
import { getBillingPlans } from '../api/billing';
import { formatMoney, formatDate } from '../utils/format';
import { isSubscriptionActive, subscriptionReasonLabel } from '../utils/subscriptionStatus';
import './ClubsPage.css';

const STATUS_FILTERS = [['', 'Всі'], ['trial', 'Тріал'], ['active', 'Активні'], ['trial_expired', 'Прострочені']];
const EMPTY_DATA = { clubs: [], pagination: { total: 0, page: 1, pages: 1, per_page: 20 } };

export default function ClubsPage() {
  const toast = useToast();

  const [stats, setStats] = useState(null);
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [data, setData] = useState(EMPTY_DATA);
  const [loading, setLoading] = useState(true);
  const [plans, setPlans] = useState([]);

  const [detail, setDetail] = useState(null); // { loading, club, tab, payments, paymentsLoading, extendDays, extendPlanId, extendNote, extendSubmitting, payPlanId, payMonths, payNote, paySubmitting }
  const [blockModal, setBlockModal] = useState(null); // { clubId, name, isActive }
  const [cancelTrialModal, setCancelTrialModal] = useState(null); // { clubId, name, submitting }
  const [deleteWarn, setDeleteWarn] = useState(null); // { clubId, name, reason, acknowledged, submitting, error }
  const [deleteCode, setDeleteCode] = useState(null); // { clubId, name, code, submitting, error, expiresAt }

  useEffect(() => {
    getClubStats().then((res) => { if (res.success) setStats(res.stats); });
    getBillingPlans().then((res) => { if (res.success) setPlans(res.plans || []); });
  }, []);

  async function reload() {
    setLoading(true);
    const res = await getClubs({ search, status, page });
    setLoading(false);
    if (!res.success) { toast(res.error, 'error'); setData(EMPTY_DATA); return; }
    setData({ clubs: res.clubs, pagination: res.pagination });
  }

  useEffect(() => {
    const t = setTimeout(() => { setSearch(searchInput.trim()); setPage(1); }, 350);
    return () => clearTimeout(t);
  }, [searchInput]);

  useEffect(() => { reload(); }, [search, status, page]);

  function selectFilter(s) { setStatus(s); setPage(1); }

  function reloadStats() {
    getClubStats().then((res) => { if (res.success) setStats(res.stats); });
  }

  async function openDetail(clubId) {
    setDetail({ loading: true, club: null, tab: 'info' });
    const res = await getClub(clubId);
    if (!res.success) { toast(res.error, 'error'); setDetail(null); return; }
    setDetail({
      loading: false, club: res.club, tab: 'info',
      payments: [], paymentsLoading: false, paymentsLoaded: false,
      extendDays: 14, extendPlanId: res.club.plan_id || plans[0]?.id || '', extendNote: '', extendSubmitting: false,
      payPlanId: plans[0]?.id ?? '', payMonths: 1, payNote: '', paySubmitting: false,
    });
  }

  async function switchDetailTab(tab) {
    setDetail((d) => ({ ...d, tab }));
    if (tab === 'payments' && detail && !detail.paymentsLoaded) {
      setDetail((d) => ({ ...d, paymentsLoading: true }));
      const res = await getClubPayments(detail.club.id);
      setDetail((d) => ({ ...d, paymentsLoading: false, paymentsLoaded: true, payments: res.success ? (res.payments || []) : [] }));
    }
  }

  async function handleExtendTrial() {
    if (!detail.extendDays || detail.extendDays < 1) { toast('Введіть кількість днів', 'error'); return; }
    setDetail({ ...detail, extendSubmitting: true });
    const res = await extendClubTrial({
      club_id: detail.club.id, days: detail.extendDays,
      plan_id: detail.extendPlanId || '', note: detail.extendNote.trim(),
    });
    if (!res.success) { toast(res.error, 'error'); setDetail({ ...detail, extendSubmitting: false }); return; }
    toast(res.message, 'success');
    setDetail(null);
    reload();
    reloadStats();
  }

  function openCancelTrialModal() {
    setCancelTrialModal({ clubId: detail.club.id, name: detail.club.name, submitting: false });
  }

  async function confirmCancelTrial() {
    setCancelTrialModal({ ...cancelTrialModal, submitting: true });
    const res = await cancelClubTrial(cancelTrialModal.clubId);
    if (!res.success) { toast(res.error, 'error'); setCancelTrialModal(null); return; }
    toast(res.message, 'success');
    setCancelTrialModal(null);
    setDetail(null);
    reload();
    reloadStats();
  }

  async function handleRecordPayment() {
    if (!detail.payPlanId) { toast('Оберіть план', 'error'); return; }
    setDetail({ ...detail, paySubmitting: true });
    const res = await recordClubPayment({ club_id: detail.club.id, plan_id: detail.payPlanId, months: detail.payMonths, note: detail.payNote.trim() || 'Ручна оплата' });
    if (!res.success) { toast(res.error, 'error'); setDetail({ ...detail, paySubmitting: false }); return; }
    toast(res.message, 'success');
    setDetail(null);
    reload();
    reloadStats();
  }

  function openBlockModal(club) {
    setBlockModal({ clubId: club.id, name: club.name, isActive: club.is_active });
  }

  function openBlockModalFromDetail() {
    setBlockModal({ clubId: detail.club.id, name: detail.club.name, isActive: detail.club.is_active });
  }

  async function confirmBlock() {
    const res = await toggleClubActive(blockModal.clubId);
    setBlockModal(null);
    setDetail(null);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message, 'success');
    reload();
  }

  function openDeleteWarn() {
    setDeleteWarn({ clubId: detail.club.id, name: detail.club.name, reason: '', acknowledged: false, submitting: false, error: '' });
  }

  async function submitDeleteWarn() {
    if (!deleteWarn.reason.trim()) { setDeleteWarn({ ...deleteWarn, error: 'Опишіть причину видалення' }); return; }
    if (!deleteWarn.acknowledged) { setDeleteWarn({ ...deleteWarn, error: 'Підтвердіть, що усвідомлюєте незворотність дії' }); return; }
    setDeleteWarn({ ...deleteWarn, submitting: true, error: '' });
    const res = await requestDeleteClub(deleteWarn.clubId, deleteWarn.reason.trim());
    if (!res.success) { setDeleteWarn({ ...deleteWarn, submitting: false, error: res.error }); return; }
    toast(res.message, 'success');
    setDeleteCode({ clubId: deleteWarn.clubId, name: deleteWarn.name, code: '', submitting: false, error: '', expiresAt: Date.now() + res.expires_in * 1000 });
    setDeleteWarn(null);
  }

  async function submitDeleteCode() {
    if (!deleteCode.code.trim()) { setDeleteCode({ ...deleteCode, error: 'Введіть код з Telegram' }); return; }
    setDeleteCode({ ...deleteCode, submitting: true, error: '' });
    const res = await confirmDeleteClub(deleteCode.clubId, deleteCode.code.trim());
    if (!res.success) { setDeleteCode({ ...deleteCode, submitting: false, error: res.error }); return; }
    toast(res.message, 'success');
    setDeleteCode(null);
    setDetail(null);
    reload();
    reloadStats();
  }

  const columns = [
    {
      key: 'club', label: 'Клуб',
      render: (c) => <><div className="club-name">{c.name}</div><div className="club-owner">{c.city || '—'}</div></>,
    },
    {
      key: 'owner', label: 'Власник',
      render: (c) => <><div style={{ fontSize: 14 }}>{c.owner_name}</div><div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{c.owner_email}</div></>,
    },
    {
      key: 'sub', label: 'Підписка',
      cardTop: true,
      render: (c) => {
        const active = isSubscriptionActive(c.sub_status);
        const days = parseInt(c.trial_days_left);
        const cls = days <= 1 ? 'danger' : days <= 3 ? 'warn' : 'ok';
        return (
          <>
            <Badge variant={active ? 'active' : 'inactive'}>{active ? 'Активний' : 'Неактивний'}</Badge>
            <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 3 }}>{subscriptionReasonLabel(c.sub_status)}</div>
            {c.sub_status === 'trial' && <div className={`trial-days ${cls}`}>{days} дн. залишилось</div>}
          </>
        );
      },
    },
    { key: 'clients', label: 'Клієнти', mobile: 'secondary', render: (c) => <span style={{ fontSize: 14 }}>{c.clients_count} клієнтів</span> },
    { key: 'created', label: 'Зареєстровано', mobile: 'trailing', render: (c) => <span style={{ fontSize: 13, color: 'var(--text-secondary)' }}>{formatDate(c.created_at)}</span> },
    {
      key: 'actions', label: '',
      render: (c) => (
        <div style={{ display: 'flex', gap: 4, whiteSpace: 'nowrap' }} onClick={(e) => e.stopPropagation()}>
          <button className="btn btn-ghost btn-sm" title="Деталі" onClick={() => openDetail(c.id)}>🔍</button>
          <button className="btn btn-ghost btn-sm" style={{ color: c.is_active ? 'var(--danger)' : 'var(--success)' }} title={c.is_active ? 'Заблокувати' : 'Розблокувати'} onClick={() => openBlockModal(c)}>{c.is_active ? '🚫' : '✅'}</button>
        </div>
      ),
    },
  ];

  const c = detail?.club;
  const subActive = c ? isSubscriptionActive(c.sub_status) : false;
  const subReason = c ? subscriptionReasonLabel(c.sub_status) : '';

  return (
    <AppLayout title="Всі клуби">
      <div className="page-header">
        <h1 className="page-title">Всі клуби</h1>
        <p className="page-subtitle">{data.pagination.total ? `Всього: ${data.pagination.total} клубів` : 'Завантаження...'}</p>
      </div>

      <div className="platform-stats">
        <div className="platform-stat">
          <div className="ps-label">Всього клубів</div>
          <div className="ps-value">{stats ? stats.total_clubs : <span className="spinner" />}</div>
          <div className="ps-sub">{stats ? `активних: ${stats.active_clubs}` : '—'}</div>
        </div>
        <div className="platform-stat green">
          <div className="ps-label">Платних</div>
          <div className="ps-value">{stats ? stats.paid_clubs : '—'}</div>
          <div className="ps-sub">{stats ? `${formatMoney(stats.revenue_month)} цього місяця` : '—'}</div>
        </div>
        <div className="platform-stat orange">
          <div className="ps-label">На тріалі</div>
          <div className="ps-value">{stats ? stats.trial_clubs : '—'}</div>
          <div className="ps-sub">{stats ? `${stats.expiring_soon} закінчуються за 7 дн.` : '—'}</div>
        </div>
        <div className="platform-stat red">
          <div className="ps-label">Тріал закінчився</div>
          <div className="ps-value">{stats ? stats.expired_clubs : '—'}</div>
          <div className="ps-sub">потребують оплати</div>
        </div>
        <div className="platform-stat purple">
          <div className="ps-label">Дохід всього</div>
          <div className="ps-value">{stats ? formatMoney(stats.revenue_total) : '—'}</div>
          <div className="ps-sub">грн</div>
        </div>
      </div>

      <div className="clubs-toolbar">
        <div style={{ position: 'relative', flex: 1, minWidth: 200, maxWidth: 320 }}>
          <span style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)', fontSize: 14, pointerEvents: 'none' }}>🔍</span>
          <input type="text" placeholder="Назва, email власника..." style={{ paddingLeft: 36, width: '100%' }} value={searchInput} onChange={(e) => setSearchInput(e.target.value)} />
        </div>
        {STATUS_FILTERS.map(([v, l]) => (
          <button key={v} className={`status-filter-btn ${status === v ? 'active' : ''}`} onClick={() => selectFilter(v)}>{l}</button>
        ))}
      </div>

      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <Table
          columns={columns}
          rows={data.clubs}
          loading={loading}
          emptyMessage="Клубів не знайдено. Зареєстровані клуби з'являться тут"
          onRowClick={(row) => openDetail(row.id)}
          rowProps={(row) => ({ className: row.is_active ? '' : 'club-blocked', style: row.is_active ? undefined : { opacity: 0.5 } })}
        />
      </div>

      <Pagination page={data.pagination.page} pages={data.pagination.pages} total={data.pagination.total} perPage={data.pagination.per_page} onChange={setPage} />

      {/* Деталі клубу */}
      <Modal size="lg" open={!!detail} onClose={() => setDetail(null)}>
        {detail?.loading && <div className="loader"><span className="spinner" /> Завантаження...</div>}
        {detail && !detail.loading && c && (
          <>
            <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', marginBottom: 20, flexWrap: 'wrap', gap: 10 }}>
              <div>
                <h2 className="modal-title" style={{ marginBottom: 4 }}>{c.name}</h2>
                <Badge variant={subActive ? 'active' : 'inactive'}>{subActive ? 'Активний' : 'Неактивний'}</Badge>
                <span style={{ marginLeft: 6, fontSize: 12, color: 'var(--text-muted)' }}>{subReason}</span>
                {!c.is_active && <span style={{ marginLeft: 6 }}><Badge variant="inactive">Заблоковано</Badge></span>}
              </div>
              <button className="btn btn-ghost btn-sm" style={{ color: c.is_active ? 'var(--danger)' : 'var(--success)' }} onClick={openBlockModalFromDetail}>
                {c.is_active ? '🚫 Заблокувати' : '✅ Розблокувати'}
              </button>
            </div>

            <ModalTabs
              tabs={[
                { key: 'info', label: 'Інфо' },
                { key: 'billing', label: 'Підписка' },
                { key: 'payments', label: 'Платежі' },
              ]}
              active={detail.tab}
              onChange={switchDetailTab}
            />

            <div className="modal-tab-body">
              {detail.tab === 'info' && (
                <div>
                  <div className="club-detail-label">Про клуб</div>
                  <DetailFieldsGrid variant="rows" fields={[
                    ['Місто', c.city || '—'], ['Адреса', c.address || '—'], ['Телефон', c.phone || '—'],
                    ['Email', c.email || '—'], ['Slug', c.slug || '—'], ['Зареєстровано', formatDate(c.created_at)],
                  ]} />
                  <div className="club-detail-label" style={{ marginTop: 20 }}>Власник</div>
                  <DetailFieldsGrid variant="rows" fields={[
                    ["Ім'я", c.owner_name || '—'], ['Email', c.owner_email || '—'], ['Телефон', c.owner_phone || '—'],
                    ['Останній вхід', c.owner_last_login ? formatDate(c.owner_last_login) : 'Ніколи'],
                  ]} />
                </div>
              )}

              {detail.tab === 'billing' && (
                <div>
                  <DetailFieldsGrid variant="rows" fields={[
                    ['План', `${c.plan_name || '—'} ${c.price_monthly ? `— ${c.price_monthly} грн/міс` : ''}`],
                    ['Статус', `${subActive ? 'Активний' : 'Неактивний'} · ${subReason}`],
                    ['Тріал до', c.sub_trial_ends ? formatDate(c.sub_trial_ends) : '—'],
                    ['Підписка до', c.current_period_end ? formatDate(c.current_period_end) : '—'],
                  ]} />
                  {c.admin_notes && <div style={{ marginTop: 12, fontSize: 12, color: 'var(--text-muted)', background: 'var(--bg-elevated)', padding: 10, borderRadius: 'var(--radius-sm)', whiteSpace: 'pre-line' }}>{c.admin_notes}</div>}

                  <div className="club-admin-card">
                    <div className="club-admin-card-head">
                      <div>
                        <div className="club-detail-label" style={{ marginBottom: 3 }}>Керування тріалом</div>
                        <div style={{ fontSize: 12, color: 'var(--text-secondary)' }}>
                          {c.sub_status === 'trial'
                            ? 'Тріал зараз активний — додані дні продовжать поточну дату закінчення.'
                            : 'Активного тріалу немає — новий стартує від сьогодні на обраний план.'}
                        </div>
                      </div>
                      {c.sub_status === 'trial' && (
                        <button className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)', flexShrink: 0 }} onClick={openCancelTrialModal}>Скасувати тріал</button>
                      )}
                    </div>
                    <div className="club-admin-card-fields">
                      <FormGroup label="План на тріалі">
                        <select value={detail.extendPlanId} onChange={(e) => setDetail({ ...detail, extendPlanId: parseInt(e.target.value) })}>
                          {plans.filter((p) => !p.is_free).map((p) => <option key={p.id} value={p.id}>{p.name} — {p.price_monthly} грн/міс</option>)}
                        </select>
                      </FormGroup>
                      <FormGroup label="Днів"><input type="number" min="1" max="90" value={detail.extendDays} onChange={(e) => setDetail({ ...detail, extendDays: parseInt(e.target.value) || '' })} /></FormGroup>
                      <FormGroup label="Причина (опційно)" fullWidth><input type="text" placeholder="напр. технічні проблеми, прохання клієнта" value={detail.extendNote} onChange={(e) => setDetail({ ...detail, extendNote: e.target.value })} /></FormGroup>
                    </div>
                    <button className="btn btn-primary" style={{ width: '100%' }} disabled={detail.extendSubmitting} onClick={handleExtendTrial}>
                      {detail.extendSubmitting ? '...' : c.sub_status === 'trial' ? `Продовжити на ${detail.extendDays || 0} дн.` : `Надати тріал на ${detail.extendDays || 0} дн.`}
                    </button>
                  </div>

                  <div className="club-admin-card">
                    <div className="club-detail-label" style={{ marginBottom: 12 }}>Записати ручний платіж</div>
                    <div className="club-admin-card-fields">
                      <FormGroup label="План">
                        <select value={detail.payPlanId} onChange={(e) => setDetail({ ...detail, payPlanId: e.target.value })}>
                          {plans.map((p) => <option key={p.id} value={p.id}>{p.name} — {p.price_monthly} грн</option>)}
                        </select>
                      </FormGroup>
                      <FormGroup label="Місяців"><input type="number" min="1" max="12" value={detail.payMonths} onChange={(e) => setDetail({ ...detail, payMonths: parseInt(e.target.value) || 1 })} /></FormGroup>
                      <FormGroup label="Примітка" fullWidth><input type="text" placeholder="напр. оплата готівкою" value={detail.payNote} onChange={(e) => setDetail({ ...detail, payNote: e.target.value })} /></FormGroup>
                    </div>
                    <button className="btn btn-ghost" style={{ width: '100%' }} disabled={detail.paySubmitting} onClick={handleRecordPayment}>{detail.paySubmitting ? '...' : 'Записати платіж'}</button>
                  </div>

                  <div style={{ marginTop: 20, paddingTop: 20, borderTop: '1px solid var(--border)' }}>
                    <div style={{ padding: 14, background: 'rgba(248,113,113,.06)', border: '1px solid rgba(248,113,113,.2)', borderRadius: 'var(--radius-sm)' }}>
                      <div style={{ fontSize: 13, fontWeight: 600, color: 'var(--danger)', marginBottom: 6 }}>Небезпечна зона — видалення власника і клубу</div>
                      {c.total_paid > 0 ? (
                        <div style={{ fontSize: 12, color: 'var(--text-secondary)' }}>
                          Видалення заблоковано: власник має успішні оплати білінгу ({formatMoney(c.total_paid)}). Видаляти клуби з реальними платежами заборонено.
                        </div>
                      ) : (
                        <>
                          <div style={{ fontSize: 12, color: 'var(--text-secondary)', marginBottom: 10 }}>
                            Повністю видаляє власника, клуб і всі його дані (клієнтів, абонементи, товари, каси, персонал) — незворотно. Використовуйте лише для тестових записів або клубів, які точно не повернуться і не оплачували білінг. Потребує коду підтвердження в Telegram.
                          </div>
                          <button className="btn btn-danger btn-sm" onClick={openDeleteWarn}>🗑 Видалити власника і клуб</button>
                        </>
                      )}
                    </div>
                  </div>
                </div>
              )}

              {detail.tab === 'payments' && (
                <div>
                  {detail.paymentsLoading && <div className="loader"><span className="spinner" /></div>}
                  {!detail.paymentsLoading && detail.payments.length === 0 && <div className="empty-state" style={{ padding: '30px 0' }}><p>Платежів ще немає</p></div>}
                  {!detail.paymentsLoading && detail.payments.map((p) => (
                    <div className="payment-row" key={p.invoice_id}>
                      <div>
                        <div style={{ fontSize: 13, fontWeight: 500 }}>{p.plan_name}</div>
                        <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{formatDate(p.period_start)} — {formatDate(p.period_end)}{p.notes ? ` · ${p.notes}` : ''}</div>
                      </div>
                      <div style={{ textAlign: 'right' }}>
                        <div className="payment-amount">{formatMoney(p.amount)}</div>
                        <div className="payment-gateway">{p.gateway || p.payment_gateway || '—'}</div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </>
        )}
      </Modal>

      {/* Блокування */}
      <Modal
        size="sm"
        open={!!blockModal}
        onClose={() => setBlockModal(null)}
        title={blockModal?.isActive ? 'Заблокувати клуб?' : 'Розблокувати клуб?'}
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-danger" onClick={confirmBlock}>{blockModal?.isActive ? 'Заблокувати' : 'Розблокувати'}</button>
            <button className="btn btn-ghost" onClick={() => setBlockModal(null)}>Скасувати</button>
          </div>
        }
      >
        {blockModal && (
          <p style={{ fontSize: 14, color: 'var(--text-secondary)' }}>
            {blockModal.isActive
              ? <>Клуб <strong>{blockModal.name}</strong> буде заблоковано. Власник не зможе входити.</>
              : <>Клуб <strong>{blockModal.name}</strong> буде розблоковано.</>}
          </p>
        )}
      </Modal>

      {/* Скасування тріалу */}
      <Modal
        size="sm"
        open={!!cancelTrialModal}
        onClose={() => setCancelTrialModal(null)}
        title="Скасувати тріал?"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-danger" disabled={cancelTrialModal?.submitting} onClick={confirmCancelTrial}>{cancelTrialModal?.submitting ? '...' : 'Скасувати тріал'}</button>
            <button className="btn btn-ghost" onClick={() => setCancelTrialModal(null)}>Назад</button>
          </div>
        }
      >
        {cancelTrialModal && (
          <p style={{ fontSize: 14, color: 'var(--text-secondary)' }}>
            Тріал клубу <strong>{cancelTrialModal.name}</strong> завершиться негайно, доступ до платних розділів буде обмежено. Цю дію можна відмінити, надавши тріал повторно.
          </p>
        )}
      </Modal>

      {/* Видалення клубу — крок 1: попередження + причина */}
      <Modal
        size="sm"
        open={!!deleteWarn}
        onClose={() => setDeleteWarn(null)}
        title="Видалити власника і клуб?"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button
              className="btn btn-danger"
              disabled={deleteWarn?.submitting || !deleteWarn?.reason.trim() || !deleteWarn?.acknowledged}
              onClick={submitDeleteWarn}
            >
              {deleteWarn?.submitting ? '...' : 'Надіслати код у Telegram'}
            </button>
            <button className="btn btn-ghost" onClick={() => setDeleteWarn(null)}>Скасувати</button>
          </div>
        }
      >
        {deleteWarn && (
          <div>
            {deleteWarn.error && <div className="alert alert-error">{deleteWarn.error}</div>}
            <div className="alert alert-error" style={{ marginBottom: 16 }}>
              Клуб <strong>{deleteWarn.name}</strong> та всі його дані (клієнти, абонементи, товари, каси, персонал) будуть видалені <strong>назавжди й без можливості відновлення</strong>.
            </div>
            <FormGroup label="Причина видалення *">
              <textarea
                rows="2"
                placeholder="напр. Тестовий обліковий запис, клієнт не оплачував і не повернеться"
                value={deleteWarn.reason}
                onChange={(e) => setDeleteWarn({ ...deleteWarn, reason: e.target.value, error: '' })}
              />
            </FormGroup>
            <label style={{ display: 'flex', alignItems: 'flex-start', gap: 8, fontSize: 13, marginTop: 12, cursor: 'pointer' }}>
              <input
                type="checkbox"
                checked={deleteWarn.acknowledged}
                onChange={(e) => setDeleteWarn({ ...deleteWarn, acknowledged: e.target.checked, error: '' })}
                style={{ marginTop: 2 }}
              />
              <span>Я усвідомлюю, що видалення незворотне і призведе до втрати всіх даних клубу.</span>
            </label>
          </div>
        )}
      </Modal>

      {/* Видалення клубу — крок 2: код з Telegram */}
      <Modal
        size="sm"
        open={!!deleteCode}
        onClose={() => setDeleteCode(null)}
        title="Код підтвердження"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-danger" disabled={deleteCode?.submitting || !deleteCode?.code.trim()} onClick={submitDeleteCode}>
              {deleteCode?.submitting ? '...' : '🗑 Видалити остаточно'}
            </button>
            <button className="btn btn-ghost" onClick={() => setDeleteCode(null)}>Скасувати</button>
          </div>
        }
      >
        {deleteCode && (
          <div>
            {deleteCode.error && <div className="alert alert-error">{deleteCode.error}</div>}
            <p style={{ fontSize: 13, color: 'var(--text-secondary)', marginBottom: 14 }}>
              Код надіслано у ваш Telegram — введіть його, щоб остаточно видалити клуб <strong>{deleteCode.name}</strong>. Дійсний 10 хвилин, макс. 5 спроб.
            </p>
            <FormGroup label="Код з Telegram *">
              <input
                type="text"
                inputMode="numeric"
                maxLength={6}
                placeholder="000000"
                style={{ fontSize: 20, letterSpacing: 4, textAlign: 'center' }}
                value={deleteCode.code}
                onChange={(e) => setDeleteCode({ ...deleteCode, code: e.target.value.replace(/\D/g, ''), error: '' })}
              />
            </FormGroup>
          </div>
        )}
      </Modal>
    </AppLayout>
  );
}
