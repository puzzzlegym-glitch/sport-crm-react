import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import Table from '../components/ui/Table';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import Badge from '../components/ui/Badge';
import ClientSearchPicker from '../components/ui/ClientSearchPicker';
import { usePermissions } from '../hooks/usePermissions';
import { useShiftLock } from '../hooks/useShiftLock';
import { useToast } from '../components/ui/ToastProvider';
import { getInvoiceGroups, createInvoiceGroup } from '../api/invoices';
import { formatMoney, formatDate, localToday, localDate } from '../utils/format';

/**
 * Вкладка "Групові" на сторінці Абонементи — групові абонементи (корпоративні,
 * сімейні тощо). Кожен учасник групи отримує свій абонемент зі спільними
 * датами групи; абонемент не діє, доки учасник не вніс мінімальну оплату.
 */
export default function InvoiceGroupsTab({ tariffs }) {
  const { has } = usePermissions();
  const { guard, lockedProps } = useShiftLock();
  const toast = useToast();
  const navigate = useNavigate();

  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('active');
  const [groups, setGroups] = useState([]);
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState(null);

  async function reload() {
    setLoading(true);
    const res = await getInvoiceGroups({ search, status });
    setLoading(false);
    if (!res.success) { toast(res.error, 'error'); setGroups([]); return; }
    setGroups(res.groups || []);
  }

  useEffect(() => {
    const t = setTimeout(() => setSearch(searchInput.trim()), 350);
    return () => clearTimeout(t);
  }, [searchInput]);

  useEffect(() => { reload(); }, [search, status]);

  function openCreate() {
    setForm({
      name: '', tariffId: '', startDate: localToday(), memberPrice: '', minPayment: '',
      limited: false, maxMembers: '', owner: null, notes: '', error: '', submitting: false,
    });
  }

  function onTariffChange(tariffId) {
    const t = tariffs.find((x) => String(x.id) === String(tariffId));
    setForm((f) => ({
      ...f,
      tariffId,
      memberPrice: t ? String(t.price) : '',
      minPayment: t ? String(t.price) : '',
      name: f.name || (t ? t.name : ''),
    }));
  }

  async function submitCreate() {
    if (!form.name.trim()) { setForm({ ...form, error: 'Вкажіть назву групи' }); return; }
    if (!form.tariffId) { setForm({ ...form, error: 'Оберіть тариф' }); return; }
    const memberPrice = parseFloat(form.memberPrice) || 0;
    const minPayment = parseFloat(form.minPayment) || 0;
    if (minPayment > memberPrice) { setForm({ ...form, error: 'Мінімальна оплата не може перевищувати вартість абонемента учасника' }); return; }
    if (form.limited && !(parseInt(form.maxMembers) > 0)) { setForm({ ...form, error: 'Вкажіть максимальну кількість учасників' }); return; }
    setForm({ ...form, submitting: true, error: '' });
    const res = await createInvoiceGroup({
      name: form.name.trim(),
      tariff_id: form.tariffId,
      start_date: form.startDate,
      member_price: memberPrice,
      min_payment: minPayment,
      max_members: form.limited ? parseInt(form.maxMembers) : 0,
      owner_client_id: form.owner?.id || 0,
      notes: form.notes.trim(),
    });
    if (!res.success) { setForm({ ...form, submitting: false, error: res.error }); return; }
    toast('Групу створено — тепер додайте учасників', 'success');
    setForm(null);
    navigate(`/invoices/groups/${res.id}`);
  }

  const selectedTariff = form ? tariffs.find((t) => String(t.id) === String(form.tariffId)) : null;
  let endDateLabel = '';
  if (selectedTariff && form?.startDate) {
    const d = new Date(`${form.startDate}T00:00:00`);
    d.setDate(d.getDate() + parseInt(selectedTariff.duration_days) - 1);
    endDateLabel = formatDate(localDate(d));
  }

  const columns = [
    {
      key: 'name', label: 'Група',
      render: (g) => (
        <div className="inv-client-info">
          <div className="inv-client-name">{g.name}</div>
          <div className="inv-client-phone">{g.owner_name ? `Контакт: ${g.owner_name}` : 'Без контактної особи'}</div>
        </div>
      ),
    },
    {
      key: 'tariff', label: 'Тариф', mobile: 'secondary',
      render: (g) => (
        <>
          <div className="inv-tariff-name" title={g.tariff_name}>{g.tariff_name}</div>
          <div className="inv-tariff-dates">{formatDate(g.start_date)} — {formatDate(g.end_date)}</div>
        </>
      ),
    },
    {
      key: 'members', label: 'Учасники', cardTop: true,
      render: (g) => (
        <Badge variant="info">
          {g.members_count}{g.max_members ? ` / ${g.max_members}` : ''} · активовано {g.activated_count}
        </Badge>
      ),
    },
    {
      key: 'price', label: 'Учасник',
      render: (g) => (
        <div style={{ fontSize: 13 }}>
          {formatMoney(g.member_price)}
          <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>мін. оплата {formatMoney(g.min_payment)}</div>
        </div>
      ),
    },
    {
      key: 'paid', label: 'Оплата', mobile: 'trailing',
      render: (g) => {
        const debt = parseFloat(g.total_price) - parseFloat(g.total_paid);
        return (
          <>
            <div style={{ fontSize: 13 }}>{formatMoney(g.total_paid)} / {formatMoney(g.total_price)}</div>
            {debt > 0.01 && <span className="debt-badge">борг {formatMoney(debt)}</span>}
          </>
        );
      },
    },
  ];

  return (
    <>
      <div className="inv-toolbar">
        <div className="search-wrap">
          <span className="search-icon">🔍</span>
          <input type="text" placeholder="Назва групи або контактна особа..." value={searchInput} onChange={(e) => setSearchInput(e.target.value)} />
        </div>
        {[['active', 'Діючі'], ['closed', 'Закриті']].map(([v, l]) => (
          <button key={v} className={`inv-filter-btn ${status === v ? 'active' : ''}`} onClick={() => setStatus(v)}>{l}</button>
        ))}
        {has('invoices.sell') && (
          <button className="btn btn-primary" style={{ marginLeft: 'auto', ...lockedProps.style }} title={lockedProps.title} onClick={guard(openCreate)}>
            + Створити групу
          </button>
        )}
      </div>

      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <Table
          columns={columns}
          rows={groups}
          loading={loading}
          emptyMessage={has('invoices.sell') ? 'Груп немає. Натисніть "+ Створити групу"' : 'Груп немає'}
          onRowClick={(row) => navigate(`/invoices/groups/${row.id}`)}
        />
      </div>

      <Modal
        open={!!form}
        onClose={() => setForm(null)}
        title="Новий груповий абонемент"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={form?.submitting} onClick={submitCreate}>{form?.submitting ? 'Зберігаємо...' : 'Створити групу'}</button>
            <button className="btn btn-ghost" onClick={() => setForm(null)}>Скасувати</button>
          </div>
        }
      >
        {form && (
          <>
            {form.error && <div className="alert alert-error">{form.error}</div>}
            <FormGroup label="Тариф для учасників *">
              <select value={form.tariffId} onChange={(e) => onTariffChange(e.target.value)}>
                <option value="">{tariffs.length ? '— Оберіть тариф —' : 'Тарифів немає'}</option>
                {tariffs.map((t) => (
                  <option key={t.id} value={t.id}>{t.name} · {t.price} грн · {t.duration_days} дн. · {t.visits_limit ? `${t.visits_limit} відвід.` : 'безліміт'}</option>
                ))}
              </select>
            </FormGroup>
            <FormGroup label="Назва групи *">
              <input type="text" placeholder="напр. ТОВ «Ромашка» — жовтень" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </FormGroup>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Дата початку (для всіх) *">
                <input type="date" value={form.startDate} onChange={(e) => setForm({ ...form, startDate: e.target.value })} />
              </FormGroup>
              <FormGroup label="Діє до">
                <input type="text" value={endDateLabel || '—'} disabled />
              </FormGroup>
              <FormGroup label="Вартість для учасника (грн)">
                <input type="number" min="0" step="1" value={form.memberPrice} onChange={(e) => setForm({ ...form, memberPrice: e.target.value })} />
              </FormGroup>
              <FormGroup label="Мінімальна оплата (грн)">
                <input type="number" min="0" step="1" value={form.minPayment} onChange={(e) => setForm({ ...form, minPayment: e.target.value })} />
              </FormGroup>
            </div>
            <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '-4px 0 12px' }}>
              Абонемент учасника почне діяти лише після того, як за нього внесуть мінімальну оплату.
            </p>
            <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, marginBottom: 10 }}>
              <input type="checkbox" checked={form.limited} onChange={(e) => setForm({ ...form, limited: e.target.checked })} />
              Обмежити кількість учасників
            </label>
            {form.limited && (
              <FormGroup label="Максимум учасників">
                <input type="number" min="1" step="1" value={form.maxMembers} onChange={(e) => setForm({ ...form, maxMembers: e.target.value })} />
              </FormGroup>
            )}
            <FormGroup label="Контактна особа / платник (необов'язково)">
              <ClientSearchPicker value={form.owner} onChange={(c) => setForm({ ...form, owner: c })} />
            </FormGroup>
            <FormGroup label="Примітка">
              <textarea rows="2" placeholder="Необов'язково..." value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>
    </>
  );
}
