/**
 * Технічних станів підписки клубу шість (trial/active/trial_expired/past_due/cancelled/deleted),
 * але для адміна головне — активна вона чи ні. Решта показуємо як причину стану, а не окремий статус.
 */
const ACTIVE_STATUSES = ['trial', 'active'];

export function isSubscriptionActive(status) {
  return ACTIVE_STATUSES.includes(status);
}

export const SUBSCRIPTION_REASON_LABELS = {
  trial: 'Тріал',
  active: 'Оплачено',
  trial_expired: 'Тріал закінчився',
  past_due: 'Не оплачено',
  cancelled: 'Скасовано',
  deleted: 'Видалено',
};

export function subscriptionReasonLabel(status) {
  return SUBSCRIPTION_REASON_LABELS[status] || status || '—';
}
