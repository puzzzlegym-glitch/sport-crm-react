/**
 * Використання: <FormGroup label="Телефон"><input type="tel" .../></FormGroup>
 */
export default function FormGroup({ label, children, fullWidth = false, hint }) {
  return (
    <div className="form-group" style={fullWidth ? { gridColumn: '1/-1' } : undefined}>
      {label && <label>{label}</label>}
      {children}
      {hint && <div className="text-muted" style={{ fontSize: 12, marginTop: 4 }}>{hint}</div>}
    </div>
  );
}
