import { useEffect, useMemo, useState } from 'react';
import AppLayout from '../components/layout/AppLayout';
import Badge from '../components/ui/Badge';
import { useAuth } from '../context/AuthContext';
import { usePermissions } from '../hooks/usePermissions';
import { useToast } from '../components/ui/ToastProvider';
import { getUsers, getRoles as getTeamRoles } from '../api/users';
import {
  getPermissionsCatalog, getClubPermissionMatrix, saveClubPermissionMatrix,
  getSystemPermissionDefaults, saveSystemPermissionDefaults,
} from '../api/permissions';
import { NAV_ITEMS, MAIN_SLUGS, MANAGE_SLUGS, MENU_GATABLE_SLUGS, PLAN_GATABLE_SLUGS } from '../components/layout/navItems';
import { getInitials } from '../utils/format';
import './AccessPage.css';

// Порядок і українські назви категорій sys_permissions.category — 1:1 з модулями застосунку.
const CATEGORY_ORDER = ['dashboard', 'clients', 'invoices', 'payments', 'tariffs', 'visits', 'products', 'arrivals', 'sales', 'warehouse', 'finance', 'certificates', 'cash', 'trainers', 'users', 'billing', 'settings', 'access', 'telegram', 'support'];
const CATEGORY_LABELS = {
  dashboard: 'Дашборд', clients: 'Клієнти', invoices: 'Абонементи', payments: 'Оплати',
  tariffs: 'Тарифи', visits: 'Відвідування', products: 'Товари', arrivals: 'Прихід',
  sales: 'Продажі', warehouse: 'Склад', finance: 'Фінанси', certificates: 'Сертифікати', cash: 'Каса',
  trainers: 'Тренери', users: 'Команда', billing: 'Підписка', settings: 'Налаштування клубу',
  access: 'Доступ і ролі', telegram: 'Telegram-бот', support: 'Підтримка',
};
const ROLE_ORDER = ['trainer', 'manager', 'owner'];
const LEVEL_BADGE = { trainer: 'trainer', manager: 'manager', owner: 'owner' };

const cellKey = (roleId, slug) => `${roleId}:${slug}`;
const splitKey = (key) => { const i = key.indexOf(':'); return [parseInt(key.slice(0, i), 10), key.slice(i + 1)]; };
const setsEqual = (a, b) => a.size === b.size && [...a].every((x) => b.has(x));

function groupByCategory(permissions) {
  const byCat = {};
  for (const p of permissions) (byCat[p.category] ??= []).push(p);
  const cats = Object.keys(byCat).sort((a, b) => {
    const ia = CATEGORY_ORDER.indexOf(a), ib = CATEGORY_ORDER.indexOf(b);
    return (ia === -1 ? 999 : ia) - (ib === -1 ? 999 : ib);
  });
  return cats.map((cat) => ({
    category: cat,
    label: CATEGORY_LABELS[cat] || cat,
    actions: [...byCat[cat]].sort((a, b) => a.sort_order - b.sort_order),
  }));
}

/**
 * Таблиця "дія × роль" з чекбоксами. `checked`/`baseline` — Set("roleId:slug"); клітинки, що відрізняються від baseline, підсвічуються.
 * Desktop — та сама таблиця "дія × роль" (усі ролі колонками), без змін.
 * Mobile (<768px) — таблиця з усіма ролями поруч не влазить без горизонтального скролу,
 * тож замість неї перемикач ролей (вкладки) + вертикальний список прав для однієї обраної ролі.
 */
