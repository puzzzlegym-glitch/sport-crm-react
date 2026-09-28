const SIZES = { sm: 420, md: 560, lg: 760, xl: 920 };

/**
 * Використання: <Modal open={isOpen} onClose={() => setIsOpen(false)} title="..." size="md">...</Modal>
 * size: 'sm' (420, підтвердження/дрібний контент) | 'md' (560, за замовчуванням — типова форма) |
 *       'lg' (760, деталі запису/вкладки/довгі форми) | 'xl' (920, найбільші форми)
 */
export default function Modal({ open, onClose, title, children, footer, size = 'md' }) {
  if (!open) return null;

  return (
    <div
      className="modal-overlay open"
      onClick={(e) => e.target === e.currentTarget && onClose?.()}
      style={{
        position: 'fixed', inset: 0, zIndex: 300,
        background: 'rgba(0,0,0,0.5)',
        display: 'flex', alignItems: 'center', justifyContent: 'center',
      }}
    >
      <div
        className="modal-box"
        style={{
          background: 'var(--bg-elevated)',
          border: '1px solid var(--border)',
          borderRadius: 'var(--radius-md)',
          padding: 20,
          width: SIZES[size] ?? SIZES.md,
          maxWidth: '90vw',
          maxHeight: '90vh',
          overflowY: 'auto',
        }}
      >
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
          {title && <h3 style={{ margin: 0 }}>{title}</h3>}
          <button className="modal-close" onClick={onClose} style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: 20, color: 'var(--text-secondary)' }}>×</button>
        </div>
        {children}
        {footer && <div style={{ marginTop: 16 }}>{footer}</div>}
      </div>
    </div>
  );
}
