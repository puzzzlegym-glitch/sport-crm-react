/**
 * Поле телефону з фіксованим кодом України (+380) і автоформатуванням під час вводу.
 * value/onChange працюють з "сирим" рядком у форматі +380XXXXXXXXX (порожній рядок, якщо не введено).
 * Використання: <PhoneInput value={form.phone} onChange={(v) => setForm({ ...form, phone: v })} error={!!phoneError} />
 */
export function extractPhoneDigits(value) {
  let d = (value || '').replace(/\D/g, '');
  if (d.startsWith('380')) d = d.slice(3);
  else if (d.startsWith('0') && d.length === 10) d = d.slice(1);
  return d.slice(0, 9);
}

export function isValidPhone(value) {
  if (!value) return true;
  return extractPhoneDigits(value).length === 9;
}

function formatDigits(d) {
  return [d.slice(0, 2), d.slice(2, 5), d.slice(5, 7), d.slice(7, 9)].filter(Boolean).join(' ');
}

export default function PhoneInput({ value, onChange, error, placeholder = 'XX XXX XX XX' }) {
  const digits = extractPhoneDigits(value);

  function handleChange(e) {
    const d = e.target.value.replace(/\D/g, '').slice(0, 9);
    onChange(d ? '+380' + d : '');
  }

  return (
    <div className={`phone-input-wrap${error ? ' has-error' : ''}`}>
      <span className="phone-prefix">+380</span>
      <input type="tel" inputMode="numeric" autoComplete="tel" placeholder={placeholder} value={formatDigits(digits)} onChange={handleChange} />
    </div>
  );
}
