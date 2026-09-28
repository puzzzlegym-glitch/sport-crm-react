import { useMemo, useRef, useState } from 'react';
import Modal from './Modal';
import Icon from './Icon';
import { useToast } from './ToastProvider';
import { importClients } from '../../api/clients';
import { extractPhoneDigits } from './PhoneInput';
import { localDate } from '../../utils/format';

const MAX_ROWS = 500;
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const FIELDS = [
  { key: 'full_name', label: 'ПІБ', required: true, aliases: ['піб', "ім'я", 'імя', 'повне ім\'я', 'прізвище ім\'я по батькові', 'name', 'full name', 'fullname'] },
  { key: 'phone', label: 'Телефон', aliases: ['телефон', 'тел', 'номер телефону', 'phone', 'моб.тел'] },
  { key: 'email', label: 'Email', aliases: ['email', 'e-mail', 'пошта', 'електронна пошта'] },
  { key: 'birthday', label: 'Дата народження', aliases: ['дата народження', 'день народження', 'birthday', 'дата'] },
  { key: 'gender', label: 'Стать', aliases: ['стать', 'gender'] },
  { key: 'address', label: 'Адреса', aliases: ['адреса', 'address'] },
  { key: 'source', label: 'Джерело', aliases: ['джерело', 'source', 'звідки дізнався'] },
  { key: 'notes', label: 'Нотатки', aliases: ['нотатки', 'примітка', 'коментар', 'notes'] },
];

function normalize(s) {
  return String(s ?? '').trim().toLowerCase().replace(/['"’ʼ]/g, '');
}

function autoMap(headerRow) {
  const mapping = {};
  const used = new Set();
  headerRow.forEach((header, colIdx) => {
    const h = normalize(header);
    if (!h) return;
    const field = FIELDS.find((f) => !used.has(f.key) && f.aliases.some((a) => h === a || h.includes(a)));
    if (field) {
      mapping[field.key] = colIdx;
      used.add(field.key);
    }
  });
  return mapping;
}

function normalizePhone(v) {
  const raw = String(v ?? '').trim();
  if (!raw) return '';
  const digits = extractPhoneDigits(raw);
  return digits.length === 9 ? '+380' + digits : raw;
}

function normalizeBirthday(v) {
  if (!v) return '';
  if (v instanceof Date && !isNaN(v)) return localDate(v);
  const s = String(v).trim();
  let m = s.match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
  if (m) return `${m[1]}-${m[2].padStart(2, '0')}-${m[3].padStart(2, '0')}`;
  m = s.match(/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})$/);
  if (m) return `${m[3]}-${m[2].padStart(2, '0')}-${m[1].padStart(2, '0')}`;
  return '';
}

function normalizeGender(v) {
  const s = normalize(v);
  if (['м', 'm', 'чоловік', 'чол', 'male'].includes(s)) return 'M';
  if (['ж', 'f', 'жінка', 'жін', 'female'].includes(s)) return 'F';
  return '';
}

function buildRow(arr, colIdx, mapping) {
  const get = (key) => {
    const idx = mapping[key];
    return idx == null ? '' : arr[idx];
  };
  const data = {
    full_name: String(get('full_name') ?? '').trim(),
    phone: normalizePhone(get('phone')),
    email: String(get('email') ?? '').trim(),
    birthday: normalizeBirthday(get('birthday')),
    gender: normalizeGender(get('gender')),
    address: String(get('address') ?? '').trim(),
    source: String(get('source') ?? '').trim(),
    notes: String(get('notes') ?? '').trim(),
  };

  let error = null;
  if (data.full_name.length < 2) error = 'Немає ПІБ (мінімум 2 символи)';
  else if (data.phone && !/^\+380\d{9}$/.test(data.phone)) error = 'Некоректний формат телефону';
  else if (data.email && !EMAIL_RE.test(data.email)) error = 'Некоректний email';

  return { rowNum: colIdx + 2, data, error };
}

async function downloadTemplate() {
  const XLSX = await import('xlsx');
  const headers = FIELDS.map((f) => f.label + (f.required ? ' *' : ''));
  const example = ['Іван Петренко', '+380671234567', 'ivan@example.com', '1990-05-14', 'Чоловік', 'м. Київ, вул. Хрещатик 1', 'Instagram', ''];
  const ws = XLSX.utils.aoa_to_sheet([headers, example]);
  ws['!cols'] = headers.map(() => ({ wch: 22 }));
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Клієнти');
  XLSX.writeFile(wb, 'Шаблон_імпорту_клієнтів.xlsx');
}

