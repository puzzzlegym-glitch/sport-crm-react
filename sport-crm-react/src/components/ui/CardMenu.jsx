import { useEffect, useRef, useState } from 'react';
import Icon from './Icon';

/**
 * Кнопка "⋮" з випадним списком дій — для мобільних карток таблиць (Table.jsx),
 * коли на рядок припадає 3+ дії і компактні іконки поряд вже не влазять.
 * На десктопі не використовується (там лишаються звичайні кнопки в колонці дій).
 *
 * <CardMenu actions={[
 *   { label: 'Редагувати', icon: 'edit', onClick: () => ... },
 *   { label: 'Видалити', icon: 'trash', onClick: () => ..., danger: true },
 * ]} />
 */
export default function CardMenu({ actions }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    if (!open) return;
    function onDocClick(e) {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    }
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, [open]);

  const visibleActions = (actions || []).filter(Boolean);
  if (visibleActions.length === 0) return null;

  return (
    <div className="card-menu" ref={ref}>
      <button
        type="button"
        className="mrc-menu-btn"
        aria-label="Дії"
        onClick={(e) => { e.stopPropagation(); setOpen((v) => !v); }}
      >⋮</button>
      {open && (
        <div className="card-menu-list" onClick={(e) => e.stopPropagation()}>
          {visibleActions.map((a, i) => (
            <button
              key={i}
              type="button"
              className={`card-menu-item${a.danger ? ' danger' : ''}`}
              onClick={() => { setOpen(false); a.onClick(); }}
            >
              {a.icon && <Icon name={a.icon} size={15} />}
              {a.label}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
