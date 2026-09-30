import { useCallback, useEffect, useState } from 'react';
import AppLayout from '../components/layout/AppLayout';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import Badge from '../components/ui/Badge';
import ClientSearchPicker from '../components/ui/ClientSearchPicker';
import { usePermissions } from '../hooks/usePermissions';
import { useToast } from '../components/ui/ToastProvider';
import {
  getGroupSessions, getGroupSession,
  createGroupSession, updateGroupSession, cancelGroupSession,
  addGroupSessionClient, removeGroupSessionClient,
  markGroupSessionAttendance, completeGroupSession,
} from '../api/groupSessions';
import { getBookingSetup, getTrainerSlots, bookPersonal } from '../api/booking';
import { formatDate, localDate, localToday } from '../utils/format';

const hhmm = (t) => (t ? String(t).slice(0, 5) : '');
import BookingSettingsTab, { WEEKDAYS } from './BookingSettingsTab';
import './InvoicesPage.css';

const STATUS_LABEL = { scheduled: 'Заплановано', completed: 'Завершено', canceled: 'Скасовано' };
const STATUS_VARIANT = { scheduled: 'info', completed: 'active', canceled: 'inactive' };
const ROSTER_BADGE = {
  booked: ['info', 'Записаний'],
  waitlist: ['pending', 'Лист очікування'],
  attended: ['active', 'Присутній'],
  no_show: ['inactive', 'Не прийшов'],
  canceled: ['inactive', 'Скасовано'],
};
const SOURCE_LABEL = { admin: 'адмін', telegram: 'Telegram', app: 'застосунок' };

function mondayOf(dateStr) {
  const d = new Date(`${dateStr}T00:00:00`);
  const wd = (d.getDay() + 6) % 7;
  d.setDate(d.getDate() - wd);
  return localDate(d);
}
function addDays(dateStr, n) {
  const d = new Date(`${dateStr}T00:00:00`);
  d.setDate(d.getDate() + n);
  return localDate(d);
}

