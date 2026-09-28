import { useEffect, useRef, useState } from 'react';
import AppLayout from '../components/layout/AppLayout';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import { usePermissions } from '../hooks/usePermissions';
import { useToast } from '../components/ui/ToastProvider';
import { scanVisit, getVisitStats, getVisitsList, deleteVisitEntry, checkIn, getActiveInvoiceForClient, getCheckinTrainers } from '../api/visits';
import { freezeInvoice } from '../api/invoices';
import { searchClients } from '../api/clients';
import { formatDate, formatTime, formatMoney, localToday, getInitials } from '../utils/format';
import './VisitsPage.css';

const METHOD_ICONS = { barcode: '📷', manual: '✎', admin: '🛡' };

function playBeep(type) {
  try {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    const ctx = new AudioCtx();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.connect(gain);
    gain.connect(ctx.destination);
    if (type === 'ok') {
      osc.frequency.value = 880;
      gain.gain.setValueAtTime(0.3, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.2);
      osc.start(); osc.stop(ctx.currentTime + 0.2);
    } else if (type === 'warn') {
      osc.frequency.value = 440;
      gain.gain.setValueAtTime(0.2, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.3);
      osc.start(); osc.stop(ctx.currentTime + 0.3);
    } else {
      osc.frequency.value = 220;
      gain.gain.setValueAtTime(0.3, ctx.currentTime);
      gain.gain.setValueAtTime(0, ctx.currentTime + 0.15);
      gain.gain.setValueAtTime(0.3, ctx.currentTime + 0.2);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4);
      osc.start(); osc.stop(ctx.currentTime + 0.4);
    }
  } catch { /* тихо ігноруємо якщо браузер не підтримує */ }
}

function VisitRow({ v, isOwner, onDelete }) {
  const time = new Date(v.visited_at).toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit' });
  const method = v.method || 'manual';
  return (
    <div className="visit-row">
      <div className="visit-avatar">{v.client_photo ? <img src={v.client_photo} onError={(e) => { e.target.style.display = 'none'; }} /> : getInitials(v.client_name)}</div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div className="visit-name">{v.client_name}</div>
        <div className="visit-sub">{v.tariff_name || '—'}{v.trainer_name ? ` · ${v.trainer_name}` : ''}</div>
      </div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
        <span className={`visit-method-badge ${method}`}>{METHOD_ICONS[method] || method}</span>
        <div className="visit-time">{time}</div>
        {isOwner && <button className="visit-del-btn" title="Видалити" onClick={() => onDelete(v.id)}>✕</button>}
      </div>
    </div>
  );
}

