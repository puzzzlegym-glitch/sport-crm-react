import { useAuth } from '../context/AuthContext';
import { useToast } from '../components/ui/ToastProvider';

/**
 * React-еквівалент applyPlanLock() з sidebar.js.
 * Блокує дію, якщо canWrite=false (тарифний план не дозволяє запис).
 *
 * Використання:
 *   const { guard, locked, lockedProps } = usePlanLock();
 *   <button className="btn btn-primary" {...lockedProps} onClick={guard(handleCreate)}>Додати</button>
 */
export function usePlanLock() {
  const { permissions } = useAuth();
  const toast = useToast();
  const locked = !permissions.canWrite;

  const guard = (fn) => (e) => {
    if (locked) {
      e?.preventDefault();
      toast('Недоступно на поточному плані підписки', 'warning');
      return;
    }
    fn(e);
  };

  const lockedProps = locked
    ? { style: { opacity: 0.45, cursor: 'not-allowed' }, title: 'Недоступно на поточному плані підписки' }
    : {};

  return { locked, guard, lockedProps };
}
