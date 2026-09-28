import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useShiftLock } from './useShiftLock';

const mockAuth = vi.fn();
const mockToast = vi.fn();

vi.mock('../context/AuthContext', () => ({
  useAuth: () => mockAuth(),
}));
vi.mock('../components/ui/ToastProvider', () => ({
  useToast: () => mockToast,
}));

describe('useShiftLock (React-еквівалент applyShiftLock з sidebar.js)', () => {
  beforeEach(() => mockToast.mockClear());

  it('trainer (level < 80) без відкритої зміни — locked=true', () => {
    mockAuth.mockReturnValue({ activeShiftId: null, permissions: { level: 30, isSuperAdmin: false } });
    const { result } = renderHook(() => useShiftLock());
    expect(result.current.locked).toBe(true);
  });

  it('trainer з відкритою зміною — locked=false', () => {
    mockAuth.mockReturnValue({ activeShiftId: 7, permissions: { level: 30, isSuperAdmin: false } });
    const { result } = renderHook(() => useShiftLock());
    expect(result.current.locked).toBe(false);
  });

  it('owner (level >= 80) без зміни — все одно НЕ locked (звільнений від правила)', () => {
    mockAuth.mockReturnValue({ activeShiftId: null, permissions: { level: 80, isSuperAdmin: false } });
    const { result } = renderHook(() => useShiftLock());
    expect(result.current.locked).toBe(false);
  });

  it('SuperAdmin без зміни — не locked', () => {
    mockAuth.mockReturnValue({ activeShiftId: null, permissions: { level: 100, isSuperAdmin: true } });
    const { result } = renderHook(() => useShiftLock());
    expect(result.current.locked).toBe(false);
  });

  it('guard() блокує виклик і показує toast, коли locked', () => {
    mockAuth.mockReturnValue({ activeShiftId: null, permissions: { level: 30, isSuperAdmin: false } });
    const { result } = renderHook(() => useShiftLock());
    const fn = vi.fn();
    result.current.guard(fn)();
    expect(fn).not.toHaveBeenCalled();
    expect(mockToast).toHaveBeenCalledWith('Відкрийте зміну в розділі Каса', 'warning');
  });

  it('guard() пропускає виклик, коли не locked', () => {
    mockAuth.mockReturnValue({ activeShiftId: 7, permissions: { level: 30, isSuperAdmin: false } });
    const { result } = renderHook(() => useShiftLock());
    const fn = vi.fn();
    result.current.guard(fn)();
    expect(fn).toHaveBeenCalled();
    expect(mockToast).not.toHaveBeenCalled();
  });
});
