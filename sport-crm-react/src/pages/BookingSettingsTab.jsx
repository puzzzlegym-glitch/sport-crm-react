import { useState } from 'react';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import Badge from '../components/ui/Badge';
import { useToast } from '../components/ui/ToastProvider';
import {
  saveBookingSettings, saveRoom, saveClassType, saveScheduleTemplate,
  deleteScheduleTemplate, saveTrainerAvailability,
} from '../api/booking';
import { formatDate, localToday } from '../utils/format';

export const WEEKDAYS = ['', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Нд'];
const COLORS = ['#0a84ff', '#34c759', '#ff9f0a', '#ff375f', '#bf5af2', '#64d2ff', '#ffd60a', '#8e8e93'];

const box = { padding: 16, marginBottom: 16 };
const h3 = { margin: '0 0 12px', fontSize: 15, display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8 };
const muted = { fontSize: 12, color: 'var(--text-muted)' };

function ColorPicker({ value, onChange }) {
  return (
    <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
      {COLORS.map((c) => (
        <button
          key={c} type="button" onClick={() => onChange(c)} title={c}
          style={{ width: 24, height: 24, borderRadius: '50%', background: c, cursor: 'pointer', border: value === c ? '2px solid var(--text-primary)' : '2px solid transparent' }}
        />
      ))}
    </div>
  );
}

/**
 * Налаштування запису: правила, зали/ресурси, типи занять, повторюваний
 * розклад (шаблони) і робочий графік тренерів для персональних тренувань.
 * setup — результат booking_api:get_setup; onChanged — перезавантажити setup.
 */
export default function BookingSettingsTab({ setup, onChanged }) {
  const toast = useToast();
  const [rules, setRules] = useState(null);
  const [room, setRoom] = useState(null);
  const [type, setType] = useState(null);
  const [tpl, setTpl] = useState(null);
  const [avail, setAvail] = useState(null);

  const { settings, rooms, types, templates, trainers, availability, tariffs } = setup;
  const activeRooms = rooms.filter((r) => Number(r.is_active));
  const groupTypes = types.filter((t) => t.kind === 'group' && Number(t.is_active));
  const cancelH = settings.cancel_deadline_minutes;

  async function run(promise, after) {
    const res = await promise;
    if (!res.success) { toast(res.error, 'error'); return false; }
    toast(res.message || 'Збережено', res.conflicts?.length ? 'error' : 'success');
    if (res.conflicts?.length) window.alert(`Ці заняття не створено через накладки:\n\n${res.conflicts.join('\n')}`);
    after?.();
    onChanged();
    return true;
  }

  // ── Правила ──
  function openRules() {
    setRules({
      ...settings,
      cancel_hours: String(settings.cancel_deadline_minutes / 60),
      close_hours: String(settings.book_close_minutes / 60),
    });
  }
  function saveRules() {
    run(saveBookingSettings({
      book_ahead_days: parseInt(rules.book_ahead_days) || 14,
      book_close_minutes: Math.round((parseFloat(rules.close_hours) || 0) * 60),
      cancel_deadline_minutes: Math.round((parseFloat(rules.cancel_hours) || 0) * 60),
      waitlist_enabled: rules.waitlist_enabled ? 1 : 0,
      require_invoice: rules.require_invoice ? 1 : 0,
      personal_slot_step_min: parseInt(rules.personal_slot_step_min) || 60,
      generate_weeks: parseInt(rules.generate_weeks) || 4,
      reminder_1_hours: Math.max(0, parseInt(rules.reminder_1_hours) || 0),
      reminder_2_hours: Math.max(0, parseInt(rules.reminder_2_hours) || 0),
    }), () => setRules(null));
  }

  // ── Зал ──
  function saveRoomForm() {
    if (!room.name.trim()) { toast('Вкажіть назву', 'error'); return; }
    run(saveRoom({ ...room, name: room.name.trim(), is_active: room.is_active ? 1 : 0 }), () => setRoom(null));
  }

  // ── Тип заняття ──
  function openType(t = null) {
    setType(t ? {
      ...t, capacity: t.capacity ?? '', default_room_id: t.default_room_id || '', is_active: !!Number(t.is_active),
      tariff_ids: (t.tariff_ids || []).map(Number), description: t.description || '',
    } : {
      id: null, name: '', kind: 'group', duration_min: 60, capacity: '', color: COLORS[0],
      default_room_id: '', tariff_ids: [], is_active: true, description: '',
    });
  }
  function toggleTariff(id) {
    setType((t) => ({ ...t, tariff_ids: t.tariff_ids.includes(id) ? t.tariff_ids.filter((x) => x !== id) : [...t.tariff_ids, id] }));
  }
  function saveTypeForm() {
    if (!type.name.trim()) { toast('Вкажіть назву', 'error'); return; }
    run(saveClassType({ ...type, name: type.name.trim(), is_active: type.is_active ? 1 : 0 }), () => setType(null));
  }

  // ── Шаблон розкладу ──
  function openTpl(t = null) {
    setTpl(t ? {
      id: t.id, class_type_id: String(t.class_type_id), trainer_id: String(t.trainer_id), room_id: t.room_id ? String(t.room_id) : '',
      weekdays: [Number(t.weekday)], start_time: t.start_time.slice(0, 5), duration_min: t.duration_min ?? '', capacity: t.capacity ?? '',
      valid_from: t.valid_from, valid_to: t.valid_to || '',
    } : {
      id: null, class_type_id: '', trainer_id: '', room_id: '', weekdays: [], start_time: '19:00',
      duration_min: '', capacity: '', valid_from: localToday(), valid_to: '',
    });
  }
  function saveTplForm() {
    if (!tpl.class_type_id || !tpl.trainer_id || !tpl.weekdays.length) { toast('Оберіть тип, тренера і дні тижня', 'error'); return; }
    run(saveScheduleTemplate(tpl), () => setTpl(null));
  }
  function removeTpl(t) {
    if (!window.confirm(`Прибрати з розкладу «${t.type_name}» (${WEEKDAYS[t.weekday]} ${t.start_time.slice(0, 5)})?\nМайбутні заняття без записів буде видалено.`)) return;
    run(deleteScheduleTemplate(t.id));
  }

  // ── Графік тренера ──
  function openAvail(trainer) {
    const rows = availability.filter((a) => Number(a.trainer_id) === Number(trainer.id)).map((a) => ({
      weekday: Number(a.weekday), start_time: a.start_time.slice(0, 5), end_time: a.end_time.slice(0, 5), room_id: a.room_id ? String(a.room_id) : '',
    }));
    setAvail({ trainer, rows });
  }
  function setAvailRow(i, patch) {
    setAvail((a) => ({ ...a, rows: a.rows.map((r, j) => (j === i ? { ...r, ...patch } : r)) }));
  }
  function saveAvailForm() {
    run(saveTrainerAvailability(avail.trainer.id, avail.rows), () => setAvail(null));
  }

  const roomName = (id) => rooms.find((r) => String(r.id) === String(id))?.name;

  return (
    <>
      {/* Правила */}
      <div className="card" style={box}>
        <div style={h3}><span>Правила запису</span><button className="btn btn-ghost btn-sm" onClick={openRules}>Змінити</button></div>
        <div style={{ display: 'flex', gap: 18, flexWrap: 'wrap', fontSize: 13 }}>
          <span>Запис відкривається за <strong>{settings.book_ahead_days} дн.</strong></span>
          <span>Запис закривається за <strong>{settings.book_close_minutes ? `${settings.book_close_minutes / 60} год` : '0 хв'}</strong> до початку</span>
          <span>Скасувати можна не пізніше ніж за <strong>{cancelH / 60} год</strong></span>
          <span>Лист очікування: <strong>{settings.waitlist_enabled ? 'так' : 'ні'}</strong></span>
          <span>Самозапис лише з абонементом: <strong>{settings.require_invoice ? 'так' : 'ні'}</strong></span>
          <span>Нагадування в Telegram: <strong>{[settings.reminder_1_hours, settings.reminder_2_hours].filter((h) => h > 0).map((h) => `за ${h} год`).join(' і ') || 'вимкнено'}</strong></span>
        </div>
      </div>

      {/* Зали */}
      <div className="card" style={box}>
        <div style={h3}><span>Зали та ресурси</span><button className="btn btn-ghost btn-sm" onClick={() => setRoom({ id: null, name: '', capacity: '', color: COLORS[0], is_active: true })}>+ Зал</button></div>
        {rooms.length === 0 && <div style={muted}>Додайте зали, корти чи інші ресурси — система не дасть поставити два заняття в один зал на той самий час.</div>}
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {rooms.map((r) => (
            <button key={r.id} className="btn btn-ghost btn-sm" style={{ opacity: Number(r.is_active) ? 1 : 0.5 }}
              onClick={() => setRoom({ ...r, capacity: r.capacity ?? '', is_active: !!Number(r.is_active) })}>
              <span style={{ width: 8, height: 8, borderRadius: '50%', background: r.color || '#8e8e93', display: 'inline-block', marginRight: 6 }} />
              {r.name}{r.capacity ? ` · до ${r.capacity} ос.` : ''}{!Number(r.is_active) ? ' (вимкнено)' : ''}
            </button>
          ))}
        </div>
      </div>

      {/* Типи занять */}
      <div className="card" style={box}>
        <div style={h3}><span>Типи занять</span><button className="btn btn-ghost btn-sm" onClick={() => openType()}>+ Тип заняття</button></div>
        {types.length === 0 && <div style={muted}>Наприклад: «Йога» (групове, 60 хв, 12 місць), «Персональне тренування» (персональне, 60 хв), «Теніс — корт» (персональне, 90 хв).</div>}
        {types.map((t) => (
          <div key={t.id} onClick={() => openType(t)}
            style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '8px 0', borderBottom: '1px solid var(--border)', cursor: 'pointer', opacity: Number(t.is_active) ? 1 : 0.5 }}>
            <span style={{ width: 10, height: 10, borderRadius: '50%', background: t.color || '#8e8e93' }} />
            <strong style={{ fontSize: 14 }}>{t.name}</strong>
            <Badge variant={t.kind === 'personal' ? 'pending' : 'info'}>{t.kind === 'personal' ? 'Персональне' : 'Групове'}</Badge>
            <span style={muted}>
              {t.duration_min} хв{t.kind === 'group' ? ` · ${t.capacity ? `${t.capacity} місць` : 'без ліміту'}` : ''}
              {t.default_room_id ? ` · ${roomName(t.default_room_id) || ''}` : ''}
              {' · '}{t.tariff_ids.length ? `тарифи: ${t.tariff_ids.map((id) => tariffs.find((x) => Number(x.id) === id)?.name).filter(Boolean).join(', ')}` : 'будь-який абонемент'}
            </span>
          </div>
        ))}
      </div>

      {/* Розклад */}
      <div className="card" style={box}>
        <div style={h3}>
          <span>Постійний розклад групових занять</span>
          <button className="btn btn-ghost btn-sm" disabled={!groupTypes.length} onClick={() => openTpl()}>+ У розклад</button>
        </div>
        <div style={{ ...muted, marginBottom: 8 }}>Заняття створюються автоматично на {settings.generate_weeks} тиж. уперед.</div>
        {templates.length === 0 && <div style={muted}>{groupTypes.length ? 'Розклад порожній.' : 'Спершу додайте хоча б один груповий тип заняття.'}</div>}
        {[1, 2, 3, 4, 5, 6, 7].map((wd) => {
          const list = templates.filter((t) => Number(t.weekday) === wd);
          if (!list.length) return null;
          return (
            <div key={wd} style={{ display: 'flex', gap: 10, padding: '6px 0', borderBottom: '1px solid var(--border)', alignItems: 'flex-start' }}>
              <strong style={{ width: 28, fontSize: 13 }}>{WEEKDAYS[wd]}</strong>
              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', flex: 1 }}>
                {list.map((t) => (
                  <span key={t.id} className="badge badge-info" style={{ display: 'inline-flex', gap: 6, alignItems: 'center', borderLeft: `3px solid ${t.color || '#8e8e93'}` }}>
                    <span style={{ cursor: 'pointer' }} onClick={() => openTpl(t)}>
                      {t.start_time.slice(0, 5)} {t.type_name} · {t.trainer_name}{t.room_name ? ` · ${t.room_name}` : ''}
                      {t.valid_to ? ` (до ${formatDate(t.valid_to)})` : ''}
                    </span>
                    <button type="button" onClick={() => removeTpl(t)} title="Прибрати" style={{ background: 'none', border: 'none', color: 'inherit', cursor: 'pointer', padding: 0 }}>×</button>
                  </span>
                ))}
              </div>
            </div>
          );
        })}
      </div>

      {/* Графік тренерів */}
      <div className="card" style={box}>
        <div style={h3}><span>Графік тренерів (персональні тренування)</span></div>
        <div style={{ ...muted, marginBottom: 8 }}>У ці години клієнти й адміністратор бачать вільний час тренера для запису.</div>
        {trainers.map((tr) => {
          const rows = availability.filter((a) => Number(a.trainer_id) === Number(tr.id));
          return (
            <div key={tr.id} onClick={() => openAvail(tr)} style={{ display: 'flex', gap: 10, padding: '8px 0', borderBottom: '1px solid var(--border)', cursor: 'pointer', fontSize: 13 }}>
              <strong style={{ minWidth: 160 }}>{tr.full_name}</strong>
              <span style={muted}>
                {rows.length ? rows.map((a) => `${WEEKDAYS[a.weekday]} ${a.start_time.slice(0, 5)}–${a.end_time.slice(0, 5)}`).join(', ') : 'графік не задано'}
              </span>
            </div>
          );
        })}
      </div>

      {/* ── Модалки ── */}
      <Modal open={!!rules} onClose={() => setRules(null)} title="Правила запису" footer={
        <div style={{ display: 'flex', gap: 10 }}><button className="btn btn-primary" onClick={saveRules}>Зберегти</button><button className="btn btn-ghost" onClick={() => setRules(null)}>Скасувати</button></div>
      }>
        {rules && (
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
            <FormGroup label="Запис відкривається за (днів)">
              <input type="number" min="1" value={rules.book_ahead_days} onChange={(e) => setRules({ ...rules, book_ahead_days: e.target.value })} />
            </FormGroup>
            <FormGroup label="Запис закривається за (год до початку)">
              <input type="number" min="0" step="0.5" value={rules.close_hours} onChange={(e) => setRules({ ...rules, close_hours: e.target.value })} />
            </FormGroup>
            <FormGroup label="Скасувати можна не пізніше ніж за (год)" fullWidth>
              <input type="number" min="0" step="0.5" value={rules.cancel_hours} onChange={(e) => setRules({ ...rules, cancel_hours: e.target.value })} />
              <div style={{ ...muted, marginTop: 4 }}>Після цього часу скасувати запис не зможе ні клієнт, ні адміністратор (лише власник клубу).</div>
            </FormGroup>
            <FormGroup label="Крок вільних годин тренера (хв)">
              <input type="number" min="5" step="5" value={rules.personal_slot_step_min} onChange={(e) => setRules({ ...rules, personal_slot_step_min: e.target.value })} />
            </FormGroup>
            <FormGroup label="Будувати розклад на (тижнів)">
              <input type="number" min="1" max="26" value={rules.generate_weeks} onChange={(e) => setRules({ ...rules, generate_weeks: e.target.value })} />
            </FormGroup>
            <FormGroup label="Нагадування 1 (год до початку)">
              <input type="number" min="0" max="168" value={rules.reminder_1_hours} onChange={(e) => setRules({ ...rules, reminder_1_hours: e.target.value })} />
            </FormGroup>
            <FormGroup label="Нагадування 2 (год до початку)">
              <input type="number" min="0" max="168" value={rules.reminder_2_hours} onChange={(e) => setRules({ ...rules, reminder_2_hours: e.target.value })} />
            </FormGroup>
            <div style={{ ...muted, gridColumn: '1/-1', marginTop: -6, marginBottom: 10 }}>Нагадування клієнтам у Telegram-бот (0 — вимкнено).</div>
            <label style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: 13, gridColumn: '1/-1', marginBottom: 8 }}>
              <input type="checkbox" checked={!!rules.waitlist_enabled} onChange={(e) => setRules({ ...rules, waitlist_enabled: e.target.checked })} />
              Лист очікування (коли місць немає; звільнене місце отримує перший у черзі)
            </label>
            <label style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: 13, gridColumn: '1/-1' }}>
              <input type="checkbox" checked={!!rules.require_invoice} onChange={(e) => setRules({ ...rules, require_invoice: e.target.checked })} />
              Самозапис (бот / застосунок) лише з діючим абонементом
            </label>
          </div>
        )}
      </Modal>

      <Modal open={!!room} onClose={() => setRoom(null)} title={room?.id ? 'Зал / ресурс' : 'Новий зал / ресурс'} size="sm" footer={
        <div style={{ display: 'flex', gap: 10 }}><button className="btn btn-primary" onClick={saveRoomForm}>Зберегти</button><button className="btn btn-ghost" onClick={() => setRoom(null)}>Скасувати</button></div>
      }>
        {room && (
          <>
            <FormGroup label="Назва *"><input type="text" placeholder="Зал 1, Корт 2, Басейн..." value={room.name} onChange={(e) => setRoom({ ...room, name: e.target.value })} /></FormGroup>
            <FormGroup label="Місткість (людей)"><input type="number" min="1" placeholder="без обмеження" value={room.capacity} onChange={(e) => setRoom({ ...room, capacity: e.target.value })} /></FormGroup>
            <FormGroup label="Колір"><ColorPicker value={room.color} onChange={(c) => setRoom({ ...room, color: c })} /></FormGroup>
            <label style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: 13 }}>
              <input type="checkbox" checked={room.is_active} onChange={(e) => setRoom({ ...room, is_active: e.target.checked })} /> Активний
            </label>
          </>
        )}
      </Modal>

      <Modal open={!!type} onClose={() => setType(null)} title={type?.id ? 'Тип заняття' : 'Новий тип заняття'} footer={
        <div style={{ display: 'flex', gap: 10 }}><button className="btn btn-primary" onClick={saveTypeForm}>Зберегти</button><button className="btn btn-ghost" onClick={() => setType(null)}>Скасувати</button></div>
      }>
        {type && (
          <>
            <FormGroup label="Назва *"><input type="text" value={type.name} onChange={(e) => setType({ ...type, name: e.target.value })} /></FormGroup>
            <FormGroup label="Вид">
              <div style={{ display: 'flex', gap: 16, fontSize: 13 }}>
                <label style={{ display: 'flex', gap: 6, alignItems: 'center' }}><input type="radio" checked={type.kind === 'group'} onChange={() => setType({ ...type, kind: 'group' })} /> Групове (за розкладом)</label>
                <label style={{ display: 'flex', gap: 6, alignItems: 'center' }}><input type="radio" checked={type.kind === 'personal'} onChange={() => setType({ ...type, kind: 'personal' })} /> Персональне / оренда (вільні години)</label>
              </div>
            </FormGroup>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Тривалість (хв)"><input type="number" min="5" step="5" value={type.duration_min} onChange={(e) => setType({ ...type, duration_min: e.target.value })} /></FormGroup>
              {type.kind === 'group' && (
                <FormGroup label="Місць"><input type="number" min="1" placeholder="без обмеження" value={type.capacity} onChange={(e) => setType({ ...type, capacity: e.target.value })} /></FormGroup>
              )}
              <FormGroup label="Зал за замовчуванням">
                <select value={type.default_room_id} onChange={(e) => setType({ ...type, default_room_id: e.target.value })}>
                  <option value="">— Без залу —</option>
                  {activeRooms.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                </select>
              </FormGroup>
            </div>
            <FormGroup label="Колір"><ColorPicker value={type.color} onChange={(c) => setType({ ...type, color: c })} /></FormGroup>
            <FormGroup label="Хто може записатися (тарифи абонемента)">
              <div style={{ ...muted, marginBottom: 6 }}>Нічого не обрано — з будь-яким діючим абонементом.</div>
              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                {tariffs.map((t) => (
                  <label key={t.id} className="badge badge-info" style={{ display: 'inline-flex', gap: 6, alignItems: 'center', cursor: 'pointer' }}>
                    <input type="checkbox" checked={type.tariff_ids.includes(Number(t.id))} onChange={() => toggleTariff(Number(t.id))} /> {t.name}
                  </label>
                ))}
              </div>
            </FormGroup>
            <FormGroup label="Опис (бачать клієнти)"><textarea rows="2" value={type.description} onChange={(e) => setType({ ...type, description: e.target.value })} /></FormGroup>
            <label style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: 13 }}>
              <input type="checkbox" checked={type.is_active} onChange={(e) => setType({ ...type, is_active: e.target.checked })} /> Активний
            </label>
          </>
        )}
      </Modal>

      <Modal open={!!tpl} onClose={() => setTpl(null)} title={tpl?.id ? 'Заняття в розкладі' : 'Додати в постійний розклад'} footer={
        <div style={{ display: 'flex', gap: 10 }}><button className="btn btn-primary" onClick={saveTplForm}>Зберегти</button><button className="btn btn-ghost" onClick={() => setTpl(null)}>Скасувати</button></div>
      }>
        {tpl && (
          <>
            <FormGroup label="Заняття *">
              <select value={tpl.class_type_id} onChange={(e) => {
                const t = groupTypes.find((x) => String(x.id) === e.target.value);
                setTpl({ ...tpl, class_type_id: e.target.value, room_id: tpl.room_id || (t?.default_room_id ? String(t.default_room_id) : '') });
              }}>
                <option value="">— Оберіть —</option>
                {groupTypes.map((t) => <option key={t.id} value={t.id}>{t.name} · {t.duration_min} хв</option>)}
              </select>
            </FormGroup>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Тренер *">
                <select value={tpl.trainer_id} onChange={(e) => setTpl({ ...tpl, trainer_id: e.target.value })}>
                  <option value="">— Оберіть —</option>
                  {trainers.map((t) => <option key={t.id} value={t.id}>{t.full_name}</option>)}
                </select>
              </FormGroup>
              <FormGroup label="Зал">
                <select value={tpl.room_id} onChange={(e) => setTpl({ ...tpl, room_id: e.target.value })}>
                  <option value="">— Без залу —</option>
                  {activeRooms.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                </select>
              </FormGroup>
            </div>
            <FormGroup label={tpl.id ? 'День тижня' : 'Дні тижня *'}>
              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                {[1, 2, 3, 4, 5, 6, 7].map((d) => {
                  const on = tpl.weekdays.includes(d);
                  return (
                    <button key={d} type="button" className={`inv-filter-btn ${on ? 'active' : ''}`}
                      onClick={() => setTpl({ ...tpl, weekdays: tpl.id ? [d] : (on ? tpl.weekdays.filter((x) => x !== d) : [...tpl.weekdays, d]) })}>
                      {WEEKDAYS[d]}
                    </button>
                  );
                })}
              </div>
            </FormGroup>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: '0 14px' }}>
              <FormGroup label="Початок *"><input type="time" value={tpl.start_time} onChange={(e) => setTpl({ ...tpl, start_time: e.target.value })} /></FormGroup>
              <FormGroup label="Тривалість (хв)"><input type="number" min="5" step="5" placeholder="з типу" value={tpl.duration_min} onChange={(e) => setTpl({ ...tpl, duration_min: e.target.value })} /></FormGroup>
              <FormGroup label="Місць"><input type="number" min="1" placeholder="з типу" value={tpl.capacity} onChange={(e) => setTpl({ ...tpl, capacity: e.target.value })} /></FormGroup>
              <FormGroup label="Діє з"><input type="date" value={tpl.valid_from} onChange={(e) => setTpl({ ...tpl, valid_from: e.target.value })} /></FormGroup>
              <FormGroup label="Діє до"><input type="date" value={tpl.valid_to} onChange={(e) => setTpl({ ...tpl, valid_to: e.target.value })} /></FormGroup>
            </div>
            {tpl.id && <div style={muted}>Після збереження майбутні заняття цього рядка без записів буде перебудовано. Заняття, на які вже є записи, не змінюються.</div>}
          </>
        )}
      </Modal>

      <Modal open={!!avail} onClose={() => setAvail(null)} title={`Графік: ${avail?.trainer.full_name || ''}`} footer={
        <div style={{ display: 'flex', gap: 10 }}><button className="btn btn-primary" onClick={saveAvailForm}>Зберегти</button><button className="btn btn-ghost" onClick={() => setAvail(null)}>Скасувати</button></div>
      }>
        {avail && (
          <>
            {avail.rows.length === 0 && <div style={{ ...muted, marginBottom: 10 }}>Робочих годин ще немає.</div>}
            {avail.rows.map((r, i) => (
              <div key={i} style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 8, flexWrap: 'wrap' }}>
                <select value={r.weekday} style={{ width: 70 }} onChange={(e) => setAvailRow(i, { weekday: Number(e.target.value) })}>
                  {[1, 2, 3, 4, 5, 6, 7].map((d) => <option key={d} value={d}>{WEEKDAYS[d]}</option>)}
                </select>
                <input type="time" value={r.start_time} style={{ width: 110 }} onChange={(e) => setAvailRow(i, { start_time: e.target.value })} />
                <span>–</span>
                <input type="time" value={r.end_time} style={{ width: 110 }} onChange={(e) => setAvailRow(i, { end_time: e.target.value })} />
                <select value={r.room_id} style={{ flex: 1, minWidth: 120 }} onChange={(e) => setAvailRow(i, { room_id: e.target.value })}>
                  <option value="">— Без залу —</option>
                  {activeRooms.map((rm) => <option key={rm.id} value={rm.id}>{rm.name}</option>)}
                </select>
                <button type="button" className="btn btn-ghost btn-sm" onClick={() => setAvail({ ...avail, rows: avail.rows.filter((_, j) => j !== i) })}>✕</button>
              </div>
            ))}
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              <button type="button" className="btn btn-ghost btn-sm" onClick={() => setAvail({ ...avail, rows: [...avail.rows, { weekday: 1, start_time: '09:00', end_time: '18:00', room_id: '' }] })}>+ Рядок</button>
              <button type="button" className="btn btn-ghost btn-sm" onClick={() => setAvail({ ...avail, rows: [1, 2, 3, 4, 5].map((d) => ({ weekday: d, start_time: '09:00', end_time: '18:00', room_id: '' })) })}>Пн–Пт 9:00–18:00</button>
            </div>
          </>
        )}
      </Modal>
    </>
  );
}
