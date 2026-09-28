import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import AppLayout from '../components/layout/AppLayout';
import Table from '../components/ui/Table';
import Pagination from '../components/ui/Pagination';
import Modal from '../components/ui/Modal';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import ClientFormModal from '../components/ui/ClientFormModal';
import ImportClientsModal from '../components/ui/ImportClientsModal';
import { usePermissions } from '../hooks/usePermissions';
import { useShiftLock } from '../hooks/useShiftLock';
import { useEntitlements } from '../hooks/useEntitlements';
import { useToast } from '../components/ui/ToastProvider';
import { useAuth } from '../context/AuthContext';
import { getClients } from '../api/clients';
import { formatMoney, formatDate, getInitials } from '../utils/format';
import './ClientsPage.css';

const STATUS_MAP = {
  regular: ['info', 'Звичайний'],
  premium: ['owner', 'Преміум'],
  blocked: ['inactive', 'Заблокований'],
};
const STATUS_ACCENT = { regular: 'var(--info)', premium: '#c4b5fd', blocked: 'var(--danger)' };
const ORDER_OPTIONS = [
  ['created_at|desc', 'Спочатку нові'],
  ['created_at|asc', 'Спочатку старі'],
  ['full_name|asc', 'А → Я'],
  ['full_name|desc', 'Я → А'],
];

const EMPTY_DATA = { clients: [], pagination: { total: 0, page: 1, pages: 1, per_page: 25 } };

