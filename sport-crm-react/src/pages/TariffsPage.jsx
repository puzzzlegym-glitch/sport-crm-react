import { useEffect, useState } from 'react';
import AppLayout from '../components/layout/AppLayout';
import Table from '../components/ui/Table';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import Badge from '../components/ui/Badge';
import { usePermissions } from '../hooks/usePermissions';
import { useToast } from '../components/ui/ToastProvider';
import { getTariffs, createTariff, updateTariff, archiveTariff } from '../api/tariffs';
import { formatMoney } from '../utils/format';
import './TariffsPage.css';

const EMPTY_FORM = {
  id: null,
  name: '', price: '', duration_days: 30, visits_limit: '',
  category: '', color: '#4f9cf9', freeze_days_max: 0, freeze_days_min: 0, prolong_sum: 0,
  has_trainer: false, earn_release_trigger: 'on_each_visit', sort_order: 0, description: '',
};

export default function TariffsPage() {
  const { has } = usePermissions();
  const isOwner = has('tariffs.edit');
  const toast = useToast();

  const [showArchived, setShowArchived] = useState(false);
  const [tariffs, setTariffs] = useState([]);
  const [loading, setLoading] = useState(true);

  const [form, setForm] = useState(null);
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  async function reload() {
    setLoading(true);
    const res = await getTariffs(showArchived);
    setLoading(false);
    if (!res.success) {
      toast(res.error, 'error');
      setTariffs([]);
      return;
    }
    setTariffs(res.tariffs);
  }

  useEffect(() => {
    reload();
  }, [showArchived]);

  function openCreate() {
    setForm({ ...EMPTY_FORM });
    setError('');
  }

  function openEdit(t) {
    setForm({
      id: t.id,
      name: t.name || '',
      price: t.price || 0,
      duration_days: t.duration_days || 30,
      visits_limit: t.visits_limit ?? '',
      category: t.category || '',
      color: t.color || '#4f9cf9',
      freeze_days_max: t.freeze_days_max || 0,
      freeze_days_min: t.freeze_days_min || 0,
      prolong_sum: t.prolong_sum || 0,
      has_trainer: !!t.has_trainer,
      earn_release_trigger: t.earn_release_trigger || 'on_each_visit',
      sort_order: t.sort_order || 0,
      description: t.description || '',
    });
    setError('');
  }

  async function submit() {
    if (!form.name.trim()) {
      setError("Назва обов'язкова");
      return;
    }
    const freezeMax = parseInt(form.freeze_days_max) || 0;
    const freezeMin = parseInt(form.freeze_days_min) || 0;
    if (freezeMax > 0 && freezeMin > freezeMax) {
      setError('Мінімум заморозки не може перевищувати максимум');
      return;
    }
    setSubmitting(true);
    setError('');
    const payload = {
      id: form.id,
      name: form.name.trim(),
      price: parseFloat(form.price) || 0,
      duration_days: parseInt(form.duration_days) || 30,
      visits_limit: form.visits_limit !== '' ? parseInt(form.visits_limit) : null,
      category: form.category.trim(),
      color: form.color,
      freeze_days_max: freezeMax,
      freeze_days_min: freezeMin,
      prolong_sum: parseFloat(form.prolong_sum) || 0,
      has_trainer: form.has_trainer ? 1 : 0,
      earn_release_trigger: form.earn_release_trigger,
      sort_order: parseInt(form.sort_order) || 0,
      description: form.description.trim(),
    };
    const res = form.id ? await updateTariff(payload) : await createTariff(payload);
    setSubmitting(false);
    if (!res.success) {
      setError(res.error);
      return;
    }
    toast(res.message || 'Збережено', 'success');
    setForm(null);
    reload();
  }

  async function handleArchive(t) {
    const verb = t.is_active ? 'Архівувати' : 'Відновити';
    if (!confirm(`${verb} тариф "${t.name}"?`)) return;
    const res = await archiveTariff(t.id);
    if (!res.success) {
      toast(res.error, 'error');
      return;
    }
    toast(res.message, 'success');
    reload();
  }

  const columns = [
    {
      key: 'name',
      label: 'Назва / Категорія',
      render: (t) => (
        <>
          <span className="tariff-name">{t.name}</span>
          {t.category && <Badge variant="info">{t.category}</Badge>}
          {!t.is_active && <Badge variant="inactive">Архів</Badge>}
        </>
      ),
    },
    { key: 'price', label: 'Ціна', mobile: 'trailing', render: (t) => <strong style={{ color: 'var(--accent)', whiteSpace: 'nowrap' }}>{formatMoney(t.price)}</strong> },
    { key: 'duration', label: 'Строк', mobile: 'secondary', render: (t) => `${t.duration_days} дн.` },
    {
      key: 'freeze', label: 'Заморозка',
      render: (t) => {
        if (!t.freeze_days_max && !t.freeze_days_min) return '—';
        return t.freeze_days_min > 0 ? `${t.freeze_days_min}–${t.freeze_days_max || '∞'} дн.` : `до ${t.freeze_days_max} дн.`;
      },
    },
    { key: 'visits', label: 'Відвідувань', render: (t) => t.visits_limit || 'безліміт' },
    { key: 'narah', label: 'Тренер', render: (t) => (t.has_trainer ? <Badge variant="active">З тренером</Badge> : '—') },
    {
      key: 'sold',
      label: 'Продано',
      render: (t) => (
        <>
          {parseInt(t.usage_total) || 0} <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>({parseInt(t.usage_active) || 0} акт.)</span>
        </>
      ),
    },
    {
      key: 'actions',
      label: '',
      render: (t) => isOwner && (
        <div style={{ display: 'flex', gap: 4, whiteSpace: 'nowrap' }} onClick={(e) => e.stopPropagation()}>
          <button className="btn btn-ghost btn-sm" title="Редагувати" onClick={() => openEdit(t)}>✎</button>
          <button
            className="btn btn-ghost btn-sm"
            style={{ color: t.is_active ? 'var(--text-muted)' : 'var(--success)' }}
            title={t.is_active ? 'Архівувати' : 'Відновити'}
            onClick={() => handleArchive(t)}
          >
            {t.is_active ? '📦' : '↩'}
          </button>
        </div>
      ),
    },
  ];

  return (
    <AppLayout title="Тарифи">
      <div className="page-header prod-page-header">
        <div className="prod-header-top">
          <label className="low-stock-label">
            <input type="checkbox" checked={showArchived} onChange={(e) => setShowArchived(e.target.checked)} /> Показати архівні
          </label>
          {isOwner && <button className="btn btn-primary" onClick={openCreate}>+ Додати тариф</button>}
        </div>
      </div>

      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <Table
          columns={columns}
          rows={tariffs}
          loading={loading}
          emptyMessage={showArchived ? 'Архів порожній' : 'Тарифів ще немає'}
          rowProps={(t) => ({ className: `tariff-tr ${!t.is_active ? 'archived' : ''}`, style: { borderLeft: `3px solid ${t.color || 'var(--accent)'}`, '--card-accent': t.color || 'var(--accent)' } })}
        />
      </div>

      <Modal size="lg"
        open={!!form}
        onClose={() => setForm(null)}
        title={form?.id ? 'Редагувати тариф' : 'Новий тариф'}
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
              <input type="text" placeholder="Наприклад: Безліміт 30 днів" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </FormGroup>
            <FormGroup label="Ціна (грн) *">
              <input type="number" min="0" step="1" placeholder="0" value={form.price} onChange={(e) => setForm({ ...form, price: e.target.value })} />
            </FormGroup>
            <FormGroup label="Тривалість (днів) *">
              <input type="number" min="1" step="1" value={form.duration_days} onChange={(e) => setForm({ ...form, duration_days: e.target.value })} />
            </FormGroup>
            <FormGroup label="Ліміт відвідувань">
              <input type="number" min="1" step="1" placeholder="порожньо = безліміт" value={form.visits_limit} onChange={(e) => setForm({ ...form, visits_limit: e.target.value })} />
            </FormGroup>
            <FormGroup label="Категорія">
              <input type="text" placeholder="Груп., Персон., Онлайн..." value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} />
            </FormGroup>
            <FormGroup label="Колір бейджа">
              <input type="color" style={{ height: 38, padding: '3px 6px' }} value={form.color} onChange={(e) => setForm({ ...form, color: e.target.value })} />
            </FormGroup>
            <FormGroup label="Мін. днів заморозки">
              <input type="number" min="0" step="1" value={form.freeze_days_min} onChange={(e) => setForm({ ...form, freeze_days_min: e.target.value })} />
            </FormGroup>
            <FormGroup label="Макс. днів заморозки">
              <input type="number" min="0" step="1" placeholder="0 = без обмеження" value={form.freeze_days_max} onChange={(e) => setForm({ ...form, freeze_days_max: e.target.value })} />
            </FormGroup>
            <FormGroup label="Вартість продовження/день">
              <input type="number" min="0" step="0.01" value={form.prolong_sum} onChange={(e) => setForm({ ...form, prolong_sum: e.target.value })} />
            </FormGroup>
            <FormGroup label="Тренер">
              <label style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 0' }}>
                <input type="checkbox" checked={form.has_trainer} onChange={(e) => setForm({ ...form, has_trainer: e.target.checked })} />
                Цей тариф передбачає тренера
              </label>
            </FormGroup>
            {!!form.has_trainer && (
              <FormGroup label="Коли тренер отримує нарахування">
                <select value={form.earn_release_trigger} onChange={(e) => setForm({ ...form, earn_release_trigger: e.target.value })}>
                  <option value="on_each_visit">Одразу за кожне заняття</option>
                  <option value="on_visits_done">Коли вичерпано всі заняття абонемента</option>
                  <option value="on_end_date">Коли закінчиться термін дії абонемента</option>
                </select>
              </FormGroup>
            )}
            {!!form.has_trainer && (
              <div style={{ gridColumn: '1/-1', fontSize: 12, color: 'var(--text-muted)', marginTop: -8, marginBottom: 8 }}>
                Сума нарахування тренеру визначається профілем тренера (сторінка «Тренери»), не тарифом.
              </div>
            )}
            <FormGroup label="Порядок сортування">
              <input type="number" min="0" step="1" value={form.sort_order} onChange={(e) => setForm({ ...form, sort_order: e.target.value })} />
            </FormGroup>
            <FormGroup label="Опис" fullWidth>
              <textarea rows="2" placeholder="Необов'язково..." value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
            </FormGroup>
          </div>
        )}
      </Modal>
    </AppLayout>
  );
}
