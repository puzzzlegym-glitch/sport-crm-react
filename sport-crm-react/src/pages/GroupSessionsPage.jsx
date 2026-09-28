import { useEffect, useState } from 'react';
import AppLayout from '../components/layout/AppLayout';
import Table from '../components/ui/Table';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import Badge from '../components/ui/Badge';
import { usePermissions } from '../hooks/usePermissions';
import { useToast } from '../components/ui/ToastProvider';
import {
  getGroupSessions, getGroupSession, getGroupSessionTrainers,
  createGroupSession, updateGroupSession, cancelGroupSession,
  addGroupSessionClient, removeGroupSessionClient,
  markGroupSessionAttendance, completeGroupSession,
} from '../api/groupSessions';
import { getClients } from '../api/clients';
import { formatDate, localToday } from '../utils/format';

// start_time/end_time — MySQL TIME ('18:00:00'), не дата: formatTime() дає Invalid Date
const hhmm = (t) => (t ? String(t).slice(0, 5) : '');

const EMPTY_FORM = {
  id: null, trainer_id: '', name: '', session_date: localToday(),
  start_time: '18:00', end_time: '', capacity: '', notes: '',
};

const STATUS_LABEL = { scheduled: 'Заплановано', completed: 'Завершено', canceled: 'Скасовано' };
const STATUS_VARIANT = { scheduled: 'info', completed: 'active', canceled: 'inactive' };