export default function VisitsPage() {
  const { has } = usePermissions();
  const isOwner = has('visits.delete');
  const toast = useToast();

  const [scanInput, setScanInput] = useState('');
  const [scanResult, setScanResult] = useState(null); // { type, avatar, name, sub, warning, icon }
  const scanInputRef = useRef(null);
  const hideResultTimer = useRef(null);

  const [stats, setStats] = useState(null);

  const [jrnFrom, setJrnFrom] = useState(localToday());
  const [jrnTo, setJrnTo] = useState(localToday());
  const [jrnSearchInput, setJrnSearchInput] = useState('');
  const [jrnSearch, setJrnSearch] = useState('');
  const [jrnPage, setJrnPage] = useState(1);
  const [journal, setJournal] = useState({ visits: [], pagination: { total: 0, page: 1, pages: 1, per_page: 30 }, loading: true });
  const [openGroups, setOpenGroups] = useState(() => new Set([localToday()]));

  const [manualModal, setManualModal] = useState(null);
  const [checkinTrainers, setCheckinTrainers] = useState([]);
  const [deleteConfirm, setDeleteConfirm] = useState(null); // { id, paidAmount, trainerName, submitting, error }

  async function reloadStats() {
    const res = await getVisitStats();
    if (res.success) setStats(res.stats);
  }

  async function reloadJournal() {
    setJournal((j) => ({ ...j, loading: true }));
    const res = await getVisitsList({ date_from: jrnFrom, date_to: jrnTo, search: jrnSearch, page: jrnPage });
    if (!res.success) { setJournal((j) => ({ ...j, loading: false })); return; }
    setJournal({ visits: res.visits, pagination: res.pagination, loading: false });
    setOpenGroups((s) => new Set([...s, localToday()]));
  }

  useEffect(() => {
    reloadStats();
    reloadJournal();
    scanInputRef.current?.focus();
    const t = setInterval(reloadStats, 30000);
    return () => clearInterval(t);
    // eslint-disable-next-line
  }, []);

  useEffect(() => {
    const t = setTimeout(() => { setJrnSearch(jrnSearchInput.trim()); setJrnPage(1); }, 350);
    return () => clearTimeout(t);
  }, [jrnSearchInput]);

  useEffect(() => { reloadJournal(); }, [jrnFrom, jrnTo, jrnSearch, jrnPage]);

  function showScanResult(result) {
    setScanResult(result);
    clearTimeout(hideResultTimer.current);
    hideResultTimer.current = setTimeout(() => setScanResult(null), 4000);
  }

  function showScanSuccess(res) {
    const c = res.client;
    const inv = res.invoice;
    const sub = inv
      ? `${inv.tariff_name}${inv.visits_total ? ` · ${inv.visits_used}/${inv.visits_total} відвід.` : ''} · до ${formatDate(inv.end_date)}`
      : 'Без абонементу';
    const type = res.already_checked_in ? 'already' : res.warning ? 'warning' : 'success';
    showScanResult({ type, photo: c.photo_url, name: c.full_name, sub, warning: res.warning, icon: res.already_checked_in ? 'ℹ️' : res.warning ? '⚠️' : '✅' });
    playBeep(res.already_checked_in ? 'warn' : 'ok');
    setTimeout(reloadStats, 300);
    setTimeout(reloadJournal, 300);
  }

  // Абонемент заморожений — пропонуємо розморозити достроково просто з екрана відмітки,
  // замість того щоб адміністратор йшов у розділ "Абонементи" вручну.
  async function offerUnfreezeAndRetry(res, retryFn, onUnfreezeFail) {
    if (res.reason !== 'frozen' || !res.invoice_id) return false;
    if (!confirm(`${res.error}\n\nРозморозити абонемент достроково і відмітити відвідування?`)) return false;
    const unfreezeRes = await freezeInvoice({ id: res.invoice_id });
    if (!unfreezeRes.success) {
      toast(unfreezeRes.error, 'error');
      if (onUnfreezeFail) onUnfreezeFail(unfreezeRes.error);
      return true;
    }
    await retryFn();
    return true;
  }

  async function doScan() {
    const query = scanInput.trim();
    if (!query) return;
    const res = await scanVisit(query);

    if (res.success) {
      showScanSuccess(res);
    } else if (!(await offerUnfreezeAndRetry(res, async () => {
      const retryRes = await scanVisit(query);
      if (retryRes.success) showScanSuccess(retryRes);
      else { showScanResult({ type: 'error', name: retryRes.error, avatarChar: '✗', icon: '❌' }); playBeep('error'); }
    }))) {
      showScanResult({ type: 'error', name: res.error || 'Клієнта не знайдено', avatarChar: '✗', icon: '❌' });
      playBeep('error');
    }

    setScanInput('');
    setTimeout(() => scanInputRef.current?.focus(), 100);
  }

  async function handleDeleteVisit(id) {
    if (!confirm('Видалити це відвідування?')) return;
    const res = await deleteVisitEntry(id);
    if (!res.success) {
      if (res.requires_confirmation) {
        setDeleteConfirm({ id, paidAmount: res.paid_amount, trainerName: res.trainer_name, submitting: false, error: '' });
        return;
      }
      toast(res.error || 'Помилка', 'error');
      return;
    }
    toast('Відмітку видалено', 'success');
    setJournal((j) => ({ ...j, visits: j.visits.filter((v) => v.id !== id) }));
    reloadStats();
  }

  async function confirmDeleteReversal() {
    setDeleteConfirm((s) => ({ ...s, submitting: true, error: '' }));
    const res = await deleteVisitEntry(deleteConfirm.id, true);
    if (!res.success) { setDeleteConfirm((s) => ({ ...s, submitting: false, error: res.error })); return; }
    toast('Відмітку видалено, кошти повернено', 'success');
    setJournal((j) => ({ ...j, visits: j.visits.filter((v) => v.id !== deleteConfirm.id) }));
    setDeleteConfirm(null);
    reloadStats();
  }

  function toggleGroup(date) {
    setOpenGroups((s) => {
      const next = new Set(s);
      if (next.has(date)) next.delete(date); else next.add(date);
      return next;
    });
  }

  // ── Ручна відмітка ──────────────────────────────────────
  async function openManual() {
    setManualModal({ search: '', results: [], clientId: null, clientName: '', clientPhone: '', notes: '', error: '', submitting: false, alreadyToday: null, trainerId: '', recommendedTrainerName: '', trainerEligible: false });
    if (!checkinTrainers.length) {
      const res = await getCheckinTrainers();
      if (res.success) setCheckinTrainers(res.trainers || []);
    }
  }

  async function selectManualClient(c) {
    setManualModal((m) => (m ? { ...m, clientId: c.id, clientName: c.full_name, clientPhone: c.phone || '', search: '', results: [], alreadyToday: null, trainerId: '', recommendedTrainerName: '', trainerEligible: false } : m));
    const today = localToday();
    const res = await getVisitsList({ date_from: today, date_to: today, search: c.phone || c.full_name });
    if (res.success) {
      const match = res.visits.find((v) => v.client_id === c.id);
      if (match) setManualModal((m) => (m && m.clientId === c.id ? { ...m, alreadyToday: match } : m));
    }
    const invRes = await getActiveInvoiceForClient(c.id);
    if (invRes.success && invRes.invoice?.trainer_id) {
      setManualModal((m) => (m && m.clientId === c.id
        ? { ...m, trainerId: String(invRes.invoice.trainer_id), recommendedTrainerName: invRes.invoice.trainer_name || '', trainerEligible: true }
        : m));
    }
  }

  useEffect(() => {
    if (!manualModal || manualModal.search.trim().length < 2) {
      if (manualModal) setManualModal((m) => ({ ...m, results: [] }));
      return;
    }
    const t = setTimeout(async () => {
      const res = await searchClients(manualModal.search.trim());
      setManualModal((m) => (m ? { ...m, results: res.success ? (res.results || []) : [] } : m));
    }, 300);
    return () => clearTimeout(t);
  }, [manualModal?.search]);

  async function submitManual() {
    if (!manualModal.clientId) { setManualModal({ ...manualModal, error: 'Оберіть клієнта' }); return; }
    setManualModal({ ...manualModal, submitting: true, error: '' });
    const res = await checkIn({ client_id: manualModal.clientId, notes: manualModal.notes.trim(), trainer_id: manualModal.trainerId || 0 });
    if (res.success) {
      toast('Відвідування відмічено', 'success');
      setManualModal(null);
      reloadStats();
      reloadJournal();
      return;
    }
    const handled = await offerUnfreezeAndRetry(
      res,
      async () => {
        const retryRes = await checkIn({ client_id: manualModal.clientId, notes: manualModal.notes.trim(), trainer_id: manualModal.trainerId || 0 });
        if (retryRes.success) {
          toast('Відвідування відмічено', 'success');
          setManualModal(null);
          reloadStats();
          reloadJournal();
        } else {
          setManualModal((m) => (m ? { ...m, submitting: false, error: retryRes.error } : m));
        }
      },
      (err) => setManualModal((m) => (m ? { ...m, submitting: false, error: err } : m)),
    );
    if (!handled) setManualModal((m) => (m ? { ...m, submitting: false, error: res.error } : m));
  }

  // ── Групування журналу по датах ──────────────────────────
  const groups = [];
  for (const v of journal.visits) {
    const date = v.visited_at.slice(0, 10);
    let g = groups.find((x) => x.date === date);
    if (!g) { g = { date, rows: [] }; groups.push(g); }
    g.rows.push(v);
  }
  groups.sort((a, b) => b.date.localeCompare(a.date));
  const today = localToday();

  return (
    <AppLayout title="Журнал відвідувань">
      <div className="vst-layout">
        <div className="vst-left">
          <div className="scan-block">
            <div className="scan-title">📅 Сканування штрих-коду</div>
            <div className="scan-input-wrap">
              <input
                ref={scanInputRef}
                type="text" className="scan-input"
                placeholder="Прикладіть картку або введіть телефон"
                autoComplete="off" autoCorrect="off" spellCheck="false" inputMode="numeric"
                value={scanInput}
                onChange={(e) => setScanInput(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); doScan(); } }}
              />
              <button className="btn btn-primary" style={{ padding: '12px 18px' }} onClick={doScan}>✓</button>
            </div>
            <div className="scan-hint">Штрих-код або телефон → <strong>Enter</strong></div>
            {scanResult && (
              <div className="scan-result">
                <div className={`scan-result-card ${scanResult.type}`}>
                  <div className="scan-avatar">{scanResult.photo ? <img src={scanResult.photo} onError={(e) => { e.target.style.display = 'none'; }} /> : (scanResult.avatarChar || getInitials(scanResult.name))}</div>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div className="scan-client-name">{scanResult.name}</div>
                    <div className="scan-client-sub">{scanResult.sub}</div>
                    {scanResult.warning && <div className="scan-warning">⚠️ {scanResult.warning}</div>}
                  </div>
                  <div className="scan-check-icon">{scanResult.icon}</div>
                </div>
              </div>
            )}
          </div>

          <div className="vst-stats">
            <div className="vst-stat"><div className="vst-stat-val accent">{stats ? stats.total_today : '—'}</div><div className="vst-stat-lbl">Сьогодні</div></div>
            <div className="vst-stat"><div className="vst-stat-val success">{stats ? stats.unique_today : '—'}</div><div className="vst-stat-lbl">Унікальних</div></div>
            <div className="vst-stat"><div className="vst-stat-val">{stats ? stats.total_week : '—'}</div><div className="vst-stat-lbl">За тиждень</div></div>
            <div className="vst-stat"><div className="vst-stat-val">{stats ? stats.total_month : '—'}</div><div className="vst-stat-lbl">За місяць</div></div>
          </div>

          <button className="btn btn-ghost" style={{ width: '100%' }} onClick={openManual}>+ Ручна відмітка</button>
        </div>

        <div className="vst-right card">
          <div className="vst-jrn-head">
            <div className="card-title" style={{ margin: 0 }}>Журнал</div>
            <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
              <input type="date" style={{ width: 'auto' }} value={jrnFrom} onChange={(e) => setJrnFrom(e.target.value)} />
              <input type="date" style={{ width: 'auto' }} value={jrnTo} onChange={(e) => setJrnTo(e.target.value)} />
            </div>
          </div>
          <div style={{ position: 'relative', marginBottom: 12 }}>
            <span style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)', fontSize: 13, pointerEvents: 'none' }}>🔍</span>
            <input type="text" placeholder="Ім'я або телефон..." style={{ paddingLeft: 32, width: '100%' }} value={jrnSearchInput} onChange={(e) => setJrnSearchInput(e.target.value)} />
          </div>

          {journal.loading && <div className="loader"><span className="spinner" /></div>}
          {!journal.loading && groups.length === 0 && <div className="empty-state" style={{ padding: '30px 0' }}><p>Відвідувань не знайдено</p></div>}
          {!journal.loading && groups.map((g) => {
            const isToday = g.date === today;
            const label = isToday
              ? `Сьогодні (${g.rows.length})`
              : `${new Date(g.date + 'T00:00:00').toLocaleDateString('uk-UA', { day: 'numeric', month: 'long', weekday: 'short' })} (${g.rows.length})`;
            const isOpen = openGroups.has(g.date);
            return (
              <div className={`jrn-group ${isOpen ? 'open' : ''}`} key={g.date}>
                <div className="jrn-group-head" onClick={() => toggleGroup(g.date)}>
                  <span>{label}</span>
                  <span className="jrn-group-arrow">›</span>
                </div>
                <div className="jrn-group-body">
                  {g.rows.map((v) => <VisitRow key={v.id} v={v} isOwner={isOwner} onDelete={handleDeleteVisit} />)}
                </div>
              </div>
            );
          })}

          {journal.pagination.pages > 1 && (
            <div className="pagination">
              <span>{(journal.pagination.page - 1) * journal.pagination.per_page + 1}–{Math.min(journal.pagination.page * journal.pagination.per_page, journal.pagination.total)} з {journal.pagination.total}</span>
              <div className="pagination-btns">
                <button className="page-btn" disabled={jrnPage <= 1} onClick={() => setJrnPage(jrnPage - 1)}>‹</button>
                <button className="page-btn active">{jrnPage}</button>
                <button className="page-btn" disabled={jrnPage >= journal.pagination.pages} onClick={() => setJrnPage(jrnPage + 1)}>›</button>
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Ручна відмітка */}
      <Modal
        open={!!manualModal}
        onClose={() => setManualModal(null)}
        title="Ручна відмітка відвідування"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={manualModal?.submitting} onClick={submitManual}>Відмітити</button>
            <button className="btn btn-ghost" onClick={() => setManualModal(null)}>Скасувати</button>
          </div>
        }
      >
        {manualModal && (
          <>
            {manualModal.error && <div className="alert alert-error">{manualModal.error}</div>}
            <FormGroup label="Клієнт *">
              <div style={{ position: 'relative' }}>
                <input type="text" placeholder="Введіть ім'я або телефон..." autoComplete="off" value={manualModal.search} onChange={(e) => setManualModal({ ...manualModal, search: e.target.value })} />
                {manualModal.results.length > 0 && (
                  <div style={{ position: 'absolute', top: '100%', left: 0, right: 0, background: 'var(--bg-elevated)', border: '1px solid var(--border-light)', borderRadius: 'var(--radius-sm)', zIndex: 100, maxHeight: 200, overflowY: 'auto', boxShadow: 'var(--shadow-md)', marginTop: 4 }}>
                    {manualModal.results.map((c) => (
                      <div key={c.id} style={{ padding: '9px 12px', cursor: 'pointer', fontSize: 14, borderBottom: '1px solid var(--border)' }}
                        onClick={() => selectManualClient(c)}>
                        <strong>{c.full_name}</strong> <span style={{ color: 'var(--text-muted)', fontSize: 12, marginLeft: 8 }}>{c.phone || ''}</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
              {manualModal.clientId && (
                <div style={{ marginTop: 8, padding: '10px 12px', background: 'var(--accent-dim)', borderRadius: 'var(--radius-sm)', fontSize: 14 }}>
                  <strong>{manualModal.clientName}</strong>
                  <span style={{ marginLeft: 8, color: 'var(--text-muted)', fontSize: 12 }}>{manualModal.clientPhone}</span>
                  <button onClick={() => setManualModal({ ...manualModal, clientId: null, clientName: '', clientPhone: '', alreadyToday: null })} style={{ float: 'right', background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)' }}>✕</button>
                </div>
              )}
              {manualModal.alreadyToday && (
                <div className="alert alert-info" style={{ marginTop: 8 }}>
                  ℹ️ Цей клієнт вже відмічений сьогодні о {formatTime(manualModal.alreadyToday.visited_at)}
                </div>
              )}
            </FormGroup>
            {manualModal.clientId && manualModal.trainerEligible && (
              <FormGroup label="Тренер">
                <select value={manualModal.trainerId} onChange={(e) => setManualModal({ ...manualModal, trainerId: e.target.value })}>
                  <option value="">— Без тренера —</option>
                  {checkinTrainers.map((t) => <option key={t.id} value={t.id}>{t.full_name}</option>)}
                </select>
                {manualModal.recommendedTrainerName && (
                  <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4 }}>Рекомендований для цього абонемента: {manualModal.recommendedTrainerName}</div>
                )}
              </FormGroup>
            )}
            <FormGroup label="Примітка">
              <input type="text" placeholder="Необов'язково" value={manualModal.notes} onChange={(e) => setManualModal({ ...manualModal, notes: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>

      {/* Підтвердження сторно виплаченого нарахування */}
      <Modal
        open={!!deleteConfirm}
        onClose={() => setDeleteConfirm(null)}
        title="Видалити відмітку з уже виплаченим нарахуванням"
        footer={
          <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
            <button className="btn btn-ghost" onClick={() => setDeleteConfirm(null)}>Скасувати</button>
            <button className="btn btn-danger" disabled={deleteConfirm?.submitting} onClick={confirmDeleteReversal}>
              {deleteConfirm?.submitting ? '...' : '🗑 Видалити і повернути кошти'}
            </button>
          </div>
        }
      >
        {deleteConfirm && (
          <div>
            {deleteConfirm.error && <div className="alert alert-error">{deleteConfirm.error}</div>}
            <div className="alert alert-error">
              Тренеру <strong>{deleteConfirm.trainerName}</strong> вже виплачено <strong>{formatMoney(deleteConfirm.paidAmount)}</strong> за це відвідування.
              Видалення поверне ці кошти в касу/облік і скасує нарахування. Якщо це виправлення помилки (не той тренер) — відмітьте відвідування заново з правильним тренером, після чого йому потрібно буде зробити виплату окремо.
            </div>
          </div>
        )}
      </Modal>
    </AppLayout>
  );
}
