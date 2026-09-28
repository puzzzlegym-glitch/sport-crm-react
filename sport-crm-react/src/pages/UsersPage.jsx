import { useEffect, useState } from 'react';
import AppLayout from '../components/layout/AppLayout';
import Table from '../components/ui/Table';
import CardMenu from '../components/ui/CardMenu';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import { useAuth } from '../context/AuthContext';
import { usePermissions } from '../hooks/usePermissions';
import { useEntitlements } from '../hooks/useEntitlements';
import { useToast } from '../components/ui/ToastProvider';
import { getUsers, getRoles, inviteUser, updateUserRole, toggleUserAccess, removeUser } from '../api/users';
import {
  getPayrollTeam, getPayroll, getPayrollSummary, getSalarySettings,
  saveSalarySettings, calcPayrollPreview, createPayroll, payPayroll, getWorkedShifts,
} from '../api/payroll';
import { formatDate, getInitials } from '../utils/format';
import './UsersPage.css';

const ROLE_INFO = {
  owner: ['#c4b5fd', 'Власник', 'Повний доступ'],
  manager: ['var(--success)', 'Менеджер', 'Клієнти · абонементи · каса'],
  trainer: ['var(--warning)', 'Тренер', 'Свої клієнти'],
};
const ROLE_DESCS = {
  owner: 'Повний доступ до клубу. Може запрошувати інших.',
  manager: 'Реєструє клієнтів, продає абонементи, працює з касою.',
  trainer: 'Бачить своїх клієнтів і розклад. Обмежений доступ.',
};
const PR_STATUS_MAP = { pending: ['pending', 'Очікує'], partial: ['info', 'Частково'], paid: ['active', 'Виплачено'] };

