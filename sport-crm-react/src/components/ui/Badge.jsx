/**
 * Використання: <Badge variant="active">Активний</Badge>
 * variant → клас .badge-{variant} з admin.css
 * (active, inactive, pending, info, superadmin, owner, manager, trainer)
 */
export default function Badge({ variant = 'info', children }) {
  return <span className={`badge badge-${variant}`}>{children}</span>;
}