function PermissionMatrix({ groups, roles, checked, baseline, editable, onToggle }) {
  const [activeRoleId, setActiveRoleId] = useState(roles[0]?.id);
  useEffect(() => {
    if (roles.length && !roles.some((r) => r.id === activeRoleId)) setActiveRoleId(roles[0].id);
  }, [roles]); // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <>
      <div className="table-wrap no-mobile-cards perm-matrix-desktop">
        <table className="perm-matrix">
          <thead>
            <tr>
              <th>Дія</th>
              {roles.map((r) => (
                <th key={r.id} style={{ textAlign: 'center' }}>
                  {r.name_ua} <span style={{ color: 'var(--text-muted)', fontWeight: 400 }}>({r.level})</span>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {groups.flatMap((g) => [
              <tr key={`h-${g.category}`} className="perm-matrix-group">
                <td colSpan={roles.length + 1}>{g.label}</td>
              </tr>,
              ...g.actions.map((a) => (
                <tr key={a.slug}>
                  <td>{a.label}</td>
                  {roles.map((r) => {
                    const key = cellKey(r.id, a.slug);
                    const isChecked = checked.has(key);
                    const overridden = baseline && checked.has(key) !== baseline.has(key);
                    return (
                      <td key={r.id} data-label={r.name_ua} className={overridden ? 'perm-overridden' : ''} style={{ textAlign: 'center' }}>
                        <input
                          type="checkbox"
                          checked={isChecked}
                          disabled={!editable}
                          onChange={() => onToggle(key)}
                        />
                      </td>
                    );
                  })}
                </tr>
              )),
            ])}
          </tbody>
        </table>
      </div>

      <div className="perm-matrix-mobile">
        <div className="perm-role-tabs">
          {roles.map((r) => (
            <button
              key={r.id}
              type="button"
              className={`perm-role-tab ${activeRoleId === r.id ? 'active' : ''}`}
              onClick={() => setActiveRoleId(r.id)}
            >
              {r.name_ua}
            </button>
          ))}
        </div>
        {groups.map((g) => (
          <div key={g.category} className="perm-role-group">
            <div className="perm-role-group-label">{g.label}</div>
            {g.actions.map((a) => {
              const key = cellKey(activeRoleId, a.slug);
              const isChecked = checked.has(key);
              const overridden = baseline && checked.has(key) !== baseline.has(key);
              return (
                <label key={a.slug} className={`perm-role-row ${overridden ? 'perm-overridden' : ''}`}>
                  <span>{a.label}</span>
                  <input
                    type="checkbox"
                    checked={isChecked}
                    disabled={!editable}
                    onChange={() => onToggle(key)}
                  />
                </label>
              );
            })}
          </div>
        ))}
      </div>
    </>
  );
}

export default function AccessPage() {
  const { club } = useAuth();
  const { isSuperAdmin } = usePermissions();
  const toast = useToast();
  const hasClub = !!club;

  const [teamRoles, setTeamRoles] = useState([]);
  const [members, setMembers] = useState([]);
  const [loadingTeam, setLoadingTeam] = useState(true);

  const [catalog, setCatalog] = useState(null); // { permissions, roles }

  const [clubDefaults, setClubDefaults] = useState(new Set());
  const [clubOriginal, setClubOriginal] = useState(new Set());
  const [clubChecked, setClubChecked] = useState(new Set());
  const [clubCanEdit, setClubCanEdit] = useState(false);
  const [loadingMatrix, setLoadingMatrix] = useState(true);
  const [savingClub, setSavingClub] = useState(false);

  const [sysOriginal, setSysOriginal] = useState(new Set());
  const [sysChecked, setSysChecked] = useState(new Set());
  const [loadingSys, setLoadingSys] = useState(false);
  const [savingSys, setSavingSys] = useState(false);

  useEffect(() => {
    if (!hasClub) { setLoadingTeam(false); return; }
    (async () => {
      const [rolesRes, usersRes] = await Promise.all([getTeamRoles(), getUsers()]);
      if (rolesRes.success) setTeamRoles(rolesRes.roles || []);
      if (usersRes.success) setMembers(usersRes.users || []);
      setLoadingTeam(false);
    })();
  }, [hasClub]);

  useEffect(() => {
    (async () => {
      const res = await getPermissionsCatalog();
      if (res.success) setCatalog({ permissions: res.permissions, roles: res.roles });
    })();
  }, []);

  useEffect(() => {
    if (!hasClub) { setLoadingMatrix(false); return; }
    (async () => {
      setLoadingMatrix(true);
      const res = await getClubPermissionMatrix();
      if (res.success) {
        const def = new Set(res.defaults.map((r) => cellKey(r.role_id, r.permission_slug)));
        const eff = new Set(def);
        for (const o of res.overrides) {
          const key = cellKey(o.role_id, o.permission_slug);
          if (o.is_allowed) eff.add(key); else eff.delete(key);
        }
        setClubDefaults(def);
        setClubOriginal(eff);
        setClubChecked(eff);
        setClubCanEdit(!!res.can_edit);
      }
      setLoadingMatrix(false);
    })();
  }, [hasClub, club?.id]);

  useEffect(() => {
    if (!isSuperAdmin || hasClub) return;
    (async () => {
      setLoadingSys(true);
      const res = await getSystemPermissionDefaults();
      if (res.success) {
        const set = new Set(res.defaults.map((r) => cellKey(r.role_id, r.permission_slug)));
        setSysOriginal(set);
        setSysChecked(set);
      }
      setLoadingSys(false);
    })();
  }, [isSuperAdmin, hasClub]);

  const groups = useMemo(() => (catalog ? groupByCategory(catalog.permissions) : []), [catalog]);
  const matrixRoles = useMemo(() => {
    if (!catalog) return [];
    return ROLE_ORDER.map((slug) => catalog.roles.find((r) => r.slug === slug)).filter(Boolean);
  }, [catalog]);

  const clubDirty = useMemo(() => !setsEqual(clubChecked, clubOriginal), [clubChecked, clubOriginal]);
  const sysDirty = useMemo(() => !setsEqual(sysChecked, sysOriginal), [sysChecked, sysOriginal]);

  function toggleClubCell(key) {
    setClubChecked((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key); else next.add(key);
      return next;
    });
  }
  function toggleSysCell(key) {
    setSysChecked((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key); else next.add(key);
      return next;
    });
  }

  async function saveClub() {
    setSavingClub(true);
    const rows = [];
    const allKeys = new Set([...clubDefaults, ...clubChecked]);
    for (const key of allKeys) {
      const inDefault = clubDefaults.has(key);
      const inChecked = clubChecked.has(key);
      if (inDefault !== inChecked) {
        const [roleId, slug] = splitKey(key);
        rows.push({ role_id: roleId, permission_slug: slug, is_allowed: inChecked });
      }
    }
    const res = await saveClubPermissionMatrix(rows);
    setSavingClub(false);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message || 'Збережено', 'success');
    setClubOriginal(new Set(clubChecked));
  }

  async function saveSys() {
    if (!confirm('Це змінить права доступу за замовчуванням для ВСІХ клубів, які не налаштовували їх індивідуально. Продовжити?')) return;
    setSavingSys(true);
    const rows = [...sysChecked].map((key) => {
      const [roleId, slug] = splitKey(key);
      return { role_id: roleId, permission_slug: slug };
    });
    const res = await saveSystemPermissionDefaults(rows);
    setSavingSys(false);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast(res.message || 'Золотий стандарт збережено', 'success');
    setSysOriginal(new Set(sysChecked));
  }

  return (
    <AppLayout title="Доступ і ролі">
      {isSuperAdmin && !hasClub && (
        <div className="card" style={{ marginBottom: 20, padding: 0, overflow: 'hidden' }}>
          <div style={{ padding: '16px 20px', display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 10 }}>
            <div>
              <div className="card-title">Золотий стандарт доступу</div>
              <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2, maxWidth: 480 }}>
                Застосовується автоматично для всіх нових клубів і для будь-якого клубу, який ще не налаштовував права доступу індивідуально.
              </div>
            </div>
            <div style={{ display: 'flex', gap: 8 }}>
              <button className="btn btn-ghost btn-sm" disabled={!sysDirty} onClick={() => setSysChecked(new Set(sysOriginal))}>Скасувати</button>
              <button className="btn btn-primary btn-sm" disabled={!sysDirty || savingSys} onClick={saveSys}>{savingSys ? 'Збереження...' : 'Зберегти'}</button>
            </div>
          </div>
          {(loadingSys || !catalog) && <div className="loader" style={{ padding: 20 }}><span className="spinner" /></div>}
          {!loadingSys && catalog && (
            <PermissionMatrix groups={groups} roles={matrixRoles} checked={sysChecked} baseline={sysOriginal} editable onToggle={toggleSysCell} />
          )}
        </div>
      )}

      {hasClub && (
        <div className="card" style={{ marginBottom: 20, padding: 0, overflow: 'hidden' }}>
          <div style={{ padding: '16px 20px', display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 10 }}>
            <div>
              <div className="card-title">Ролі та можливості{club ? ` — ${club.name}` : ''}</div>
              <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>
                {clubCanEdit
                  ? 'Редагувати може лише власник клубу. Непозначені клітинки — стандартна поведінка застосунку.'
                  : 'Редагувати права доступу може лише власник клубу.'}
              </div>
            </div>
            {clubCanEdit && (
              <div style={{ display: 'flex', gap: 8 }}>
                <button className="btn btn-ghost btn-sm" onClick={() => setClubChecked(new Set(clubDefaults))}>Скинути до стандарту</button>
                <button className="btn btn-ghost btn-sm" disabled={!clubDirty} onClick={() => setClubChecked(new Set(clubOriginal))}>Скасувати</button>
                <button className="btn btn-primary btn-sm" disabled={!clubDirty || savingClub} onClick={saveClub}>{savingClub ? 'Збереження...' : 'Зберегти'}</button>
              </div>
            )}
          </div>
          {(loadingMatrix || !catalog) && <div className="loader" style={{ padding: 20 }}><span className="spinner" /></div>}
          {!loadingMatrix && catalog && (
            <PermissionMatrix groups={groups} roles={matrixRoles} checked={clubChecked} baseline={clubDefaults} editable={clubCanEdit} onToggle={toggleClubCell} />
          )}
          <div style={{ padding: '10px 20px 16px', fontSize: 12, color: 'var(--text-muted)' }}>
            SuperAdmin (100) має все перелічене вище для будь-якого клубу, а також окремі розділи поза клубом: Всі клуби, Білінг платформи, Платежі.
          </div>
        </div>
      )}

      {!hasClub && !isSuperAdmin && (
        <div className="card" style={{ color: 'var(--text-muted)', textAlign: 'center', padding: '30px 0' }}>
          Оберіть клуб, щоб побачити команду та права доступу
        </div>
      )}

      {hasClub && (
        <>
          <div className="card" style={{ marginBottom: 20 }}>
            <div className="card-title" style={{ marginBottom: 14 }}>Команда клубу{club ? ` — ${club.name}` : ''}</div>
            {loadingTeam && <div className="loader"><span className="spinner" /></div>}
            {!loadingTeam && teamRoles.length === 0 && members.length === 0 && (
              <div style={{ color: 'var(--text-muted)', fontSize: 13 }}>Немає даних про роль</div>
            )}
            {!loadingTeam && (
              <div style={{ display: 'grid', gap: 16 }}>
                {['owner', 'manager', 'trainer'].map((slug) => {
                  const roleMembers = members.filter((m) => m.role_slug === slug);
                  if (roleMembers.length === 0) return null;
                  const roleInfo = teamRoles.find((r) => r.slug === slug);
                  return (
                    <div key={slug}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
                        <Badge variant={LEVEL_BADGE[slug]}>{roleInfo?.name_ua || slug}</Badge>
                        <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>{roleMembers.length} {roleMembers.length === 1 ? 'особа' : 'осіб'}</span>
                      </div>
                      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10 }}>
                        {roleMembers.map((m) => (
                          <div key={m.id} className="access-member" style={{ opacity: m.is_active && m.club_access ? 1 : 0.5 }}>
                            <div className="access-member-avatar">{getInitials(m.full_name)}</div>
                            <div>
                              <div style={{ fontSize: 13, fontWeight: 500 }}>{m.full_name}</div>
                              <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{m.email}</div>
                            </div>
                          </div>
                        ))}
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </div>

          <MenuVisibilityCard />
          <div style={{ height: 14 }} />
          <PlanRestrictionCard />
        </>
      )}
    </AppLayout>
  );
}

/** Видимість пунктів меню за роллю в клубі (auth.menu, club_menu_settings) — окремо від тарифного плану, див. PlanRestrictionCard нижче. */
function MenuVisibilityCard() {
  const { menu } = useAuth();
  const hasMenu = menu && Object.keys(menu).length > 0;
  // Лише сторінки з menuGate: true (navItems.js) реально підпадають під це налаштування —
  // "Склад"/"Доступ і ролі" в auth.menu просто немає (бекенд про них не знає), тому їх сюди не додаємо.
  const included = hasMenu ? MENU_GATABLE_SLUGS.filter((s) => menu[s] === true) : MENU_GATABLE_SLUGS;
  const excluded = hasMenu ? MENU_GATABLE_SLUGS.filter((s) => menu[s] !== true) : [];

  return (
    <div className="card">
      <div className="card-title" style={{ marginBottom: 14 }}>Видимість розділів меню (за роллю)</div>
      {!hasMenu ? (
        <div style={{ fontSize: 13, color: 'var(--text-secondary)' }}>Меню не обмежене — доступні всі розділи, на які вистачає рівня доступу.</div>
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: '6px 16px' }}>
          {included.map((s) => (
            <div key={s} style={{ fontSize: 13, color: 'var(--text-secondary)' }}>✅ {NAV_ITEMS[s]?.label || s}</div>
          ))}
          {excluded.map((s) => (
            <div key={s} style={{ fontSize: 13, color: 'var(--text-muted)' }}>🔒 {NAV_ITEMS[s]?.label || s}</div>
          ))}
        </div>
      )}
    </div>
  );
}

