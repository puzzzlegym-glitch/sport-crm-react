import { useState, useEffect, useCallback } from 'react';
import { getEntitlements } from '../api/entitlements';
import { useToast } from '../components/ui/ToastProvider';

/**
 * Завантажує entitlement-стан клубу (план/usage/ліміти/permissions) при
 * монтуванні. Це лише UX-підказка — реальна блокуюча перевірка завжди на
 * бекенді (Billing::checkClientLimit/checkTeamLimit); якщо запит не вдався
 * (мережа, демо-режим) — гейти фейляться "відкрито", щоб не ламати UI.
 */
export function useEntitlements() {
  const [entitlements, setEntitlements] = useState(null);
  const [loading, setLoading] = useState(true);
  const toast = useToast();

  const reload = useCallback(async () => {
    setLoading(true);
    const res = await getEntitlements();
    setEntitlements(res?.success ? res : null);
    setLoading(false);
  }, []);

  useEffect(() => { reload(); }, [reload]);

  const canAddClient = entitlements?.permissions?.canAddClient ?? true;
  const canAddMember = entitlements?.permissions?.canAddMember ?? true;

  const limitMessage = (kind) => {
    const plan = entitlements?.plan?.name;
    const rec = entitlements?.recommendedPlan?.name;
    const base = kind === 'client'
      ? `Ви використали всі місця тарифу${plan ? ` «${plan}»` : ''} для клієнтів.`
      : `Ви використали ліміт команди тарифу${plan ? ` «${plan}»` : ''}.`;
    return rec ? `${base} Перейдіть на «${rec}».` : base;
  };

  const guard = (allowed, message) => (fn) => (e) => {
    if (!allowed) {
      e?.preventDefault();
      toast(message, 'warning');
      return;
    }
    fn(e);
  };

  return {
    entitlements,
    loading,
    reload,
    canAddClient,
    canAddMember,
    guardAddClient: guard(canAddClient, limitMessage('client')),
    guardAddMember: guard(canAddMember, limitMessage('member')),
  };
}
