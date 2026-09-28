import { useAuth } from '../context/AuthContext';
import { useToast } from '../components/ui/ToastProvider';

/**
 * React-еквівалент applyShiftLock() з sidebar.js.
 * DOM-версія перехоплювала кліки по [data-shift-action]; тут замість цього —
 * guard-обгортка для onClick, бо в React немає сенсу пост-фактум патчити DOM.
 *
 * Використання:
 *   const { guard, locked, lockedProps } = useShiftLock();
 *   <button className="btn btn-primary" {...lockedProps} onClick={guard(handleSell)}>Продати</button>
 */
export function useShiftLock() {
  const { activeShiftId, permissions } = useAuth();
  const toast = useToast();
  const { level, isSuperAdmin } = permissions;

  const exempt = level >= 80 || isSuperAdmin;
  const locked = !exempt && !activeShiftId;

  const guard = (fn) => (e) => {
    if (locked) {
      e?.preventDefault();
      toast('Відкрийте зміну в розділі Каса', 'warning');
      return;
    }
    fn(e);
  };

  const lockedProps = locked
    ? { style: { opacity: 0.45, cursor: 'not-allowed' }, title: 'Відкрийте зміну в розділі Каса 🔒' }
    : {};

  return { locked, guard, lockedProps };
}
