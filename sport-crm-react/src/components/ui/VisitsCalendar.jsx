import { useMemo, useState } from 'react';
import { localDate, formatDate } from '../../utils/format';

const MONTH_LABELS = [
  'Січень', 'Лютий', 'Березень', 'Квітень', 'Травень', 'Червень',
  'Липень', 'Серпень', 'Вересень', 'Жовтень', 'Листопад', 'Грудень',
];
const WEEKDAY_LABELS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Нд'];

/**
 * Календар відвідувань клієнта — місячна сітка з позначеними днями замість плаского списку.
 * Клік на позначений день розкриває деталі (час, нотатки) знизу.
 *
 * Використання:
 * <VisitsCalendar visits={view.visits} onDelete={(id) => ...} />
 * onDelete необов'язковий — коли переданий, у деталях дня з'являється кнопка видалення (власник).
 */
export default function VisitsCalendar({ visits, onDelete }) {
  const visitsByDay = useMemo(() => {
    const map = new Map();
    for (const v of visits) {
      const key = localDate(v.visited_at);
      if (!map.has(key)) map.set(key, []);
      map.get(key).push(v);
    }
    return map;
  }, [visits]);

  const initialMonth = useMemo(() => {
    const latest = visits[0]?.visited_at;
    const d = latest ? new Date(latest) : new Date();
    return new Date(d.getFullYear(), d.getMonth(), 1);
  }, [visits]);

  const [month, setMonth] = useState(initialMonth);
  const [selectedDay, setSelectedDay] = useState(null);

  const today = localDate();

  const cells = useMemo(() => {
    const year = month.getFullYear();
    const mo = month.getMonth();
    const firstDay = new Date(year, mo, 1);
    const daysInMonth = new Date(year, mo + 1, 0).getDate();
    const leadingBlanks = (firstDay.getDay() + 6) % 7; // Пн = 0

    const list = [];
    for (let i = 0; i < leadingBlanks; i++) list.push(null);
    for (let day = 1; day <= daysInMonth; day++) {
      const dateKey = `${year}-${String(mo + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
      list.push({ day, dateKey, visits: visitsByDay.get(dateKey) || null });
    }
    return list;
  }, [month, visitsByDay]);

  const visitsThisMonth = cells.reduce((sum, c) => sum + (c?.visits?.length || 0), 0);
  const selected = selectedDay ? visitsByDay.get(selectedDay) : null;

  function changeMonth(delta) {
    setMonth(new Date(month.getFullYear(), month.getMonth() + delta, 1));
    setSelectedDay(null);
  }

  return (
    <div className="visits-calendar">
      <div className="visits-calendar-header">
        <button type="button" className="visits-calendar-nav" onClick={() => changeMonth(-1)}>‹</button>
        <div className="visits-calendar-title">
          {MONTH_LABELS[month.getMonth()]} {month.getFullYear()}
          {visitsThisMonth > 0 && <span className="visits-calendar-count"> · {visitsThisMonth}</span>}
        </div>
        <button type="button" className="visits-calendar-nav" onClick={() => changeMonth(1)}>›</button>
      </div>

      <div className="visits-calendar-grid">
        {WEEKDAY_LABELS.map((w) => (
          <div key={w} className="visits-calendar-weekday">{w}</div>
        ))}
        {cells.map((c, i) => {
          if (!c) return <div key={`blank-${i}`} />;
          const hasVisit = !!c.visits;
          const isToday = c.dateKey === today;
          const isSelected = c.dateKey === selectedDay;
          return (
            <button
              type="button"
              key={c.dateKey}
              className={`visits-calendar-day${hasVisit ? ' has-visit' : ''}${isToday ? ' is-today' : ''}${isSelected ? ' is-selected' : ''}`}
              disabled={!hasVisit}
              onClick={() => setSelectedDay(isSelected ? null : c.dateKey)}
            >
              {c.day}
              {hasVisit && <span className="visits-calendar-dot" />}
            </button>
          );
        })}
      </div>

      {visitsThisMonth === 0 && (
        <div className="empty-state" style={{ marginTop: 12 }}>
          <div className="icon">📅</div>
          <p>Відвідувань у цьому місяці немає</p>
        </div>
      )}

      {selected && (
        <div className="visits-calendar-details">
          {selected.map((v, i) => (
            <div key={i} className="visits-calendar-detail-row" style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8 }}>
              <span>{formatDate(v.visited_at)}</span>
              {v.notes && <span className="text-muted">{v.notes}</span>}
              {onDelete && (
                <button
                  type="button" className="visit-del-btn" title="Видалити"
                  onClick={() => onDelete(v.id)}
                >✕</button>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
