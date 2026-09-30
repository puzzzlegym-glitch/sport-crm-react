import { useEffect, useState } from 'react';
import AppLayout from '../components/layout/AppLayout';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import Badge from '../components/ui/Badge';
import { usePermissions } from '../hooks/usePermissions';
import { useToast } from '../components/ui/ToastProvider';
import {
  getTrainers, getTrainer, saveTrainerProfile, toggleTrainerActive,
  getTrainerEarnings, payTrainerEarning, getTrainerRent, saveTrainerRent, deleteTrainerRent,
  getTrainerSummary, getTrainerUsers,
  getMyTrainerProfile, getMyTrainerEarnings, getMyTrainerSummary,
  payTrainerRent, getTrainerPayouts, reverseTrainerPayout, reverseTrainerRentPayment,
} from '../api/trainers';
import { formatMoney, formatDate, localToday, getInitials } from '../utils/format';
import './TrainersPage.css';

const EARN_TYPE_LABELS = { personal_percent: '% персональне', personal_fixed: 'Фікс. персональне', group_fixed: 'Фікс. групове', group_bonus: 'Бонус групового' };
const TRIGGER_LABELS = { on_sale: 'При продажу', on_each_visit: 'Після кожного', on_visits_done: 'Всі заняття', on_end_date: 'Кінець дати' };
const STATUS_VARIANT = { locked: 'inactive', available: 'active', partial: 'pending', paid: 'active' };
const STATUS_ICON = { locked: '🔒', available: '✅', partial: '⏳', paid: '💚' };
const STATUS_LABEL = { locked: 'Заблок.', available: 'Доступно', partial: 'Частково', paid: 'Виплачено' };
const RENT_STATUS = { pending: ['pending', 'Очікує'], partial: ['pending', 'Частково'], paid: ['active', 'Оплачено'] };
const RENT_METHOD_LABEL = { cash: 'готівка', card: 'картка', transfer: 'на рахунок' };
const WORK_TYPE_VARIANT = { employee: 'info', rent: 'pending', both: 'active' };
const WORK_TYPE_LABEL = { employee: 'Найманий', rent: 'Орендар', both: 'Найм + Оренда' };

const EMPTY_TRAINER_FORM = {
  editId: null, mode: 'existing', userId: '', userName: '', ghostName: '', ghostPhone: '',
  specialization: '', workType: 'employee',
  persType: 'percent', persValue: '50', persTierThreshold: '0', persTierValue: '0',
  groupRate: '0', groupBonus: '0', groupThreshold: '0', groupMonthlySessions: '0', groupMonthlyAmount: '0',
  error: '', submitting: false,
};

function SummaryCards({ s, idPrefix }) {
  if (!s) return <div className="loader"><span className="spinner" /></div>;
  const toPayOut = parseFloat(s.to_pay_out || 0);
  return (
    <div className="tr-summary-cards" id={idPrefix}>
      <div className="tr-sum-card"><div className="tr-sum-label">Всього нараховано</div><div className="tr-sum-value">{formatMoney(s.total_earned)}</div></div>
      <div className="tr-sum-card highlight"><div className="tr-sum-label">До виплати</div><div className="tr-sum-value positive">{formatMoney(toPayOut)}</div></div>
      <div className="tr-sum-card"><div className="tr-sum-label">Виплачено</div><div className="tr-sum-value">{formatMoney(s.total_paid)}</div></div>
      <div className="tr-sum-card"><div className="tr-sum-label">🔒 Заблоковано</div><div className="tr-sum-value" style={{ fontSize: 14 }}>{s.cnt_locked || 0} нарахувань</div></div>
      {parseFloat(s.rent_deduction || 0) > 0 && (
        <div className="tr-sum-card" style={{ borderColor: 'var(--warning)' }}>
          <div className="tr-sum-label">🏠 Оренда — утримається з виплат</div>
          <div className="tr-sum-value" style={{ color: 'var(--warning)' }}>{formatMoney(s.rent_deduction)}</div>
        </div>
      )}
      {parseFloat(s.rent_manual || 0) > 0 && (
        <div className="tr-sum-card" style={{ borderColor: 'var(--warning)' }}>
          <div className="tr-sum-label">🏠 Оренда — борг тренера</div>
          <div className="tr-sum-value" style={{ color: 'var(--warning)' }}>{formatMoney(s.rent_manual)}</div>
        </div>
      )}
    </div>
  );
}

