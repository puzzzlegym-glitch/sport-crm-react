import Icon from './Icon';

/**
 * Вкладки для модалки перегляду запису (клієнт/клуб/абонемент тощо).
 * Контент вкладки має фіксовану висоту (.modal-tab-body в admin.css) —
 * перемикання вкладок не змінює розмір модалки.
 *
 * Використання:
 * <ModalTabs tabs={[{ key: 'info', label: 'Інформація' }, { key: 'visits', label: 'Відвідування' }]}
 *            active={tab} onChange={setTab} />
 * <div className="modal-tab-body">{tab === 'info' && <DetailFieldsGrid fields={...} />}</div>
 */
export default function ModalTabs({ tabs, active, onChange }) {
  return (
    <div className="modal-tabs">
      {tabs.map((t) => (
        <button
          key={t.key}
          className={`modal-tab-btn ${active === t.key ? 'active' : ''}`}
          onClick={() => onChange(t.key)}
        >
          {t.icon && <Icon name={t.icon} size={14} />}
          {t.label}
        </button>
      ))}
    </div>
  );
}
