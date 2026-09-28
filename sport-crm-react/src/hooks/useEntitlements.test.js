import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { useEntitlements } from './useEntitlements';

const mockGetEntitlements = vi.fn();
const mockToast = vi.fn();

vi.mock('../api/entitlements', () => ({
  getEntitlements: (...args) => mockGetEntitlements(...args),
}));
vi.mock('../components/ui/ToastProvider', () => ({
  useToast: () => mockToast,
}));

describe('useEntitlements', () => {
  beforeEach(() => {
    mockGetEntitlements.mockReset();
    mockToast.mockClear();
  });

  it('canAddClient=false, canAddMember=false, коли ліміти вичерпані', async () => {
    mockGetEntitlements.mockResolvedValue({
      success: true,
      plan: { name: 'FREE' },
      recommendedPlan: { name: 'SOLO' },
      usage: { team: 1, activeClients: 5 },
      limits: { maxTeam: 1, maxActiveClients: 5 },
      permissions: { canAddClient: false, canAddMember: false },
    });
    const { result } = renderHook(() => useEntitlements());
    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.canAddClient).toBe(false);
    expect(result.current.canAddMember).toBe(false);
  });

  it('canAddClient=true, коли ліміт не вичерпаний', async () => {
    mockGetEntitlements.mockResolvedValue({
      success: true,
      plan: { name: 'SOLO' },
      recommendedPlan: null,
      usage: { team: 1, activeClients: 4 },
      limits: { maxTeam: 1, maxActiveClients: 30 },
      permissions: { canAddClient: true, canAddMember: false },
    });
    const { result } = renderHook(() => useEntitlements());
    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.canAddClient).toBe(true);
  });

  it('запит не вдався (демо/мережа) — гейти фейляться відкрито (true)', async () => {
    mockGetEntitlements.mockResolvedValue({ success: false });
    const { result } = renderHook(() => useEntitlements());
    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.canAddClient).toBe(true);
    expect(result.current.canAddMember).toBe(true);
    expect(result.current.entitlements).toBe(null);
  });

  it('guardAddClient() блокує дію і показує toast з назвою рекомендованого плану, коли ліміт вичерпано', async () => {
    mockGetEntitlements.mockResolvedValue({
      success: true,
      plan: { name: 'FREE' },
      recommendedPlan: { name: 'SOLO' },
      usage: { team: 1, activeClients: 5 },
      limits: { maxTeam: 1, maxActiveClients: 5 },
      permissions: { canAddClient: false, canAddMember: true },
    });
    const { result } = renderHook(() => useEntitlements());
    await waitFor(() => expect(result.current.loading).toBe(false));

    const fn = vi.fn();
    result.current.guardAddClient(fn)();
    expect(fn).not.toHaveBeenCalled();
    expect(mockToast).toHaveBeenCalledWith(
      expect.stringContaining('«SOLO»'),
      'warning'
    );
  });

  it('guardAddMember() пропускає дію, коли ліміт команди не вичерпано', async () => {
    mockGetEntitlements.mockResolvedValue({
      success: true,
      plan: { name: 'PRO' },
      recommendedPlan: null,
      usage: { team: 2, activeClients: 50 },
      limits: { maxTeam: 3, maxActiveClients: 100 },
      permissions: { canAddClient: true, canAddMember: true },
    });
    const { result } = renderHook(() => useEntitlements());
    await waitFor(() => expect(result.current.loading).toBe(false));

    const fn = vi.fn();
    result.current.guardAddMember(fn)();
    expect(fn).toHaveBeenCalled();
    expect(mockToast).not.toHaveBeenCalled();
  });
});