export default function GroupSessionsPage() {
  const { has, isOwner } = usePermissions();
  const canManage = has('group_sessions.manage');
  const toast = useToast();

  const [tab, setTab] = useState('calendar');
  const [setup, setSetup] = useState(null);
  const [weekStart, setWeekStart] = useState(mondayOf(localToday()));
  const [filters, setFilters] = useState({ room_id: '', trainer_id: '', kind: '' });
  const [sessions, setSessions] = useState([]);
  const [loading, setLoading] = useState(true);

  const [form, setForm] = useState(null);
  const [personal, setPersonal] = useState(null);
  const [detailId, setDetailId] = useState(null);
  const [detail, setDetail] = useState(null);
  const [addClient, setAddClient] = useState(null);

  const loadSetup = useCallback(async () => {
    const res = await getBookingSetup();
    if (res.success) setSetup(res);
    else setSetup({ settings: {}, rooms: [], types: [], templates: [], trainers: [], availability: [], tariffs: [], error: res.error });
  }, []);

  const reload = useCallback(async () => {
    setLoading(true);
    const res = await getGroupSessions({ date_from: weekStart, date_to: addDays(weekStart, 6), ...filters });
    setLoading(false);
    if (!res.success) { toast(res.error, 'error'); setSessions([]); return; }
    setSessions(res.sessions || []);
  }, [weekStart, filters, toast]);

  useEffect(() => { loadSetup(); }, [loadSetup]);
  useEffect(() => { reload(); }, [reload]);

  const rooms = (setup?.rooms || []).filter((r) => Number(r.is_active));
  const trainers = setup?.trainers || [];
  const types = (setup?.types || []).filter((t) => Number(t.is_active));
  const personalTypes = types.filter((t) => t.kind === 'personal');
  const groupTypes = types.filter((t) => t.kind === 'group');

  // ── Деталі заняття ──
  async function openDetail(s) {
    setDetailId(s.id);
    setDetail(null);
    const res = await getGroupSession(s.id);
    if (!res.success) { toast(res.error, 'error'); setDetailId(null); return; }
    setDetail(res);
  }
  async function reloadDetail() {
    if (!detailId) return;
    const res = await getGroupSession(detailId);
    if (res.success) setDetail(res);
  }

  // ── Створення / редагування заняття ──
  function openCreate(date = localToday()) {
    setForm({ id: null, class_type_id: '', trainer_id: '', room_id: '', name: '', session_date: date, start_time: '18:00', end_time: '', capacity: '', notes: '', error: '', submitting: false });
  }
  function openEdit(s) {
    setForm({
      id: s.id, class_type_id: s.class_type_id ? String(s.class_type_id) : '', trainer_id: String(s.trainer_id), room_id: s.room_id ? String(s.room_id) : '',
      name: s.name, session_date: s.session_date, start_time: s.start_time?.slice(0, 5) || '', end_time: s.end_time?.slice(0, 5) || '',
      capacity: s.capacity ?? '', notes: s.notes || '', kind: s.kind, error: '', submitting: false,
    });
  }
  function onFormType(id) {
    const t = types.find((x) => String(x.id) === id);
    setForm((f) => ({
      ...f, class_type_id: id,
      name: t ? t.name : f.name,
      capacity: t && t.capacity != null ? String(t.capacity) : f.capacity,
      room_id: t?.default_room_id ? String(t.default_room_id) : f.room_id,
      end_time: '',
    }));
  }
  async function submitForm() {
    if (!form.trainer_id) { setForm({ ...form, error: 'Оберіть тренера' }); return; }
    if (!form.name.trim() && !form.class_type_id) { setForm({ ...form, error: 'Вкажіть назву або тип заняття' }); return; }
    setForm({ ...form, submitting: true, error: '' });
    const payload = {
      class_type_id: form.class_type_id || 0, trainer_id: form.trainer_id, room_id: form.room_id || 0,
      name: form.name.trim(), session_date: form.session_date, start_time: form.start_time, end_time: form.end_time || '',
      capacity: form.capacity === '' ? '' : parseInt(form.capacity), notes: form.notes.trim(),
    };
    const res = form.id ? await updateGroupSession({ session_id: form.id, ...payload }) : await createGroupSession(payload);
    if (!res.success) { setForm({ ...form, submitting: false, error: res.error }); return; }
    toast(res.message || 'Збережено', 'success');
    setForm(null);
    reload();
    reloadDetail();
  }
  async function handleCancelSession(s) {
    if (!window.confirm(`Скасувати заняття «${s.name}» (${formatDate(s.session_date)})? Усі записи буде скасовано.`)) return;
    const res = await cancelGroupSession(s.id);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message, 'success');
    reload();
    reloadDetail();
  }
  async function handleComplete() {
    if (!window.confirm('Завершити заняття? Після цього нарахування тренеру буде зафіксовано.')) return;
    const res = await completeGroupSession(detailId);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message, 'success');
    reload();
    reloadDetail();
  }

  // ── Запис клієнта ──
  async function submitAddClient() {
    if (!addClient?.client) return;
    const res = await addGroupSessionClient(detailId, addClient.client.id);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message, res.status === 'waitlist' ? 'info' : 'success');
    setAddClient(null);
    reload();
    reloadDetail();
  }
  async function handleCancelBooking(r) {
    if (!window.confirm(`Скасувати запис «${r.client_name}»?`)) return;
    let res = await removeGroupSessionClient(r.id);
    if (!res.success && res.can_force && isOwner) {
      if (!window.confirm(`${res.error}.\n\nВи власник клубу — скасувати попри обмеження?`)) return;
      res = await removeGroupSessionClient(r.id, true);
    }
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message, 'success');
    reload();
    reloadDetail();
  }
  async function handleAttendance(rosterId, status) {
    const res = await markGroupSessionAttendance(rosterId, status);
    if (!res.success) { toast(res.error, 'error'); return; }
    reload();
    reloadDetail();
  }

  // ── Персональне тренування ──
  function openPersonal() {
    const t = personalTypes[0];
    setPersonal({ class_type_id: t ? String(t.id) : '', trainer_id: '', date: localToday(), slots: null, start: '', custom: '', client: null, error: '', submitting: false });
  }
  async function loadSlots(p) {
    setPersonal({ ...p, slots: null, start: '' });
    if (!p.class_type_id || !p.trainer_id || !p.date) return;
    const res = await getTrainerSlots({ class_type_id: p.class_type_id, trainer_id: p.trainer_id, date: p.date });
    setPersonal((cur) => ({ ...cur, slots: res.success ? res.slots : [], error: res.success ? '' : res.error }));
  }
  async function submitPersonal() {
    const start = personal.start || personal.custom;
    if (!personal.class_type_id || !personal.trainer_id) { setPersonal({ ...personal, error: 'Оберіть тип і тренера' }); return; }
    if (!start) { setPersonal({ ...personal, error: 'Оберіть час' }); return; }
    if (!personal.client) { setPersonal({ ...personal, error: 'Оберіть клієнта' }); return; }
    setPersonal({ ...personal, submitting: true, error: '' });
    const res = await bookPersonal({
      class_type_id: personal.class_type_id, trainer_id: personal.trainer_id, date: personal.date,
      start_time: start, client_id: personal.client.id,
    });
    if (!res.success) { setPersonal({ ...personal, submitting: false, error: res.error }); return; }
    toast(res.message, 'success');
    setPersonal(null);
    reload();
  }

  const days = [0, 1, 2, 3, 4, 5, 6].map((i) => addDays(weekStart, i));
  const today = localToday();
  const weekLabel = `${formatDate(days[0])} — ${formatDate(days[6])}`;

  function SessionCard({ s }) {
    const full = s.capacity && Number(s.roster_count) >= Number(s.capacity);
    return (
      <div
        onClick={() => openDetail(s)}
        style={{
          borderLeft: `3px solid ${s.color || (s.kind === 'personal' ? '#bf5af2' : '#0a84ff')}`,
          background: 'var(--bg-elevated)', border: '1px solid var(--border)', borderLeftWidth: 3,
          borderRadius: 'var(--radius-sm)', padding: '6px 8px', marginBottom: 6, cursor: 'pointer', fontSize: 12,
          opacity: s.status === 'canceled' ? 0.45 : 1,
        }}
      >
        <div style={{ fontWeight: 600 }}>{hhmm(s.start_time)}{s.end_time ? `–${hhmm(s.end_time)}` : ''}</div>
        <div style={{ fontWeight: 500, fontSize: 13, textDecoration: s.status === 'canceled' ? 'line-through' : 'none' }}>{s.name}</div>
        <div style={{ color: 'var(--text-muted)' }}>{s.trainer_name}{s.room_name ? ` · ${s.room_name}` : ''}</div>
        {s.kind === 'personal'
          ? <div style={{ color: 'var(--text-secondary)' }}>👤 {s.personal_client || '—'}</div>
          : (
            <div style={{ color: full ? 'var(--danger)' : 'var(--text-secondary)' }}>
              {s.roster_count}{s.capacity ? ` / ${s.capacity}` : ''} {full ? '· місць немає' : ''}
              {Number(s.waitlist_count) > 0 && ` · черга ${s.waitlist_count}`}
            </div>
          )}
        {s.status === 'completed' && <div style={{ color: 'var(--success)' }}>✓ завершено</div>}
      </div>
    );
  }

  const session = detail?.session;
  const activeRoster = detail ? detail.roster.filter((r) => ['booked', 'attended', 'no_show'].includes(r.status)) : [];

  return (
    <AppLayout title="Розклад і запис">
      <div className="inv-tabs">
        <button className={`inv-tab ${tab === 'calendar' ? 'active' : ''}`} onClick={() => setTab('calendar')}>Розклад</button>
        {canManage && <button className={`inv-tab ${tab === 'settings' ? 'active' : ''}`} onClick={() => setTab('settings')}>Налаштування</button>}
      </div>

      {tab === 'settings' && setup && (
        setup.error
          ? <div className="alert alert-error">{setup.error}</div>
          : <BookingSettingsTab setup={setup} onChanged={() => { loadSetup(); reload(); }} />
      )}

      {tab === 'calendar' && (
        <>
          <div className="inv-toolbar">
            <button className="btn btn-ghost btn-sm" onClick={() => setWeekStart(addDays(weekStart, -7))}>‹</button>
            <button className="btn btn-ghost btn-sm" onClick={() => setWeekStart(mondayOf(localToday()))}>Сьогодні</button>
            <button className="btn btn-ghost btn-sm" onClick={() => setWeekStart(addDays(weekStart, 7))}>›</button>
            <strong style={{ fontSize: 14 }}>{weekLabel}</strong>
            <select style={{ width: 'auto' }} value={filters.room_id} onChange={(e) => setFilters({ ...filters, room_id: e.target.value })}>
              <option value="">Усі зали</option>
              {rooms.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
            </select>
            <select style={{ width: 'auto' }} value={filters.trainer_id} onChange={(e) => setFilters({ ...filters, trainer_id: e.target.value })}>
              <option value="">Усі тренери</option>
              {trainers.map((t) => <option key={t.id} value={t.id}>{t.full_name}</option>)}
            </select>
            <select style={{ width: 'auto' }} value={filters.kind} onChange={(e) => setFilters({ ...filters, kind: e.target.value })}>
              <option value="">Групові й персональні</option>
              <option value="group">Групові</option>
              <option value="personal">Персональні</option>
            </select>
            {canManage && (
              <div style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
                <button className="btn btn-ghost" onClick={() => openCreate()}>+ Заняття</button>
                <button className="btn btn-primary" onClick={openPersonal} disabled={!personalTypes.length}
                  title={personalTypes.length ? '' : 'Спершу додайте тип «Персональне» в Налаштуваннях'}>+ Персональне</button>
              </div>
            )}
          </div>

          {loading && <div className="loader"><span className="spinner" /> Завантаження...</div>}
          {!loading && (
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 8 }}>
              {days.map((d, i) => {
                const list = sessions.filter((s) => s.session_date === d);
                return (
                  <div key={d} className="card" style={{ padding: 8, minHeight: 120, outline: d === today ? '2px solid var(--accent)' : 'none' }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                      <strong style={{ fontSize: 13 }}>{WEEKDAYS[i + 1]} {formatDate(d).slice(0, 5)}</strong>
                      {canManage && d >= today && <button className="btn btn-ghost btn-sm" title="Додати заняття" onClick={() => openCreate(d)}>+</button>}
                    </div>
                    {list.length === 0 && <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>—</div>}
                    {list.map((s) => <SessionCard key={s.id} s={s} />)}
                  </div>
                );
              })}
            </div>
          )}
        </>
      )}

      {/* Заняття: створити / редагувати */}
      <Modal open={!!form} onClose={() => setForm(null)} title={form?.id ? 'Редагувати заняття' : 'Нове заняття'} footer={
        <div style={{ display: 'flex', gap: 10 }}>
          <button className="btn btn-primary" disabled={form?.submitting} onClick={submitForm}>{form?.submitting ? 'Збереження...' : 'Зберегти'}</button>
          <button className="btn btn-ghost" onClick={() => setForm(null)}>Скасувати</button>
        </div>
      }>
        {form && (
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 16px' }}>
            {form.error && <div className="alert alert-error" style={{ gridColumn: '1/-1' }}>{form.error}</div>}
            {form.kind !== 'personal' && (
              <FormGroup label="Тип заняття" fullWidth>
                <select value={form.class_type_id} onChange={(e) => onFormType(e.target.value)}>
                  <option value="">— Без типу (разове) —</option>
                  {groupTypes.map((t) => <option key={t.id} value={t.id}>{t.name} · {t.duration_min} хв</option>)}
                </select>
              </FormGroup>
            )}
            <FormGroup label="Назва *" fullWidth>
              <input type="text" placeholder="Наприклад: Йога" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </FormGroup>
            <FormGroup label="Тренер *">
              <select value={form.trainer_id} onChange={(e) => setForm({ ...form, trainer_id: e.target.value })}>
                <option value="">— Оберіть —</option>
                {trainers.map((t) => <option key={t.id} value={t.id}>{t.full_name}</option>)}
              </select>
            </FormGroup>
            <FormGroup label="Зал">
              <select value={form.room_id} onChange={(e) => setForm({ ...form, room_id: e.target.value })}>
                <option value="">— Без залу —</option>
                {rooms.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
              </select>
            </FormGroup>
            <FormGroup label="Дата *">
              <input type="date" value={form.session_date} onChange={(e) => setForm({ ...form, session_date: e.target.value })} />
            </FormGroup>
            <FormGroup label="Початок *">
              <input type="time" value={form.start_time} onChange={(e) => setForm({ ...form, start_time: e.target.value })} />
            </FormGroup>
            <FormGroup label="Завершення">
              <input type="time" value={form.end_time} placeholder="з типу" onChange={(e) => setForm({ ...form, end_time: e.target.value })} />
            </FormGroup>
            {form.kind !== 'personal' && (
              <FormGroup label="Місць">
                <input type="number" min="1" placeholder="без обмеження" value={form.capacity} onChange={(e) => setForm({ ...form, capacity: e.target.value })} />
              </FormGroup>
            )}
            <FormGroup label="Примітки" fullWidth>
              <textarea rows="2" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
            </FormGroup>
          </div>
        )}
      </Modal>

      {/* Персональне тренування */}
      <Modal open={!!personal} onClose={() => setPersonal(null)} title="Запис на персональне тренування" footer={
        <div style={{ display: 'flex', gap: 10 }}>
          <button className="btn btn-primary" disabled={personal?.submitting} onClick={submitPersonal}>{personal?.submitting ? 'Записуємо...' : 'Записати'}</button>
          <button className="btn btn-ghost" onClick={() => setPersonal(null)}>Скасувати</button>
        </div>
      }>
        {personal && (
          <>
            {personal.error && <div className="alert alert-error">{personal.error}</div>}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Вид *">
                <select value={personal.class_type_id} onChange={(e) => loadSlots({ ...personal, class_type_id: e.target.value })}>
                  {personalTypes.map((t) => <option key={t.id} value={t.id}>{t.name} · {t.duration_min} хв</option>)}
                </select>
              </FormGroup>
              <FormGroup label="Тренер *">
                <select value={personal.trainer_id} onChange={(e) => loadSlots({ ...personal, trainer_id: e.target.value })}>
                  <option value="">— Оберіть —</option>
                  {trainers.map((t) => <option key={t.id} value={t.id}>{t.full_name}</option>)}
                </select>
              </FormGroup>
              <FormGroup label="Дата *">
                <input type="date" min={localToday()} value={personal.date} onChange={(e) => loadSlots({ ...personal, date: e.target.value })} />
              </FormGroup>
              <FormGroup label="Інший час (поза графіком)">
                <input type="time" value={personal.custom} onChange={(e) => setPersonal({ ...personal, custom: e.target.value, start: '' })} />
              </FormGroup>
            </div>
            <FormGroup label="Вільний час">
              {!personal.trainer_id && <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>Оберіть тренера</div>}
              {personal.trainer_id && personal.slots === null && <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>Завантаження...</div>}
              {personal.slots?.length === 0 && <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>Вільних годин немає (або графік тренера не задано)</div>}
              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                {personal.slots?.map((s) => (
                  <button key={s.start} type="button" className={`inv-filter-btn ${personal.start === s.start ? 'active' : ''}`}
                    onClick={() => setPersonal({ ...personal, start: s.start, custom: '' })}>
                    {s.start}
                  </button>
                ))}
              </div>
            </FormGroup>
            <FormGroup label="Клієнт *">
              <ClientSearchPicker value={personal.client} onChange={(c) => setPersonal({ ...personal, client: c })} />
            </FormGroup>
          </>
        )}
      </Modal>

      {/* Деталі заняття */}
      <Modal size="lg" open={!!detailId} onClose={() => { setDetailId(null); setDetail(null); }} title={session?.name || 'Заняття'}
        footer={session?.status === 'scheduled' && canManage && (
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
            {session.kind !== 'personal' && <button className="btn btn-primary" onClick={handleComplete}>Завершити заняття</button>}
            {session.kind !== 'personal' && <button className="btn btn-ghost" onClick={() => setAddClient({ client: null })}>+ Записати клієнта</button>}
            <button className="btn btn-ghost" onClick={() => openEdit(session)}>✎ Редагувати</button>
            <button className="btn btn-ghost" style={{ color: 'var(--danger)' }} onClick={() => handleCancelSession(session)}>Скасувати заняття</button>
          </div>
        )}
      >
        {!detail && <div className="loader"><span className="spinner" /> Завантаження...</div>}
        {detail && (
          <>
            <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap', marginBottom: 14, fontSize: 13, color: 'var(--text-muted)' }}>
              <span>{formatDate(session.session_date)} {hhmm(session.start_time)}{session.end_time ? `–${hhmm(session.end_time)}` : ''}</span>
              <span>Тренер: {session.trainer_name}</span>
              {session.room_name && <span>Зал: {session.room_name}</span>}
              {session.kind !== 'personal' && <span>Записано: {activeRoster.length}{session.capacity ? ` / ${session.capacity}` : ''}</span>}
              <Badge variant={STATUS_VARIANT[session.status]}>{STATUS_LABEL[session.status]}</Badge>
              {session.kind === 'personal' && <Badge variant="pending">Персональне</Badge>}
            </div>
            {session.status === 'scheduled' && session.cancel_deadline_at && (
              <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 10 }}>
                Скасувати запис можна до {formatDate(session.cancel_deadline_at.replace(' ', 'T'))} {session.cancel_deadline_at.slice(11, 16)}
              </div>
            )}
            <table style={{ width: '100%' }}>
              <thead>
                <tr>
                  <th style={{ textAlign: 'left' }}>Клієнт</th>
                  <th style={{ textAlign: 'left' }}>Абонемент</th>
                  <th style={{ textAlign: 'left' }}>Статус</th>
                  {canManage && session.status === 'scheduled' && <th />}
                </tr>
              </thead>
              <tbody>
                {detail.roster.length === 0 && <tr><td colSpan={4} style={{ padding: '12px 0', color: 'var(--text-muted)' }}>Ще нікого не записано</td></tr>}
                {detail.roster.map((r) => {
                  const [variant, label] = ROSTER_BADGE[r.status] || ['info', r.status];
                  return (
                    <tr key={r.id} style={{ opacity: r.status === 'canceled' ? 0.5 : 1 }}>
                      <td>
                        {r.client_name}
                        <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{SOURCE_LABEL[r.source] || r.source}</div>
                      </td>
                      <td>{r.tariff_name || <span style={{ color: 'var(--warning)' }}>без абонемента</span>}</td>
                      <td><Badge variant={variant}>{label}</Badge></td>
                      {canManage && session.status === 'scheduled' && (
                        <td style={{ whiteSpace: 'nowrap' }}>
                          {r.status === 'booked' && (
                            <>
                              <button className="btn btn-ghost btn-sm" onClick={() => handleAttendance(r.id, 'attended')}>Прийшов</button>
                              <button className="btn btn-ghost btn-sm" onClick={() => handleAttendance(r.id, 'no_show')}>Не прийшов</button>
                            </>
                          )}
                          {['booked', 'waitlist'].includes(r.status) && (
                            <button className="btn btn-ghost btn-sm" style={{ color: 'var(--text-muted)' }} title="Скасувати запис" onClick={() => handleCancelBooking(r)}>✕</button>
                          )}
                        </td>
                      )}
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </>
        )}
      </Modal>

      {/* Записати клієнта */}
      <Modal size="sm" open={!!addClient} onClose={() => setAddClient(null)} title="Записати клієнта" footer={
        <div style={{ display: 'flex', gap: 10 }}>
          <button className="btn btn-primary" disabled={!addClient?.client} onClick={submitAddClient}>Записати</button>
          <button className="btn btn-ghost" onClick={() => setAddClient(null)}>Скасувати</button>
        </div>
      }>
        {addClient && (
          <>
            <ClientSearchPicker value={addClient.client} onChange={(c) => setAddClient({ client: c })}
              excludeIds={detail ? detail.roster.filter((r) => r.status !== 'canceled').map((r) => r.client_id) : []} />
            <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 8 }}>Якщо місць немає — клієнт потрапить у лист очікування.</div>
          </>
        )}
      </Modal>
    </AppLayout>
  );
}
