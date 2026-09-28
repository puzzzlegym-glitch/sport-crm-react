/**
 * Простий пейджер "N записів · ‹ page/pages ›" — 1:1 з renderPagination()
 * у saas.php/saas_payments.php (відрізняється від Pagination.jsx з номерами сторінок).
 */
export default function SimplePager({ page, pages, total, onChange }) {
  if (pages <= 1) return null;
  return (
    <div className="pagination">
      <span>{total} записів</span>
      <div className="pagination-btns">
        <button className="page-btn" disabled={page <= 1} onClick={() => onChange(page - 1)}>‹</button>
        <button className="page-btn active">{page} / {pages}</button>
        <button className="page-btn" disabled={page >= pages} onClick={() => onChange(page + 1)}>›</button>
      </div>
    </div>
  );
}
