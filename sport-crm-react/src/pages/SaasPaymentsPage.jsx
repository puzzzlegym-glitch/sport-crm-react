import { useEffect, useState } from 'react';
import AppLayout from '../components/layout/AppLayout';
import Table from '../components/ui/Table';
import SimplePager from '../components/ui/SimplePager';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import Badge from '../components/ui/Badge';
import { useToast } from '../components/ui/ToastProvider';
import { getSaasPayments, updateSaasPayment, deleteSaasPayment } from '../api/saas';
import { formatMoney, formatDate } from '../utils/format';

const STATUS_MAP = { success: 'active', failed: 'inactive', pending: 'info', refunded: 'pending' };
const GATEWAYS = ['manual', 'wayforpay', 'liqpay'];
const STATUSES = ['success', 'pending', 'failed', 'refunded'];
const EMPTY_DATA = { payments: [], summary: { success: 0, manual: 0 }, pagination: { total: 0, page: 1, pages: 1, per_page: 30 } };

export default function SaasPaymentsPage() {
  const toast = useToast();
  const [page, setPage] = useState(1);
  const [data, setData] = useState(EMPTY_DATA);
  const [loading, setLoading] = useState(true);
  const [edit, setEdit] = useState(null); // { id, amount, gateway, status, note, error, submitting }

  async function reload() {
    setLoading(true);
    const res = await getSaasPayments({ page });
    setLoading(false);
    if (!res.success) {
      toast(res.error, 'error');
      setData(EMPTY_DATA);
      return;
    }
    setData({ payments: res.payments || [], summary: res.summary || { success: 0, manual: 0 }, pagination: res.pagination });
  }

  useEffect(() => {
    reload();
  }, [page]);

  function openEdit(p) {
    setEdit({ id: p.id, amount: p.amount, gateway: p.gateway, status: p.status, note: p.recorded_note || '', error: '', submitting: false });
  }

  async function submitEdit() {
    setEdit({ ...edit, submitting: true, error: '' });
    const res = await updateSaasPayment({
      id: edit.id, amount: parseFloat(edit.amount) || 0, gateway: edit.gateway, status: edit.status, note: edit.note,
    });
    if (!res.success) {
      setEdit({ ...edit, submitting: false, error: res.error });
      return;
    }
    toast('Збережено', 'success');
    setEdit(null);
    reload();
  }

  async function handleDelete(id) {
    if (!confirm(`Видалити платіж #${id}?`)) return;
    const res = await deleteSaasPayment(id);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast('Видалено', 'success');
    reload();
  }

  const columns = [
    { key: 'club', label: 'Клуб', render: (p) => <span style={{ fontWeight: 500 }}>{p.club_name || ''}</span> },
    { key: 'status', label: 'Статус', cardTop: true, render: (p) => <Badge variant={STATUS_MAP[p.status] || 'info'}>{p.status}</Badge> },
    { key: 'plan', label: 'План', mobile: 'secondary', render: (p) => <span style={{ fontSize: 13, color: 'var(--text-secondary)' }}>{p.plan_name || ''}</span> },
    { key: 'amount', label: 'Сума', mobile: 'trailing', render: (p) => <span style={{ fontWeight: 600, color: 'var(--success)' }}>{formatMoney(p.amount)}</span> },
    { key: 'gateway', label: 'Метод', render: (p) => <span style={{ fontSize: 12, textTransform: 'uppercase', color: 'var(--text-secondary)' }}>{p.gateway}</span> },
    { key: 'id', label: 'ID', render: (p) => <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>#{p.id}</span> },
    { key: 'date', label: 'Дата', render: (p) => <span style={{ fontSize: 13, color: 'var(--text-secondary)' }}>{formatDate(p.created_at)}</span> },
    { key: 'note', label: 'Примітка', render: (p) => <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>{p.recorded_note || ''}</span> },
    {
      key: 'actions', label: '',
      render: (p) => (
        <div style={{ display: 'flex', gap: 4, whiteSpace: 'nowrap' }}>
          <button className="btn btn-ghost btn-sm" onClick={() => openEdit(p)}>✎</button>
          <button className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }} onClick={() => handleDelete(p.id)}>🗑</button>
        </div>
      ),
    },
  ];

  return (
    <AppLayout title="Платежі">
      <div className="card" style={{ padding: 0, overflow: 'hidden', marginTop: 8 }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '16px 20px', borderBottom: '1px solid var(--border)' }}>
          <div style={{ fontSize: 15, fontWeight: 600 }}>Платежі платформи</div>
          <div style={{ fontSize: 13, color: 'var(--text-secondary)' }}>
            Успішних: {formatMoney(data.summary.success || 0)} · Ручних: {formatMoney(data.summary.manual || 0)}
          </div>
        </div>
        <Table columns={columns} rows={data.payments} loading={loading} emptyMessage="Платежів немає" />
        <div style={{ padding: '14px 20px' }}>
          <SimplePager page={data.pagination.page} pages={data.pagination.pages} total={data.pagination.total} onChange={setPage} />
        </div>
      </div>

      <Modal
        open={!!edit}
        onClose={() => setEdit(null)}
        title="Редагувати платіж"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={edit?.submitting} onClick={submitEdit}>{edit?.submitting ? '...' : 'Зберегти'}</button>
            <button className="btn btn-ghost" onClick={() => setEdit(null)}>Скасувати</button>
          </div>
        }
      >
        {edit && (
          <>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Сума (грн)">
                <input type="number" min="0" step="0.01" value={edit.amount} onChange={(e) => setEdit({ ...edit, amount: e.target.value })} />
              </FormGroup>
              <FormGroup label="Метод">
                <select value={edit.gateway} onChange={(e) => setEdit({ ...edit, gateway: e.target.value })}>
                  {GATEWAYS.map((g) => <option key={g} value={g}>{g}</option>)}
                </select>
              </FormGroup>
            </div>
            <FormGroup label="Статус">
              <select value={edit.status} onChange={(e) => setEdit({ ...edit, status: e.target.value })}>
                {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
              </select>
            </FormGroup>
            <FormGroup label="Примітка">
              <input type="text" placeholder="Необов'язково" value={edit.note} onChange={(e) => setEdit({ ...edit, note: e.target.value })} />
            </FormGroup>
            {edit.error && <div className="alert alert-error">{edit.error}</div>}
          </>
        )}
      </Modal>
    </AppLayout>
  );
}
