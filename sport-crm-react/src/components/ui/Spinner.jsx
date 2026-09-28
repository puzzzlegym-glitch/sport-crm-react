export default function Spinner({ fullPage = false, size = 28 }) {
  const el = (
    <div
      style={{
        width: size,
        height: size,
        border: '3px solid var(--border, #334155)',
        borderTopColor: 'var(--accent, #6366f1)',
        borderRadius: '50%',
        animation: 'spin 0.7s linear infinite',
      }}
    />
  );

  if (!fullPage) return el;

  return (
    <div
      style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        height: '100vh',
        width: '100%',
      }}
    >
      {el}
      <style>{`@keyframes spin { to { transform: rotate(360deg); } }`}</style>
    </div>
  );
}