export default function ClientsPage() {
  const { has } = usePermissions();
  const { clientsUnlimited } = useAuth();
  const { guard, lockedProps } = useShiftLock();
  const { guardAddClient } = useEntitlements();
  const toast = useToast();
  const navigate = useNavigate();

  const [importOpen, setImportOpen] = useState(false);
  const [proUpsellOpen, setProUpsellOpen] = useState(false);

  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [order, setOrder] = useState('created_at|desc');
  const [page, setPage] = useState(1);

  const [data, setData] = useState(EMPTY_DATA);
  const [loading, setLoading] = useState(true);

  const [editClientId, setEditClientId] = useState(null);
  const [formOpen, setFormOpen] = useState(false);

  async function reload() {
    setLoading(true);
    const [orderField, orderDir] = order.split('|');
    const res = await getClients({ search, status, page, per_page: 25, order: orderField, dir: orderDir });
    setLoading(false);
    if (!res.success) {
      toast(res.error, 'error');
      setData(EMPTY_DATA);
      return;
    }
    setData({ clients: res.clients, pagination: res.pagination });
  }

  useEffect(() => {
    const t = setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 350);
    return () => clearTimeout(t);
  }, [searchInput]);

  useEffect(() => {
    reload();
  }, [search, status, order, page]);

  function onFilterChange(setter) {
    return (e) => {
      setter(e.target.value);
      setPage(1);
    };
  }

  function openAdd() {
    setEditClientId(null);
    setFormOpen(true);
  }

  function openEdit(id) {
    setEditClientId(id);
    setFormOpen(true);
  }

  function handleFormSaved(message) {
    toast(message, 'success');
    reload();
  }

  const columns = [
    {
      key: 'client',
      label: 'Клієнт',
      render: (c) => (
        <div className="client-info">
          <div className="client-avatar">{getInitials(c.full_name)}</div>
          <div>
            <div className="client-name">{c.full_name}</div>
            <div className="client-phone">{c.phone || '—'}</div>
          </div>
        </div>
      ),
    },
    {
      key: 'status',
      label: 'Статус',
      cardTop: true,
      render: (c) => {
        const [variant, label] = STATUS_MAP[c.status] || ['info', c.status];
        return <Badge variant={variant}>{label}</Badge>;
      },
    },
    {
      key: 'tariff',
      label: 'Абонемент',
      icon: 'tag',
      mobile: 'secondary',
      render: (c) => {
        if (!c.active_tariff) return <span className="text-muted" style={{ fontSize: 12 }}>—</span>;
        const daysLeft = Math.ceil((new Date(c.tariff_end_date) - new Date()) / 86400000);
        const cls = daysLeft <= 0 ? 'expired' : daysLeft <= 7 ? 'soon' : '';
        const label = daysLeft <= 0 ? 'Прострочено' : `до ${formatDate(c.tariff_end_date)}`;
        return (
          <>
            <div className="tariff-badge" title={c.active_tariff}>{c.active_tariff}</div>
            <div className={`tariff-expire ${cls}`}>{label}</div>
          </>
        );
      },
    },
    { key: 'last_visit', label: 'Останнє відвідування', icon: 'calendar', render: (c) => <span style={{ fontSize: 13 }}>{c.last_visit ? formatDate(c.last_visit) : '—'}</span> },
    { key: 'balance', label: 'Баланс', icon: 'wallet', render: (c) => <span style={{ fontSize: 13 }}>{c.balance != 0 ? formatMoney(c.balance) : '—'}</span> },
    {
      key: 'actions',
      label: '',
      render: (c) => has('clients.edit') && (
        <button
          className="btn btn-ghost btn-sm"
          title="Редагувати"
          {...lockedProps}
          onClick={guard((e) => { e.stopPropagation(); openEdit(c.id); })}
        >
          <Icon name="edit" size={14} />
          <span className="btn-label-mobile">Редагувати</span>
        </button>
      ),
    },
  ];

  return (
    <AppLayout title="Клієнти">
      <div className="toolbar">
        <div className="search-wrap">
          <span className="search-icon">🔍</span>
          <input type="text" placeholder="Ім'я, телефон, email..." value={searchInput} onChange={(e) => setSearchInput(e.target.value)} />
        </div>
        <select className="filter-select" value={status} onChange={onFilterChange(setStatus)}>
          <option value="">Всі статуси</option>
          <option value="regular">Звичайні</option>
          <option value="premium">Преміум</option>
          <option value="blocked">Заблоковані</option>
        </select>
        <select className="filter-select" value={order} onChange={onFilterChange(setOrder)}>
          {ORDER_OPTIONS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        {has('clients.create') && (
          clientsUnlimited ? (
            <button className="btn btn-ghost" {...lockedProps} onClick={guard(() => setImportOpen(true))}>
              <Icon name="fileText" size={15} /> Імпорт
            </button>
          ) : (
            <button className="btn btn-ghost" title="Доступно на тарифі без ліміту клієнтів" onClick={() => setProUpsellOpen(true)}>
              <Icon name="lock" size={15} /> Імпорт <Badge variant="owner">PRO</Badge>
            </button>
          )
        )}
        {has('clients.create') && (
          <button className="btn btn-primary" {...lockedProps} onClick={guard(guardAddClient(openAdd))}>+ Додати клієнта</button>
        )}
      </div>

      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <Table
          columns={columns}
          rows={data.clients}
          loading={loading}
          emptyMessage="Клієнтів не знайдено"
          onRowClick={(row) => navigate(`/clients/${row.id}`)}
          rowProps={(row) => ({ style: { '--card-accent': STATUS_ACCENT[row.status] || 'var(--border-light)' } })}
        />
      </div>

      <Pagination
        page={data.pagination.page}
        pages={data.pagination.pages}
        total={data.pagination.total}
        perPage={data.pagination.per_page}
        onChange={setPage}
      />

      {/* Модалка додати/редагувати */}
      <ClientFormModal clientId={editClientId} open={formOpen} onClose={() => setFormOpen(false)} onSaved={handleFormSaved} />

      {/* Імпорт клієнтів з Excel */}
      <ImportClientsModal open={importOpen} onClose={() => setImportOpen(false)} onImported={reload} />

      {/* Апсел на тариф PRO */}
      <Modal
        size="sm"
        open={proUpsellOpen}
        onClose={() => setProUpsellOpen(false)}
        title="Імпорт клієнтів — фіча без обмежень"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" onClick={() => { setProUpsellOpen(false); navigate('/billing'); }}>Переглянути тарифи</button>
            <button className="btn btn-ghost" onClick={() => setProUpsellOpen(false)}>Закрити</button>
          </div>
        }
      >
        <p style={{ color: 'var(--text-secondary)', fontSize: 14, lineHeight: 1.5 }}>
          Масовий імпорт клієнтів з Excel-файлу доступний лише на тарифі без обмеження кількості клієнтів.
          Оновіть тариф клубу, щоб завантажувати список клієнтів одним файлом замість ручного додавання.
        </p>
      </Modal>
    </AppLayout>
  );
}