/** Обмеження доступу тарифним SaaS-планом клубу (auth.allowed_pages, saas_plans.allowed_pages) — окремо від видимості за роллю вище. */
function PlanRestrictionCard() {
  const { allowedPages, planIsFree } = useAuth();
  const restricted = Array.isArray(allowedPages);
  const included = restricted ? PLAN_GATABLE_SLUGS.filter((s) => allowedPages.includes(s)) : PLAN_GATABLE_SLUGS;
  const excluded = restricted ? PLAN_GATABLE_SLUGS.filter((s) => !allowedPages.includes(s)) : [];

  return (
    <div className="card">
      <div className="card-title" style={{ marginBottom: 14 }}>Доступ за тарифним планом{planIsFree ? ' (безкоштовний план)' : ''}</div>
      {!restricted ? (
        <div style={{ fontSize: 13, color: 'var(--text-secondary)' }}>Тариф не обмежує сторінки — доступні всі розділи, на які вистачає ролі.</div>
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: '6px 16px' }}>
          {included.map((s) => (
            <div key={s} style={{ fontSize: 13, color: 'var(--text-secondary)' }}>✅ {NAV_ITEMS[s]?.label || s}</div>
          ))}
          {excluded.map((s) => (
            <div key={s} style={{ fontSize: 13, color: 'var(--text-muted)' }}>🔒 {NAV_ITEMS[s]?.label || s}</div>
          ))}
        </div>
      )}
    </div>
  );
}
