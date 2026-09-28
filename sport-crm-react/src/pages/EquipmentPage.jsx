import { useEffect, useState } from 'react';
import AppLayout from '../components/layout/AppLayout';
import Table from '../components/ui/Table';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import Icon from '../components/ui/Icon';
import Badge from '../components/ui/Badge';
import { useToast } from '../components/ui/ToastProvider';
import { usePermissions } from '../hooks/usePermissions';
import { getEquipmentList, createEquipment, updateEquipment, deleteEquipment } from '../api/equipment';

const STATUS_LABELS = {
  working: { text: 'Працює', variant: 'active' },
  maintenance: { text: 'На обслуговуванні', variant: 'pending' },
  broken: { text: 'Зламано', variant: 'inactive' },
};

const EMPTY_FORM = { id: null, name: '', category: '', location_note: '', status: 'working', notes: '', error: '', submitting: false };

export default function EquipmentPage() {
  const toast = useToast();
  const { has } = usePermissions();
  const canManage = has('equipment.manage');

  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [formModal, setFormModal] = useState(null);
  const [deleteTarget, setDeleteTarget] = useState(null);

  async function reload() {
    setLoading(true);
    const res = await getEquipmentList();
    if (!res.success) { toast(res.error, 'error'); setLoading(false); return; }
    setRows(res.equipment || []);
    setLoading(false);
  }

  useEffect(() => { reload(); }, []);

  function openCreate() {
    setFormModal({ ...EMPTY_FORM });
  }

  function openEdit(item) {
    setFormModal({
      id: item.id,
      name: item.name,
      category: item.category || '',
      location_note: item.location_note || '',
      status: item.status,
      notes: item.notes || '',
      error: '',
      submitting: false,
    });
  }

  async function submitForm() {
    const name = formModal.name.trim();
    if (!name) { setFormModal({ ...formModal, error: 'Введіть назву' }); return; }

    setFormModal({ ...formModal, submitting: true, error: '' });
    const payload = {
      id: formModal.id,
      name,
      category: formModal.category.trim(),
      location_note: formModal.location_note.trim(),
      status: formModal.status,
      notes: formModal.notes.trim(),
    };
    const res = formModal.id ? await updateEquipment(payload) : await createEquipment(payload);
    if (!res.success) { setFormModal({ ...formModal, submitting: false, error: res.error || 'Невідома помилка' }); return; }

    toast(res.message, 'success');
    setFormModal(null);
    reload();
  }

  async function confirmDelete() {
    const res = await deleteEquipment(deleteTarget.id);
    if (!res.success) { toast(res.error, 'error'); setDeleteTarget(null); return; }
    toast(res.message, 'success');
    setDeleteTarget(null);
    reload();
  }

  const columns = [
    {
      key: 'name', label: 'Назва',
      render: (e) => (
        <div>
          <strong>{e.name}</strong>
          <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{e.category || '—'}</div>
        </div>
      ),
    },
    { key: 'location', label: 'Де стоїть', mobile: 'secondary', render: (e) => e.location_note || '—' },
    {
      key: 'status', label: 'Стан', cardTop: true,
      render: (e) => {
        const s = STATUS_LABELS[e.status] || STATUS_LABELS.working;
        return <Badge variant={s.variant}>{s.text}</Badge>;
      },
    },
    { key: 'notes', label: 'Примітки', mobile: 'trailing', render: (e) => e.notes || '—' },
    ...(canManage ? [{
      key: 'actions', label: '',
      render: (e) => (
        <div style={{ display: 'flex', gap: 6 }}>
          <button type="button" className="btn btn-ghost btn-sm" onClick={(ev) => { ev.stopPropagation(); openEdit(e); }}>
            <Icon name="edit" size={14} />
          </button>
          <button type="button" className="btn btn-ghost btn-sm" onClick={(ev) => { ev.stopPropagation(); setDeleteTarget(e); }}>
            <Icon name="trash" size={14} />
          </button>
        </div>
      ),
    }] : []),
  ];

  return (
    <AppLayout title="Обладнання">
      {canManage && (
        <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 14 }}>
          <button type="button" className="btn btn-primary" onClick={openCreate}>
            <Icon name="plus" size={14} /> Додати
          </button>
        </div>
      )}

      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <Table columns={columns} rows={rows} loading={loading} emptyMessage="Обладнання ще немає" />
      </div>

      <Modal
        open={!!formModal}
        onClose={() => setFormModal(null)}
        title={formModal?.id ? 'Редагувати обладнання' : 'Нове обладнання'}
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={formModal?.submitting} onClick={submitForm}>
              {formModal?.submitting ? 'Збереження...' : 'Зберегти'}
            </button>
            <button className="btn btn-ghost" onClick={() => setFormModal(null)}>Скасувати</button>
          </div>
        }
      >
        {formModal && (
          <>
            {formModal.error && <div className="alert alert-error">{formModal.error}</div>}
            <FormGroup label="Назва" fullWidth>
              <input type="text" value={formModal.name} onChange={(e) => setFormModal({ ...formModal, name: e.target.value })} />
            </FormGroup>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Категорія">
                <input type="text" value={formModal.category} onChange={(e) => setFormModal({ ...formModal, category: e.target.value })} />
              </FormGroup>
              <FormGroup label="Стан">
                <select value={formModal.status} onChange={(e) => setFormModal({ ...formModal, status: e.target.value })}>
                  <option value="working">Працює</option>
                  <option value="maintenance">На обслуговуванні</option>
                  <option value="broken">Зламано</option>
                </select>
              </FormGroup>
            </div>
            <FormGroup label="Де стоїть" fullWidth>
              <input type="text" value={formModal.location_note} onChange={(e) => setFormModal({ ...formModal, location_note: e.target.value })} />
            </FormGroup>
            <FormGroup label="Примітки" fullWidth>
              <textarea rows={3} value={formModal.notes} onChange={(e) => setFormModal({ ...formModal, notes: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>

      <Modal
        open={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        title="Видалити обладнання?"
        size="sm"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-danger" onClick={confirmDelete}>Видалити</button>
            <button className="btn btn-ghost" onClick={() => setDeleteTarget(null)}>Скасувати</button>
          </div>
        }
      >
        {deleteTarget && <p>Видалити «{deleteTarget.name}»? Цю дію не можна скасувати.</p>}
      </Modal>
    </AppLayout>
  );
}
