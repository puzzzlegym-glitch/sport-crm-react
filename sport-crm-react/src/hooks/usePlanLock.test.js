import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { usePlanLock } from './usePlanLock';

const mockAuth = vi.fn();
const mockToast = vi.fn();

vi.mock('../context/AuthContext', () => ({
  useAuth: () => mockAuth(),
}));
vi.mock('../components/ui/ToastProvider', () => ({
  useToast: () => mockToast,
}));

describe('usePlanLock (React-еквівалент applyPlanLock з sidebar.js)', () => {
  beforeEach(() => mockToast.mockClear());

  it('canWrite=false — locked=true', () => {
    mockAuth.mockReturnValue({ permissions: { canWrite: false } });
    const { result } = renderHook(() => usePlanLock());
    expect(result.current.locked).toBe(true);
  });

  it('canWrite=true — locked=false', () => {
    mockAuth.mockReturnValue({ permissions: { canWrite: true } });
    const { result } = renderHook(() => usePlanLock());
    expect(result.current.locked).toBe(false);
  });

  it('guard() блокує і показує toast про план підписки, коли locked', () => {
    mockAuth.mockReturnValue({ permissions: { canWrite: false } });
    const { result } = renderHook(() => usePlanLock());
    const fn = vi.fn();
    result.current.guard(fn)();
    expect(fn).not.toHaveBeenCalled();
    expect(mockToast).toHaveBeenCalledWith('Недоступно на поточному плані підписки', 'warning');
  });
});