/** Використання: <ImportClientsModal open={open} onClose={...} onImported={reload} /> */
export default function ImportClientsModal({ open, onClose, onImported }) {
  const toast = useToast();
  const fileInputRef = useRef(null);

  const [step, setStep] = useState('upload'); // 'upload' | 'preview' | 'result'
  const [dragging, setDragging] = useState(false);
  const [parseError, setParseError] = useState('');
  const [fileName, setFileName] = useState('');
  const [columns, setColumns] = useState([]);
  const [dataRows, setDataRows] = useState([]);
  const [mapping, setMapping] = useState({});
  const [importing, setImporting] = useState(false);
  const [result, setResult] = useState(null);

  function reset() {
    setStep('upload');
    setDragging(false);
    setParseError('');
    setFileName('');
    setColumns([]);
    setDataRows([]);
    setMapping({});
    setImporting(false);
    setResult(null);
  }

  function handleClose() {
    reset();
    onClose?.();
  }

  async function handleFile(file) {
    setParseError('');
    if (!file) return;
    let wb, XLSX;
    try {
      const [mod, buf] = await Promise.all([import('xlsx'), file.arrayBuffer()]);
      XLSX = mod;
      // codepage 65001 (UTF-8) — інакше .csv без BOM (типовий експорт з Google Таблиць/Excel) читається кракозябрами
      wb = XLSX.read(buf, { type: 'array', cellDates: true, codepage: 65001 });
    } catch {
      setParseError('Не вдалося прочитати файл. Перевірте формат (.xlsx, .xls, .csv).');
      return;
    }
    const sheet = wb.Sheets[wb.SheetNames[0]];
    const aoa = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '', raw: true });
    const headerRow = (aoa[0] || []).map((h) => String(h ?? '').trim());
    const rows = aoa.slice(1).filter((r) => r.some((c) => String(c ?? '').trim() !== ''));

    if (!headerRow.length || !rows.length) {
      setParseError('Файл порожній або містить лише заголовки.');
      return;
    }
    if (rows.length > MAX_ROWS) {
      setParseError(`У файлі ${rows.length} рядків — максимум ${MAX_ROWS} клієнтів за один імпорт.`);
      return;
    }

    setFileName(file.name);
    setColumns(headerRow);
    setDataRows(rows);
    setMapping(autoMap(headerRow));
    setStep('preview');
  }

  function onInputChange(e) {
    handleFile(e.target.files?.[0]);
    e.target.value = '';
  }
  function onDrop(e) {
    e.preventDefault();
    setDragging(false);
    handleFile(e.dataTransfer.files?.[0]);
  }

  const previewRows = useMemo(() => {
    const built = dataRows.map((r, i) => buildRow(r, i, mapping));
    const seenPhone = new Map();
    const seenEmail = new Map();
    return built.map((r) => {
      if (r.error) return r;
      if (r.data.phone) {
        if (seenPhone.has(r.data.phone)) return { ...r, error: `Дублікат телефону в файлі (рядок ${seenPhone.get(r.data.phone)})` };
        seenPhone.set(r.data.phone, r.rowNum);
      }
      if (r.data.email) {
        const key = r.data.email.toLowerCase();
        if (seenEmail.has(key)) return { ...r, error: `Дублікат email в файлі (рядок ${seenEmail.get(key)})` };
        seenEmail.set(key, r.rowNum);
      }
      return r;
    });
  }, [dataRows, mapping]);

  const validRows = previewRows.filter((r) => !r.error);
  const invalidCount = previewRows.length - validRows.length;

  async function handleImport() {
    setImporting(true);
    const res = await importClients(validRows.map((r) => r.data));
    setImporting(false);
    if (!res.success) {
      toast(res.error, 'error');
      return;
    }
    setResult({ added: res.added, skipped: res.skipped || [] });
    setStep('result');
    onImported?.();
  }

  const title = { upload: 'Імпорт клієнтів з Excel', preview: `Перевірка даних — ${fileName}`, result: 'Імпорт завершено' }[step];

  return (
    <Modal size="lg" open={open} onClose={handleClose} title={title}>
      {step === 'upload' && (
        <div>
          <div
            className={`import-dropzone${dragging ? ' dragging' : ''}`}
            onClick={() => fileInputRef.current?.click()}
            onDragOver={(e) => { e.preventDefault(); setDragging(true); }}
            onDragLeave={() => setDragging(false)}
            onDrop={onDrop}
          >
            <Icon name="fileText" size={28} />
            <div className="import-dropzone-title">Перетягніть файл сюди або натисніть, щоб обрати</div>
            <div className="text-muted" style={{ fontSize: 12 }}>.xlsx, .xls, .csv — до {MAX_ROWS} клієнтів за раз</div>
            <input ref={fileInputRef} type="file" accept=".xlsx,.xls,.csv" style={{ display: 'none' }} onChange={onInputChange} />
          </div>
          {parseError && <div className="alert alert-error" style={{ marginTop: 14 }}>{parseError}</div>}
          <button className="btn btn-ghost btn-sm" style={{ marginTop: 14 }} onClick={downloadTemplate}>
            <Icon name="download" size={14} /> Завантажити шаблон файлу
          </button>
        </div>
      )}

      {step === 'preview' && (
        <div>
          <div className="import-mapping">
            {FIELDS.map((f) => (
              <div key={f.key} className="import-mapping-field">
                <label>{f.label}{f.required ? ' *' : ''}</label>
                <select
                  value={mapping[f.key] ?? ''}
                  onChange={(e) => setMapping({ ...mapping, [f.key]: e.target.value === '' ? undefined : Number(e.target.value) })}
                >
                  <option value="">— не імпортувати —</option>
                  {columns.map((c, idx) => <option key={idx} value={idx}>{c || `Колонка ${idx + 1}`}</option>)}
                </select>
              </div>
            ))}
          </div>

          <div className="import-summary">
            Знайдено рядків: <strong>{previewRows.length}</strong>
            {' · '}<span className="text-success">коректних: {validRows.length}</span>
            {invalidCount > 0 && <>{' · '}<span className="text-danger">з помилками: {invalidCount}</span></>}
          </div>

          <div className="import-preview-wrap">
            <table className="import-preview-table">
              <thead>
                <tr>
                  <th>#</th><th>ПІБ</th><th>Телефон</th><th>Email</th><th>Статус</th>
                </tr>
              </thead>
              <tbody>
                {previewRows.map((r) => (
                  <tr key={r.rowNum} className={r.error ? 'row-error' : ''}>
                    <td>{r.rowNum}</td>
                    <td>{r.data.full_name || '—'}</td>
                    <td>{r.data.phone || '—'}</td>
                    <td>{r.data.email || '—'}</td>
                    <td>
                      {r.error
                        ? <span className="text-danger" title={r.error}><Icon name="alertTriangle" size={13} /> {r.error}</span>
                        : <span className="text-success"><Icon name="check" size={13} /> Готово</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div style={{ display: 'flex', gap: 10, marginTop: 16 }}>
            <button className="btn btn-primary" disabled={!validRows.length || importing} onClick={handleImport}>
              {importing ? 'Імпортування...' : `Імпортувати ${validRows.length} клієнтів`}
            </button>
            <button className="btn btn-ghost" onClick={reset}>Назад</button>
          </div>
        </div>
      )}

      {step === 'result' && result && (
        <div>
          <div className="alert alert-success" style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <Icon name="checkCircle" size={20} /> Додано клієнтів: <strong>{result.added}</strong>
          </div>
          {result.skipped.length > 0 && (
            <div style={{ marginTop: 14 }}>
              <div className="text-muted" style={{ fontSize: 13, marginBottom: 8 }}>Пропущено: {result.skipped.length}</div>
              <div className="import-preview-wrap" style={{ maxHeight: 240 }}>
                <table className="import-preview-table">
                  <thead><tr><th>Рядок</th><th>Ім'я</th><th>Причина</th></tr></thead>
                  <tbody>
                    {result.skipped.map((s, i) => (
                      <tr key={i} className="row-error">
                        <td>{s.row}</td><td>{s.name || '—'}</td><td>{s.reason}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
          <button className="btn btn-primary" style={{ marginTop: 16 }} onClick={handleClose}>Готово</button>
        </div>
      )}
    </Modal>
  );
}
