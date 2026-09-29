import { useEffect, useState } from 'react';
import { searchClients } from '../../api/clients';

/**
 * Пошук клієнта за ПІБ / телефоном / штрих-кодом (clients_api:search).
 * multiple=false: value — {id, full_name} | null, onChange(client|null)
 * multiple=true:  value — масив {id, full_name}, onChange(array)
 * excludeIds — клієнти, яких не показувати (напр. вже в групі).
 */
export default function ClientSearchPicker({ value, onChange, multiple = false, excludeIds = [], placeholder = "Ім'я, телефон або штрих-код..." }) {
  const [q, setQ] = useState('');
  const [results, setResults] = useState([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    const term = q.trim();
    if (term.length < 2) { setResults([]); return undefined; }
    let alive = true;
    const t = setTimeout(async () => {
      setLoading(true);
      const res = await searchClients(term);
      if (!alive) return;
      setLoading(false);
      setResults(res.success ? (res.results || []) : []);
    }, 300);
    return () => { alive = false; clearTimeout(t); };
  }, [q]);

  const selected = multiple ? (value || []) : (value ? [value] : []);
  const selectedIds = new Set(selected.map((c) => c.id));
  const hidden = new Set(excludeIds.map(Number));
  const visible = results.filter((c) => !hidden.has(Number(c.id)));

  function pick(c) {
    const item = { id: Number(c.id), full_name: c.full_name, phone: c.phone };
    if (multiple) {
      onChange(selectedIds.has(item.id) ? selected.filter((x) => x.id !== item.id) : [...selected, item]);
    } else {
      onChange(item);
      setQ('');
      setResults([]);
    }
  }

  return (
    <div>
      {selected.length > 0 && (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginBottom: 8 }}>
          {selected.map((c) => (
            <span key={c.id} className="badge badge-info" style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
              {c.full_name}
              <button
                type="button"
                onClick={() => onChange(multiple ? selected.filter((x) => x.id !== c.id) : null)}
                style={{ background: 'none', border: 'none', color: 'inherit', cursor: 'pointer', padding: 0, fontSize: 14, lineHeight: 1 }}
                title="Прибрати"
              >×</button>
            </span>
          ))}
        </div>
      )}
      {(multiple || selected.length === 0) && (
        <input type="text" value={q} placeholder={placeholder} onChange={(e) => setQ(e.target.value)} />
      )}
      {q.trim().length >= 2 && (
        <div style={{ marginTop: 6, maxHeight: 220, overflowY: 'auto', border: '1px solid var(--border)', borderRadius: 'var(--radius-sm)' }}>
          {loading && <div style={{ padding: 10, fontSize: 13, color: 'var(--text-muted)' }}>Пошук...</div>}
          {!loading && visible.length === 0 && <div style={{ padding: 10, fontSize: 13, color: 'var(--text-muted)' }}>Нікого не знайдено</div>}
          {!loading && visible.map((c) => (
            <label
              key={c.id}
              style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 10px', cursor: 'pointer', borderBottom: '1px solid var(--border)', fontSize: 13 }}
            >
              {multiple
                ? <input type="checkbox" checked={selectedIds.has(Number(c.id))} onChange={() => pick(c)} />
                : <input type="radio" checked={false} onChange={() => pick(c)} />}
              <span style={{ fontWeight: 500 }}>{c.full_name}</span>
              <span style={{ color: 'var(--text-muted)' }}>{c.phone || ''}</span>
            </label>
          ))}
        </div>
      )}
    </div>
  );
}
