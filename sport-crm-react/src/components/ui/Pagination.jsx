/**
 * 1:1 з renderPagination() у pages/clients.php.
 * Використання: <Pagination page={page} pages={pages} total={total} perPage={perPage} onChange={setPage} />
 */
export default function Pagination({ page, pages, total, perPage, onChange }) {
  if (pages <= 1) return null;

  const from = (page - 1) * perPage + 1;
  const to = Math.min(page * perPage, total);
  const delta = 2;

  const items = [];
  for (let i = 1; i <= pages; i++) {
    if (i === 1 || i === pages || Math.abs(i - page) <= delta) {
      items.push(
        <button
          key={i}
          className={`page-btn ${i === page ? 'active' : ''}`}
          onClick={() => onChange(i)}
        >
          {i}
        </button>
      );
    } else if (Math.abs(i - page) === delta + 1) {
      items.push(<button key={`ellipsis-${i}`} className="page-btn" disabled>…</button>);
    }
  }

  return (
    <div className="pagination">
      <span>{from}–{to} з {total}</span>
      <div className="pagination-btns">
        <button className="page-btn" onClick={() => onChange(page - 1)} disabled={page <= 1}>‹</button>
        {items}
        <button className="page-btn" onClick={() => onChange(page + 1)} disabled={page >= pages}>›</button>
      </div>
    </div>
  );
}