const fmt = (v) => Number(v || 0).toLocaleString('uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₴';

const EMPTY_SALARY_CFG = {
  pay_month_on: false, pay_month_amount: 0, pay_day_on: false, pay_day_amount: 0,
  pct_tovar_on: false, pct_tovar_value: 0, pct_tovar_min: 0,
  pct_abon_on: false, pct_abon_value: 0, pct_abon_min: 0,
  plan_on: false, plan_amount: 0, plan_bonus_pct: 0,
};

const currentMonth = () => new Date().toISOString().slice(0, 7);

export default function UsersPage() {
  const { user } = useAuth();
  const { has } = usePermissions();
  const { entitlements, canAddMember } = useEntitlements();
  const isOwner = has('users.manage');
  const toast = useToast();

  const [tab, setTab] = useState('team');

  // Команда
  const [members, setMembers] = useState([]);
  const [teamLoading, setTeamLoading] = useState(true);
  const [roles, setRoles] = useState([]);
  const [showHidden, setShowHidden] = useState(false);

  const [invite, setInvite] = useState(null);
  const [roleModal, setRoleModal] = useState(null);
  const [removeModal, setRemoveModal] = useState(null);
  const [salaryModal, setSalaryModal] = useState(null);

  // Зарплата
  const [payrollTeam, setPayrollTeam] = useState(null);
  const [prUserFilter, setPrUserFilter] = useState('');
  const [prMonth, setPrMonth] = useState(currentMonth());
  const [payrollRows, setPayrollRows] = useState([]);
  const [payrollLoading, setPayrollLoading] = useState(true);
  const [summary, setSummary] = useState(null);
  const [createModal, setCreateModal] = useState(null);
  const [payModal, setPayModal] = useState(null);

  async function reloadTeam() {
    setTeamLoading(true);
    const res = await getUsers(showHidden);
    setTeamLoading(false);
    if (!res.success) {
      toast(res.error, 'error');
      return;
    }
    setMembers(res.users || []);
  }

  useEffect(() => { reloadTeam(); }, [showHidden]);
  useEffect(() => {
    getRoles().then((res) => { if (res.success) setRoles(res.roles); });
  }, []);

  async function ensurePayrollTeam() {
    if (payrollTeam) return;
    const res = await getPayrollTeam();
    if (res.success) setPayrollTeam(res.team);
  }

  async function reloadPayroll() {
    setPayrollLoading(true);
    const res = await getPayroll({ user_id: prUserFilter || 0, month: prMonth });
    setPayrollLoading(false);
    if (!res.success) return;
    setPayrollRows(res.payroll);
  }

  async function reloadSummary() {
    const res = await getPayrollSummary({ month: prMonth, user_id: prUserFilter || 0 });
    if (res.success) setSummary(res.summary);
  }

  useEffect(() => {
    if (tab !== 'payroll') return;
    ensurePayrollTeam();
    reloadPayroll();
    reloadSummary();
  }, [tab, prUserFilter, prMonth]);

  // ── Запрошення ────────────────────────────────────────────
  function openInvite() {
    setInvite({ name: '', email: '', phone: '', roleId: '', error: '', submitting: false, success: false, tmpPwd: null });
  }

  async function submitInvite() {
    const name = invite.name.trim();
    const email = invite.email.trim();
    const role = roles.find((r) => String(r.id) === String(invite.roleId));
    if (!name) { setInvite({ ...invite, error: "Введіть ім'я" }); return; }
    if (!email) { setInvite({ ...invite, error: 'Введіть email' }); return; }
    if (!role) { setInvite({ ...invite, error: 'Оберіть роль' }); return; }
    if (!canAddMember) {
      const rec = entitlements?.recommendedPlan?.name;
      setInvite({
        ...invite,
        error: `Досягнуто ліміт команди вашого тарифу${entitlements?.plan?.name ? ` («${entitlements.plan.name}»)` : ''}.${rec ? ` Перейдіть на «${rec}».` : ''}`,
      });
      return;
    }

    setInvite({ ...invite, submitting: true, error: '' });
    const res = await inviteUser({ full_name: name, email, phone: invite.phone.trim(), role_slug: role.slug });
    if (!res.success) {
      setInvite({ ...invite, submitting: false, error: res.error });
      return;
    }
    setInvite({ ...invite, submitting: false, success: true, tmpPwd: res.tmp_pwd || null });
  }

  // ── Роль ─────────────────────────────────────────────────
  function openRoleModal(m) {
    setRoleModal({ userId: m.id, name: m.full_name, roleId: m.role_id, error: '' });
  }

  async function submitRole() {
    const role = roles.find((r) => String(r.id) === String(roleModal.roleId));
    if (!role) return;
    const res = await updateUserRole({ user_id: roleModal.userId, role_slug: role.slug });
    if (!res.success) {
      setRoleModal({ ...roleModal, error: res.error });
      return;
    }
    toast('Роль змінено', 'success');
    setRoleModal(null);
    reloadTeam();
  }

  // ── Доступ ────────────────────────────────────────────────
  async function handleToggleAccess(m) {
    const res = await toggleUserAccess(m.id, m.club_access == 0);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message || 'Готово', 'success');
    reloadTeam();
  }

  // ── Видалення ─────────────────────────────────────────────
  async function confirmRemove() {
    const res = await removeUser(removeModal.userId);
    setRemoveModal(null);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast('Прибрано з команди', 'success');
    reloadTeam();
  }

  // ── Налаштування зарплати ─────────────────────────────────
  async function openSalarySettings(userId, name) {
    setSalaryModal({ userId, name, cfg: { ...EMPTY_SALARY_CFG }, notes: '', loading: true, error: '', submitting: false });
    const res = await getSalarySettings(userId);
    const s = (res.success && res.settings) || {};
    setSalaryModal({
      userId, name, loading: false, error: '', submitting: false,
      cfg: {
        pay_month_on: !!+s.pay_month_on, pay_month_amount: s.pay_month_amount ?? 0,
        pay_day_on: !!+s.pay_day_on, pay_day_amount: s.pay_day_amount ?? 0,
        pct_tovar_on: !!+s.pct_tovar_on, pct_tovar_value: s.pct_tovar_value ?? 0, pct_tovar_min: s.pct_tovar_min ?? 0,
        pct_abon_on: !!+s.pct_abon_on, pct_abon_value: s.pct_abon_value ?? 0, pct_abon_min: s.pct_abon_min ?? 0,
        plan_on: !!+s.plan_on, plan_amount: s.plan_amount ?? 0, plan_bonus_pct: s.plan_bonus_pct ?? 0,
      },
      notes: s.notes || '',
    });
  }

  function setCfg(key, value) {
    setSalaryModal((m) => ({ ...m, cfg: { ...m.cfg, [key]: value } }));
  }

  async function submitSalarySettings() {
    setSalaryModal({ ...salaryModal, submitting: true, error: '' });
    const res = await saveSalarySettings({
      user_id: salaryModal.userId,
      pay_month_on: salaryModal.cfg.pay_month_on ? 1 : 0, pay_month_amount: parseFloat(salaryModal.cfg.pay_month_amount) || 0,
      pay_day_on: salaryModal.cfg.pay_day_on ? 1 : 0, pay_day_amount: parseFloat(salaryModal.cfg.pay_day_amount) || 0,
      pct_tovar_on: salaryModal.cfg.pct_tovar_on ? 1 : 0, pct_tovar_value: parseFloat(salaryModal.cfg.pct_tovar_value) || 0,
      pct_tovar_min: parseFloat(salaryModal.cfg.pct_tovar_min) || 0,
      pct_abon_on: salaryModal.cfg.pct_abon_on ? 1 : 0, pct_abon_value: parseFloat(salaryModal.cfg.pct_abon_value) || 0,
      pct_abon_min: parseFloat(salaryModal.cfg.pct_abon_min) || 0,
      plan_on: salaryModal.cfg.plan_on ? 1 : 0, plan_amount: parseFloat(salaryModal.cfg.plan_amount) || 0,
      plan_bonus_pct: parseFloat(salaryModal.cfg.plan_bonus_pct) || 0,
      notes: salaryModal.notes.trim(),
    });
    if (!res.success) {
      setSalaryModal({ ...salaryModal, submitting: false, error: res.error || 'Помилка збереження' });
      return;
    }
    toast('Налаштування збережено', 'success');
    setSalaryModal(null);
  }

  // ── Нарахувати зарплату ───────────────────────────────────
  async function openCreatePayroll() {
    await ensurePayrollTeam();
    setCreateModal({ userId: '', month: currentMonth(), days: '', hasOpenShift: false, notes: '', preview: null, previewLoading: false, error: '', submitting: false });
  }

  // Автопідрахунок відпрацьованих змін з каси при виборі співробітника/місяця
  useEffect(() => {
    if (!createModal || !createModal.userId || !createModal.month) return;
    (async () => {
      const res = await getWorkedShifts({ user_id: createModal.userId, month: createModal.month });
      if (res.success) {
        setCreateModal((m) => (m ? { ...m, days: String(res.shifts_worked), hasOpenShift: !!res.has_open_shift } : m));
      }
    })();
  }, [createModal?.userId, createModal?.month]);

  useEffect(() => {
    if (!createModal || !createModal.userId || !createModal.month) return;
    const t = setTimeout(async () => {
      setCreateModal((m) => (m ? { ...m, previewLoading: true } : m));
      const res = await calcPayrollPreview({
        user_id: createModal.userId, month: createModal.month,
        days_worked: parseFloat(createModal.days) || 0,
      });
      setCreateModal((m) => {
        if (!m) return m;
        if (!res.success) return { ...m, previewLoading: false, error: res.error, preview: null };
        return { ...m, previewLoading: false, error: '', preview: res.preview };
      });
    }, 400);
    return () => clearTimeout(t);
  }, [createModal?.userId, createModal?.month, createModal?.days]);

  async function submitCreatePayroll() {
    if (!createModal.userId) { setCreateModal({ ...createModal, error: 'Оберіть співробітника' }); return; }
    if (!createModal.month) { setCreateModal({ ...createModal, error: 'Оберіть місяць' }); return; }
    setCreateModal({ ...createModal, submitting: true, error: '' });
    const res = await createPayroll({
      user_id: createModal.userId, month: createModal.month, notes: createModal.notes.trim(),
      days_worked: parseFloat(createModal.days) || 0,
    });
    if (!res.success) {
      setCreateModal({ ...createModal, submitting: false, error: res.error || 'Помилка' });
      return;
    }
    setCreateModal(null);
    reloadPayroll();
    reloadSummary();
  }

  // ── Виплата ───────────────────────────────────────────────
  function openPayModal(row) {
    const maxPay = Math.round((row.total_amount - row.paid_amount) * 100) / 100;
    setPayModal({ payrollId: row.id, total: row.total_amount, paid: row.paid_amount, maxPay, amount: maxPay, error: '', submitting: false });
  }

  function setPayPercent(pct) {
    setPayModal((m) => ({ ...m, amount: (Math.round(m.maxPay * pct) / 100).toFixed(2) }));
  }

  async function submitPay() {
    const amount = parseFloat(payModal.amount);
    if (!amount || amount <= 0) { setPayModal({ ...payModal, error: 'Введіть суму' }); return; }
    if (amount > payModal.maxPay) { setPayModal({ ...payModal, error: `Максимум: ${fmt(payModal.maxPay)}` }); return; }
    setPayModal({ ...payModal, submitting: true, error: '' });
    const res = await payPayroll({ payroll_id: payModal.payrollId, amount });
    if (!res.success) {
      setPayModal({ ...payModal, submitting: false, error: res.error || 'Помилка' });
      return;
    }
    setPayModal(null);
    reloadPayroll();
    reloadSummary();
  }

  // ── Колонки ───────────────────────────────────────────────
  const teamColumns = [
    {
      key: 'member', label: 'Співробітник',
      render: (m) => (
        <div className="user-info">
          <div className={`user-avatar ${m.role_slug}`}>{getInitials(m.full_name)}</div>
          <div>
            <div className="user-name">{m.full_name}{m.id === user?.id && <span style={{ fontSize: 11, color: 'var(--text-muted)', marginLeft: 6 }}>(ви)</span>}</div>
            <div className="user-email">{m.email}</div>
          </div>
        </div>
      ),
    },
    { key: 'role', label: 'Роль', mobile: 'secondary', render: (m) => <span style={{ fontWeight: 600, color: (ROLE_INFO[m.role_slug] || [])[0] || 'var(--accent)' }}>{(ROLE_INFO[m.role_slug] || [])[1] || m.role_name}</span> },
    { key: 'perms', label: 'Права', render: (m) => <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>{(ROLE_INFO[m.role_slug] || [])[2] || ''}</span> },
    { key: 'status', label: 'Статус', cardTop: true, render: (m) => <Badge variant={m.club_access != 0 ? 'active' : 'inactive'}>{m.club_access != 0 ? 'Активний' : 'Призупинено'}</Badge> },
    { key: 'last_login', label: 'Останній вхід', render: (m) => <span style={{ fontSize: 13, color: 'var(--text-secondary)' }}>{m.last_login_at ? formatDate(m.last_login_at) : 'Ніколи'}</span> },
    {
      key: 'actions', label: '',
      render: (m) => isOwner && m.id !== user?.id && (
        <div onClick={(e) => e.stopPropagation()}>
          <div className="desktop-only-actions" style={{ display: 'flex', gap: 4, whiteSpace: 'nowrap' }}>
            <button className="btn btn-ghost btn-sm" title="Змінити роль" onClick={() => openRoleModal(m)}>✎</button>
            {m.role_slug !== 'trainer' && <button className="btn btn-ghost btn-sm" title="Налаштування зарплати" onClick={() => openSalarySettings(m.id, m.full_name)}>💰</button>}
            <button className="btn btn-ghost btn-sm" style={{ color: m.club_access != 0 ? undefined : 'var(--success)' }} title={m.club_access != 0 ? 'Призупинити' : 'Відновити'} onClick={() => handleToggleAccess(m)}>{m.club_access != 0 ? '🚫' : '✅'}</button>
            <button className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }} title="Прибрати з команди" onClick={() => setRemoveModal({ userId: m.id, name: m.full_name })}>🗑</button>
          </div>
          <div className="mobile-only-actions">
            <CardMenu actions={[
              { label: 'Змінити роль', icon: 'edit', onClick: () => openRoleModal(m) },
              m.role_slug !== 'trainer' && { label: 'Налаштування зарплати', icon: 'banknote', onClick: () => openSalarySettings(m.id, m.full_name) },
              { label: m.club_access != 0 ? 'Призупинити' : 'Відновити', icon: m.club_access != 0 ? 'ban' : 'check', onClick: () => handleToggleAccess(m) },
              { label: 'Прибрати з команди', icon: 'trash', danger: true, onClick: () => setRemoveModal({ userId: m.id, name: m.full_name }) },
            ]} />
          </div>
        </div>
      ),
    },
  ];

  const payrollColumns = [
    { key: 'employee', label: 'Співробітник', render: (r) => <>{r.full_name}<br /><span style={{ fontSize: 11, color: 'var(--text-muted)' }}>{r.role_name || ''}</span></> },
    { key: 'status', label: 'Статус', cardTop: true, render: (r) => { const [v, l] = PR_STATUS_MAP[r.status] || ['info', r.status]; return <Badge variant={v}>{l}</Badge>; } },
    { key: 'month', label: 'Місяць', mobile: 'secondary', render: (r) => <strong>{r.period_month}</strong> },
    { key: 'base', label: 'Оклад', render: (r) => fmt(r.pay_month) },
    { key: 'bonus', label: 'Бонус', render: (r) => fmt((parseFloat(r.pay_day) || 0) + (parseFloat(r.pct_tovar) || 0) + (parseFloat(r.pct_abon) || 0) + (parseFloat(r.plan_bonus) || 0)) },
    { key: 'total', label: 'Всього', mobile: 'trailing', render: (r) => <strong>{fmt(r.total_amount)}</strong> },
    { key: 'paid', label: 'Виплачено', render: (r) => fmt(r.paid_amount) },
    {
      key: 'actions', label: '',
      render: (r) => (
        <div style={{ display: 'flex', gap: 4, whiteSpace: 'nowrap' }} onClick={(e) => e.stopPropagation()}>
          {r.status !== 'paid' && <button className="btn btn-sm btn-ghost" onClick={() => openPayModal(r)}><Icon name="checkCircle" size={14} /> <span className="action-label-text">Виплатити</span></button>}
          <button className="btn btn-sm btn-ghost" onClick={() => openSalarySettings(r.user_id, r.full_name)}>⚙️</button>
        </div>
      ),
    },
  ];

  return (
    <AppLayout title="Команда">
      <div className="page-tabs">
        <button className={`page-tab-btn ${tab === 'team' ? 'active' : ''}`} onClick={() => setTab('team')}>👥 Команда</button>
        {isOwner && <button className={`page-tab-btn ${tab === 'payroll' ? 'active' : ''}`} onClick={() => setTab('payroll')}>💰 Зарплата</button>}
      </div>

      {tab === 'team' && (
        <>
          {!canAddMember && (
            <div className="limit-warning">
              <span>⚠</span>
              <span>Ліміт команди {entitlements?.limits?.maxTeam != null ? `(${entitlements.limits.maxTeam}) ` : ''}досягнуто</span>
              <a href="/billing" className="btn btn-ghost btn-sm" style={{ marginLeft: 'auto' }}>Підвищити план →</a>
            </div>
          )}
          <div style={{ display: 'flex', justifyContent: 'flex-end', alignItems: 'center', gap: 14, marginBottom: 12 }}>
            {isOwner && (
              <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, color: 'var(--text-secondary)', cursor: 'pointer' }}>
                <input type="checkbox" checked={showHidden} onChange={(e) => setShowHidden(e.target.checked)} />
                Показати приховані
              </label>
            )}
            {isOwner && (
              <button className="btn btn-primary" disabled={!canAddMember} onClick={openInvite}>
                + Запросити співробітника
              </button>
            )}
          </div>
          <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
            <Table
              columns={teamColumns}
              rows={members}
              loading={teamLoading}
              emptyMessage={isOwner ? 'Команда порожня. Натисніть "+ Запросити співробітника"' : 'Команда порожня. Зверніться до власника клубу'}
              rowProps={(m) => ({ className: m.club_access == 0 ? 'staff-inactive' : '', style: m.club_access == 0 ? { opacity: 0.6 } : undefined })}
            />
          </div>
        </>
      )}

      {tab === 'payroll' && isOwner && (
        <>
          <div className="payroll-filter-bar">
            <select value={prUserFilter} onChange={(e) => setPrUserFilter(e.target.value)}>
              <option value="">Всі співробітники</option>
              {payrollTeam?.map((t) => <option key={t.id} value={t.id}>{t.full_name} ({t.role_name})</option>)}
            </select>
            <input type="month" value={prMonth} onChange={(e) => setPrMonth(e.target.value)} />
            <button className="btn btn-primary btn-sm" onClick={openCreatePayroll}>+ Нарахувати</button>
          </div>

          <div className="payroll-summary-cards">
            <div className="pr-card"><div className="pr-card-val">{summary ? fmt(summary.total_accrued) : '—'}</div><div className="pr-card-label">Нараховано</div></div>
            <div className="pr-card"><div className="pr-card-val success">{summary ? fmt(summary.total_paid) : '—'}</div><div className="pr-card-label">Виплачено</div></div>
            <div className="pr-card"><div className="pr-card-val warning">{summary ? fmt(summary.total_pending) : '—'}</div><div className="pr-card-label">До виплати</div></div>
            <div className="pr-card"><div className="pr-card-val">{summary ? summary.cnt : '—'}</div><div className="pr-card-label">Записів</div></div>
          </div>

          <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
            <Table columns={payrollColumns} rows={payrollRows} loading={payrollLoading} emptyMessage="Нарахувань не знайдено" />
          </div>
        </>
      )}

      {/* Запросити співробітника */}
      <Modal open={!!invite} onClose={() => setInvite(null)} title="Запросити співробітника">
        {invite && !invite.success && (
          <>
            <div style={{ padding: '12px 14px', background: 'var(--bg-elevated)', borderRadius: 'var(--radius-sm)', fontSize: 13, color: 'var(--text-secondary)', marginBottom: 18, lineHeight: 1.6 }}>
              Якщо людина вже має акаунт у системі — вкажіть її email і вона отримає доступ до вашого клубу.
              Якщо ні — буде створено новий акаунт і на пошту надіслано тимчасовий пароль.
            </div>
            {invite.error && <div className="alert alert-error">{invite.error}</div>}
            <FormGroup label="Ім'я та прізвище *">
              <input type="text" maxLength={120} placeholder="Олена Коваль" value={invite.name} onChange={(e) => setInvite({ ...invite, name: e.target.value })} />
            </FormGroup>
            <FormGroup label="Email *">
              <input type="email" placeholder="olena@example.com" value={invite.email} onChange={(e) => setInvite({ ...invite, email: e.target.value })} />
            </FormGroup>
            <FormGroup label="Телефон">
              <input type="tel" placeholder="+38 067 123 45 67" value={invite.phone} onChange={(e) => setInvite({ ...invite, phone: e.target.value })} />
            </FormGroup>
            <FormGroup label="Роль *">
              <select value={invite.roleId} onChange={(e) => setInvite({ ...invite, roleId: e.target.value })}>
                <option value="">— Оберіть роль —</option>
                {roles.map((r) => <option key={r.id} value={r.id}>{r.name_ua}</option>)}
              </select>
              <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4 }}>
                {ROLE_DESCS[roles.find((r) => String(r.id) === String(invite.roleId))?.slug] || ''}
              </div>
            </FormGroup>
            <div style={{ display: 'flex', gap: 10 }}>
              <button className="btn btn-primary" disabled={invite.submitting} onClick={submitInvite}>{invite.submitting ? 'Надсилаємо...' : 'Надіслати запрошення'}</button>
              <button className="btn btn-ghost" onClick={() => setInvite(null)}>Скасувати</button>
            </div>
          </>
        )}
        {invite?.success && (
          <div>
            <div className="alert alert-success">Запрошення надіслано!</div>
            {invite.tmpPwd && (
              <div style={{ marginTop: 12, padding: '12px 14px', background: 'var(--bg-elevated)', borderRadius: 'var(--radius-sm)' }}>
                <div style={{ fontSize: 11, color: 'var(--text-muted)', marginBottom: 4 }}>ТИМЧАСОВИЙ ПАРОЛЬ (якщо лист не дійде)</div>
                <div style={{ fontSize: 20, fontWeight: 700, color: 'var(--success)', letterSpacing: 1 }}>{invite.tmpPwd}</div>
              </div>
            )}
            <button className="btn btn-primary" style={{ marginTop: 16, width: '100%' }} onClick={() => { setInvite(null); reloadTeam(); }}>Готово</button>
          </div>
        )}
      </Modal>

      {/* Змінити роль */}
      <Modal
        size="sm"
        open={!!roleModal}
        onClose={() => setRoleModal(null)}
        title="Змінити роль"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" onClick={submitRole}>Зберегти</button>
            <button className="btn btn-ghost" onClick={() => setRoleModal(null)}>Скасувати</button>
          </div>
        }
      >
        {roleModal && (
          <>
            {roleModal.error && <div className="alert alert-error">{roleModal.error}</div>}
            <div style={{ marginBottom: 14 }}>
              <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 2 }}>Співробітник</div>
              <div style={{ fontSize: 15, fontWeight: 600 }}>{roleModal.name}</div>
            </div>
            <FormGroup label="Нова роль">
              <select value={roleModal.roleId} onChange={(e) => setRoleModal({ ...roleModal, roleId: e.target.value })}>
                {roles.map((r) => <option key={r.id} value={r.id}>{r.name_ua}</option>)}
              </select>
            </FormGroup>
          </>
        )}
      </Modal>

      {/* Прибрати з команди */}
      <Modal
        size="sm"
        open={!!removeModal}
        onClose={() => setRemoveModal(null)}
        title="Прибрати з команди?"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-danger" onClick={confirmRemove}>Прибрати</button>
            <button className="btn btn-ghost" onClick={() => setRemoveModal(null)}>Скасувати</button>
          </div>
        }
      >
        {removeModal && (
          <>
            <p style={{ fontSize: 14, color: 'var(--text-secondary)', marginBottom: 6 }}><strong>{removeModal.name}</strong> втратить доступ до клубу.</p>
            <p style={{ fontSize: 13, color: 'var(--text-muted)' }}>Акаунт залишиться. Повторне запрошення поверне доступ.</p>
          </>
        )}
      </Modal>

      {/* Налаштування зарплати */}
      <Modal
        open={!!salaryModal}
        onClose={() => setSalaryModal(null)}
        title={<span style={{ display: 'flex', alignItems: 'center', gap: 8 }}><Icon name="wallet" size={18} />Налаштування зарплати</span>}
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={salaryModal?.submitting} onClick={submitSalarySettings}>Зберегти</button>
            <button className="btn btn-ghost" onClick={() => setSalaryModal(null)}>Скасувати</button>
          </div>
        }
      >
        {salaryModal && !salaryModal.loading && (
          <>
            <div style={{ fontSize: 13, color: 'var(--text-secondary)', marginBottom: 16 }}>Співробітник: <strong>{salaryModal.name}</strong></div>
            {salaryModal.error && <div className="alert alert-error">{salaryModal.error}</div>}
            <div className="salary-components">
              <div className="salary-row">
                <label className="salary-check-label"><input type="checkbox" checked={salaryModal.cfg.pay_month_on} onChange={(e) => setCfg('pay_month_on', e.target.checked)} /><span className="salary-label-icon"><Icon name="calendar" size={15} /></span>Оклад / місяць</label>
                <div className="salary-input-wrap"><input type="number" className="salary-amount-input" min="0" step="1" disabled={!salaryModal.cfg.pay_month_on} value={salaryModal.cfg.pay_month_amount} onChange={(e) => setCfg('pay_month_amount', e.target.value)} /><span className="salary-unit">грн</span></div>
              </div>
              <div className="salary-row">
                <label className="salary-check-label"><input type="checkbox" checked={salaryModal.cfg.pay_day_on} onChange={(e) => setCfg('pay_day_on', e.target.checked)} /><span className="salary-label-icon"><Icon name="clock" size={15} /></span>Ставка за зміну</label>
                <div className="salary-input-wrap"><input type="number" className="salary-amount-input" min="0" step="1" disabled={!salaryModal.cfg.pay_day_on} value={salaryModal.cfg.pay_day_amount} onChange={(e) => setCfg('pay_day_amount', e.target.value)} /><span className="salary-unit">грн</span></div>
              </div>
              {salaryModal.cfg.pay_day_on && <div className="salary-hint">Кількість змін рахується автоматично з відкриттів/закриттів каси</div>}
              <div className="salary-row">
                <label className="salary-check-label"><input type="checkbox" checked={salaryModal.cfg.pct_tovar_on} onChange={(e) => setCfg('pct_tovar_on', e.target.checked)} /><span className="salary-label-icon"><Icon name="cart" size={15} /></span>% від продажів товарів</label>
                <div className="salary-input-wrap"><input type="number" className="salary-amount-input" min="0" max="100" step="0.01" disabled={!salaryModal.cfg.pct_tovar_on} value={salaryModal.cfg.pct_tovar_value} onChange={(e) => setCfg('pct_tovar_value', e.target.value)} /><span className="salary-unit">%</span></div>
              </div>
              {salaryModal.cfg.pct_tovar_on && (
                <div className="salary-sub-row">
                  <label>Мінімальний план товарів, з якого рахується %</label>
                  <input type="number" className="salary-amount-input" min="0" step="1" value={salaryModal.cfg.pct_tovar_min} onChange={(e) => setCfg('pct_tovar_min', e.target.value)} style={{ width: 90 }} />
                  <span className="salary-unit">грн</span>
                </div>
              )}
              <div className="salary-row">
                <label className="salary-check-label"><input type="checkbox" checked={salaryModal.cfg.pct_abon_on} onChange={(e) => setCfg('pct_abon_on', e.target.checked)} /><span className="salary-label-icon"><Icon name="dumbbell" size={15} /></span>% від абонементів</label>
                <div className="salary-input-wrap"><input type="number" className="salary-amount-input" min="0" max="100" step="0.01" disabled={!salaryModal.cfg.pct_abon_on} value={salaryModal.cfg.pct_abon_value} onChange={(e) => setCfg('pct_abon_value', e.target.value)} /><span className="salary-unit">%</span></div>
              </div>
              {salaryModal.cfg.pct_abon_on && (
                <div className="salary-sub-row">
                  <label>Мінімальний план абонементів, з якого рахується %</label>
                  <input type="number" className="salary-amount-input" min="0" step="1" value={salaryModal.cfg.pct_abon_min} onChange={(e) => setCfg('pct_abon_min', e.target.value)} style={{ width: 90 }} />
                  <span className="salary-unit">грн</span>
                </div>
              )}
              <div className="salary-row">
                <label className="salary-check-label"><input type="checkbox" checked={salaryModal.cfg.plan_on} onChange={(e) => setCfg('plan_on', e.target.checked)} /><span className="salary-label-icon"><Icon name="trendingUp" size={15} /></span>Бонус за перевищення плану</label>
              </div>
              {salaryModal.cfg.plan_on && (
                <div className="salary-plan-fields">
                  <FormGroup label="Плановий дохід (товари + абонементи) / міс.">
                    <div className="salary-input-wrap"><input type="number" min="0" step="1" value={salaryModal.cfg.plan_amount} onChange={(e) => setCfg('plan_amount', e.target.value)} /><span className="salary-unit">грн</span></div>
                  </FormGroup>
                  <FormGroup label="% бонусу з перевищення">
                    <div className="salary-input-wrap"><input type="number" min="0" max="100" step="0.01" value={salaryModal.cfg.plan_bonus_pct} onChange={(e) => setCfg('plan_bonus_pct', e.target.value)} /><span className="salary-unit">%</span></div>
                  </FormGroup>
                </div>
              )}
            </div>
            <FormGroup label="Примітка">
              <input type="text" maxLength={200} placeholder="Необов'язково" value={salaryModal.notes} onChange={(e) => setSalaryModal({ ...salaryModal, notes: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>

      {/* Нарахувати зарплату */}
      <Modal size="lg"
        open={!!createModal}
        onClose={() => setCreateModal(null)}
        title="➕ Нарахувати зарплату"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={createModal?.submitting || !createModal?.preview || createModal.preview.total <= 0} onClick={submitCreatePayroll}>Зафіксувати</button>
            <button className="btn btn-ghost" onClick={() => setCreateModal(null)}>Скасувати</button>
          </div>
        }
      >
        {createModal && (
          <>
            {createModal.error && <div className="alert alert-error">{createModal.error}</div>}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
              <FormGroup label="Співробітник *">
                <select value={createModal.userId} onChange={(e) => setCreateModal({ ...createModal, userId: e.target.value })}>
                  <option value="">— Оберіть —</option>
                  {payrollTeam?.map((t) => <option key={t.id} value={t.id}>{t.full_name} ({t.role_name})</option>)}
                </select>
              </FormGroup>
              <FormGroup label="Місяць *">
                <input type="month" value={createModal.month} onChange={(e) => setCreateModal({ ...createModal, month: e.target.value })} />
              </FormGroup>
            </div>

            {createModal.preview?.cfg?.pay_day_on && (
              <FormGroup label="Відпрацьовано змін">
                <input type="number" min="0" max="31" step="1" placeholder="напр. 22" value={createModal.days} onChange={(e) => setCreateModal({ ...createModal, days: e.target.value })} />
                <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 4 }}>Автоматично з відкриттів/закриттів каси — можна відредагувати</div>
              </FormGroup>
            )}
            {createModal.hasOpenShift && (
              <div style={{ fontSize: 12, color: 'var(--warning)', display: 'flex', alignItems: 'center', gap: 6, margin: '-8px 0 14px 2px' }}>
                ⚠ Є незакрита зміна за цей місяць — вона не врахована в підрахунку
              </div>
            )}

            {createModal.previewLoading && <div style={{ color: 'var(--text-muted)', fontSize: 13, marginBottom: 12 }}>⏳ Розраховую...</div>}
            {createModal.preview && !createModal.previewLoading && (
              <div style={{ background: 'var(--bg-elevated)', borderRadius: 'var(--radius-sm)', padding: 14, marginBottom: 16 }}>
                <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 10, textTransform: 'uppercase', letterSpacing: '.4px' }}>Розрахунок</div>
                {[
                  [createModal.preview.cfg.pay_month_on, 'Оклад/місяць', createModal.preview.pay_month],
                  [createModal.preview.cfg.pay_day_on, `Ставка за зміну (×${createModal.days || 0})`, createModal.preview.pay_day],
                  [createModal.preview.cfg.pct_tovar_on, `% товари (${createModal.preview.cfg.pct_tovar_value}%)`, createModal.preview.pct_tovar],
                  [createModal.preview.cfg.pct_abon_on, `% абонем. (${createModal.preview.cfg.pct_abon_value}%)`, createModal.preview.pct_abon],
                  [createModal.preview.cfg.plan_on, `Бонус за план (факт ${fmt(createModal.preview.plan_fact)} / план ${fmt(createModal.preview.cfg.plan_amount)})`, createModal.preview.plan_bonus],
                ].filter(([on]) => on).map(([, label, val], i) => (
                  <div className="pr-preview-row" key={i}><span>{label}</span><strong>{fmt(val)}</strong></div>
                ))}
                <div className="pr-preview-row" style={{ borderTop: '1px solid var(--border)', marginTop: 8, paddingTop: 8 }}>
                  <span style={{ fontWeight: 600 }}>Разом</span><strong style={{ color: 'var(--accent)', fontSize: 16 }}>{fmt(createModal.preview.total)}</strong>
                </div>
              </div>
            )}

            <FormGroup label="Примітка">
              <input type="text" maxLength={200} placeholder="Необов'язково" value={createModal.notes} onChange={(e) => setCreateModal({ ...createModal, notes: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>

      {/* Виплата */}
      <Modal
        open={!!payModal}
        onClose={() => setPayModal(null)}
        title="💸 Виплата"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={payModal?.submitting} onClick={submitPay}>✓ Виплатити</button>
            <button className="btn btn-ghost" onClick={() => setPayModal(null)}>Скасувати</button>
          </div>
        }
      >
        {payModal && (
          <>
            {payModal.error && <div className="alert alert-error">{payModal.error}</div>}
            <div style={{ background: 'var(--bg-elevated)', borderRadius: 'var(--radius-sm)', padding: '12px 14px', marginBottom: 16, fontSize: 13 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 6 }}><span style={{ color: 'var(--text-muted)' }}>Нараховано</span><strong>{fmt(payModal.total)}</strong></div>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 6 }}><span style={{ color: 'var(--text-muted)' }}>Вже виплачено</span><strong style={{ color: 'var(--success)' }}>{fmt(payModal.paid)}</strong></div>
              <div style={{ display: 'flex', justifyContent: 'space-between', borderTop: '1px solid var(--border)', paddingTop: 6 }}><span style={{ color: 'var(--text-muted)' }}>Залишок</span><strong style={{ color: 'var(--warning)' }}>{fmt(payModal.maxPay)}</strong></div>
            </div>
            <div style={{ marginBottom: 12 }}>
              <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 6 }}>Швидкий вибір</div>
              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                <button className="btn btn-ghost btn-sm" onClick={() => setPayPercent(25)}>25%</button>
                <button className="btn btn-ghost btn-sm" onClick={() => setPayPercent(50)}>Аванс 50%</button>
                <button className="btn btn-ghost btn-sm" onClick={() => setPayPercent(100)}>Повна виплата</button>
              </div>
            </div>
            <FormGroup label="Сума виплати (грн)">
              <input type="number" min="0.01" step="0.01" placeholder="0.00" value={payModal.amount} onChange={(e) => setPayModal({ ...payModal, amount: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>
    </AppLayout>
  );
}