export default function TrainersPage() {
  const { has, isOwner } = usePermissions();
  const toast = useToast();
  const isManager = has('trainers.manage');
  const isTrainerRole = !isManager && has('trainers.view');

  const [view, setView] = useState('list'); // 'list' | 'detail'
  const [trainers, setTrainers] = useState([]);
  const [trainersLoading, setTrainersLoading] = useState(true);
  const [showArchived, setShowArchived] = useState(false);

  const [selectedId, setSelectedId] = useState(null);
  const [trainer, setTrainer] = useState(null);
  const [summary, setSummary] = useState(null);
  const [detailTab, setDetailTab] = useState('earnings');

  const [earnStatus, setEarnStatus] = useState('');
  const [earnings, setEarnings] = useState([]);
  const [earningsLoading, setEarningsLoading] = useState(true);

  const [rent, setRent] = useState([]);
  const [rentLoading, setRentLoading] = useState(true);

  const [trainerModal, setTrainerModal] = useState(null);
  const [payModal, setPayModal] = useState(null);
  const [rentModal, setRentModal] = useState(null);
  const [rentPay, setRentPay] = useState(null);     // { rent, amount, method, submitting, error }
  const [payouts, setPayouts] = useState(null);     // { payouts, rent_payments }
  const [storno, setStorno] = useState(null);       // { kind: 'payout'|'rent', id, label, reason, submitting, error }

  const [profileForm, setProfileForm] = useState(null);
  const [profileSaved, setProfileSaved] = useState(false);

  // ── Мої дані (тренер) ─────────────────────────────────────
  const [myTab, setMyTab] = useState('earnings');
  const [mySummary, setMySummary] = useState(null);
  const [myEarnings, setMyEarnings] = useState([]);
  const [myProfile, setMyProfile] = useState(null);

  async function reloadTrainersList() {
    setTrainersLoading(true);
    const res = await getTrainers();
    setTrainersLoading(false);
    if (!res.success) { toast(res.error, 'error'); return; }
    setTrainers(res.trainers || []);
  }

  useEffect(() => {
    if (isManager) reloadTrainersList();
    else if (isTrainerRole) loadMyData();
    // eslint-disable-next-line
  }, [isManager, isTrainerRole]);

  async function loadMyData() {
    const [sumRes, earRes, profRes] = await Promise.all([getMyTrainerSummary(), getMyTrainerEarnings(), getMyTrainerProfile()]);
    if (sumRes.success) setMySummary(sumRes.summary);
    if (earRes.success) setMyEarnings(earRes.earnings || []);
    if (profRes.success) setMyProfile(profRes.trainer);
  }

  // ── Деталі тренера ────────────────────────────────────────
  async function openDetail(t) {
    setSelectedId(t.id);
    setView('detail');
    setDetailTab('earnings');
    setEarnStatus('');
    const [trRes, sumRes] = await Promise.all([getTrainer(t.id), getTrainerSummary(t.id)]);
    if (trRes.success) { setTrainer(trRes.trainer); buildProfileForm(trRes.trainer); }
    if (sumRes.success) setSummary(sumRes.summary);
    reloadEarnings(t.id, '');
  }

  function backToList() {
    setView('list');
    setSelectedId(null);
    reloadTrainersList();
  }

  async function reloadEarnings(trainerId, status) {
    setEarningsLoading(true);
    const res = await getTrainerEarnings(trainerId, status);
    setEarningsLoading(false);
    if (!res.success) { toast(res.error, 'error'); return; }
    setEarnings(res.earnings || []);
  }

  function changeEarnStatus(status) {
    setEarnStatus(status);
    reloadEarnings(selectedId, status);
  }

  async function reloadRent() {
    setRentLoading(true);
    const res = await getTrainerRent(selectedId);
    setRentLoading(false);
    if (!res.success) { toast(res.error, 'error'); return; }
    setRent(res.rent || []);
  }

  async function reloadPayouts() {
    const res = await getTrainerPayouts(selectedId);
    setPayouts(res.success ? res : { payouts: [], rent_payments: [] });
  }

  function switchDetailTab(tab) {
    setDetailTab(tab);
    if (tab === 'rent') { reloadRent(); reloadPayouts(); }
    if (tab === 'payouts') reloadPayouts();
  }

  // ── Оплата оренди тренером ("Оплачено") ─────────────────────
  function openRentPay(r) {
    const left = Math.round((parseFloat(r.amount) - parseFloat(r.paid_amount || 0)) * 100) / 100;
    setRentPay({ rent: r, left, amount: String(left), method: 'cash', submitting: false, error: '' });
  }
  async function submitRentPay() {
    const amount = parseFloat(rentPay.amount);
    if (!amount || amount <= 0 || amount > rentPay.left + 0.001) { setRentPay({ ...rentPay, error: `Сума від 0 до ${rentPay.left} грн` }); return; }
    setRentPay({ ...rentPay, submitting: true, error: '' });
    const res = await payTrainerRent({ rent_id: rentPay.rent.id, amount, payment_method: rentPay.method });
    if (!res.success) { setRentPay({ ...rentPay, submitting: false, error: res.error }); return; }
    toast(res.message, 'success');
    setRentPay(null);
    reloadRent(); reloadPayouts(); reloadSummary();
  }

  // ── Сторно (лише власник) ───────────────────────────────────
  async function submitStorno() {
    if (!storno.reason.trim()) { setStorno({ ...storno, error: 'Вкажіть причину' }); return; }
    setStorno({ ...storno, submitting: true, error: '' });
    const res = storno.kind === 'payout'
      ? await reverseTrainerPayout(storno.id, storno.reason.trim())
      : await reverseTrainerRentPayment(storno.id, storno.reason.trim());
    if (!res.success) { setStorno({ ...storno, submitting: false, error: res.error }); return; }
    toast('Сторно проведено', 'success');
    setStorno(null);
    reloadPayouts(); reloadRent(); reloadSummary(); reloadEarnings(selectedId, earnStatus);
  }

  async function reloadSummary() {
    const res = await getTrainerSummary(selectedId);
    if (res.success) setSummary(res.summary);
  }

  async function handleToggle() {
    const res = await toggleTrainerActive(selectedId);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message, 'success');
    setTrainer((t) => ({ ...t, is_active: res.is_active }));
  }

  // ── Форма налаштувань (вкладка "Налаштування") ─────────────
  function buildProfileForm(t) {
    setProfileForm({
      specialization: t.specialization || '', workType: t.work_type || 'employee',
      persType: t.personal_earn_type || 'percent', persValue: t.personal_earn_value ?? 50,
      persTierThreshold: t.personal_tier_threshold ?? 0, persTierValue: t.personal_tier_value ?? 0,
      groupRate: t.group_earn_rate ?? 0, groupBonus: t.group_earn_bonus_per_client ?? 0,
      groupThreshold: t.group_bonus_threshold ?? 0,
      groupMonthlySessions: t.group_monthly_bonus_sessions ?? 0, groupMonthlyAmount: t.group_monthly_bonus_amount ?? 0,
    });
  }

  async function saveProfileInline() {
    const res = await saveTrainerProfile({
      trainer_id: selectedId,
      specialization: profileForm.specialization.trim(),
      work_type: profileForm.workType,
      personal_earn_type: profileForm.persType,
      personal_earn_value: parseFloat(profileForm.persValue) || 0,
      personal_tier_threshold: parseInt(profileForm.persTierThreshold) || 0,
      personal_tier_value: parseFloat(profileForm.persTierValue) || 0,
      group_earn_rate: parseFloat(profileForm.groupRate) || 0,
      group_earn_bonus_per_client: parseFloat(profileForm.groupBonus) || 0,
      group_bonus_threshold: parseInt(profileForm.groupThreshold) || 0,
      group_monthly_bonus_sessions: parseInt(profileForm.groupMonthlySessions) || 0,
      group_monthly_bonus_amount: parseFloat(profileForm.groupMonthlyAmount) || 0,
    });
    if (!res.success) { toast(res.error, 'error'); return; }
    setProfileSaved(true);
    setTimeout(() => setProfileSaved(false), 3000);
  }

  // ── Виплата ─────────────────────────────────────────────────
  function openPayModal(e) {
    const canPay = parseFloat(e.available_amount) - parseFloat(e.paid_amount);
    setPayModal({ earningId: e.id, max: canPay, clientName: e.client_name || '', amount: String(canPay), paymentMethod: 'cash', rentDeduction: parseFloat(summary?.rent_deduction || 0), error: '' });
  }

  async function confirmPay() {
    const amount = parseFloat(payModal.amount);
    if (!amount || amount <= 0) { setPayModal({ ...payModal, error: 'Введіть суму' }); return; }
    if (amount > payModal.max) { setPayModal({ ...payModal, error: `Максимум ${payModal.max} грн` }); return; }
    const res = await payTrainerEarning(payModal.earningId, amount, payModal.paymentMethod);
    if (!res.success) { setPayModal({ ...payModal, error: res.error }); return; }
    toast(res.message || 'Виплату зафіксовано', 'success');
    setPayModal(null);
    reloadEarnings(selectedId, earnStatus);
    reloadSummary();
  }

  // ── Оренда ──────────────────────────────────────────────────
  function openRentModal() {
    setRentModal({ type: 'manual', amount: '', start: localToday(), end: localToday(), notes: '', error: '' });
  }

  async function submitRent() {
    const res = await saveTrainerRent({
      trainer_id: selectedId,
      rent_type: rentModal.type,
      amount: parseFloat(rentModal.amount) || 0,
      period_start: rentModal.start,
      period_end: rentModal.end,
      notes: rentModal.notes.trim(),
    });
    if (!res.success) { setRentModal({ ...rentModal, error: res.error }); return; }
    toast('Оренду додано', 'success');
    setRentModal(null);
    reloadRent();
    reloadSummary();
  }

  async function handleDeleteRent(rentId) {
    if (!confirm('Видалити запис оренди?')) return;
    const res = await deleteTrainerRent(rentId);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast('Видалено', 'success');
    reloadRent();
    reloadSummary();
  }

  // ── Модалка тренера (create/edit) ──────────────────────────
  async function openCreateTrainerModal() {
    const res = await getTrainerUsers();
    const users = res.success ? res.users : [];
    const available = users.filter((u) => !u.trainer_profile_id);
    setTrainerModal({ ...EMPTY_TRAINER_FORM, mode: available.length ? 'existing' : 'new', availableUsers: available });
  }

  function openEditTrainerModal() {
    setTrainerModal({
      ...EMPTY_TRAINER_FORM,
      editId: selectedId, userName: trainer.full_name || '',
      specialization: trainer.specialization || '', workType: trainer.work_type || 'employee',
      persType: trainer.personal_earn_type || 'percent', persValue: String(trainer.personal_earn_value ?? 50),
      persTierThreshold: String(trainer.personal_tier_threshold ?? 0), persTierValue: String(trainer.personal_tier_value ?? 0),
      groupRate: String(trainer.group_earn_rate ?? 0), groupBonus: String(trainer.group_earn_bonus_per_client ?? 0),
      groupThreshold: String(trainer.group_bonus_threshold ?? 0),
      groupMonthlySessions: String(trainer.group_monthly_bonus_sessions ?? 0), groupMonthlyAmount: String(trainer.group_monthly_bonus_amount ?? 0),
      availableUsers: [],
    });
  }

  async function submitTrainerModal() {
    const payload = {
      specialization: trainerModal.specialization.trim(),
      work_type: trainerModal.workType,
      personal_earn_type: trainerModal.persType,
      personal_earn_value: parseFloat(trainerModal.persValue) || 0,
      personal_tier_threshold: parseInt(trainerModal.persTierThreshold) || 0,
      personal_tier_value: parseFloat(trainerModal.persTierValue) || 0,
      group_earn_rate: parseFloat(trainerModal.groupRate) || 0,
      group_earn_bonus_per_client: parseFloat(trainerModal.groupBonus) || 0,
      group_bonus_threshold: parseInt(trainerModal.groupThreshold) || 0,
      group_monthly_bonus_sessions: parseInt(trainerModal.groupMonthlySessions) || 0,
      group_monthly_bonus_amount: parseFloat(trainerModal.groupMonthlyAmount) || 0,
    };
    if (trainerModal.editId) {
      payload.trainer_id = trainerModal.editId;
    } else if (trainerModal.mode === 'new') {
      if (trainerModal.ghostName.trim().length < 2) { setTrainerModal({ ...trainerModal, error: 'Введіть ім\'я тренера' }); return; }
      payload.full_name = trainerModal.ghostName.trim();
      payload.phone = trainerModal.ghostPhone.trim();
    } else {
      if (!trainerModal.userId) { setTrainerModal({ ...trainerModal, error: 'Оберіть тренера' }); return; }
      payload.user_id = parseInt(trainerModal.userId);
    }
    const res = await saveTrainerProfile(payload);
    if (!res.success) { setTrainerModal({ ...trainerModal, error: res.error }); return; }
    toast('Збережено', 'success');
    setTrainerModal(null);
    if (trainerModal.editId) {
      const [trRes, sumRes] = await Promise.all([getTrainer(selectedId), getTrainerSummary(selectedId)]);
      if (trRes.success) { setTrainer(trRes.trainer); buildProfileForm(trRes.trainer); }
      if (sumRes.success) setSummary(sumRes.summary);
    } else {
      reloadTrainersList();
    }
  }

  const isPersPercent = trainerModal?.persType === 'percent';
  const archivedCount = trainers.filter((t) => !t.is_active).length;
  const visibleTrainers = showArchived ? trainers : trainers.filter((t) => t.is_active);

  // ── Рендер: немає доступу ──────────────────────────────────
  if (!isManager && !isTrainerRole) {
    return (
      <AppLayout title="Тренери">
        <div style={{ color: 'var(--text-muted)', padding: '40px 0', textAlign: 'center' }}>Доступ заборонено</div>
      </AppLayout>
    );
  }

  // ── Рендер: кабінет тренера ─────────────────────────────────
  if (isTrainerRole) {
    return (
      <AppLayout title="Тренери">
        <SummaryCards s={mySummary} idPrefix="my-summary" />
        <div className="tr-detail-tabs">
          <button className={`tr-detail-tab ${myTab === 'earnings' ? 'active' : ''}`} onClick={() => setMyTab('earnings')}>💰 Нарахування та виплати</button>
          <button className={`tr-detail-tab ${myTab === 'settings' ? 'active' : ''}`} onClick={() => setMyTab('settings')}>⚙ Мої налаштування</button>
        </div>

        {myTab === 'earnings' && (
          <div className="card" style={{ padding: 0, overflow: 'hidden', marginTop: 4 }}>
            <div className="table-wrap tr-earnings-desktop">
              <table>
                <thead><tr><th>Клієнт</th><th>Тип</th><th>Тригер</th><th>Нараховано</th><th>Доступно</th><th>Виплачено</th><th>Статус</th></tr></thead>
                <tbody>
                  {myEarnings.length === 0 && <tr><td colSpan={7} style={{ textAlign: 'center', color: 'var(--text-muted)' }}>Нарахувань немає</td></tr>}
                  {myEarnings.map((e) => (
                    <tr key={e.id}>
                      <td>{e.client_name || '—'}</td>
                      <td>{EARN_TYPE_LABELS[e.earn_type] || e.earn_type}</td>
                      <td style={{ fontSize: 12, color: 'var(--text-secondary)' }}>{TRIGGER_LABELS[e.release_trigger] || ''}</td>
                      <td><strong>{formatMoney(e.amount)}</strong></td>
                      <td className={parseFloat(e.available_amount) > 0 ? 'positive' : ''}>{formatMoney(e.available_amount)}</td>
                      <td>{formatMoney(e.paid_amount)}</td>
                      <td><Badge variant={STATUS_VARIANT[e.status]}>{STATUS_ICON[e.status]} {STATUS_LABEL[e.status] || e.status}</Badge></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="mobile-record-list">
              {myEarnings.length === 0 && <div className="empty-state"><p>Нарахувань немає</p></div>}
              {myEarnings.map((e) => (
                <div className="mobile-record-card" key={e.id} style={{ cursor: 'default' }}>
                  <div className="mrc-top">
                    <span className="mrc-title">{e.client_name || '—'}</span>
                    <Badge variant={STATUS_VARIANT[e.status]}>{STATUS_ICON[e.status]} {STATUS_LABEL[e.status] || e.status}</Badge>
                  </div>
                  <div className="mrc-sub">
                    <span className="mrc-meta">{EARN_TYPE_LABELS[e.earn_type] || e.earn_type}</span>
                    <span className={parseFloat(e.available_amount) > 0 ? 'mrc-amount' : 'mrc-date'}>{formatMoney(e.available_amount)}</span>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}

        {myTab === 'settings' && (
          <div style={{ marginTop: 4 }}>
            {!myProfile ? (
              <div style={{ color: 'var(--text-muted)' }}>Налаштування недоступні</div>
            ) : (
              <>
                <div className="tr-settings-grid">
                  <div className="card">
                    <div className="card-title" style={{ marginBottom: 14 }}>👤 Загальне</div>
                    <div className="my-set-row"><span className="my-set-label">Спеціалізація</span><span className="my-set-val">{myProfile.specialization || '—'}</span></div>
                    <div className="my-set-row"><span className="my-set-label">Тип роботи</span><span className="my-set-val"><Badge variant={WORK_TYPE_VARIANT[myProfile.work_type]}>{WORK_TYPE_LABEL[myProfile.work_type]}</Badge></span></div>
                  </div>
                  <div className="card">
                    <div className="card-title" style={{ marginBottom: 14 }}>🏋 Персональні тренування</div>
                    <div className="my-set-row"><span className="my-set-label">Тип нарахування</span><span className="my-set-val">{myProfile.personal_earn_type === 'percent' ? '%' : 'грн (фікс.)'}</span></div>
                    <div className="my-set-row">
                      <span className="my-set-label">{myProfile.personal_earn_type === 'percent' ? 'Відсоток' : 'Сума за заняття'}</span>
                      <span className="my-set-val accent">{myProfile.personal_earn_value} {myProfile.personal_earn_type === 'percent' ? '%' : 'грн'}</span>
                    </div>
                    {myProfile.personal_tier_threshold > 0 && (
                      <div className="my-set-row">
                        <span className="my-set-label">Тир (від {myProfile.personal_tier_threshold} клієнтів)</span>
                        <span className="my-set-val">{myProfile.personal_tier_value} {myProfile.personal_earn_type === 'percent' ? '%' : 'грн'}</span>
                      </div>
                    )}
                  </div>
                  <div className="card">
                    <div className="card-title" style={{ marginBottom: 14 }}>👥 Групові тренування</div>
                    <div className="my-set-row"><span className="my-set-label">Ставка за заняття</span><span className="my-set-val accent">{myProfile.group_earn_rate} грн</span></div>
                    {myProfile.group_earn_bonus_per_client > 0 && (
                      <div className="my-set-row">
                        <span className="my-set-label">Бонус за учасника</span>
                        <span className="my-set-val">{myProfile.group_earn_bonus_per_client} грн {myProfile.group_bonus_threshold > 0 ? `(від ${myProfile.group_bonus_threshold} осіб)` : '(з першого)'}</span>
                      </div>
                    )}
                    {myProfile.group_monthly_bonus_sessions > 0 && (
                      <div className="my-set-row">
                        <span className="my-set-label">Місячний бонус</span>
                        <span className="my-set-val">{myProfile.group_monthly_bonus_amount} грн (якщо ≥ {myProfile.group_monthly_bonus_sessions} занять/міс)</span>
                      </div>
                    )}
                    {!myProfile.group_earn_bonus_per_client && !myProfile.group_monthly_bonus_sessions && !myProfile.group_earn_rate && (
                      <div style={{ color: 'var(--text-muted)', fontSize: 13 }}>Групові нарахування не налаштовано</div>
                    )}
                  </div>
                </div>
                <div style={{ marginTop: 12, fontSize: 12, color: 'var(--text-muted)' }}>ℹ Налаштування встановлюються клубом. Для зміни зверніться до менеджера.</div>
              </>
            )}
          </div>
        )}
      </AppLayout>
    );
  }

  // ── Рендер: менеджер/власник ─────────────────────────────────
  return (
    <AppLayout title="Тренери">
      {view === 'list' && (
        <>
          <div className="page-header" style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginBottom: 16 }}>
            {archivedCount > 0 && (
              <button className="btn btn-ghost" onClick={() => setShowArchived((v) => !v)}>
                {showArchived ? 'Сховати архівних' : `Показати архівних (${archivedCount})`}
              </button>
            )}
            <button className="btn btn-primary" onClick={openCreateTrainerModal}>+ Додати тренера</button>
          </div>
          {trainersLoading && <div className="loader" style={{ padding: '60px 0' }}><span className="spinner" /></div>}
          {!trainersLoading && trainers.length === 0 && (
            <div style={{ color: 'var(--text-muted)', padding: '40px 0', textAlign: 'center' }}>Тренерів ще немає. Додайте першого!</div>
          )}
          {!trainersLoading && trainers.length > 0 && visibleTrainers.length === 0 && (
            <div style={{ color: 'var(--text-muted)', padding: '40px 0', textAlign: 'center' }}>Активних тренерів немає.</div>
          )}
          {!trainersLoading && visibleTrainers.length > 0 && (
            <div className="tr-grid">
              {visibleTrainers.map((t) => {
                const avail = (parseFloat(t.total_available) - parseFloat(t.total_paid)).toFixed(2);
                return (
                  <div key={t.id} className={`tr-card ${t.is_active ? '' : 'tr-card-inactive'}`} onClick={() => openDetail(t)}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 12 }}>
                      <div className="tr-avatar tr-avatar-sm">{getInitials(t.full_name)}</div>
                      <div style={{ flex: 1, minWidth: 0 }}>
                        <div style={{ fontWeight: 600, fontSize: 15 }}>{t.full_name}</div>
                        <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{t.specialization || '—'}</div>
                      </div>
                      <Badge variant={WORK_TYPE_VARIANT[t.work_type]}>{WORK_TYPE_LABEL[t.work_type]}</Badge>
                    </div>
                    <div className="tr-card-stats">
                      <div><div className="tr-stat-label">Нараховано</div><div className="tr-stat-value">{formatMoney(t.total_earned)}</div></div>
                      <div><div className="tr-stat-label">Доступно</div><div className={`tr-stat-value ${avail > 0 ? 'positive' : ''}`}>{formatMoney(avail)}</div></div>
                      <div><div className="tr-stat-label">Виплачено</div><div className="tr-stat-value">{formatMoney(t.total_paid)}</div></div>
                    </div>
                    {parseFloat(t.rent_pending) > 0 && (
                      <div style={{ marginTop: 8, fontSize: 12, color: 'var(--warning)' }}>🏠 Оренда до виплати: {formatMoney(t.rent_pending)}</div>
                    )}
                  </div>
                );
              })}
            </div>
          )}
        </>
      )}

      {view === 'detail' && trainer && (
        <>
          <div style={{ marginBottom: 16 }}>
            <button className="btn btn-ghost btn-sm" onClick={backToList}>← До списку</button>
          </div>

          <div className="tr-profile-header card" style={{ marginBottom: 20 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 20, flexWrap: 'wrap' }}>
              <div className="tr-avatar" style={{ opacity: trainer.is_active ? 1 : 0.45 }}>{getInitials(trainer.full_name)}</div>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontSize: 18, fontWeight: 600 }}>{trainer.full_name}</div>
                <div style={{ color: 'var(--text-secondary)', fontSize: 13, marginTop: 2 }}>{trainer.specialization || '—'}</div>
                <div style={{ marginTop: 6 }}><Badge variant={WORK_TYPE_VARIANT[trainer.work_type]}>{WORK_TYPE_LABEL[trainer.work_type]}</Badge></div>
              </div>
              <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                <button className="btn btn-ghost btn-sm" onClick={openEditTrainerModal}>✎ Редагувати</button>
                {trainer.is_active
                  ? <button className="btn btn-ghost btn-sm tr-btn-pause" title="Призупинити" onClick={handleToggle}>⏸ Призупинити</button>
                  : (isOwner
                    ? <button className="tr-btn-resume" title="Активувати" onClick={handleToggle}>▶ Активувати</button>
                    : <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>Архівовано · відновити може лише власник</span>)}
              </div>
            </div>
            <div style={{ marginTop: 16, paddingTop: 16, borderTop: '1px solid var(--border)' }}>
              <SummaryCards s={summary} idPrefix="tr-summary" />
            </div>
          </div>

          <div className="tr-detail-tabs">
            <button className={`tr-detail-tab ${detailTab === 'earnings' ? 'active' : ''}`} onClick={() => switchDetailTab('earnings')}>💰 Нарахування</button>
            <button className={`tr-detail-tab ${detailTab === 'payouts' ? 'active' : ''}`} onClick={() => switchDetailTab('payouts')}>💸 Виплати</button>
            <button className={`tr-detail-tab ${detailTab === 'rent' ? 'active' : ''}`} onClick={() => switchDetailTab('rent')}>🏠 Оренда</button>
            <button className={`tr-detail-tab ${detailTab === 'profile' ? 'active' : ''}`} onClick={() => switchDetailTab('profile')}>⚙ Налаштування</button>
          </div>

          {detailTab === 'earnings' && (
            <>
              <div style={{ display: 'flex', gap: 8, marginBottom: 12, flexWrap: 'wrap', alignItems: 'center' }}>
                <select style={{ width: 'auto' }} value={earnStatus} onChange={(e) => changeEarnStatus(e.target.value)}>
                  <option value="">Всі статуси</option>
                  <option value="locked">🔒 Заблоковано</option>
                  <option value="available">✅ Доступно</option>
                  <option value="partial">⏳ Частково</option>
                  <option value="paid">💚 Виплачено</option>
                </select>
              </div>
              <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                <div className="table-wrap tr-earnings-desktop">
                  <table>
                    <thead><tr><th>Клієнт / Абонемент</th><th>Тип</th><th>Тригер</th><th>Нараховано</th><th>Доступно</th><th>Виплачено</th><th>Статус</th><th></th></tr></thead>
                    <tbody>
                      {earningsLoading && <tr><td colSpan={8}><div className="loader"><span className="spinner" /></div></td></tr>}
                      {!earningsLoading && earnings.length === 0 && <tr><td colSpan={8} style={{ textAlign: 'center', color: 'var(--text-muted)' }}>Нарахувань немає</td></tr>}
                      {!earningsLoading && earnings.map((e) => {
                        const canPay = parseFloat(e.available_amount) - parseFloat(e.paid_amount);
                        return (
                          <tr key={e.id}>
                            <td>
                              <div style={{ fontWeight: 500 }}>{e.client_name || '—'}</div>
                              {e.end_date && <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>до {formatDate(e.end_date)}{e.visits_total ? ` · ${e.visits_used}/${e.visits_total} занять` : ''}</div>}
                            </td>
                            <td>{EARN_TYPE_LABELS[e.earn_type] || e.earn_type}</td>
                            <td style={{ fontSize: 12, color: 'var(--text-secondary)' }}>{TRIGGER_LABELS[e.release_trigger] || e.release_trigger}</td>
                            <td><strong>{formatMoney(e.amount)}</strong></td>
                            <td className={parseFloat(e.available_amount) > 0 ? 'positive' : ''}>{formatMoney(e.available_amount)}</td>
                            <td>{formatMoney(e.paid_amount)}</td>
                            <td><Badge variant={STATUS_VARIANT[e.status]}>{STATUS_ICON[e.status]} {STATUS_LABEL[e.status] || e.status}</Badge></td>
                            <td>{canPay > 0 && e.status !== 'locked' && <button className="btn btn-ghost btn-sm" style={{ color: 'var(--success)' }} onClick={() => openPayModal(e)}>Виплатити</button>}</td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>

                <div className="mobile-record-list">
                  {earningsLoading && <div className="loader" style={{ padding: '20px 0' }}><span className="spinner" /></div>}
                  {!earningsLoading && earnings.length === 0 && <div className="empty-state"><p>Нарахувань немає</p></div>}
                  {!earningsLoading && earnings.map((e) => {
                    const canPay = parseFloat(e.available_amount) - parseFloat(e.paid_amount);
                    return (
                      <div className="mobile-record-card" key={e.id} style={{ cursor: 'default' }}>
                        <div className="mrc-top">
                          <span className="mrc-title">{e.client_name || '—'}</span>
                          {canPay > 0 && e.status !== 'locked' ? (
                            <button type="button" className="mrc-menu-btn" aria-label="Виплатити" onClick={() => openPayModal(e)}>💸</button>
                          ) : (
                            <Badge variant={STATUS_VARIANT[e.status]}>{STATUS_ICON[e.status]} {STATUS_LABEL[e.status] || e.status}</Badge>
                          )}
                        </div>
                        <div className="mrc-sub">
                          <span className="mrc-meta">{EARN_TYPE_LABELS[e.earn_type] || e.earn_type}</span>
                          <span className={parseFloat(e.available_amount) > 0 ? 'mrc-amount' : 'mrc-date'}>{formatMoney(e.available_amount)}</span>
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            </>
          )}

          {detailTab === 'rent' && (
            <>
              <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 12 }}>
                <button className="btn btn-primary btn-sm" onClick={openRentModal}>+ Додати оренду</button>
              </div>
              <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                <div className="table-wrap tr-rent-desktop">
                  <table>
                    <thead><tr><th>Тип</th><th>Сума</th><th>Сплачено</th><th>Період</th><th>Статус</th><th>Нотатки</th><th></th></tr></thead>
                    <tbody>
                      {rentLoading && <tr><td colSpan={7}><div className="loader"><span className="spinner" /></div></td></tr>}
                      {!rentLoading && rent.length === 0 && <tr><td colSpan={7} style={{ textAlign: 'center', color: 'var(--text-muted)' }}>Записів немає</td></tr>}
                      {!rentLoading && rent.map((r) => (
                        <tr key={r.id}>
                          <td>{r.rent_type === 'manual' ? 'Тренер платить' : 'З заробітку'}</td>
                          <td><strong>{formatMoney(r.amount)}</strong></td>
                          <td>{formatMoney(r.paid_amount || 0)}</td>
                          <td style={{ fontSize: 13 }}>{formatDate(r.period_start)} — {formatDate(r.period_end)}</td>
                          <td><Badge variant={RENT_STATUS[r.status]?.[0] || 'pending'}>{RENT_STATUS[r.status]?.[1] || r.status}</Badge></td>
                          <td style={{ color: 'var(--text-secondary)', fontSize: 13 }}>{r.notes || '—'}</td>
                          <td style={{ whiteSpace: 'nowrap' }}>
                            {r.rent_type === 'manual' && r.status !== 'paid' && <button className="btn btn-ghost btn-sm" style={{ color: 'var(--success)' }} onClick={() => openRentPay(r)}>Оплачено</button>}
                            {parseFloat(r.paid_amount || 0) === 0 && <button className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }} onClick={() => handleDeleteRent(r.id)}>🗑</button>}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                <div className="mobile-record-list">
                  {rentLoading && <div className="loader" style={{ padding: '20px 0' }}><span className="spinner" /></div>}
                  {!rentLoading && rent.length === 0 && <div className="empty-state"><p>Записів немає</p></div>}
                  {!rentLoading && rent.map((r) => (
                    <div className="mobile-record-card" key={r.id} style={{ cursor: 'default' }}>
                      <div className="mrc-top">
                        <span className="mrc-title">{r.rent_type === 'manual' ? 'Тренер платить' : 'З заробітку'}</span>
                        {r.rent_type === 'manual' && r.status !== 'paid'
                          ? <button type="button" className="mrc-menu-btn" aria-label="Оплачено" style={{ color: 'var(--success)' }} onClick={() => openRentPay(r)}>💵</button>
                          : parseFloat(r.paid_amount || 0) === 0 && <button type="button" className="mrc-menu-btn" aria-label="Видалити" style={{ color: 'var(--danger)' }} onClick={() => handleDeleteRent(r.id)}>🗑</button>}
                      </div>
                      <div className="mrc-sub">
                        <span className="mrc-meta">
                          <Badge variant={RENT_STATUS[r.status]?.[0] || 'pending'}>{RENT_STATUS[r.status]?.[1] || r.status}</Badge>
                          <span className="mrc-dot">•</span>
                          {formatDate(r.period_start)} — {formatDate(r.period_end)}
                        </span>
                        <span className="mrc-amount">{formatMoney(r.amount)}</span>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </>
          )}

          {detailTab === 'rent' && payouts && payouts.rent_payments.length > 0 && (
            <div className="card" style={{ marginTop: 12 }}>
              <div style={{ fontWeight: 600, marginBottom: 8 }}>Оплати оренди</div>
              {payouts.rent_payments.map((p) => (
                <div key={p.id} style={{ display: 'flex', gap: 10, alignItems: 'center', padding: '6px 0', borderBottom: '1px solid var(--border)', fontSize: 13, flexWrap: 'wrap' }}>
                  <span style={{ minWidth: 90 }}>{formatDate(p.created_at)}</span>
                  <strong>{formatMoney(p.amount)}</strong>
                  <span style={{ color: 'var(--text-secondary)' }}>{p.source === 'deduction' ? 'утримано з виплати' : RENT_METHOD_LABEL[p.payment_method] || p.payment_method}</span>
                  <span style={{ color: 'var(--text-muted)', flex: 1 }}>{p.admin_name || ''}</span>
                  {isOwner && p.source === 'manual' && (
                    <button className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }} onClick={() => setStorno({ kind: 'rent', id: p.id, label: `оплату оренди ${formatMoney(p.amount)} від ${formatDate(p.created_at)}`, reason: '', error: '' })}>Сторно</button>
                  )}
                </div>
              ))}
            </div>
          )}

          {detailTab === 'payouts' && (
            <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
              {!payouts && <div className="loader"><span className="spinner" /></div>}
              {payouts && payouts.payouts.length === 0 && <div className="empty-state"><p>Виплат ще не було</p></div>}
              {payouts && payouts.payouts.map((p) => {
                const ded = parseFloat(p.rent_deducted || 0);
                return (
                  <div key={p.id} style={{ display: 'flex', gap: 12, alignItems: 'center', padding: '10px 14px', borderBottom: '1px solid var(--border)', fontSize: 13, flexWrap: 'wrap' }}>
                    <span style={{ minWidth: 90 }}>{formatDate(p.expense_date)}</span>
                    <div style={{ flex: 1, minWidth: 160 }}>
                      <strong>{formatMoney(p.amount)}</strong> · {p.payment_method === 'cash' ? 'готівка' : 'картка'}
                      {ded > 0 && <span style={{ color: 'var(--warning)' }}> · утримано оренду {formatMoney(ded)} → видано {formatMoney(parseFloat(p.amount) - ded)}</span>}
                      <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{p.client_name || '—'} · {p.admin_name || ''}</div>
                    </div>
                    {isOwner && (
                      <button className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }} onClick={() => setStorno({ kind: 'payout', id: p.id, label: `виплату ${formatMoney(p.amount)} від ${formatDate(p.expense_date)}`, reason: '', error: '' })}>Сторно</button>
                    )}
                  </div>
                );
              })}
            </div>
          )}

          {detailTab === 'profile' && profileForm && (
            <div className="card" style={{ maxWidth: 560 }}>
              <div style={{ fontWeight: 600, marginBottom: 14 }}>Ставки нарахувань</div>
              <FormGroup label="Спеціалізація">
                <input type="text" value={profileForm.specialization} onChange={(e) => setProfileForm({ ...profileForm, specialization: e.target.value })} />
              </FormGroup>
              <FormGroup label="Тип роботи">
                <select value={profileForm.workType} onChange={(e) => setProfileForm({ ...profileForm, workType: e.target.value })}>
                  <option value="employee">Найманий</option>
                  <option value="rent">Орендар</option>
                  <option value="both">Обидва</option>
                </select>
              </FormGroup>

              <div style={{ background: 'var(--bg-elevated)', borderRadius: 'var(--radius-sm)', padding: 12, marginBottom: 12 }}>
                <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-secondary)', marginBottom: 8 }}>Персональні</div>
                <div className="settings-fields-2">
                  <FormGroup label="Тип">
                    <select value={profileForm.persType} onChange={(e) => setProfileForm({ ...profileForm, persType: e.target.value })}>
                      <option value="percent">%</option>
                      <option value="fixed">Фіксована</option>
                    </select>
                  </FormGroup>
                  <FormGroup label="Значення">
                    <input type="number" min="0" step="0.01" value={profileForm.persValue} onChange={(e) => setProfileForm({ ...profileForm, persValue: e.target.value })} />
                  </FormGroup>
                  <FormGroup label={<span title="0 = вимкнено">Поріг клієнтів (тир)</span>}>
                    <input type="number" min="0" step="1" value={profileForm.persTierThreshold} onChange={(e) => setProfileForm({ ...profileForm, persTierThreshold: e.target.value })} />
                  </FormGroup>
                  <FormGroup label="Підвищений % / сума">
                    <input type="number" min="0" step="0.01" value={profileForm.persTierValue} onChange={(e) => setProfileForm({ ...profileForm, persTierValue: e.target.value })} />
                  </FormGroup>
                </div>
              </div>

              <div style={{ background: 'var(--bg-elevated)', borderRadius: 'var(--radius-sm)', padding: 12, marginBottom: 16 }}>
                <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-secondary)', marginBottom: 8 }}>Групові</div>
                <div className="settings-fields-2">
                  <FormGroup label="Ставка за заняття">
                    <input type="number" min="0" step="0.01" value={profileForm.groupRate} onChange={(e) => setProfileForm({ ...profileForm, groupRate: e.target.value })} />
                  </FormGroup>
                  <FormGroup label="Бонус за учасника">
                    <input type="number" min="0" step="0.01" value={profileForm.groupBonus} onChange={(e) => setProfileForm({ ...profileForm, groupBonus: e.target.value })} />
                  </FormGroup>
                  <FormGroup label={<span title="0 = з першого">Поріг учасників</span>}>
                    <input type="number" min="0" step="1" value={profileForm.groupThreshold} onChange={(e) => setProfileForm({ ...profileForm, groupThreshold: e.target.value })} />
                  </FormGroup>
                  <div />
                  <FormGroup label={<span title="0 = вимкнено">Занять/місяць для бонусу</span>}>
                    <input type="number" min="0" step="1" value={profileForm.groupMonthlySessions} onChange={(e) => setProfileForm({ ...profileForm, groupMonthlySessions: e.target.value })} />
                  </FormGroup>
                  <FormGroup label="Місячний бонус (грн)">
                    <input type="number" min="0" step="0.01" value={profileForm.groupMonthlyAmount} onChange={(e) => setProfileForm({ ...profileForm, groupMonthlyAmount: e.target.value })} />
                  </FormGroup>
                </div>
              </div>

              <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                <button className="btn btn-primary" onClick={saveProfileInline}>Зберегти</button>
                {profileSaved && <span style={{ color: 'var(--success)', fontSize: 13 }}>✓ Збережено</span>}
              </div>
            </div>
          )}
        </>
      )}

      {/* Профіль тренера (create/edit) */}
      <Modal
        size="xl"
        open={!!trainerModal}
        onClose={() => setTrainerModal(null)}
        title={trainerModal?.editId ? 'Редагувати тренера' : 'Додати тренера'}
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" onClick={submitTrainerModal}>Зберегти</button>
            <button className="btn btn-ghost" onClick={() => setTrainerModal(null)}>Скасувати</button>
          </div>
        }
      >
        {trainerModal && (
          <div className="tr-modal-body">
            {trainerModal.error && <div className="alert alert-error">{trainerModal.error}</div>}
            <>
                {!trainerModal.editId && (
                  <div className="form-group tr-modal-col-2" style={{ marginBottom: 14 }}>
                    <label>Тип тренера *</label>
                    <div style={{ display: 'flex', gap: 16 }}>
                      <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontWeight: 400 }}>
                        <input type="radio" name="tr-mode" checked={trainerModal.mode === 'new'} onChange={() => setTrainerModal({ ...trainerModal, mode: 'new' })} />
                        Без входу в систему
                      </label>
                      <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontWeight: 400 }}>
                        <input type="radio" name="tr-mode" checked={trainerModal.mode === 'existing'} onChange={() => setTrainerModal({ ...trainerModal, mode: 'existing' })} />
                        Існуючий співробітник
                      </label>
                    </div>
                  </div>
                )}
                <div className="tr-modal-grid">
                  {trainerModal.editId ? (
                    <div className="form-group tr-modal-col-2">
                      <label>Тренер *</label>
                      <div style={{ fontWeight: 500, padding: '8px 0' }}>{trainerModal.userName}</div>
                    </div>
                  ) : trainerModal.mode === 'new' ? (
                    <>
                      <div className="form-group">
                        <label>Ім'я тренера *</label>
                        <input type="text" placeholder="Прізвище Ім'я" value={trainerModal.ghostName} onChange={(e) => setTrainerModal({ ...trainerModal, ghostName: e.target.value })} />
                      </div>
                      <div className="form-group">
                        <label>Телефон</label>
                        <input type="text" placeholder="+380..." value={trainerModal.ghostPhone} onChange={(e) => setTrainerModal({ ...trainerModal, ghostPhone: e.target.value })} />
                      </div>
                    </>
                  ) : (
                    <div className="form-group tr-modal-col-2">
                      <label>Тренер *</label>
                      {trainerModal.availableUsers.length === 0 ? (
                        <div style={{ color: 'var(--text-muted)', padding: '8px 0' }}>Немає співробітників без профілю тренера — запросіть у розділі "Команда", або оберіть "Без входу в систему".</div>
                      ) : (
                        <select value={trainerModal.userId} onChange={(e) => setTrainerModal({ ...trainerModal, userId: e.target.value })}>
                          <option value="">— Оберіть —</option>
                          {trainerModal.availableUsers.map((u) => <option key={u.id} value={u.id}>{u.full_name} ({u.email})</option>)}
                        </select>
                      )}
                    </div>
                  )}
                  <div className="form-group tr-modal-col-1">
                    <label>Спеціалізація</label>
                    <input type="text" placeholder="Йога, Силові, Кардіо..." value={trainerModal.specialization} onChange={(e) => setTrainerModal({ ...trainerModal, specialization: e.target.value })} />
                  </div>
                  <div className="form-group tr-modal-col-1">
                    <label>Тип роботи</label>
                    <select value={trainerModal.workType} onChange={(e) => setTrainerModal({ ...trainerModal, workType: e.target.value })}>
                      <option value="employee">Найманий</option>
                      <option value="rent">Орендар</option>
                      <option value="both">Найм + Оренда</option>
                    </select>
                  </div>
                </div>

                <div className="tr-modal-group-label">Персональні тренування</div>
                <div className="tr-modal-grid" style={{ marginBottom: 14 }}>
                  <div className="form-group">
                    <label>Тип нарахування</label>
                    <select value={trainerModal.persType} onChange={(e) => setTrainerModal({ ...trainerModal, persType: e.target.value })}>
                      <option value="percent">% від абонементу</option>
                      <option value="fixed">Фіксована сума (грн)</option>
                    </select>
                  </div>
                  <div className="form-group">
                    <label>{isPersPercent ? 'Відсоток (%)' : 'Сума (грн)'}</label>
                    <input type="number" min="0" step="0.01" value={trainerModal.persValue} onChange={(e) => setTrainerModal({ ...trainerModal, persValue: e.target.value })} />
                  </div>
                  <div className="form-group">
                    <label title="Якщо тренер має більше N активних клієнтів — підвищений % або сума">Поріг клієнтів для підвищення</label>
                    <input type="number" min="0" step="1" placeholder="0 = вимкнено" value={trainerModal.persTierThreshold} onChange={(e) => setTrainerModal({ ...trainerModal, persTierThreshold: e.target.value })} />
                  </div>
                  <div className="form-group">
                    <label>{isPersPercent ? 'Підвищений %' : 'Підвищена сума (грн)'}</label>
                    <input type="number" min="0" step="0.01" placeholder="0 = вимкнено" value={trainerModal.persTierValue} onChange={(e) => setTrainerModal({ ...trainerModal, persTierValue: e.target.value })} />
                  </div>
                </div>

                <div className="tr-modal-group-label">Групові тренування</div>
                <div className="tr-modal-grid" style={{ marginBottom: 20 }}>
                  <div className="form-group">
                    <label>Ставка за заняття (грн)</label>
                    <input type="number" min="0" step="0.01" value={trainerModal.groupRate} onChange={(e) => setTrainerModal({ ...trainerModal, groupRate: e.target.value })} />
                  </div>
                  <div className="form-group">
                    <label>Бонус за учасника (грн)</label>
                    <input type="number" min="0" step="0.01" value={trainerModal.groupBonus} onChange={(e) => setTrainerModal({ ...trainerModal, groupBonus: e.target.value })} />
                  </div>
                  <div className="form-group">
                    <label title="Бонус за учасника нараховується лише якщо учасників більше N">Поріг учасників для бонусу</label>
                    <input type="number" min="0" step="1" placeholder="0 = з першого" value={trainerModal.groupThreshold} onChange={(e) => setTrainerModal({ ...trainerModal, groupThreshold: e.target.value })} />
                  </div>
                  <div className="form-group" />
                </div>
                <div className="tr-modal-group-label">Місячний бонус (групові)</div>
                <div className="tr-modal-grid" style={{ marginBottom: 20 }}>
                  <div className="form-group">
                    <label title="Якщо тренер проводить ≥ N занять на місяць — отримує бонус">Занять на місяць для бонусу</label>
                    <input type="number" min="0" step="1" placeholder="0 = вимкнено" value={trainerModal.groupMonthlySessions} onChange={(e) => setTrainerModal({ ...trainerModal, groupMonthlySessions: e.target.value })} />
                  </div>
                  <div className="form-group">
                    <label>Сума місячного бонусу (грн)</label>
                    <input type="number" min="0" step="0.01" value={trainerModal.groupMonthlyAmount} onChange={(e) => setTrainerModal({ ...trainerModal, groupMonthlyAmount: e.target.value })} />
                  </div>
                </div>
              </>
          </div>
        )}
      </Modal>

      {/* Виплата */}
      <Modal
        open={!!payModal}
        onClose={() => setPayModal(null)}
        title="Виплата тренеру"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" onClick={confirmPay}>Виплатити</button>
            <button className="btn btn-ghost" onClick={() => setPayModal(null)}>Скасувати</button>
          </div>
        }
      >
        {payModal && (
          <>
            {payModal.error && <div className="alert alert-error">{payModal.error}</div>}
            <div style={{ background: 'var(--bg-elevated)', padding: '10px 14px', borderRadius: 'var(--radius-sm)', fontSize: 13, marginBottom: 14 }}>
              <div>Клієнт: <strong>{payModal.clientName}</strong></div>
              <div>Доступно до виплати: <strong style={{ color: 'var(--success)' }}>{formatMoney(payModal.max)}</strong></div>
            </div>
            <FormGroup label="Сума виплати (грн) *">
              <input type="number" min="0.01" step="0.01" max={payModal.max} value={payModal.amount} onChange={(e) => setPayModal({ ...payModal, amount: e.target.value })} />
            </FormGroup>
            <FormGroup label="Спосіб виплати">
              <select value={payModal.paymentMethod} onChange={(e) => setPayModal({ ...payModal, paymentMethod: e.target.value })}>
                <option value="cash">Готівка (з каси)</option>
                <option value="card">Картка</option>
              </select>
            </FormGroup>
            {payModal.rentDeduction > 0 && (() => {
              const gross = parseFloat(payModal.amount) || 0;
              const ded = Math.min(gross, payModal.rentDeduction);
              return (
                <div className="alert" style={{ fontSize: 13 }}>
                  🏠 Утримається оренда: <strong>{formatMoney(ded)}</strong><br />
                  Видати тренеру: <strong>{formatMoney(gross - ded)}</strong>
                </div>
              );
            })()}
          </>
        )}
      </Modal>

      {/* Оренда */}
      <Modal
        open={!!rentModal}
        onClose={() => setRentModal(null)}
        title="Оренда залу"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" onClick={submitRent}>Зберегти</button>
            <button className="btn btn-ghost" onClick={() => setRentModal(null)}>Скасувати</button>
          </div>
        }
      >
        {rentModal && (
          <>
            {rentModal.error && <div className="alert alert-error">{rentModal.error}</div>}
            <FormGroup label="Тип">
              <select value={rentModal.type} onChange={(e) => setRentModal({ ...rentModal, type: e.target.value })}>
                <option value="manual">Тренер платить клубу</option>
                <option value="deduction">Утримується з заробітку</option>
              </select>
            </FormGroup>
            <FormGroup label="Сума (грн) *">
              <input type="number" min="0.01" step="0.01" value={rentModal.amount} onChange={(e) => setRentModal({ ...rentModal, amount: e.target.value })} />
            </FormGroup>
            <div className="settings-fields-2">
              <FormGroup label="Від *"><input type="date" value={rentModal.start} onChange={(e) => setRentModal({ ...rentModal, start: e.target.value })} /></FormGroup>
              <FormGroup label="До *"><input type="date" value={rentModal.end} onChange={(e) => setRentModal({ ...rentModal, end: e.target.value })} /></FormGroup>
            </div>
            <FormGroup label="Нотатки">
              <input type="text" placeholder="Необов'язково" value={rentModal.notes} onChange={(e) => setRentModal({ ...rentModal, notes: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>

      {/* Оплата оренди тренером */}
      <Modal open={!!rentPay} onClose={() => setRentPay(null)} title="Оренду оплачено" size="sm" footer={
        <div style={{ display: 'flex', gap: 10 }}>
          <button className="btn btn-primary" disabled={rentPay?.submitting} onClick={submitRentPay}>Зарахувати</button>
          <button className="btn btn-ghost" onClick={() => setRentPay(null)}>Скасувати</button>
        </div>
      }>
        {rentPay && (
          <>
            {rentPay.error && <div className="alert alert-error">{rentPay.error}</div>}
            <div style={{ fontSize: 13, marginBottom: 12 }}>Залишок оренди: <strong>{formatMoney(rentPay.left)}</strong></div>
            <FormGroup label="Сума (грн)">
              <input type="number" min="0.01" step="0.01" value={rentPay.amount} onChange={(e) => setRentPay({ ...rentPay, amount: e.target.value })} />
            </FormGroup>
            <FormGroup label="Спосіб оплати">
              <select value={rentPay.method} onChange={(e) => setRentPay({ ...rentPay, method: e.target.value })}>
                <option value="cash">Готівка (прихід у касу)</option>
                <option value="card">Картка</option>
                <option value="transfer">На рахунок</option>
              </select>
            </FormGroup>
          </>
        )}
      </Modal>

      {/* Сторно */}
      <Modal open={!!storno} onClose={() => setStorno(null)} title="Сторно" size="sm" footer={
        <div style={{ display: 'flex', gap: 10 }}>
          <button className="btn btn-danger" disabled={storno?.submitting} onClick={submitStorno}>Провести сторно</button>
          <button className="btn btn-ghost" onClick={() => setStorno(null)}>Відмінити</button>
        </div>
      }>
        {storno && (
          <>
            {storno.error && <div className="alert alert-error">{storno.error}</div>}
            <p style={{ fontSize: 13, marginTop: 0 }}>
              Скасувати {storno.label}? Гроші повернуться в касу / облік, сума знову стане «до виплати»
              {storno.kind === 'payout' ? ', утримана з цієї виплати оренда знову стане боргом' : ''}.
            </p>
            <FormGroup label="Причина *">
              <textarea rows="2" placeholder="напр. помилково обрано не того тренера" value={storno.reason} onChange={(e) => setStorno({ ...storno, reason: e.target.value, error: '' })} />
            </FormGroup>
          </>
        )}
      </Modal>
    </AppLayout>
  );
}
