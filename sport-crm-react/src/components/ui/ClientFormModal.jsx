import { useEffect, useState } from 'react';
import Modal from './Modal';
import FormGroup from './FormGroup';
import PhoneInput, { extractPhoneDigits } from './PhoneInput';
import { usePermissions } from '../../hooks/usePermissions';
import { getClient, createClient, updateClient } from '../../api/clients';

const SOURCE_OPTIONS = ['Реклама', 'Instagram', 'Рекомендація', 'Вивіска', 'Google', 'Інше'];
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const isValidEmail = (v) => !v || EMAIL_RE.test(v);

const EMPTY_FORM = { id: null, full_name: '', phone: '', email: '', birthday: '', gender: '', address: '', status: 'regular', status_reason: '', source: '', notes: '' };

/**
 * Модалка додавання/редагування клієнта — спільна для ClientsPage (список) і
 * ClientCardPage (картка клієнта).
 *
 * Використання:
 * <ClientFormModal clientId={editId} open={formOpen} onClose={...} onSaved={reload} />
 * clientId=null → режим "новий клієнт"; clientId=<id> → підвантажує і редагує.
 */
export default function ClientFormModal({ clientId, open, onClose, onSaved }) {
  const { isOwner } = usePermissions();
  const [form, setForm] = useState(null);
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    setError('');
    if (!clientId) {
      setForm({ ...EMPTY_FORM });
      return;
    }
    setForm({ ...EMPTY_FORM, id: clientId });
    getClient(clientId).then((res) => {
      if (!res.success) {
        setError(res.error);
        return;
      }
      const c = res.client;
      setForm({
        id: clientId,
        full_name: c.full_name || '',
        phone: c.phone || '',
        email: c.email || '',
        birthday: c.birthday || '',
        gender: c.gender || '',
        address: c.address || '',
        status: c.status || 'regular',
        status_reason: c.status_reason || '',
        source: c.source || '',
        notes: c.notes || '',
      });
    });
  }, [open, clientId]);

  async function submit() {
    const phoneDigits = extractPhoneDigits(form.phone);
    if (phoneDigits && phoneDigits.length !== 9) {
      setError('Некоректний формат телефону. Введіть повний номер після +380.');
      return;
    }
    const normalizedPhone = phoneDigits ? '+380' + phoneDigits : '';
    if (!isValidEmail(form.email.trim())) {
      setError('Некоректний email');
      return;
    }
    if (form.status === 'blocked' && !form.status_reason.trim()) {
      setError('Вкажіть причину блокування');
      return;
    }
    setSubmitting(true);
    setError('');
    const payload = { ...form, full_name: form.full_name.trim(), phone: normalizedPhone, email: form.email.trim(), address: form.address.trim(), status_reason: form.status_reason.trim(), notes: form.notes.trim() };
    const res = form.id ? await updateClient(payload) : await createClient(payload);
    setSubmitting(false);
    if (!res.success) {
      setError(res.error);
      return;
    }
    onSaved?.(form.id ? 'Зміни збережено' : 'Клієнта додано');
    onClose();
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={clientId ? 'Редагування клієнта' : 'Новий клієнт'}
      footer={
        <div style={{ display: 'flex', gap: 10 }}>
          <button className="btn btn-primary" disabled={!form || submitting} onClick={submit}>{submitting ? 'Збереження...' : 'Зберегти'}</button>
          <button className="btn btn-ghost" onClick={onClose}>Скасувати</button>
        </div>
      }
    >
      {form && (
        <div className="form-row">
          {error && <div className="alert alert-error" style={{ gridColumn: '1/-1' }}>{error}</div>}
          <FormGroup label="ПІБ *" fullWidth>
            <input type="text" maxLength={120} placeholder="Іван Петренко" value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} />
          </FormGroup>
          <FormGroup label="Телефон">
            <PhoneInput value={form.phone} onChange={(v) => setForm({ ...form, phone: v })} />
          </FormGroup>
          <FormGroup label="Email">
            <input type="email" placeholder="ivan@example.com" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          </FormGroup>
          <FormGroup label="Дата народження">
            <input type="date" value={form.birthday} onChange={(e) => setForm({ ...form, birthday: e.target.value })} />
          </FormGroup>
          <FormGroup label="Стать">
            <select value={form.gender} onChange={(e) => setForm({ ...form, gender: e.target.value })}>
              <option value="">Не вказано</option>
              <option value="M">Чоловік</option>
              <option value="F">Жінка</option>
            </select>
          </FormGroup>
          <FormGroup label="Адреса" fullWidth>
            <input type="text" placeholder="м. Київ, вул. Хрещатик 1" value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} />
          </FormGroup>
          <FormGroup label="Статус">
            <select
              value={form.status}
              disabled={form.status === 'blocked' && !isOwner}
              onChange={(e) => setForm({ ...form, status: e.target.value, status_reason: e.target.value === 'blocked' ? form.status_reason : '' })}
            >
              <option value="regular">Звичайний</option>
              <option value="premium">Преміум</option>
              {isOwner && <option value="blocked">Заблокований</option>}
            </select>
            {form.status === 'blocked' && !isOwner && (
              <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4 }}>Зняти блокування може лише власник клубу</div>
            )}
          </FormGroup>
          {form.status === 'blocked' && (
            <FormGroup label="Причина блокування *" fullWidth>
              <textarea rows="2" placeholder="Наприклад: борг, конфлікт, порушення правил клубу..." value={form.status_reason} onChange={(e) => setForm({ ...form, status_reason: e.target.value })} disabled={!isOwner} />
            </FormGroup>
          )}
          <FormGroup label="Звідки дізнався">
            <select value={form.source} onChange={(e) => setForm({ ...form, source: e.target.value })}>
              <option value="">Не вказано</option>
              {SOURCE_OPTIONS.map((s) => <option key={s} value={s}>{s}</option>)}
            </select>
          </FormGroup>
          <FormGroup label="Нотатки" fullWidth>
            <textarea placeholder="Внутрішні нотатки (клієнт не бачить)" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
          </FormGroup>
        </div>
      )}
    </Modal>
  );
}
