/**
 * Спільна таблиця з мобільними картками.
 * Десктоп: звичайна таблиця з усіма колонками, без змін.
 * Мобільна картка (≤768px) — компактно, 2 рядки:
 *   1) заголовок (1-ша колонка) + cardTop-бейдж (статус) + дії-іконки;
 *   2) колонки з mobile:'secondary' — через "•", колонка з mobile:'trailing' — праворуч (дата/сума).
 * Колонки без cardTop/mobile-прапорця на мобільному приховані (лишаються на десктопі);
 * повна інформація — по тапу на картку, якщо є onRowClick.
 *
 * Використання:
 * <Table
 *   columns={[
 *     { key: 'name', label: 'Ім'я' },
 *     { key: 'status', label: 'Статус', cardTop: true, render: (row) => <Badge>...</Badge> },
 *     { key: 'plan', label: 'Тариф', mobile: 'secondary' },
 *     { key: 'date', label: 'Дата', mobile: 'trailing', render: (row) => formatDate(row.date) },
 *     { key: 'phone', label: 'Телефон', icon: 'phone' },
 *     { key: 'actions', label: '', render: (row) => <button ...>Редагувати</button> },
 *   ]}
 *   rows={clients}
 *   keyField="id"
 *   loading={loading}
 *   emptyMessage="Клієнтів ще немає"
 *   onRowClick={(row) => openView(row)}
 *   rowProps={(row) => ({ className: row.is_active ? '' : 'archived', style: { '--card-accent': row.color } })}
 * />
 */
export default function Table({ columns, rows, keyField = 'id', loading = false, emptyMessage = 'Немає даних', onRowClick, rowProps }) {
  const hasRow2 = columns.some((col) => col.mobile === 'secondary' || col.mobile === 'trailing');
  return (
    <div className="table-wrap">
      <table>
        <thead>
          <tr>
            {columns.map((col) => (
              <th key={col.key}>{col.label}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {loading && (
            <tr>
              <td colSpan={columns.length}>
                <div className="loader"><span className="spinner" /> Завантаження...</div>
              </td>
            </tr>
          )}
          {!loading && rows.length === 0 && (
            <tr>
              <td colSpan={columns.length}>
                <div className="empty-state"><p>{emptyMessage}</p></div>
              </td>
            </tr>
          )}
          {!loading && rows.map((row, rowIndex) => {
            const extra = rowProps?.(row) ?? {};
            return (
              <tr
                key={row[keyField] ?? rowIndex}
                {...extra}
                onClick={onRowClick ? () => onRowClick(row) : undefined}
                style={{ ...(onRowClick ? { cursor: 'pointer' } : undefined), ...extra.style }}
              >
                {columns.map((col, colIndex) => {
                  const isHeaderCol = colIndex === 0;
                  const isActionsCol = !isHeaderCol && col.label === '';
                  const isCardTopCol = !isHeaderCol && !isActionsCol && !!col.cardTop;
                  const isSecondaryCol = !isHeaderCol && !isActionsCol && !isCardTopCol && col.mobile === 'secondary';
                  const isTrailingCol = !isHeaderCol && !isActionsCol && !isCardTopCol && col.mobile === 'trailing';
                  const isHiddenOnMobile = !isHeaderCol && !isActionsCol && !isCardTopCol && !isSecondaryCol && !isTrailingCol;
                  const mobileClass = isActionsCol ? 'table-actions-cell'
                    : isCardTopCol ? 'table-card-top-cell'
                    : isSecondaryCol ? 'table-mobile-secondary'
                    : isTrailingCol ? 'table-mobile-trailing'
                    : isHiddenOnMobile ? 'table-mobile-hidden-cell'
                    // Заголовок без render() — звичайний текст, можна безпечно ellipsis'увати в 1 рядок.
                    // З render() — може бути власна розмітка (avatar+ім'я+телефон тощо), тож нехай переноситься.
                    : isHeaderCol && !col.render ? 'table-mobile-title-text'
                    : undefined;
                  return (
                    <td key={col.key} className={mobileClass}>
                      {isActionsCol && onRowClick && (
                        <button type="button" className="btn btn-ghost table-details-btn" onClick={(e) => { e.stopPropagation(); onRowClick(row); }}>
                          Деталі
                        </button>
                      )}
                      {col.render ? col.render(row) : row[col.key]}
                    </td>
                  );
                })}
                {/* Примусовий розрив на новий рядок картки перед мета-рядком (2), незалежно
                    від того, скільки вільного місця лишилось у рядку 1 — інакше flex-wrap
                    може "дотиснути" мета-поля туди ж, де заголовок+статус+дії. Прихована на десктопі. */}
                {hasRow2 && <td className="table-mobile-linebreak" aria-hidden="true" />}
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
