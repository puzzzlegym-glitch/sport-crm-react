/**
 * Універсальний блок полів "мітка: значення" для перегляду запису (клієнт, клуб,
 * абонемент, товар, прихід тощо) — єдина верстка замість дублювання по сторінках.
 *
 * Використання:
 * <DetailFieldsGrid fields={[['Телефон', c.phone || '—'], ['Email', c.email || '—']]} />
 *
 * variant:
 *  - 'grid' (за замовчуванням) — мітка над значенням, 2 колонки (картка клієнта/товару/приходу)
 *  - 'rows' — мітка зліва, значення справа, один стовпець (картка клубу/абонементу)
 */
export default function DetailFieldsGrid({ fields, variant = 'grid' }) {
  const className = variant === 'rows' ? 'detail-rows' : 'detail-fields-grid';
  const fieldClassName = variant === 'rows' ? 'detail-row' : 'detail-field';
  const labelClassName = variant === 'rows' ? 'detail-row-label' : 'detail-label';
  const valueClassName = variant === 'rows' ? 'detail-row-value' : 'detail-value';

  return (
    <div className={className}>
      {fields.filter(Boolean).map(([label, value]) => (
        <div className={fieldClassName} key={label}>
          <span className={labelClassName}>{label}</span>
          <span className={valueClassName}>{value}</span>
        </div>
      ))}
    </div>
  );
}