export default function GroupSessionsPage() {
  const { has } = usePermissions();
  const canManage = has('group_sessions.manage');
  const toast = useToast();

  const [dateFrom, setDateFrom] = useState(localToday());
  const [dateTo, setDateTo] = useState(() => {
    const d = new Date();
    d.setDate(d.getDate() + 30);
    return d.toISOString().slice(0, 10);
  });
  const [sessions, setSessions] = useState([]);
  const [loading, setLoading] = useState(true);
  const [trainers, setTrainers] = useState([]);

  const [form, setForm] = useState(null);
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const [detailId, setDetailId] = useState(null);
  const [detail, setDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);

  const [addClient, setAddClient] = useState(null); // { clients: null|[], clientId }

  async function reload() {
    setLoading(true);
    const res = await getGroupSessions({ date_from: dateFrom, date_to: dateTo });
    setLoading(false);
    if (!res.success) {
      toast(res.error, 'error');
      setSessions([]);
      return;
    }
    setSessions(res.sessions);
  }

  useEffect(() => { reload(); }, [dateFrom, dateTo]);

  useEffect(() => {
    getGroupSessionTrainers().then((res) => { if (res.success) setTrainers(res.trainers || []); });
  }, []);

  async function openDetail(session) {
    setDetailId(session.id);
    setDetailLoading(true);
    const res = await getGroupSession(session.id);
    setDetailLoading(false);
    if (!res.success) { toast(res.error, 'error'); setDetailId(null); return; }
    setDetail(res);
  }

  async function reloadDetail() {
    if (!detailId) return;
    const res = await getGroupSession(detailId);
    if (res.success) setDetail(res);
  }

  function openCreate() {
    setForm({ ...EMPTY_FORM });
    setError('');
  }

  function openEdit(s) {
    setForm({
      id: s.id, trainer_id: s.trainer_id, name: s.name,
      session_date: s.session_date, start_time: s.start_time?.slice(0, 5) || '',
      end_time: s.end_time?.slice(0, 5) || '', capacity: s.capacity ?? '', notes: s.notes || '',
    });
    setError('');
  }

  async function submit() {
    if (!form.trainer_id) { setError('Оберіть тренера'); return; }
    if (!form.name.trim()) { setError("Вкажіть назву заняття"); return; }
    setSubmitting(true);
    setError('');
    const payload = {
      id: form.id,
      trainer_id: form.trainer_id,
      name: form.name.trim(),
      session_date: form.session_date,
      start_time: form.start_time,
      end_time: form.end_time || '',
      capacity: form.capacity === '' ? '' : parseInt(form.capacity),
      notes: form.notes.trim(),
    };
    const res = form.id
      ? await updateGroupSession({ session_id: form.id, ...payload })
      : await createGroupSession(payload);
    setSubmitting(false);
    if (!res.success) { setError(res.error); return; }
    toast(res.message || 'Збережено', 'success');
    setForm(null);
    reload();
    if (detailId === form.id) reloadDetail();
  }

  async function handleCancel(s) {
    if (!confirm(`Скасувати заняття "${s.name}" (${formatDate(s.session_date)})?`)) return;
    const res = await cancelGroupSession(s.id);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message, 'success');
    reload();
    if (detailId === s.id) reloadDetail();
  }

  async function handleComplete() {
    if (!confirm('Завершити заняття? Після цього нарахування тренеру буде зафіксовано, а редагувати заняття вже не можна.')) return;
    const res = await completeGroupSession(detailId);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message, 'success');
    reload();
    reloadDetail();
  }

  async function openAddClient() {
    setAddClient({ clients: null, clientId: '' });
    const res = await getClients({ per_page: 200, order: 'full_name', dir: 'asc' });
    setAddClient((s) => ({ ...s, clients: res.success ? res.clients : [] }));
  }

  async function submitAddClient() {
    if (!addClient.clientId) return;
    const res = await addGroupSessionClient(detailId, addClient.clientId);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message, 'success');
    setAddClient(null);
    reload();
    reloadDetail();
  }

  async function handleRemoveClient(rosterId) {
    const res = await removeGroupSessionClient(rosterId);
    if (!res.success) { toast(res.error, 'error'); return; }
    reloadDetail();
    reload();
  }

  async function handleAttendance(rosterId, status) {
    const res = await markGroupSessionAttendance(rosterId, status);
    if (!res.success) { toast(res.error, 'error'); return; }
    reloadDetail();
  }

  const columns = [
    {
      key: 'name', label: 'Заняття',
      render: (s) => (
        <>
          <span className="tariff-name">{s.name}</span>
          <Badge variant={STATUS_VARIANT[s.status]}>{STATUS_LABEL[s.status]}</Badge>
        </>
      ),
    },
    { key: 'date', label: 'Дата / Час', mobile: 'secondary', render: (s) => `${formatDate(s.session_date)} ${hhmm(s.start_time)}${s.end_time ? `–${hhmm(s.end_time)}` : ''}` },
    { key: 'trainer', label: 'Тренер', mobile: 'secondary', render: (s) => s.trainer_name },
    {
      key: 'roster', label: 'Учасники', mobile: 'trailing',
      render: (s) => `${s.roster_count}${s.capacity ? ` / ${s.capacity}` : ''}`,
    },
    {
      key: 'actions', label: '',
      render: (s) => canManage && s.status === 'scheduled' && (
        <div style={{ display: 'flex', gap: 4, whiteSpace: 'nowrap' }} onClick={(e) => e.stopPropagation()}>
          <button className="btn btn-ghost btn-sm" title="Редагувати" onClick={() => openEdit(s)}>✎</button>
          <button className="btn btn-ghost btn-sm" title="Скасувати" style={{ color: 'var(--text-muted)' }} onClick={() => handleCancel(s)}>✕</button>
        </div>
      ),
    },
  ];

  return (
    <AppLayout title="Групові заняття">
      <div className="page-header prod-page-header">
        <div className="prod-header-top" style={{ gap: 12, flexWrap: 'wrap' }}>
          <FormGroup label="З">
            <input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
          </FormGroup>
          <FormGroup label="По">
            <input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
          </FormGroup>
          {canManage && <button className="btn btn-primary" onClick={openCreate}>+ Нове заняття</button>}
        </div>
      </div>

      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <Table
          columns={columns}
          rows={sessions}
          loading={loading}
          emptyMessage="Занять за цей період немає"
          onRowClick={openDetail}
        />
      </div>

      {/* Створення/редагування заняття */}
      <Modal size="md"
        open={!!form}
        onClose={() => setForm(null)}
        title={form?.id ? 'Редагувати заняття' : 'Нове групове заняття'}
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={submitting} onClick={submit}>{submitting ? 'Збереження...' : 'Зберегти'}</button>
            <button className="btn btn-ghost" onClick={() => setForm(null)}>Скасувати</button>
          </div>
        }
      >
        {form && (
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 16px' }}>
            {error && <div className="alert alert-error" style={{ gridColumn: '1/-1' }}>{error}</div>}
            <FormGroup label="Назва *" fullWidth>
              <input type="text" placeholder="Наприклад: Йога" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </FormGroup>
            <FormGroup label="Тренер *" fullWidth>
              <select value={form.trainer_id} onChange={(e) => setForm({ ...form, trainer_id: e.target.value })}>
                <option value="">— Оберіть тренера —</option>
                {trainers.map((t) => <option key={t.id} value={t.id}>{t.full_name}</option>)}
              </select>
            </FormGroup>
            <FormGroup label="Дата *">
              <input type="date" value={form.session_date} onChange={(e) => setForm({ ...form, session_date: e.target.value })} />
            </FormGroup>
            <FormGroup label="Час початку *">
              <input type="time" value={form.start_time} onChange={(e) => setForm({ ...form, start_time: e.target.value })} />
            </FormGroup>
            <FormGroup label="Час завершення">
              <input type="time" value={form.end_time} onChange={(e) => setForm({ ...form, end_time: e.target.value })} />
            </FormGroup>
            <FormGroup label="Місткість">
              <input type="number" min="1" step="1" placeholder="без обмеження" value={form.capacity} onChange={(e) => setForm({ ...form, capacity: e.target.value })} />
            </FormGroup>
            <FormGroup label="Примітки" fullWidth>
              <textarea rows="2" placeholder="Необов'язково..." value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
            </FormGroup>
          </div>
        )}
      </Modal>

      {/* Деталі заняття + ростер */}
      <Modal size="lg"
        open={!!detailId}
        onClose={() => { setDetailId(null); setDetail(null); }}
        title={detail?.session?.name || 'Заняття'}
        footer={detail?.session?.status === 'scheduled' && canManage && (
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" onClick={handleComplete}>Завершити заняття</button>
            <button className="btn btn-ghost" onClick={openAddClient}>+ Додати клієнта</button>
          </div>
        )}
      >
        {detailLoading && <div className="loader"><span className="spinner" /> Завантаження...</div>}
        {detail && (
          <>
            <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap', marginBottom: 14, fontSize: 13, color: 'var(--text-muted)' }}>
              <span>{formatDate(detail.session.session_date)} {hhmm(detail.session.start_time)}{detail.session.end_time ? `–${hhmm(detail.session.end_time)}` : ''}</span>
              <span>Тренер: {detail.session.trainer_name}</span>
              <span>Учасників: {detail.roster.filter((r) => r.status !== 'canceled').length}{detail.session.capacity ? ` / ${detail.session.capacity}` : ''}</span>
              <Badge variant={STATUS_VARIANT[detail.session.status]}>{STATUS_LABEL[detail.session.status]}</Badge>
            </div>

            <table style={{ width: '100%' }}>
              <thead>
                <tr>
                  <th style={{ textAlign: 'left' }}>Клієнт</th>
                  <th style={{ textAlign: 'left' }}>Абонемент</th>
                  <th style={{ textAlign: 'left' }}>Статус</th>
                  {canManage && detail.session.status === 'scheduled' && <th />}
                </tr>
              </thead>
              <tbody>
                {detail.roster.length === 0 && (
                  <tr><td colSpan={4} style={{ padding: '12px 0', color: 'var(--text-muted)' }}>Ще нікого не записано</td></tr>
                )}
                {detail.roster.map((r) => (
                  <tr key={r.id}>
                    <td>{r.client_name}</td>
                    <td>{r.tariff_name || '—'}</td>
                    <td>
                      {r.status === 'booked' && <Badge variant="info">Записаний</Badge>}
                      {r.status === 'attended' && <Badge variant="active">Присутній</Badge>}
                      {r.status === 'no_show' && <Badge variant="inactive">Не прийшов</Badge>}
                    </td>
                    {canManage && detail.session.status === 'scheduled' && (
                      <td style={{ whiteSpace: 'nowrap' }}>
                        {r.status === 'booked' && (
                          <>
                            <button className="btn btn-ghost btn-sm" onClick={() => handleAttendance(r.id, 'attended')}>Відмітити</button>
                            <button className="btn btn-ghost btn-sm" onClick={() => handleAttendance(r.id, 'no_show')}>Не прийшов</button>
                            <button className="btn btn-ghost btn-sm" style={{ color: 'var(--text-muted)' }} onClick={() => handleRemoveClient(r.id)}>✕</button>
                          </>
                        )}
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </>
        )}
      </Modal>

      {/* Додати клієнта в ростер */}
      <Modal size="sm"
        open={!!addClient}
        onClose={() => setAddClient(null)}
        title="Додати клієнта до заняття"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={!addClient?.clientId} onClick={submitAddClient}>Додати</button>
            <button className="btn btn-ghost" onClick={() => setAddClient(null)}>Скасувати</button>
          </div>
        }
      >
        {addClient && (
          <FormGroup label="Клієнт" fullWidth>
            <select value={addClient.clientId} onChange={(e) => setAddClient({ ...addClient, clientId: e.target.value })} disabled={!addClient.clients}>
              <option value="">{addClient.clients === null ? '— Завантаження... —' : '— Оберіть клієнта —'}</option>
              {addClient.clients?.map((c) => <option key={c.id} value={c.id}>{c.full_name}{c.phone ? ` · ${c.phone}` : ''}</option>)}
            </select>
          </FormGroup>
        )}
      </Modal>
    </AppLayout>
  );
}
