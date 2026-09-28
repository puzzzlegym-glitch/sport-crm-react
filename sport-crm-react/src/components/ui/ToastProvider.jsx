import { createContext, useContext, useCallback, useState } from 'react';

const ToastContext = createContext(null);
const COLORS = {
  success: 'var(--success)',
  error:   'var(--danger)',
  warning: 'var(--warning)',
  info:    'var(--accent)',
};

export function ToastProvider({ children }) {
  const [items, setItems] = useState([]);

  const toast = useCallback((message, type = 'info', ms = 3000) => {
    const id = Date.now() + Math.random();
    setItems((list) => [...list, { id, message, type }]);
    setTimeout(() => {
      setItems((list) => list.filter((t) => t.id !== id));
    }, ms);
  }, []);

  return (
    <ToastContext.Provider value={toast}>
      {children}
      <div style={{ position: 'fixed', bottom: 24, right: 24, zIndex: 9999, display: 'flex', flexDirection: 'column', gap: 8 }}>
        {items.map((t) => (
          <div
            key={t.id}
            style={{
              background: 'var(--bg-elevated)',
              border: '1px solid var(--border-light)',
              borderLeft: `3px solid ${COLORS[t.type] ?? COLORS.info}`,
              borderRadius: 'var(--radius-sm)',
              padding: '12px 18px',
              fontSize: 14,
              color: 'var(--text-primary)',
              boxShadow: 'var(--shadow-md)',
              maxWidth: 340,
            }}
          >
            {t.message}
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  );
}

/** Використання: const toast = useToast(); toast('Готово', 'success'); */
export function useToast() {
  const ctx = useContext(ToastContext);
  if (!ctx) throw new Error('useToast() має використовуватись всередині <ToastProvider>');
  return ctx;
}
