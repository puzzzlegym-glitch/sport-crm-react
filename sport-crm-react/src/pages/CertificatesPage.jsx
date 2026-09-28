import { useEffect, useState } from 'react';
import AppLayout from '../components/layout/AppLayout';
import Table from '../components/ui/Table';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import { usePermissions } from '../hooks/usePermissions';
import { useToast } from '../components/ui/ToastProvider';
import { getCertificates, sellCertificate, redeemCertificate, cancelCertificateSale, updateCertificate, deleteCertificate } from '../api/certificates';
import { searchClients } from '../api/clients';
import { formatMoney, formatDate } from '../utils/format';
import './CertificatesPage.css';

const PAY_LABELS = { cash: 'Готівка', card: 'Карта', terminal: 'Термінал', transfer: 'Переказ', other: 'Інше' };
const CERT_METHODS = ['cash', 'card', 'terminal', 'transfer', 'other'];

export default function CertificatesPage() {
  const { has } = usePermissions();
  const canManage = has('certificates.manage');
  const canCancel = has('certificates.cancel');
  const toast = useToast();

  const [statusFilter, setStatusFilter] = useState('');
  const [certificates, setCertificates] = useState({ rows: [], summary: {}, loading: true });

  const [sellModal, setSellModal] = useState(null);
  const [redeemModal, setRedeemModal] = useState(null);
  const [cancelModal, setCancelModal] = useState(null);
  const [editModal, setEditModal] = useState(null);
  const [deleteConfirm, setDeleteConfirm] = useState(null);

  async function reload() {
    setCertificates((s) => ({ ...s, loading: true }));
    const res = await getCertificates({ status: statusFilter });
    if (!res.success) { toast(res.error, 'error'); setCertificates({ rows: [], summary: {}, loading: false }); return; }
    setCertificates({ rows: res.certificates || [], summary: res.summary || {}, loading: false });
  }

  useEffect(() => { reload(); }, [statusFilter]);

  // ── Продаж ────────────────────────────────────────────────
  function openSellModal() {
    setSellModal({ code: '', amount: '', method: 'cash', buyerName: '', notes: '', error: '', submitting: false });
  }

  async function submitSell() {
    const code = sellModal.code.trim();
    if (!code) { setSellModal({ ...sellModal, error: 'Введіть код сертифіката' }); return; }
    const amount = parseFloat(sellModal.amount) || 0;
    if (amount <= 0) { setSellModal({ ...sellModal, error: 'Сума має бути більше 0' }); return; }
    setSellModal({ ...sellModal, submitting: true, error: '' });
    const res = await sellCertificate({
      code, amount, payment_method: sellModal.method,
      buyer_name: sellModal.buyerName.trim(), notes: sellModal.notes.trim(),
    });
    if (!res.success) { setSellModal({ ...sellModal, submitting: false, error: res.error }); return; }
    toast(res.message, 'success');
    setSellModal(null);
    reload();
  }

  // ── Активація ─────────────────────────────────────────────
  function openRedeemModal(prefillCode = '') {
    setRedeemModal({ code: prefillCode, search: '', results: [], clientId: null, clientName: '', error: '', submitting: false });
  }

  useEffect(() => {
    if (!redeemModal || redeemModal.search.trim().length < 2) {
      if (redeemModal) setRedeemModal((m) => ({ ...m, results: [] }));
      return;
    }
    const t = setTimeout(async () => {
      const res = await searchClients(redeemModal.search.trim());
      setRedeemModal((m) => (m ? { ...m, results: res.success ? (res.results || []) : [] } : m));
    }, 300);
    return () => clearTimeout(t);
  }, [redeemModal?.search]);

  async function submitRedeem() {
    const code = redeemModal.code.trim();
    if (!code) { setRedeemModal({ ...redeemModal, error: 'Введіть код сертифіката' }); return; }
    if (!redeemModal.clientId) { setRedeemModal({ ...redeemModal, error: 'Оберіть клієнта-отримувача' }); return; }
    setRedeemModal({ ...redeemModal, submitting: true, error: '' });
    const res = await redeemCertificate({ code, client_id: redeemModal.clientId });
    if (!res.success) { setRedeemModal({ ...redeemModal, submitting: false, error: res.error }); return; }
    toast(res.message, 'success');
    setRedeemModal(null);
    reload();
  }

  // ── Скасування продажу ────────────────────────────────────
  function openCancelModal(row) {
    setCancelModal({ code: row.code, reason: '' });
  }

  async function confirmCancel() {
    const res = await cancelCertificateSale({ code: cancelModal.code, reason: cancelModal.reason.trim() });
    setCancelModal(null);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message, 'success');
    reload();
  }

  // ── Редагування ────────────────────────────────────────────
  function openEditModal(row) {
    setEditModal({
      id: row.id, status: row.status, code: row.code,
      amount: row.amount ?? '', method: row.payment_method || 'cash',
      buyerName: row.buyer_name || '', notes: row.notes || '',
      error: '', submitting: false,
    });
  }

  async function submitEdit() {
    const code = editModal.code.trim();
    if (!code) { setEditModal({ ...editModal, error: 'Введіть код сертифіката' }); return; }
    if (editModal.status === 'sold' && (parseFloat(editModal.amount) || 0) <= 0) {
      setEditModal({ ...editModal, error: 'Сума має бути більше 0' });
      return;
    }
    setEditModal({ ...editModal, submitting: true, error: '' });
    const res = await updateCertificate({
      id: editModal.id, code,
      amount: parseFloat(editModal.amount) || 0,
      payment_method: editModal.method,
      buyer_name: editModal.buyerName.trim(),
      notes: editModal.notes.trim(),
    });
    if (!res.success) { setEditModal({ ...editModal, submitting: false, error: res.error }); return; }
    toast(res.message || 'Збережено', 'success');
    setEditModal(null);
    reload();
  }

  // ── Видалення коду ───────────────────────────────────────────
  function openDeleteConfirm(row) {
    setDeleteConfirm({ id: row.id, code: row.code });
  }

  async function confirmDelete() {
    const res = await deleteCertificate(deleteConfirm.id);
    setDeleteConfirm(null);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message || 'Видалено', 'success');
    reload();
  }

  const columns = [
    { key: 'code', label: 'Код', render: (r) => <strong>{r.code}</strong> },
    {
      key: 'status', label: 'Статус', cardTop: true,
      render: (r) => <Badge variant={r.status === 'available' ? 'active' : 'pending'}>{r.status === 'available' ? 'Доступний' : 'Продано — очікує активації'}</Badge>,
    },
    { key: 'amount', label: 'Сума', mobile: 'trailing', render: (r) => (r.amount != null ? formatMoney(r.amount) : '—') },
    {
      key: 'who', label: 'Покупець / отримувач', mobile: 'secondary',
      render: (r) => {
        if (r.status === 'sold') return r.buyer_name || '—';
        if (r.sale_status === 'redeemed' && r.redeemed_client_name) return <span title="Востаннє активовано на клієнта">↳ {r.redeemed_client_name}</span>;
        return '—';
      },
    },
    { key: 'date', label: 'Дата', render: (r) => formatDate(r.status === 'sold' ? r.sold_at : (r.redeemed_at || r.created_at)) },
    { key: 'admin', label: 'Ким оформлено', mobile: 'secondary', render: (r) => (r.status === 'sold' ? r.sold_admin_name : r.redeemed_admin_name) || '—' },
    {
      key: 'actions', label: '', render: (r) => (
        <div className="cert-row-actions">
          {r.status === 'sold' && canManage && <button className="btn btn-ghost btn-sm" onClick={() => openRedeemModal(r.code)}>Активувати</button>}
          {canManage && <button className="btn btn-ghost btn-sm" onClick={() => openEditModal(r)}>Редагувати</button>}
          {r.status === 'sold' && canCancel && <button className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }} onClick={() => openCancelModal(r)}>Скасувати</button>}
          {r.status === 'available' && canCancel && <button className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }} onClick={() => openDeleteConfirm(r)}>Видалити</button>}
        </div>
      ),
    },
  ];

  return (
    <AppLayout title="Сертифікати" subtitle="Продаж і активація подарункових сертифікатів">
      <div className="card" style={{ marginBottom: 16 }}>
        <div className="cert-toolbar">
          <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
            <option value="">Всі статуси</option>
            <option value="available">Доступні</option>
            <option value="sold">Очікують активації</option>
          </select>

          {!certificates.loading && (
            <>
              <span className="cert-chip">Доступно: {certificates.summary.available_count || 0}</span>
              <span className="cert-chip danger">Очікують активації: {certificates.summary.sold_count || 0} на {formatMoney(certificates.summary.sold_amount || 0)}</span>
              <span className="cert-chip success">Активовано всього: {certificates.summary.redeemed_count || 0} на {formatMoney(certificates.summary.redeemed_amount || 0)}</span>
            </>
          )}

          {canManage && (
            <div className="cert-toolbar-actions">
              <button className="btn btn-ghost btn-sm" onClick={() => openRedeemModal()}>Активувати за кодом</button>
              <button className="btn btn-primary btn-sm" onClick={openSellModal}><Icon name="plus" size={14} /> Продати сертифікат</button>
            </div>
          )}
        </div>
      </div>

      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <Table columns={columns} rows={certificates.rows} keyField="id" loading={certificates.loading} emptyMessage="Сертифікатів ще немає" />
      </div>

      {/* Продаж сертифіката */}
      <Modal
        open={!!sellModal}
        onClose={() => setSellModal(null)}
        title="Продати сертифікат"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={sellModal?.submitting} onClick={submitSell}>Продати</button>
            <button className="btn btn-ghost" onClick={() => setSellModal(null)}>Скасувати</button>
          </div>
        }
      >
        {sellModal && (
          <>
            {sellModal.error && <div className="alert alert-error">{sellModal.error}</div>}
            <FormGroup label="Код сертифіката *">
              <input type="text" placeholder="Наприклад, GIFT-001" autoComplete="off" value={sellModal.code} onChange={(e) => setSellModal({ ...sellModal, code: e.target.value })} />
            </FormGroup>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Сума (грн) *">
                <input type="number" placeholder="0" min="1" step="1" value={sellModal.amount} onChange={(e) => setSellModal({ ...sellModal, amount: e.target.value })} />
              </FormGroup>
              <FormGroup label="Спосіб оплати">
                <select value={sellModal.method} onChange={(e) => setSellModal({ ...sellModal, method: e.target.value })}>
                  {CERT_METHODS.map((m) => <option key={m} value={m}>{PAY_LABELS[m]}</option>)}
                </select>
              </FormGroup>
            </div>
            <FormGroup label="Покупець">
              <input type="text" placeholder="Ім'я (необов'язково)" value={sellModal.buyerName} onChange={(e) => setSellModal({ ...sellModal, buyerName: e.target.value })} />
            </FormGroup>
            <FormGroup label="Примітка">
              <input type="text" placeholder="Необов'язково" value={sellModal.notes} onChange={(e) => setSellModal({ ...sellModal, notes: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>

      {/* Активація сертифіката */}
      <Modal
        open={!!redeemModal}
        onClose={() => setRedeemModal(null)}
        title="Активувати сертифікат"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={redeemModal?.submitting} onClick={submitRedeem}>Активувати</button>
            <button className="btn btn-ghost" onClick={() => setRedeemModal(null)}>Скасувати</button>
          </div>
        }
      >
        {redeemModal && (
          <>
            {redeemModal.error && <div className="alert alert-error">{redeemModal.error}</div>}
            <FormGroup label="Код сертифіката *">
              <input type="text" placeholder="Наприклад, GIFT-001" autoComplete="off" value={redeemModal.code} onChange={(e) => setRedeemModal({ ...redeemModal, code: e.target.value })} />
            </FormGroup>
            <FormGroup label="Клієнт-отримувач *">
              <div style={{ position: 'relative' }}>
                <input type="text" placeholder="Введіть ім'я або телефон..." autoComplete="off" value={redeemModal.search} onChange={(e) => setRedeemModal({ ...redeemModal, search: e.target.value })} />
                {redeemModal.results.length > 0 && (
                  <div style={{ position: 'absolute', top: '100%', left: 0, right: 0, background: 'var(--bg-elevated)', border: '1px solid var(--border-light)', borderRadius: 'var(--radius-sm)', zIndex: 100, maxHeight: 180, overflowY: 'auto', boxShadow: 'var(--shadow-md)', marginTop: 4 }}>
                    {redeemModal.results.map((c) => (
                      <div key={c.id} style={{ padding: '9px 12px', cursor: 'pointer', fontSize: 14, borderBottom: '1px solid var(--border)' }}
                        onClick={() => setRedeemModal({ ...redeemModal, clientId: c.id, clientName: c.full_name, search: '', results: [] })}>
                        <strong>{c.full_name}</strong> <span style={{ color: 'var(--text-muted)', fontSize: 12, marginLeft: 8 }}>{c.phone || ''}</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
              {redeemModal.clientId && (
                <div style={{ marginTop: 8, padding: '10px 12px', background: 'var(--accent-dim)', borderRadius: 'var(--radius-sm)', fontSize: 14 }}>
                  <strong>{redeemModal.clientName}</strong>
                  <button onClick={() => setRedeemModal({ ...redeemModal, clientId: null, clientName: '' })} style={{ float: 'right', background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)' }}>✕</button>
                </div>
              )}
            </FormGroup>
          </>
        )}
      </Modal>

      {/* Скасування продажу */}
      <Modal
        size="sm"
        open={!!cancelModal}
        onClose={() => setCancelModal(null)}
        title={`Скасувати продаж «${cancelModal?.code}»?`}
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-danger" onClick={confirmCancel}>Скасувати продаж</button>
            <button className="btn btn-ghost" onClick={() => setCancelModal(null)}>Назад</button>
          </div>
        }
      >
        {cancelModal && (
          <FormGroup label="Причина (необов'язково)">
            <input type="text" value={cancelModal.reason} onChange={(e) => setCancelModal({ ...cancelModal, reason: e.target.value })} />
          </FormGroup>
        )}
      </Modal>

      {/* Редагування коду / даних продажу */}
      <Modal
        open={!!editModal}
        onClose={() => setEditModal(null)}
        title="Редагувати сертифікат"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={editModal?.submitting} onClick={submitEdit}>{editModal?.submitting ? 'Збереження...' : 'Зберегти'}</button>
            <button className="btn btn-ghost" onClick={() => setEditModal(null)}>Скасувати</button>
          </div>
        }
      >
        {editModal && (
          <>
            {editModal.error && <div className="alert alert-error">{editModal.error}</div>}
            <FormGroup label="Код сертифіката *">
              <input type="text" autoComplete="off" value={editModal.code} onChange={(e) => setEditModal({ ...editModal, code: e.target.value })} />
            </FormGroup>
            {editModal.status === 'sold' && (
              <>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
                  <FormGroup label="Сума (грн) *">
                    <input type="number" min="1" step="1" value={editModal.amount} onChange={(e) => setEditModal({ ...editModal, amount: e.target.value })} />
                  </FormGroup>
                  <FormGroup label="Спосіб оплати">
                    <select value={editModal.method} onChange={(e) => setEditModal({ ...editModal, method: e.target.value })}>
                      {CERT_METHODS.map((m) => <option key={m} value={m}>{PAY_LABELS[m]}</option>)}
                    </select>
                  </FormGroup>
                </div>
                <FormGroup label="Покупець">
                  <input type="text" placeholder="Ім'я (необов'язково)" value={editModal.buyerName} onChange={(e) => setEditModal({ ...editModal, buyerName: e.target.value })} />
                </FormGroup>
                <FormGroup label="Примітка">
                  <input type="text" placeholder="Необов'язково" value={editModal.notes} onChange={(e) => setEditModal({ ...editModal, notes: e.target.value })} />
                </FormGroup>
              </>
            )}
          </>
        )}
      </Modal>

      {/* Видалення коду */}
      <Modal
        size="sm"
        open={!!deleteConfirm}
        onClose={() => setDeleteConfirm(null)}
        title={`Видалити сертифікат «${deleteConfirm?.code}»?`}
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-danger" onClick={confirmDelete}>Видалити</button>
            <button className="btn btn-ghost" onClick={() => setDeleteConfirm(null)}>Назад</button>
          </div>
        }
      >
        <p>Код і вся історія його активацій будуть видалені безповоротно.</p>
      </Modal>
    </AppLayout>
  );
}
