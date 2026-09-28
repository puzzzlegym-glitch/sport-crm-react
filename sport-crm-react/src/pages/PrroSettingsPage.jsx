import { useEffect, useState } from 'react';
import AppLayout from '../components/layout/AppLayout';
import Table from '../components/ui/Table';
import Icon from '../components/ui/Icon';
import Badge from '../components/ui/Badge';
import { useToast } from '../components/ui/ToastProvider';
import {
  getPrroSettings, savePrroSettings, syncPrroCashier,
  getPrroPaymentMethods, savePrroPaymentMethods,
} from '../api/prro';
import { getAcquiringSettings, saveAcquiringSettings, testAcquiringConnection } from '../api/acquiring';
import { formatDate, formatTime } from '../utils/format';
import './PrroSettingsPage.css';

const METHOD_LABELS = { cash: 'Готівка', card: 'Картка', terminal: 'Термінал', deposit: 'Депозит', other: 'Інше' };
const METHOD_ORDER = ['cash', 'card', 'terminal', 'deposit', 'other'];

const EMPTY_FORM = { cashier_login: '', cashier_password: '', license_key: '', cash_register_id: '', cashier_id: '', is_active: false };
const EMPTY_CK_EDITING = { cashier_login: false, cashier_password: false, license_key: false, cash_register_id: false, cashier_id: false };

const EMPTY_ACQ_FORM = { device_name: '', dm_proxy_token: '' };
const EMPTY_ACQ_EDITING = { device_name: false, dm_proxy_token: false };

/** Заголовок картки: іконка + назва/підзаголовок + шеврон, що згортає/розгортає тіло картки. */
function SectionHeader({ icon, tint, title, subtitle, open, onToggle }) {
  return (
    <div className="prro-card-header collapsible" onClick={onToggle} role="button" tabIndex={0}>
      <div className="prro-card-icon" style={{ background: tint.bg, color: tint.color }}>
        <Icon name={icon} size={20} />
      </div>
      <div className="prro-card-heading">
        <div className="prro-card-title">{title}</div>
        {subtitle && <div className="prro-card-subtitle">{subtitle}</div>}
      </div>
      <span className="prro-card-header-toggle">
        <Icon name={open ? 'chevronDown' : 'chevronRight'} size={18} />
      </span>
    </div>
  );
}

/**
 * Рядок налаштування з режимом перегляду/редагування.
 * Якщо значення ще не задано (empty) — рядок одразу показує поле вводу (children),
 * ховати порожнє поле за іконкою редагування немає сенсу. Якщо значення вже є —
 * показується як текст + іконка "редагувати", що розкриває children для зміни.
 */
function SettingsRow({ label, value, empty, editing, onEdit, children }) {
  if (empty || editing) {
    return (
      <div className="form-group">
        <label>{label}</label>
        {children}
      </div>
    );
  }
  return (
    <div className="settings-view-row">
      <div className="settings-view-row-text">
        <span className="settings-view-row-label">{label}</span>
        <span className="settings-view-row-value">{value}</span>
      </div>
      <button type="button" className="settings-view-row-edit" title="Редагувати" onClick={onEdit}>
        <Icon name="edit" size={14} />
      </button>
    </div>
  );
}

