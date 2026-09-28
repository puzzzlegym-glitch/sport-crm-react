/**
 * Набір лінійних SVG-іконок (24x24, stroke=currentColor) замість emoji.
 * Використання: <Icon name="user" size={18} />
 */
const PATHS = {
  user: <><circle cx="12" cy="8" r="4" /><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8" /></>,
  users: <><circle cx="9" cy="8" r="3.2" /><path d="M2.5 21c0-3.9 2.9-7 6.5-7s6.5 3.1 6.5 7" /><path d="M15.3 4.6c1.6.4 2.7 1.9 2.7 3.6 0 1.7-1.1 3.2-2.7 3.6" /><path d="M18 14.2c2.6.5 4.5 2.7 4.5 5.8" /></>,
  lock: <><rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V7a4 4 0 0 1 8 0v4" /></>,
  building: <><rect x="4" y="3" width="16" height="18" rx="1" /><path d="M9 8h1M14 8h1M9 12h1M14 12h1M9 16h1M14 16h1" /></>,
  card: <><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 10h18" /><path d="M7 15h4" /></>,
  key: <><circle cx="8" cy="15" r="4" /><path d="M11 12l9-9" /><path d="M16 7l3 3" /><path d="M13 10l2.3 2.3" /></>,
  edit: <path d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-3-3L5 17v3z" />,
  ban: <><circle cx="12" cy="12" r="9" /><path d="M5.5 5.5l13 13" /></>,
  check: <path d="M4 12l5 5L20 6" />,
  checkCircle: <><circle cx="12" cy="12" r="9" /><path d="M8 12l3 3 5-6" /></>,
  trash: <><path d="M4 7h16" /><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" /><path d="M6 7l1 13a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-13" /></>,
  search: <><circle cx="10.5" cy="10.5" r="6.5" /><path d="M20 20l-4.6-4.6" /></>,
  eye: <><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z" /><circle cx="12" cy="12" r="3" /></>,
  eyeOff: <><path d="M3 3l18 18" /><path d="M10.6 5.2A10.6 10.6 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.5 6.6C4 8.3 2 12 2 12s3.6 7 10 7c1.3 0 2.5-.2 3.6-.6" /><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2" /></>,
  shield: <path d="M12 3l7 3v6c0 4.5-3 8-7 9-4-1-7-4.5-7-9V6l7-3z" />,
  chevronDown: <path d="M6 9l6 6 6-6" />,
  menu: <><path d="M4 7h16" /><path d="M4 12h16" /><path d="M4 17h16" /></>,
  switch: <><path d="M4 8h13l-3-3" /><path d="M20 16H7l3 3" /></>,
  arrowLeft: <><path d="M19 12H5" /><path d="M5 12l6-6" /><path d="M5 12l6 6" /></>,
  logout: <><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><path d="M16 17l5-5-5-5" /><path d="M21 12H9" /></>,
  grid: <><rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" /></>,
  fileText: <><path d="M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z" /><path d="M9 13h6M9 17h6M9 9h2" /></>,
  banknote: <><rect x="2" y="6" width="20" height="12" rx="2" /><circle cx="12" cy="12" r="3" /><path d="M6 9v.01M18 15v.01" /></>,
  tag: <><path d="M20.6 12.6L12 21.2a2 2 0 0 1-2.8 0l-7-7a2 2 0 0 1 0-2.8L10.8 3H20a2 2 0 0 1 2 2v7.6z" /><circle cx="15" cy="8" r="1.3" /></>,
  calendar: <><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M3 10h18M8 3v4M16 3v4" /></>,
  cart: <><circle cx="9" cy="20" r="1.4" /><circle cx="17" cy="20" r="1.4" /><path d="M2 3h2l2.4 12.2a2 2 0 0 0 2 1.8h8.6a2 2 0 0 0 2-1.7L21 8H6" /></>,
  receipt: <><path d="M6 2h12v18l-3-2-3 2-3-2-3 2V2z" /><path d="M9 7h6M9 11h6" /></>,
  package: <><path d="M21 8l-9-5-9 5v8l9 5 9-5V8z" /><path d="M3 8l9 5 9-5M12 13v8" /></>,
  warehouse: <><path d="M3 21V10l9-6 9 6v11" /><path d="M7 21v-6h10v6" /></>,
  wallet: <><path d="M3 7a2 2 0 0 1 2-2h13a1 1 0 0 1 1 1v3" /><path d="M3 7v11a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2H6a2 2 0 0 1-2-2z" /><circle cx="16.3" cy="14" r="1.1" /></>,
  atm: <><rect x="2" y="7" width="20" height="12" rx="2" /><path d="M2 11h20" /><circle cx="6" cy="15" r="1" /></>,
  dumbbell: <><path d="M4 9.5v5M20 9.5v5" /><rect x="1.5" y="10.3" width="2.2" height="3.4" rx="0.8" /><rect x="20.3" y="10.3" width="2.2" height="3.4" rx="0.8" /><path d="M7 12h10" /><rect x="6" y="9.5" width="2" height="5" rx="1" /><rect x="16" y="9.5" width="2" height="5" rx="1" /></>,
  gear: <><circle cx="12" cy="12" r="3" /><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1" /></>,
  pin: <><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z" /><circle cx="12" cy="10" r="2.3" /></>,
  clock: <><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 3" /></>,
  link: <><path d="M9 15l6-6" /><path d="M8 16.5L5.5 19a3.5 3.5 0 0 1-5-5L3 11.5" /><path d="M16 7.5L18.5 5a3.5 3.5 0 0 1 5 5L21 12.5" /></>,
  alertTriangle: <><path d="M12 3.5l9.5 16.5H2.5L12 3.5z" /><path d="M12 10v4M12 17.5v.01" /></>,
  plus: <><path d="M12 5v14M5 12h14" /></>,
  phone: <path d="M6.6 10.8a15.6 15.6 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.2c1.1.4 2.3.6 3.6.6a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1C10.6 21 3 13.4 3 4a1 1 0 0 1 1-1h3.4a1 1 0 0 1 1 1c0 1.3.2 2.5.6 3.6a1 1 0 0 1-.3 1z" />,
  mail: <><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 7l9 6 9-6" /></>,
  image: <><rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="8.5" cy="8.5" r="1.5" /><path d="M21 15l-5-5L5 21" /></>,
  download: <><path d="M12 3v12" /><path d="M7 10l5 5 5-5" /><path d="M4 19h16" /></>,
  trendingUp: <><path d="M3 17l6-6 4 4 8-8" /><path d="M15 7h6v6" /></>,
  helpCircle: <><circle cx="12" cy="12" r="9" /><path d="M9.1 9a3 3 0 1 1 4.6 2.5c-.9.6-1.7 1.1-1.7 2.3" /><path d="M12 17.5v.01" /></>,
  messageCircle: <><path d="M21 11.5a8.5 8.5 0 0 1-8.9 8.5 8.7 8.7 0 0 1-3.8-.8L3 21l1.8-5.3a8.5 8.5 0 0 1-.8-3.7A8.5 8.5 0 0 1 12.5 3a8.5 8.5 0 0 1 8.5 8.5z" /><path d="M8 10.5h8M8 14h5" /></>,
  bell: <><path d="M6 9a6 6 0 0 1 12 0c0 4.2 1.2 6 2 7H4c.8-1 2-2.8 2-7z" /><path d="M9.5 20a2.5 2.5 0 0 0 5 0" /></>,
  info: <><circle cx="12" cy="12" r="9" /><path d="M12 11.5v5" /><path d="M12 8v.01" /></>,
  gift: <><rect x="4" y="9" width="16" height="4" rx="1" /><rect x="5" y="13" width="14" height="8" rx="1" /><path d="M12 9v12" /><path d="M12 9C10.5 5.5 5 6 5 9" /><path d="M12 9c1.5-3.5 7-3 7 0" /></>,
  moreVertical: <><circle cx="12" cy="5" r="1" /><circle cx="12" cy="12" r="1" /><circle cx="12" cy="19" r="1" /></>,
  leaf: <><path d="M20 4c-9 0-16 6-16 15 9 0 15-6 15-15z" /><path d="M5 19c3-5 7-9 12-12" /></>,
  rocket: <><path d="M12 15c-2 0-4-.5-5-1.5C6 9 9 4 12 2c3 2 6 7 5 11.5-1 1-3 1.5-5 1.5z" /><circle cx="12" cy="9" r="1.6" /><path d="M9 14l-2.5 2.5.8 3 3-2.5" /><path d="M15 14l2.5 2.5-.8 3-3-2.5" /></>,
  briefcase: <><rect x="3" y="8" width="18" height="11" rx="2" /><path d="M8 8V6a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /><path d="M3 13h18" /></>,
  crown: <><path d="M4 18h16l1.2-9-5.2 4-4-7-4 7-5.2-4L4 18z" /><path d="M4 18v2h16v-2" /></>,
  smartphone: <><rect x="6" y="2" width="12" height="20" rx="2" /><path d="M11 18h2" /></>,
  send: <><path d="M22 2L11 13" /><path d="M22 2l-7 20-4-9-9-4 20-7z" /></>,
  qrCode: <><rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><path d="M14 14h3v3h-3zM19 14h2v2h-2zM14 19h2v2h-2zM19 19h2v2h-2z" /></>,
  bookOpen: <><path d="M12 6C10 4.5 7.5 4 5 4v14c2.5 0 5 .5 7 2 2-1.5 4.5-2 7-2V4c-2.5 0-5 .5-7 2z" /><path d="M12 6v14" /></>,
  copy: <><rect x="9" y="9" width="12" height="12" rx="2" /><path d="M5 15V5a2 2 0 0 1 2-2h10" /></>,
  globe: <><circle cx="12" cy="12" r="9" /><path d="M3 12h18" /><path d="M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18z" /></>,
  chevronRight: <path d="M9 5l7 7-7 7" />,
  zap: <path d="M13 2L4 14h6l-1 8 9-12h-6l1-8z" />,
  arrowUp: <><path d="M12 19V5" /><path d="M6 11l6-6 6 6" /></>,
  barcode: <><path d="M4 5v14M7 5v14M10 5v14M13 5v9M16 5v14M19 5v9" /></>,
  undo: <><path d="M4 11h11a5 5 0 0 1 0 10h-2" /><path d="M9 6L4 11l5 5" /></>,
  refresh: <><polyline points="23 4 23 10 17 10" /><polyline points="1 20 1 14 7 14" /><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" /></>,
};

export default function Icon({ name, size = 18, strokeWidth = 1.8, className = '', style, title }) {
  const content = PATHS[name];
  if (!content) return null;
  return (
    <svg
      className={`icon${className ? ` ${className}` : ''}`}
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={strokeWidth}
      strokeLinecap="round"
      strokeLinejoin="round"
      style={style}
      aria-hidden={title ? undefined : true}
      role={title ? 'img' : undefined}
    >
      {title && <title>{title}</title>}
      {content}
    </svg>
  );
}
