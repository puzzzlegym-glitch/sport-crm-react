import { useEffect, useState } from 'react';
import QRCode from 'qrcode';
import AppLayout from '../components/layout/AppLayout';
import Table from '../components/ui/Table';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import { useAuth } from '../context/AuthContext';
import { usePermissions } from '../hooks/usePermissions';
import { useToast } from '../components/ui/ToastProvider';
import {
  getClubSettings, updateClubSettings, updateProfile,
  getSysRoles, updateSysRole,
  getSysUsers, toggleSysUser, updateSysUserProfile, createSysUser,
  getSysUserClubs, addSysUserClub, updateSysUserClub, removeSysUserClub,
} from '../api/settings';
import { changePassword } from '../api/users';
import { getMyBilling } from '../api/billing';
import { getClubs } from '../api/clubs';
import {
  generateStaffLink, unlinkStaff, broadcastClients, broadcastOwners,
  getTelegramSettings, saveTelegramSettings, getTelegramStats,
} from '../api/telegram';
import { formatDate } from '../utils/format';
import './SettingsPage.css';

const ROLE_COLORS = { owner: '#c4b5fd', manager: 'var(--success)', trainer: 'var(--warning)' };
const STRENGTH_LEVELS = [
  { w: '0%', bg: 'transparent', t: 'Мінімум 8 символів' },
  { w: '25%', bg: 'var(--danger)', t: 'Слабкий' },
  { w: '50%', bg: 'var(--warning)', t: 'Середній' },
  { w: '75%', bg: 'var(--info)', t: 'Гарний' },
  { w: '90%', bg: 'var(--success)', t: 'Сильний' },
  { w: '100%', bg: 'var(--success)', t: 'Відмінний' },
];
const GLOBAL_ROLE_OPTIONS = [
  ['', '— Без ролі —'], ['1', 'SuperAdmin (100)'], ['2', 'Owner (80)'], ['3', 'Manager (50)'], ['4', 'Trainer (30)'],
];

const TINT_PROFILE = { bg: 'rgba(79,156,249,.15)', color: 'var(--accent)' };
const TINT_PASSWORD = { bg: 'rgba(167,139,250,.15)', color: '#a78bfa' };
const TINT_CLUB = { bg: 'rgba(52,211,153,.15)', color: 'var(--success)' };
const TINT_SUB = { bg: 'rgba(251,191,36,.15)', color: 'var(--warning)' };
const TINT_TELEGRAM = { bg: 'rgba(79,156,249,.15)', color: 'var(--accent)' };

const PHONE_RE = /^[\d+()\-\s]{7,20}$/;
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const isValidPhone = (v) => !v || PHONE_RE.test(v);
const isValidEmail = (v) => !v || EMAIL_RE.test(v);

function pwdStrength(pwd) {
  let sc = 0;
  if (pwd.length >= 8) sc++;
  if (pwd.length >= 12) sc++;
  if (/[A-Z]/.test(pwd)) sc++;
  if (/[0-9]/.test(pwd)) sc++;
  if (/[^A-Za-z0-9]/.test(pwd)) sc++;
  return STRENGTH_LEVELS[Math.min(sc, 5)];
}

/** Заголовок картки: іконка в кольоровому боксі + назва/підзаголовок + кнопка розблокування */
function SectionHeader({ icon, tint, title, subtitle, editable, editing, onEdit }) {
  return (
    <div className="settings-card-header">
      <div className="settings-card-icon" style={{ background: tint.bg, color: tint.color }}>
        <Icon name={icon} size={20} />
      </div>
      <div className="settings-card-heading">
        <div className="settings-card-title">{title}</div>
        {subtitle && <div className="settings-card-subtitle">{subtitle}</div>}
      </div>
      {editable && !editing && (
        <button type="button" className="settings-edit-btn" title="Редагувати" onClick={onEdit}>
          <Icon name="edit" size={15} />
        </button>
      )}
    </div>
  );
}

/** Текстове поле з іконкою-префіксом */
function IconField({ icon, error, ...props }) {
  return (
    <div>
      <div className={`input-icon-wrap ${error ? 'has-error' : ''}`}>
        <Icon name={icon} size={16} className="input-icon" />
        <input {...props} />
      </div>
      {error && <div className="field-error">{error}</div>}
    </div>
  );
}

/** Select з іконкою-префіксом */
function IconSelect({ icon, children, ...props }) {
  return (
    <div className="input-icon-wrap">
      <Icon name={icon} size={16} className="input-icon" />
      <select {...props}>{children}</select>
      <Icon name="chevronDown" size={14} className="input-caret" />
    </div>
  );
}

/** Поле пароля з іконкою-замком та кнопкою показу/приховування */
function PasswordField({ show, onToggleShow, error, ...props }) {
  return (
    <div>
      <div className={`input-icon-wrap has-eye ${error ? 'has-error' : ''}`}>
        <Icon name="lock" size={16} className="input-icon" />
        <input type={show ? 'text' : 'password'} {...props} />
        <button type="button" className="input-eye-btn" tabIndex={-1} onClick={onToggleShow} aria-label={show ? 'Приховати пароль' : 'Показати пароль'}>
          <Icon name={show ? 'eyeOff' : 'eye'} size={16} />
        </button>
      </div>
      {error && <div className="field-error">{error}</div>}
    </div>
  );
}