export default function PrroSettingsPage() {
  const toast = useToast();

  const [ckOpen, setCkOpen] = useState(false);
  const [methodsOpen, setMethodsOpen] = useState(false);
  const [acqOpen, setAcqOpen] = useState(false);

  const [settings, setSettings] = useState(null);
  const [form, setForm] = useState(EMPTY_FORM);
  const [ckEditing, setCkEditing] = useState(EMPTY_CK_EDITING);
  const [saving, setSaving] = useState(false);
  const [syncing, setSyncing] = useState(false);
  const [showPassword, setShowPassword] = useState(false);

  const [methods, setMethods] = useState(null);
  const [methodsSaving, setMethodsSaving] = useState(false);

  const [acqSettings, setAcqSettings] = useState(null);
  const [acqForm, setAcqForm] = useState(EMPTY_ACQ_FORM);
  const [acqEditing, setAcqEditing] = useState(EMPTY_ACQ_EDITING);
  const [acqActive, setAcqActive] = useState(false);
  const [acqSaving, setAcqSaving] = useState(false);
  const [acqTesting, setAcqTesting] = useState(false);
  const [showAcqToken, setShowAcqToken] = useState(false);

  async function reload() {
    const [settingsRes, methodsRes, acqRes] = await Promise.all([getPrroSettings(), getPrroPaymentMethods(), getAcquiringSettings()]);
    if (settingsRes.success) {
      setSettings(settingsRes.settings);
      setForm({
        cashier_login: settingsRes.settings.cashier_login || '',
        cashier_password: '',
        license_key: '',
        cash_register_id: settingsRes.settings.cash_register_id || '',
        cashier_id: settingsRes.settings.cashier_id || '',
        is_active: !!settingsRes.settings.is_active,
      });
      setCkEditing(EMPTY_CK_EDITING);
    } else {
      toast(settingsRes.error, 'error');
    }
    if (methodsRes.success) setMethods(methodsRes.methods);
    else toast(methodsRes.error, 'error');

    if (acqRes.success) {
      setAcqSettings(acqRes.settings);
      setAcqForm({ device_name: acqRes.settings.device_name || '', dm_proxy_token: '' });
      setAcqActive(!!acqRes.settings.is_active);
      setAcqEditing(EMPTY_ACQ_EDITING);
    }
  }

  useEffect(() => { reload(); }, []);

  async function submitSettings(e) {
    e.preventDefault();
    setSaving(true);
    const res = await savePrroSettings(form);
    setSaving(false);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message || 'Збережено', 'success');
    setForm((f) => ({ ...f, cashier_password: '', license_key: '' }));
    reload();
  }

  async function handleSync() {
    setSyncing(true);
    const res = await syncPrroCashier();
    setSyncing(false);
    toast(res.success ? (res.message || 'Синхронізовано') : res.error, res.success ? 'success' : 'error');
    reload();
  }

  function toggleMethod(method) {
    setMethods((list) => list.map((m) => (m.payment_method === method ? { ...m, auto_fiscalize: m.auto_fiscalize ? 0 : 1 } : m)));
  }

  async function submitMethods() {
    setMethodsSaving(true);
    const res = await savePrroPaymentMethods(methods);
    setMethodsSaving(false);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message || 'Збережено', 'success');
  }

  const sortedMethods = methods ? [...methods].sort((a, b) => METHOD_ORDER.indexOf(a.payment_method) - METHOD_ORDER.indexOf(b.payment_method)) : [];

  async function submitAcquiring(e) {
    e.preventDefault();
    setAcqSaving(true);
    const res = await saveAcquiringSettings({ ...acqForm, is_active: acqActive });
    setAcqSaving(false);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message || 'Збережено', 'success');
    setAcqForm((f) => ({ ...f, dm_proxy_token: '' }));
    reload();
  }

  async function handleAcqTest() {
    setAcqTesting(true);
    const res = await testAcquiringConnection();
    setAcqTesting(false);
    toast(res.error || 'З\'єднання працює', res.success ? 'success' : 'info');
  }

  return (
    <AppLayout title="ПРРО / Термінали" subtitle="Автоматична фіскалізація оплат через Checkbox">
      <div className="prro-grid">
        {/* ── Підключення до Checkbox ─────────────────────────────── */}
        <div className="prro-section">
          <SectionHeader
            icon="receipt"
            tint={{ bg: 'rgba(52,211,153,.15)', color: 'var(--success)' }}
            title="Підключення до Checkbox"
            subtitle="Дані каси й касира з особистого кабінету my.checkbox.ua"
            open={ckOpen}
            onToggle={() => setCkOpen((v) => !v)}
          />
          {ckOpen && (
            <div className="prro-card-body">
              <form onSubmit={submitSettings} className="prro-form">
                <SettingsRow
                  label="Логін касира"
                  value={settings?.cashier_login}
                  empty={!settings?.cashier_login}
                  editing={ckEditing.cashier_login}
                  onEdit={() => setCkEditing({ ...ckEditing, cashier_login: true })}
                >
                  <input
                    type="text"
                    value={form.cashier_login}
                    onChange={(e) => setForm({ ...form, cashier_login: e.target.value })}
                    placeholder="Логін з кабінету Checkbox"
                  />
                </SettingsRow>

                <SettingsRow
                  label="Пароль касира"
                  value="••••••••"
                  empty={!settings?.has_password}
                  editing={ckEditing.cashier_password}
                  onEdit={() => setCkEditing({ ...ckEditing, cashier_password: true })}
                >
                  <div className="input-icon-wrap has-eye">
                    <input
                      type={showPassword ? 'text' : 'password'}
                      value={form.cashier_password}
                      onChange={(e) => setForm({ ...form, cashier_password: e.target.value })}
                      placeholder={settings?.has_password ? '•••••••• (збережено)' : 'Введіть пароль'}
                    />
                    <button type="button" className="input-eye-btn" tabIndex={-1} onClick={() => setShowPassword((v) => !v)}>
                      <Icon name={showPassword ? 'eyeOff' : 'eye'} size={16} />
                    </button>
                  </div>
                </SettingsRow>

                <SettingsRow
                  label="Ліцензійний ключ ПРРО"
                  value="••••••••"
                  empty={!settings?.has_license_key}
                  editing={ckEditing.license_key}
                  onEdit={() => setCkEditing({ ...ckEditing, license_key: true })}
                >
                  <input
                    type="text"
                    value={form.license_key}
                    onChange={(e) => setForm({ ...form, license_key: e.target.value })}
                    placeholder={settings?.has_license_key ? '•••••••• (збережено)' : 'Ключ каси з кабінету Checkbox'}
                  />
                </SettingsRow>

                <SettingsRow
                  label="ID каси (необов'язково)"
                  value={settings?.cash_register_id}
                  empty={!settings?.cash_register_id}
                  editing={ckEditing.cash_register_id}
                  onEdit={() => setCkEditing({ ...ckEditing, cash_register_id: true })}
                >
                  <input type="text" value={form.cash_register_id} onChange={(e) => setForm({ ...form, cash_register_id: e.target.value })} />
                </SettingsRow>

                <SettingsRow
                  label="ID касира (необов'язково)"
                  value={settings?.cashier_id}
                  empty={!settings?.cashier_id}
                  editing={ckEditing.cashier_id}
                  onEdit={() => setCkEditing({ ...ckEditing, cashier_id: true })}
                >
                  <input type="text" value={form.cashier_id} onChange={(e) => setForm({ ...form, cashier_id: e.target.value })} />
                </SettingsRow>

                <label className="prro-toggle-row">
                  <input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
                  <span>Увімкнути автоматичну фіскалізацію для клубу</span>
                </label>

                <div className="prro-actions">
                  <button type="submit" className="btn btn-primary" disabled={saving}>{saving ? '...' : 'Зберегти'}</button>
                  <button type="button" className="btn btn-ghost" disabled={syncing} onClick={handleSync}>
                    <Icon name="refresh" size={15} /> {syncing ? 'Перевірка...' : 'Перевірити підключення'}
                  </button>
                </div>
              </form>

              {settings?.last_sync_at && (
                <div className="prro-sync-status">
                  {settings.last_sync_status === 'ok' ? (
                    <Badge variant="active">Підключення працює</Badge>
                  ) : (
                    <Badge variant="pending">Помилка підключення</Badge>
                  )}
                  <span className="prro-sync-time">{formatDate(settings.last_sync_at)} · {formatTime(settings.last_sync_at)}</span>
                  {settings.last_sync_status !== 'ok' && settings.last_sync_error && (
                    <div className="prro-sync-error">{settings.last_sync_error}</div>
                  )}
                </div>
              )}
            </div>
          )}
        </div>

        {/* ── Способи оплати ───────────────────────────────────────── */}
        <div className="prro-section">
          <SectionHeader
            icon="banknote"
            tint={{ bg: 'rgba(79,156,249,.15)', color: 'var(--accent)' }}
            title="Способи оплати"
            subtitle="Які оплати фіскалізувати автоматично при проведенні"
            open={methodsOpen}
            onToggle={() => setMethodsOpen((v) => !v)}
          />
          {methodsOpen && (
            <div className="prro-card-body">
              <Table
                columns={[
                  { key: 'method', label: 'Спосіб оплати', render: (m) => METHOD_LABELS[m.payment_method] || m.payment_method },
                  {
                    key: 'auto',
                    label: 'Фіскалізувати автоматично',
                    render: (m) => (
                      <label className="prro-toggle-row prro-toggle-inline">
                        <input type="checkbox" checked={!!m.auto_fiscalize} onChange={() => toggleMethod(m.payment_method)} />
                      </label>
                    ),
                  },
                ]}
                rows={sortedMethods}
                keyField="payment_method"
                loading={!methods}
                emptyMessage="Немає способів оплати"
              />
              <div className="prro-actions">
                <button type="button" className="btn btn-primary" disabled={methodsSaving || !methods} onClick={submitMethods}>
                  {methodsSaving ? '...' : 'Зберегти'}
                </button>
              </div>
              <div className="prro-hint">
                Для конкретної оплати фіскалізацію можна вимкнути вручну — перемикач
                "Не проводити фіскальний чек" є у формі продажу/оплати абонемента.
              </div>
            </div>
          )}
        </div>

        {/* ── Еквайринг (POS-термінали) ───────────────────────────── */}
        <div className="prro-section">
          <SectionHeader
            icon="card"
            tint={{ bg: 'rgba(251,191,36,.15)', color: 'var(--warning)' }}
            title="Еквайринг"
            subtitle="Оплата карткою через термінал ПриватБанку (Device Manager від Вчасно.Каса)"
            open={acqOpen}
            onToggle={() => setAcqOpen((v) => !v)}
          />
          {acqOpen && (
            <div className="prro-card-body">
              <form onSubmit={submitAcquiring} className="prro-form">
                <div className="form-group">
                  <label>Провайдер</label>
                  <select value="vchasno_kasa" disabled>
                    <option value="vchasno_kasa">Вчасно.Каса (Device Manager)</option>
                  </select>
                </div>

                <SettingsRow
                  label="Назва пристрою (device)"
                  value={acqSettings?.device_name}
                  empty={!acqSettings?.device_name}
                  editing={acqEditing.device_name}
                  onEdit={() => setAcqEditing({ ...acqEditing, device_name: true })}
                >
                  <input
                    type="text"
                    value={acqForm.device_name}
                    onChange={(e) => setAcqForm({ ...acqForm, device_name: e.target.value })}
                    placeholder="Як термінал названо в Device Manager"
                  />
                </SettingsRow>

                <SettingsRow
                  label="API-токен Device Manager"
                  value="••••••••"
                  empty={!acqSettings?.has_token}
                  editing={acqEditing.dm_proxy_token}
                  onEdit={() => setAcqEditing({ ...acqEditing, dm_proxy_token: true })}
                >
                  <div className="input-icon-wrap has-eye">
                    <input
                      type={showAcqToken ? 'text' : 'password'}
                      value={acqForm.dm_proxy_token}
                      onChange={(e) => setAcqForm({ ...acqForm, dm_proxy_token: e.target.value })}
                      placeholder={acqSettings?.has_token ? '•••••••• (збережено)' : 'X-AP-DM-PROXY-TOKEN з кабінету Вчасно.Каса'}
                    />
                    <button type="button" className="input-eye-btn" tabIndex={-1} onClick={() => setShowAcqToken((v) => !v)}>
                      <Icon name={showAcqToken ? 'eyeOff' : 'eye'} size={16} />
                    </button>
                  </div>
                </SettingsRow>

                <label className="prro-toggle-row">
                  <input type="checkbox" checked={acqActive} onChange={(e) => setAcqActive(e.target.checked)} />
                  <span>Увімкнути еквайринг для клубу</span>
                </label>

                <div className="prro-actions">
                  <button type="submit" className="btn btn-primary" disabled={acqSaving}>{acqSaving ? '...' : 'Зберегти'}</button>
                  <button type="button" className="btn btn-ghost" disabled={acqTesting} onClick={handleAcqTest}>
                    <Icon name="refresh" size={15} /> {acqTesting ? 'Перевірка...' : 'Перевірити з\'єднання'}
                  </button>
                </div>
              </form>

              <div className="prro-hint">
                Клуб самостійно реєструє й оплачує власний кабінет Вчасно.Каса, встановлює застосунок
                Device Manager і підключає до нього термінал ПриватБанку — це не стосується CRM.
                Тут зберігається лише API-токен і назва пристрою з кабінету Вчасно, щоб пізніше CRM
                могла проводити оплату через Device Manager Proxy API. Сам виклик оплати ще не
                реалізовано — фіскалізація чека, як і раніше, іде через Checkbox (окрема секція вище),
                а до появи реального виклику оплата карткою фіксується вручну (кнопка «Оплатити
                карткою» у формі оплати абонемента).
              </div>
            </div>
          )}
        </div>
      </div>
    </AppLayout>
  );
}