export default function SettingsPage() {
  const { user, club, refresh } = useAuth();
  const { has, isSuperAdmin } = usePermissions();
  const isOwner = has('settings.manage');
  const toast = useToast();

  const [tab, setTab] = useState('profile');

  const [profileName, setProfileName] = useState(user?.full_name || '');
  const [profilePhone, setProfilePhone] = useState(user?.phone || '');
  const [profileMsg, setProfileMsg] = useState(null);
  const [profileErrors, setProfileErrors] = useState({});
  const [profileEditing, setProfileEditing] = useState(false);
  const [profileSaving, setProfileSaving] = useState(false);
  const [profileSaved, setProfileSaved] = useState(false);

  const [pwdCurrent, setPwdCurrent] = useState('');
  const [pwdNew, setPwdNew] = useState('');
  const [pwdConfirm, setPwdConfirm] = useState('');
  const [pwdMsg, setPwdMsg] = useState(null);
  const [pwdErrors, setPwdErrors] = useState({});
  const [pwdSaving, setPwdSaving] = useState(false);
  const [pwdSaved, setPwdSaved] = useState(false);
  const [showCurrent, setShowCurrent] = useState(false);
  const [showNew, setShowNew] = useState(false);
  const [showConfirm, setShowConfirm] = useState(false);

  const [clubForm, setClubForm] = useState(null); // null = не завантажено
  const [clubError, setClubError] = useState('');
  const [clubErrors, setClubErrors] = useState({});
  const [clubEditing, setClubEditing] = useState(false);
  const [clubSaving, setClubSaving] = useState(false);
  const [clubSaved, setClubSaved] = useState(false);

  const [subInfo, setSubInfo] = useState(null);

  const [roles, setRoles] = useState(null);
  const [roleModal, setRoleModal] = useState(null);

  const [usersSearchInput, setUsersSearchInput] = useState('');
  const [usersSearch, setUsersSearch] = useState('');
  const [usersPage, setUsersPage] = useState(1);
  const [usersData, setUsersData] = useState(null);
  const [usersLoading, setUsersLoading] = useState(false);
  const [userModal, setUserModal] = useState(null);

  const [ucClubFilter, setUcClubFilter] = useState('');
  const [ucRows, setUcRows] = useState(null);
  const [ucLoading, setUcLoading] = useState(false);
  const [ucClubs, setUcClubs] = useState(null);
  const [ucModal, setUcModal] = useState(null);
  const [confirmModal, setConfirmModal] = useState(null);

  const [tgOverview, setTgOverview] = useState(null); // { myLinked, stats, platformWide }
  const [tgSettings, setTgSettings] = useState(null); // SuperAdmin only: { remindersEnabled, reminderDaysBefore, botUsername, botConfigured }
  const [tgSettingsSaving, setTgSettingsSaving] = useState(false);
  const [tgLink, setTgLink] = useState(null); // { loading, link, qrDataUrl }
  const [tgClientBroadcast, setTgClientBroadcast] = useState({ text: '', sending: false });
  const [tgOwnerBroadcast, setTgOwnerBroadcast] = useState({ text: '', sending: false });

  const showOwnerSections = !!club && isOwner;
  const canBroadcastClients = !!club && (isOwner || has('telegram.broadcast'));

  function loadClubSettings() {
    getClubSettings().then((res) => {
      if (res.success) {
        const c = res.club;
        setClubForm({
          name: c.name || '', city: c.city || '', phone: c.phone || '', email: c.email || '', address: c.address || '',
          timezone: c.timezone || 'Europe/Kyiv', currency: c.currency || 'UAH',
          cashShiftAutoCloseEnabled: !!c.cash_shift_auto_close_enabled,
          cashShiftAutoCloseTime: c.cash_shift_auto_close_time ? c.cash_shift_auto_close_time.slice(0, 5) : '',
        });
      }
    });
  }

  useEffect(() => {
    if (showOwnerSections) {
      loadClubSettings();
      getMyBilling().then((res) => {
        if (res.success && !res.no_subscription) setSubInfo(res.subscription);
      });
    }
  }, [showOwnerSections]);

  function startProfileEdit() { setProfileEditing(true); }
  function cancelProfileEdit() {
    setProfileName(user?.full_name || '');
    setProfilePhone(user?.phone || '');
    setProfileErrors({});
    setProfileMsg(null);
    setProfileEditing(false);
  }

  async function submitProfile() {
    const errs = {};
    if (profileName.trim().length < 2) errs.name = 'Мінімум 2 символи';
    if (!isValidPhone(profilePhone.trim())) errs.phone = 'Некоректний формат телефону';
    setProfileErrors(errs);
    if (Object.keys(errs).length) return;

    setProfileSaving(true);
    setProfileMsg(null);
    const res = await updateProfile({ full_name: profileName.trim(), phone: profilePhone.trim() });
    setProfileSaving(false);
    if (!res.success) { setProfileMsg({ type: 'error', text: res.error }); return; }
    setProfileSaved(true);
    setProfileEditing(false);
    setTimeout(() => setProfileSaved(false), 3000);
    await refresh();
  }

  const strength = pwdStrength(pwdNew);

  async function submitPassword() {
    setPwdMsg(null);
    const errs = {};
    if (!pwdCurrent) errs.current = 'Введіть поточний пароль';
    if (pwdNew.length < 8) errs.next = 'Мінімум 8 символів';
    else if (pwdCurrent && pwdNew === pwdCurrent) errs.next = 'Новий пароль має відрізнятися від поточного';
    if (pwdConfirm !== pwdNew) errs.confirm = 'Паролі не збігаються';
    setPwdErrors(errs);
    if (Object.keys(errs).length) return;

    setPwdSaving(true);
    const res = await changePassword(pwdCurrent, pwdNew);
    setPwdSaving(false);
    if (!res.success) { setPwdMsg({ type: 'error', text: res.error }); return; }
    setPwdSaved(true);
    setTimeout(() => setPwdSaved(false), 3000);
    setPwdCurrent(''); setPwdNew(''); setPwdConfirm(''); setPwdErrors({});
  }

  function startClubEdit() { setClubEditing(true); }
  function cancelClubEdit() {
    setClubErrors({});
    setClubError('');
    setClubEditing(false);
    loadClubSettings();
  }

  async function submitClub() {
    const errs = {};
    if (!clubForm.name.trim()) errs.name = 'Введіть назву';
    if (!isValidPhone(clubForm.phone.trim())) errs.phone = 'Некоректний формат телефону';
    if (!isValidEmail(clubForm.email.trim())) errs.email = 'Некоректний email';
    if (clubForm.cashShiftAutoCloseEnabled && !clubForm.cashShiftAutoCloseTime) errs.cashShiftAutoCloseTime = 'Вкажіть час автозакриття';
    setClubErrors(errs);
    if (Object.keys(errs).length) return;

    setClubError('');
    setClubSaving(true);
    const res = await updateClubSettings({
      ...clubForm,
      cash_shift_auto_close_enabled: clubForm.cashShiftAutoCloseEnabled ? 1 : 0,
      cash_shift_auto_close_time: clubForm.cashShiftAutoCloseTime,
    });
    setClubSaving(false);
    if (!res.success) { setClubError(res.error); return; }
    setClubSaved(true);
    setClubEditing(false);
    setTimeout(() => setClubSaved(false), 3000);
  }

  function selectTab(id) {
    setTab(id);
    if (id === 'roles' && roles === null) {
      getSysRoles().then((res) => { if (res.success) setRoles(res.roles); });
    }
    if (id === 'users' && usersData === null) reloadUsers(1);
    if (id === 'uc' && ucRows === null) reloadUC();
    if (id === 'telegram' && tgOverview === null) reloadTelegram();
  }

  async function reloadTelegram() {
    const res = await getTelegramStats();
    if (res.success) setTgOverview({ myLinked: !!res.my_linked, stats: res.stats || [], platformWide: !!res.platform_wide });
    if (isSuperAdmin) {
      const s = await getTelegramSettings();
      if (s.success) setTgSettings({ remindersEnabled: !!s.reminders_enabled, reminderDaysBefore: s.reminder_days_before, botUsername: s.bot_username, botConfigured: !!s.bot_configured });
    }
  }

  async function openStaffTelegramLink() {
    setTgLink({ loading: true, link: '', qrDataUrl: '' });
    const res = await generateStaffLink();
    if (!res.success) { toast(res.error, 'error'); setTgLink(null); return; }
    const qrDataUrl = await QRCode.toDataURL(res.link, { width: 220, margin: 1 });
    setTgLink({ loading: false, link: res.link, qrDataUrl });
  }

  async function handleUnlinkStaff() {
    if (!window.confirm("Відв'язати ваш Telegram?")) return;
    const res = await unlinkStaff();
    if (!res.success) { toast(res.error, 'error'); return; }
    toast("Telegram відв'язано", 'success');
    reloadTelegram();
  }

  async function sendClientBroadcast() {
    const text = tgClientBroadcast.text.trim();
    if (!text) return;
    setTgClientBroadcast({ ...tgClientBroadcast, sending: true });
    const res = await broadcastClients(text);
    if (!res.success) { toast(res.error, 'error'); setTgClientBroadcast({ ...tgClientBroadcast, sending: false }); return; }
    toast(`Надіслано: ${res.sent}`, 'success');
    setTgClientBroadcast({ text: '', sending: false });
  }

  async function sendOwnerBroadcast() {
    const text = tgOwnerBroadcast.text.trim();
    if (!text) return;
    setTgOwnerBroadcast({ ...tgOwnerBroadcast, sending: true });
    const res = await broadcastOwners(text);
    if (!res.success) { toast(res.error, 'error'); setTgOwnerBroadcast({ ...tgOwnerBroadcast, sending: false }); return; }
    toast(`Надіслано: ${res.sent}`, 'success');
    setTgOwnerBroadcast({ text: '', sending: false });
  }

  async function saveTgSettings() {
    setTgSettingsSaving(true);
    const res = await saveTelegramSettings({
      reminders_enabled: tgSettings.remindersEnabled ? 1 : 0,
      reminder_days_before: tgSettings.reminderDaysBefore,
    });
    setTgSettingsSaving(false);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast('Збережено', 'success');
  }

  async function reloadUsers(page) {
    setUsersPage(page);
    setUsersLoading(true);
    const res = await getSysUsers({ search: usersSearch, page });
    setUsersLoading(false);
    if (!res.success) return;
    setUsersData({ rows: res.users, pagination: res.pagination });
  }

  useEffect(() => {
    const t = setTimeout(() => setUsersSearch(usersSearchInput.trim()), 350);
    return () => clearTimeout(t);
  }, [usersSearchInput]);

  useEffect(() => {
    if (usersData !== null) reloadUsers(1);
  }, [usersSearch]);

  function openRoleModal(r) {
    setRoleModal({ id: r.id, slug: r.slug, level: r.level, nameUa: r.name_ua, error: '' });
  }

  async function submitRole() {
    if (!roleModal.nameUa.trim()) { setRoleModal({ ...roleModal, error: 'Введіть назву' }); return; }
    const res = await updateSysRole({ id: roleModal.id, name_ua: roleModal.nameUa.trim() });
    if (!res.success) { setRoleModal({ ...roleModal, error: res.error }); return; }
    toast('Збережено', 'success');
    setRoleModal(null);
    getSysRoles().then((r) => { if (r.success) setRoles(r.roles); });
  }

  function openUserModal(u) {
    if (u) {
      setUserModal({ id: u.id, name: u.full_name, email: u.email, phone: u.phone || '', globalRoleId: '', active: !!u.is_active, password: '', error: '', submitting: false });
    } else {
      setUserModal({ id: null, name: '', email: '', phone: '', globalRoleId: '', active: true, password: '', error: '', submitting: false });
    }
  }

  async function submitUser() {
    setUserModal({ ...userModal, submitting: true, error: '' });
    const payload = {
      id: userModal.id,
      full_name: userModal.name.trim(),
      email: userModal.email.trim(),
      phone: userModal.phone.trim(),
      global_role_id: userModal.globalRoleId || null,
      is_active: userModal.active ? 1 : 0,
    };
    if (!userModal.id) payload.password = userModal.password;
    const res = userModal.id ? await updateSysUserProfile(payload) : await createSysUser(payload);
    if (!res.success) { setUserModal({ ...userModal, submitting: false, error: res.error }); return; }
    toast(userModal.id ? 'Збережено' : 'Створено', 'success');
    setUserModal(null);
    reloadUsers(usersPage);
  }

  async function handleToggleSysUser(u) {
    const res = await toggleSysUser(u.id);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message, 'success');
    reloadUsers(usersPage);
  }

  async function reloadUC() {
    setUcLoading(true);
    const res = await getSysUserClubs(ucClubFilter || 0);
    setUcLoading(false);
    if (!res.success) return;
    setUcRows(res.links);
  }

  useEffect(() => {
    if (ucRows !== null) reloadUC();
  }, [ucClubFilter]);

  async function ensureUcClubs() {
    if (ucClubs) return ucClubs;
    const res = await getClubs({});
    const list = res.success ? (res.clubs || []) : [];
    setUcClubs(list);
    return list;
  }

  async function openUcModal(link) {
    const clubsList = await ensureUcClubs();
    let usersList = usersData?.rows;
    if (!usersList) {
      const res = await getSysUsers({ page: 1 });
      usersList = res.success ? res.users : [];
    }
    let rolesList = roles;
    if (!rolesList) {
      const res = await getSysRoles();
      rolesList = res.success ? res.roles : [];
      setRoles(rolesList);
    }
    setUcModal({
      id: link?.id ?? null,
      userId: link?.user_id ?? usersList[0]?.id ?? '',
      clubId: link?.club_id ?? clubsList[0]?.id ?? '',
      roleId: link?.role_id ?? rolesList.find((r) => r.slug !== 'superadmin')?.id ?? '',
      active: link ? !!link.is_active : true,
      error: '', submitting: false,
      usersList, clubsList, rolesList: rolesList.filter((r) => r.slug !== 'superadmin'),
    });
  }

  async function submitUc() {
    setUcModal({ ...ucModal, submitting: true, error: '' });
    const payload = { id: ucModal.id, user_id: parseInt(ucModal.userId), club_id: parseInt(ucModal.clubId), role_id: parseInt(ucModal.roleId), is_active: ucModal.active ? 1 : 0 };
    const res = ucModal.id ? await updateSysUserClub(payload) : await addSysUserClub(payload);
    if (!res.success) { setUcModal({ ...ucModal, submitting: false, error: res.error }); return; }
    toast('Збережено', 'success');
    setUcModal(null);
    reloadUC();
  }

  function askRemoveUc(id) {
    setConfirmModal({ text: "Видалити прив'язку?", onConfirm: () => doRemoveUc(id) });
  }

  async function doRemoveUc(id) {
    setConfirmModal(null);
    const res = await removeSysUserClub(id);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast('Видалено', 'success');
    reloadUC();
  }

  const rolesColumns = [
    { key: 'name', label: 'Назва (укр.)', render: (r) => <span style={{ fontWeight: 600 }}>{r.name_ua}</span> },
    { key: 'slug', label: 'Slug', mobile: 'secondary', render: (r) => <code style={{ background: 'var(--bg-elevated)', padding: '2px 6px', borderRadius: 4, fontSize: 12 }}>{r.slug}</code> },
    { key: 'level', label: 'Рівень', mobile: 'trailing', render: (r) => r.level },
    { key: 'id', label: 'ID', render: (r) => <span style={{ color: 'var(--text-muted)' }}>{r.id}</span> },
    { key: 'actions', label: '', render: (r) => <button className="btn btn-ghost btn-sm icon-btn-label" onClick={() => openRoleModal(r)}><Icon name="edit" size={13} /> <span className="action-label-text">Редагувати</span></button> },
  ];

  const usersColumns = [
    { key: 'user', label: 'Користувач', render: (u) => <><div style={{ fontWeight: 500 }}>{u.full_name}</div><div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{u.email}</div></> },
    { key: 'status', label: 'Статус', cardTop: true, render: (u) => <Badge variant={u.is_active ? 'active' : 'inactive'}>{u.is_active ? 'Активний' : 'Вимкнено'}</Badge> },
    { key: 'role', label: 'Глобальна роль', mobile: 'secondary', render: (u) => (u.global_role ? <Badge variant={u.global_role}>{u.global_role_name || u.global_role}</Badge> : <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>—</span>) },
    { key: 'clubs', label: 'Клубів', mobile: 'trailing', render: (u) => `${u.clubs_count} клуб.` },
    { key: 'login', label: 'Вхід', render: (u) => <span style={{ fontSize: 13, color: 'var(--text-secondary)' }}>{u.last_login_at ? formatDate(u.last_login_at) : '—'}</span> },
    {
      key: 'actions', label: '',
      render: (u) => (
        <div style={{ display: 'flex', gap: 4 }}>
          <button className="btn btn-ghost btn-sm icon-btn" title="Редагувати" onClick={() => openUserModal(u)}><Icon name="edit" size={14} /></button>
          <button className="btn btn-ghost btn-sm icon-btn" title={u.is_active ? 'Вимкнути' : 'Увімкнути'} style={{ color: u.is_active ? 'var(--warning)' : 'var(--success)' }} onClick={() => handleToggleSysUser(u)}>
            <Icon name={u.is_active ? 'ban' : 'check'} size={14} />
          </button>
        </div>
      ),
    },
  ];

  const ucColumns = [
    { key: 'user', label: 'Користувач', render: (l) => <><div style={{ fontWeight: 500 }}>{l.full_name}</div><div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{l.email}</div></> },
    { key: 'access', label: 'Доступ', cardTop: true, render: (l) => <Badge variant={l.is_active ? 'active' : 'inactive'}>{l.is_active ? 'Активний' : 'Вимкнено'}</Badge> },
    { key: 'club', label: 'Клуб', mobile: 'secondary', render: (l) => <span style={{ fontWeight: 500 }}>{l.club_name}</span> },
    { key: 'role', label: 'Роль', mobile: 'secondary', render: (l) => <span style={{ fontWeight: 600, color: ROLE_COLORS[l.role_slug] || 'var(--accent)' }}>{l.role_name}</span> },
    { key: 'granted', label: 'Додано', mobile: 'trailing', render: (l) => <span style={{ fontSize: 13, color: 'var(--text-secondary)' }}>{formatDate(l.granted_at)}</span> },
    {
      key: 'actions', label: '',
      render: (l) => (
        <div style={{ display: 'flex', gap: 4 }}>
          <button className="btn btn-ghost btn-sm icon-btn" title="Редагувати" onClick={() => openUcModal(l)}><Icon name="edit" size={14} /></button>
          <button className="btn btn-ghost btn-sm icon-btn" title="Видалити" style={{ color: 'var(--danger)' }} onClick={() => askRemoveUc(l.id)}><Icon name="trash" size={14} /></button>
        </div>
      ),
    },
  ];

  const clientsPct = subInfo?.clients_limit ? Math.min(100, Math.round((subInfo.clients_used / subInfo.clients_limit) * 100)) : 0;
  const clientsLeft = subInfo?.clients_limit ? Math.max(0, subInfo.clients_limit - subInfo.clients_used) : null;

  return (
    <AppLayout title="Налаштування" subtitle="Керуйте параметрами вашого акаунта та клубу">
      {(isSuperAdmin || showOwnerSections) && (
        <div id="tab-bar">
          <button className={`fin-tab-btn ${tab === 'profile' ? 'active' : ''}`} onClick={() => selectTab('profile')}><Icon name="user" size={14} /> Профіль</button>
          {isSuperAdmin && <button className={`fin-tab-btn ${tab === 'roles' ? 'active' : ''}`} onClick={() => selectTab('roles')}><Icon name="key" size={14} /> Ролі доступу</button>}
          {isSuperAdmin && <button className={`fin-tab-btn ${tab === 'users' ? 'active' : ''}`} onClick={() => selectTab('users')}><Icon name="users" size={14} /> Користувачі</button>}
          {isSuperAdmin && <button className={`fin-tab-btn ${tab === 'uc' ? 'active' : ''}`} onClick={() => selectTab('uc')}><Icon name="link" size={14} /> Прив'язки до клубів</button>}
          <button className={`fin-tab-btn ${tab === 'telegram' ? 'active' : ''}`} onClick={() => selectTab('telegram')}><Icon name="link" size={14} /> Telegram</button>
        </div>
      )}

      {tab === 'profile' && (
        <div className="settings-grid">
          <div className="settings-section">
            <SectionHeader icon="user" tint={TINT_PROFILE} title="Мій профіль" subtitle="Особиста інформація та контакти" editable editing={profileEditing} onEdit={startProfileEdit} />
            {profileMsg && <div className={`alert alert-${profileMsg.type}`}>{profileMsg.text}</div>}
            <FormGroup label="Ім'я та прізвище *">
              <IconField icon="user" type="text" placeholder="Іван Петренко" value={profileName} readOnly={!profileEditing} error={profileErrors.name}
                onChange={(e) => { setProfileName(e.target.value); if (profileErrors.name) setProfileErrors({ ...profileErrors, name: '' }); }} />
            </FormGroup>
            <FormGroup label="Email"><IconField icon="mail" type="email" disabled value={user?.email || ''} readOnly /></FormGroup>
            <FormGroup label="Телефон">
              <IconField icon="phone" type="tel" placeholder="+38 067 123 45 67" value={profilePhone} readOnly={!profileEditing} error={profileErrors.phone}
                onChange={(e) => { setProfilePhone(e.target.value); if (profileErrors.phone) setProfileErrors({ ...profileErrors, phone: '' }); }} />
            </FormGroup>
            {profileEditing && (
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <button className="btn btn-primary" disabled={profileSaving} onClick={submitProfile}>Зберегти зміни</button>
                <button className="btn btn-ghost" disabled={profileSaving} onClick={cancelProfileEdit}>Скасувати</button>
                <span className={`save-indicator ${profileSaved ? 'show' : ''}`}><Icon name="check" size={13} /> Збережено</span>
              </div>
            )}
            {!profileEditing && <span className={`save-indicator ${profileSaved ? 'show' : ''}`}><Icon name="check" size={13} /> Збережено</span>}
          </div>

          <div className="settings-section">
            <SectionHeader icon="lock" tint={TINT_PASSWORD} title="Зміна пароля" subtitle="Оновіть пароль для безпеки акаунта" />
            {pwdMsg && <div className={`alert alert-${pwdMsg.type}`}>{pwdMsg.text}</div>}
            <FormGroup label="Поточний пароль">
              <PasswordField autoComplete="current-password" value={pwdCurrent} show={showCurrent} onToggleShow={() => setShowCurrent((v) => !v)} error={pwdErrors.current}
                onChange={(e) => { setPwdCurrent(e.target.value); if (pwdErrors.current) setPwdErrors({ ...pwdErrors, current: '' }); }} />
            </FormGroup>
            <FormGroup label="Новий пароль">
              <PasswordField placeholder="Мін. 8 символів" autoComplete="new-password" value={pwdNew} show={showNew} onToggleShow={() => setShowNew((v) => !v)} error={pwdErrors.next}
                onChange={(e) => { setPwdNew(e.target.value); if (pwdErrors.next) setPwdErrors({ ...pwdErrors, next: '' }); }} />
              <div style={{ height: 3, background: 'var(--border)', borderRadius: 2, marginTop: 8, overflow: 'hidden' }}>
                <div style={{ height: '100%', width: strength.w, background: strength.bg, borderRadius: 2, transition: 'width .3s, background .3s' }} />
              </div>
              <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 3 }}>Надійність пароля: {strength.t}</div>
            </FormGroup>
            <FormGroup label="Повторіть новий пароль">
              <PasswordField placeholder="Введіть новий пароль ще раз" autoComplete="new-password" value={pwdConfirm} show={showConfirm} onToggleShow={() => setShowConfirm((v) => !v)} error={pwdErrors.confirm}
                onChange={(e) => { setPwdConfirm(e.target.value); if (pwdErrors.confirm) setPwdErrors({ ...pwdErrors, confirm: '' }); }} />
            </FormGroup>
            <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
              <button className="btn btn-primary" disabled={pwdSaving} onClick={submitPassword}><Icon name="lock" size={14} /> Оновити пароль</button>
              <span className={`save-indicator ${pwdSaved ? 'show' : ''}`}><Icon name="check" size={13} /> Змінено</span>
            </div>
          </div>

          {showOwnerSections && clubForm && (
            <div className="settings-section">
              <SectionHeader icon="building" tint={TINT_CLUB} title="Профіль клубу" subtitle="Інформація про ваш клуб" editable editing={clubEditing} onEdit={startClubEdit} />
              {clubError && <div className="alert alert-error">{clubError}</div>}
              <FormGroup label="Назва *">
                <IconField icon="building" type="text" value={clubForm.name} readOnly={!clubEditing} error={clubErrors.name}
                  onChange={(e) => { setClubForm({ ...clubForm, name: e.target.value }); if (clubErrors.name) setClubErrors({ ...clubErrors, name: '' }); }} />
              </FormGroup>
              <div className="settings-fields-2">
                <FormGroup label="Місто">
                  <IconField icon="pin" type="text" value={clubForm.city} readOnly={!clubEditing} onChange={(e) => setClubForm({ ...clubForm, city: e.target.value })} />
                </FormGroup>
                <FormGroup label="Телефон">
                  <IconField icon="phone" type="tel" value={clubForm.phone} readOnly={!clubEditing} error={clubErrors.phone}
                    onChange={(e) => { setClubForm({ ...clubForm, phone: e.target.value }); if (clubErrors.phone) setClubErrors({ ...clubErrors, phone: '' }); }} />
                </FormGroup>
              </div>
              <FormGroup label="Email">
                <IconField icon="mail" type="email" value={clubForm.email} readOnly={!clubEditing} error={clubErrors.email}
                  onChange={(e) => { setClubForm({ ...clubForm, email: e.target.value }); if (clubErrors.email) setClubErrors({ ...clubErrors, email: '' }); }} />
              </FormGroup>
              <FormGroup label="Адреса">
                <IconField icon="pin" type="text" value={clubForm.address} readOnly={!clubEditing} onChange={(e) => setClubForm({ ...clubForm, address: e.target.value })} />
              </FormGroup>
              <div className="settings-fields-2">
                <FormGroup label="Часовий пояс">
                  <IconSelect icon="clock" value={clubForm.timezone} disabled={!clubEditing} onChange={(e) => setClubForm({ ...clubForm, timezone: e.target.value })}>
                    <option value="Europe/Kyiv">Europe/Kyiv (UTC+2/+3)</option>
                    <option value="Europe/Warsaw">Europe/Warsaw</option>
                    <option value="UTC">UTC</option>
                  </IconSelect>
                </FormGroup>
                <FormGroup label="Валюта">
                  <IconSelect icon="card" value={clubForm.currency} disabled={!clubEditing} onChange={(e) => setClubForm({ ...clubForm, currency: e.target.value })}>
                    <option value="UAH">UAH</option>
                    <option value="USD">USD</option>
                    <option value="EUR">EUR</option>
                  </IconSelect>
                </FormGroup>
              </div>
              <FormGroup label="Автозакриття зміни каси">
                <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: clubForm.cashShiftAutoCloseEnabled ? 8 : 0 }}>
                  <input type="checkbox" id="club-auto-close-shift" checked={clubForm.cashShiftAutoCloseEnabled} disabled={!clubEditing}
                    onChange={(e) => setClubForm({ ...clubForm, cashShiftAutoCloseEnabled: e.target.checked })} />
                  <label htmlFor="club-auto-close-shift" style={{ margin: 0, fontWeight: 400 }}>Автоматично закривати відкриту зміну щодня о вказаному часі</label>
                </div>
                {clubForm.cashShiftAutoCloseEnabled && (
                  <IconField icon="clock" type="time" style={{ maxWidth: 160 }} value={clubForm.cashShiftAutoCloseTime} readOnly={!clubEditing} error={clubErrors.cashShiftAutoCloseTime}
                    onChange={(e) => { setClubForm({ ...clubForm, cashShiftAutoCloseTime: e.target.value }); if (clubErrors.cashShiftAutoCloseTime) setClubErrors({ ...clubErrors, cashShiftAutoCloseTime: '' }); }} />
                )}
                <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 6 }}>
                  Якщо адміністратор забуде закрити зміну, вона закриється сама о цій годині. Зміну, відкриту вже після цього часу, автозакриття не чіпає.
                </div>
              </FormGroup>
              {clubEditing && (
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                  <button className="btn btn-primary" disabled={clubSaving} onClick={submitClub}>Зберегти клуб</button>
                  <button className="btn btn-ghost" disabled={clubSaving} onClick={cancelClubEdit}>Скасувати</button>
                  <span className={`save-indicator ${clubSaved ? 'show' : ''}`}><Icon name="check" size={13} /> Збережено</span>
                </div>
              )}
              {!clubEditing && <span className={`save-indicator ${clubSaved ? 'show' : ''}`}><Icon name="check" size={13} /> Збережено</span>}
            </div>
          )}

          {showOwnerSections && (
            <div className="settings-section">
              <SectionHeader icon="card" tint={TINT_SUB} title="Підписка" subtitle="План та інформація про підписку" />
              {!subInfo && <div className="loader"><span className="spinner" /></div>}
              {subInfo && (
                <>
                  <div className="sub-plan-card">
                    <div className="sub-plan-row">
                      <span className="sub-plan-label">Ваш план</span>
                      <Badge variant={{ trial: 'info', active: 'active', trial_expired: 'inactive', past_due: 'pending' }[subInfo.status] || 'info'}>
                        {{ trial: 'Тріал', active: 'Активна', trial_expired: 'Закінчився', past_due: 'Прострочено' }[subInfo.status] || subInfo.status}
                      </Badge>
                    </div>
                    <div className="sub-plan-name">{subInfo.plan_name || '—'}</div>
                  </div>
                  <div className="sub-clients">
                    <div className="sub-clients-row">
                      <span>Клієнтів</span>
                      <strong>{subInfo.clients_used} / {subInfo.clients_limit || '∞'}</strong>
                    </div>
                    {subInfo.clients_limit > 0 && (
                      <>
                        <div className="sub-progress"><div className="sub-progress-fill" style={{ width: `${clientsPct}%` }} /></div>
                        <div className="sub-clients-hint">
                          <span>Доступно ще {clientsLeft} клієнтів</span>
                          <span>Ліміт: {subInfo.clients_limit}</span>
                        </div>
                      </>
                    )}
                  </div>
                  <a href="/billing" className="btn btn-ghost btn-sm" style={{ marginTop: 14, width: '100%', justifyContent: 'center' }}>Керувати підпискою →</a>
                </>
              )}
            </div>
          )}

          <div className="settings-security-note">
            <Icon name="shield" size={18} />
            <span>Ваші дані захищені. Ми використовуємо сучасні методи шифрування для захисту вашої інформації.</span>
          </div>
        </div>
      )}

      {tab === 'roles' && isSuperAdmin && (
        <div className="card">
          <div className="card-title">sys_roles — Ролі системи</div>
          <Table columns={rolesColumns} rows={roles || []} loading={roles === null} emptyMessage="Ролей немає" />
        </div>
      )}

      {tab === 'users' && isSuperAdmin && (
        <>
          <div style={{ display: 'flex', gap: 10, marginBottom: 14 }}>
            <div className="input-icon-wrap" style={{ flex: 1, maxWidth: 300 }}>
              <Icon name="search" size={16} className="input-icon" />
              <input type="text" placeholder="Ім'я або email..." value={usersSearchInput} onChange={(e) => setUsersSearchInput(e.target.value)} />
            </div>
            <button className="btn btn-primary btn-sm" onClick={() => openUserModal(null)}><Icon name="plus" size={14} /> Новий</button>
          </div>
          <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
            <Table columns={usersColumns} rows={usersData?.rows || []} loading={usersLoading} emptyMessage="Користувачів не знайдено" />
          </div>
        </>
      )}

      {tab === 'uc' && isSuperAdmin && (
        <>
          <div style={{ display: 'flex', gap: 10, marginBottom: 14 }}>
            <select style={{ width: 'auto', minWidth: 180 }} value={ucClubFilter} onChange={(e) => setUcClubFilter(e.target.value)}>
              <option value="">Всі клуби</option>
              {ucClubs?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
            <button className="btn btn-primary btn-sm" onClick={() => openUcModal(null)}><Icon name="link" size={14} /> Прив'язати</button>
          </div>
          <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
            <Table columns={ucColumns} rows={ucRows || []} loading={ucLoading} emptyMessage="Прив'язок немає" />
          </div>
        </>
      )}

      {tab === 'telegram' && (
        <div className="settings-grid">
          <div className="settings-section">
            <SectionHeader icon="link" tint={TINT_TELEGRAM} title="Мій Telegram" subtitle="Отримуйте сповіщення в особистий чат" />
            {tgOverview === null && <div className="loader"><span className="spinner" /></div>}
            {tgOverview && (
              <>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14 }}>
                  <Badge variant={tgOverview.myLinked ? 'active' : 'inactive'}>{tgOverview.myLinked ? 'Прив’язано ✓' : 'Не прив’язано'}</Badge>
                </div>
                {tgOverview.myLinked
                  ? <button className="btn btn-ghost" onClick={handleUnlinkStaff}>Відв'язати Telegram</button>
                  : <button className="btn btn-primary" onClick={openStaffTelegramLink}>📲 Прив'язати Telegram</button>}
              </>
            )}
          </div>

          {canBroadcastClients && (
            <div className="settings-section">
              <SectionHeader icon="users" tint={TINT_TELEGRAM} title="Розсилка клієнтам клубу" subtitle="Повідомлення всім прив'язаним клієнтам вашого клубу" />
              <FormGroup label="Текст повідомлення">
                <textarea rows={3} placeholder="Наприклад: цими вихідними клуб працює за скороченим графіком..."
                  value={tgClientBroadcast.text} onChange={(e) => setTgClientBroadcast({ ...tgClientBroadcast, text: e.target.value })} />
              </FormGroup>
              <button className="btn btn-primary" disabled={tgClientBroadcast.sending || !tgClientBroadcast.text.trim()} onClick={sendClientBroadcast}>
                {tgClientBroadcast.sending ? 'Надсилання...' : 'Надіслати'}
              </button>
            </div>
          )}

          {isSuperAdmin && (
            <div className="settings-section">
              <SectionHeader icon="mail" tint={TINT_TELEGRAM} title="Розсилка власникам клубів" subtitle="Повідомлення всім прив'язаним власникам на платформі" />
              <FormGroup label="Текст повідомлення">
                <textarea rows={3} placeholder="Наприклад: у четвер технічні роботи 02:00-04:00..."
                  value={tgOwnerBroadcast.text} onChange={(e) => setTgOwnerBroadcast({ ...tgOwnerBroadcast, text: e.target.value })} />
              </FormGroup>
              <button className="btn btn-primary" disabled={tgOwnerBroadcast.sending || !tgOwnerBroadcast.text.trim()} onClick={sendOwnerBroadcast}>
                {tgOwnerBroadcast.sending ? 'Надсилання...' : 'Надіслати'}
              </button>
            </div>
          )}

          {isSuperAdmin && tgSettings && (
            <div className="settings-section">
              <SectionHeader icon="gear" tint={TINT_TELEGRAM} title="Статус і налаштування бота" subtitle="Глобальні для всієї платформи" />
              <div style={{ fontSize: 13, color: 'var(--text-secondary)', marginBottom: 14 }}>
                Бот: {tgSettings.botUsername ? `@${tgSettings.botUsername}` : '—'}
                {' · '}
                <span style={{ color: tgSettings.botConfigured ? 'var(--success)' : 'var(--danger)' }}>
                  {tgSettings.botConfigured ? 'налаштовано' : 'токен не задано'}
                </span>
              </div>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14 }}>
                <input type="checkbox" id="tg-reminders" checked={tgSettings.remindersEnabled}
                  onChange={(e) => setTgSettings({ ...tgSettings, remindersEnabled: e.target.checked })} />
                <label htmlFor="tg-reminders" style={{ margin: 0, fontWeight: 400 }}>Нагадування клієнтам про закінчення абонемента</label>
              </div>
              <FormGroup label="Днів до закінчення (0 = у день закінчення)">
                <input type="number" min={0} max={30} style={{ maxWidth: 120 }} value={tgSettings.reminderDaysBefore}
                  onChange={(e) => setTgSettings({ ...tgSettings, reminderDaysBefore: Math.max(0, Math.min(30, parseInt(e.target.value, 10) || 0)) })} />
              </FormGroup>
              <button className="btn btn-primary" disabled={tgSettingsSaving} onClick={saveTgSettings}>Зберегти налаштування</button>
            </div>
          )}

          {tgOverview && (
            <div className="settings-section" style={{ gridColumn: '1 / -1' }}>
              <SectionHeader icon="grid" tint={TINT_TELEGRAM} title="Статистика прив'язки" subtitle={tgOverview.platformWide ? 'По всіх клубах платформи' : 'Поточний клуб'} />
              <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                <Table
                  columns={[
                    { key: 'club', label: 'Клуб', render: (r) => r.club_name },
                    { key: 'linked', label: 'Прив\'язано', render: (r) => `${r.linked_clients} / ${r.total_clients}` },
                  ]}
                  rows={tgOverview.stats}
                  loading={false}
                  emptyMessage="Даних немає"
                />
              </div>
            </div>
          )}
        </div>
      )}

      {/* Прив'язка Telegram (персонал) — посилання + QR */}
      <Modal size="sm" open={!!tgLink} onClose={() => setTgLink(null)} title="Прив'язка Telegram">
        {tgLink?.loading && <div className="loader"><span className="spinner" /> Створення посилання...</div>}
        {tgLink && !tgLink.loading && (
          <div style={{ textAlign: 'center' }}>
            <p style={{ color: 'var(--text-muted)', fontSize: 13, marginBottom: 16 }}>
              Відскануйте QR-код своїм телефоном — посилання дійсне 24 год.
            </p>
            {tgLink.qrDataUrl && <img src={tgLink.qrDataUrl} alt="QR-код прив'язки Telegram" style={{ width: 200, height: 200, margin: '0 auto 16px', display: 'block' }} />}
            <FormGroup label="Посилання">
              <input type="text" readOnly value={tgLink.link} onFocus={(e) => e.target.select()} />
            </FormGroup>
            <button
              className="btn btn-ghost"
              style={{ marginTop: 8 }}
              onClick={() => { navigator.clipboard?.writeText(tgLink.link); toast('Скопійовано', 'success'); }}
            >
              📋 Скопіювати посилання
            </button>
          </div>
        )}
      </Modal>

      {/* Роль */}
      <Modal
        size="sm"
        open={!!roleModal}
        onClose={() => setRoleModal(null)}
        title="Редагувати роль"
        footer={<div style={{ display: 'flex', gap: 10 }}><button className="btn btn-primary" onClick={submitRole}>Зберегти</button><button className="btn btn-ghost" onClick={() => setRoleModal(null)}>Скасувати</button></div>}
      >
        {roleModal && (
          <>
            {roleModal.error && <div className="alert alert-error">{roleModal.error}</div>}
            <div style={{ padding: '10px 14px', background: 'var(--bg-elevated)', borderRadius: 'var(--radius-sm)', marginBottom: 16, fontSize: 13 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', padding: '4px 0' }}><span style={{ color: 'var(--text-secondary)' }}>Slug</span><code style={{ color: 'var(--accent)' }}>{roleModal.slug}</code></div>
              <div style={{ display: 'flex', justifyContent: 'space-between', padding: '4px 0' }}><span style={{ color: 'var(--text-secondary)' }}>Рівень доступу</span><span>{roleModal.level}</span></div>
            </div>
            <FormGroup label="Назва (укр.) *"><input type="text" placeholder="Менеджер" value={roleModal.nameUa} onChange={(e) => setRoleModal({ ...roleModal, nameUa: e.target.value })} /></FormGroup>
          </>
        )}
      </Modal>

      {/* Користувач */}
      <Modal
        open={!!userModal}
        onClose={() => setUserModal(null)}
        title={userModal?.id ? 'Редагувати користувача' : 'Новий користувач'}
        footer={<div style={{ display: 'flex', gap: 10 }}><button className="btn btn-primary" disabled={userModal?.submitting} onClick={submitUser}>Зберегти</button><button className="btn btn-ghost" onClick={() => setUserModal(null)}>Скасувати</button></div>}
      >
        {userModal && (
          <>
            {userModal.error && <div className="alert alert-error">{userModal.error}</div>}
            <FormGroup label="Ім'я та прізвище *"><input type="text" placeholder="Іван Петренко" value={userModal.name} onChange={(e) => setUserModal({ ...userModal, name: e.target.value })} /></FormGroup>
            <FormGroup label="Email *"><input type="email" placeholder="user@example.com" value={userModal.email} onChange={(e) => setUserModal({ ...userModal, email: e.target.value })} /></FormGroup>
            <FormGroup label="Телефон"><input type="tel" placeholder="+38 067..." value={userModal.phone} onChange={(e) => setUserModal({ ...userModal, phone: e.target.value })} /></FormGroup>
            <FormGroup label="Глобальна роль">
              <select value={userModal.globalRoleId} onChange={(e) => setUserModal({ ...userModal, globalRoleId: e.target.value })}>
                {GLOBAL_ROLE_OPTIONS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </FormGroup>
            {!userModal.id && (
              <FormGroup label="Пароль *"><input type="password" placeholder="Мін. 8 символів" autoComplete="new-password" value={userModal.password} onChange={(e) => setUserModal({ ...userModal, password: e.target.value })} /></FormGroup>
            )}
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <input type="checkbox" id="um-active" checked={userModal.active} onChange={(e) => setUserModal({ ...userModal, active: e.target.checked })} />
              <label htmlFor="um-active" style={{ margin: 0, fontWeight: 400 }}>Активний</label>
            </div>
          </>
        )}
      </Modal>

      {/* Прив'язка до клубу */}
      <Modal
        open={!!ucModal}
        onClose={() => setUcModal(null)}
        title={ucModal?.id ? "Редагувати прив'язку" : "Нова прив'язка"}
        footer={<div style={{ display: 'flex', gap: 10 }}><button className="btn btn-primary" disabled={ucModal?.submitting} onClick={submitUc}>Зберегти</button><button className="btn btn-ghost" onClick={() => setUcModal(null)}>Скасувати</button></div>}
      >
        {ucModal && (
          <>
            {ucModal.error && <div className="alert alert-error">{ucModal.error}</div>}
            <FormGroup label="Користувач *">
              <select value={ucModal.userId} onChange={(e) => setUcModal({ ...ucModal, userId: e.target.value })}>
                {ucModal.usersList.map((u) => <option key={u.id} value={u.id}>{u.full_name} ({u.email})</option>)}
              </select>
            </FormGroup>
            <FormGroup label="Клуб *">
              <select value={ucModal.clubId} onChange={(e) => setUcModal({ ...ucModal, clubId: e.target.value })}>
                {ucModal.clubsList.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </FormGroup>
            <FormGroup label="Роль *">
              <select value={ucModal.roleId} onChange={(e) => setUcModal({ ...ucModal, roleId: e.target.value })}>
                {ucModal.rolesList.map((r) => <option key={r.id} value={r.id}>{r.name_ua}</option>)}
              </select>
            </FormGroup>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <input type="checkbox" id="uc-active" checked={ucModal.active} onChange={(e) => setUcModal({ ...ucModal, active: e.target.checked })} />
              <label htmlFor="uc-active" style={{ margin: 0, fontWeight: 400 }}>Активний доступ</label>
            </div>
          </>
        )}
      </Modal>

      {/* Підтвердження */}
      <Modal
        size="sm"
        open={!!confirmModal}
        onClose={() => setConfirmModal(null)}
        title="Підтвердити дію"
        footer={<div style={{ display: 'flex', gap: 10 }}><button className="btn btn-danger" onClick={confirmModal?.onConfirm}>Підтвердити</button><button className="btn btn-ghost" onClick={() => setConfirmModal(null)}>Скасувати</button></div>}
      >
        {confirmModal && <p style={{ fontSize: 14, color: 'var(--text-secondary)' }}>{confirmModal.text}</p>}
      </Modal>
    </AppLayout>
  );
}
