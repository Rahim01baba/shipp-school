// Jeu d'icones SHIPP (SVG en ligne, trait 2 px, 24 x 24) : aucune dependance externe.
// Usage : <Icon name="bus" /> ; taille par la propriete size (par defaut 20).

const P = {
  home: 'M3 10.5 12 3l9 7.5V21a1 1 0 0 1-1 1h-5v-7h-6v7H4a1 1 0 0 1-1-1z',
  bus: 'M4 16V6a3 3 0 0 1 3-3h10a3 3 0 0 1 3 3v10M4 16h16M4 16v3h2v-3m12 0v3h2v-3M4 10h16M7.5 13h.01M16.5 13h.01',
  map: 'M9 4 3 6v14l6-2 6 2 6-2V4l-6 2-6-2zM9 4v14m6-12v14',
  pin: 'M12 22s7-6.2 7-12a7 7 0 0 0-14 0c0 5.8 7 12 7 12zm0-9a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
  route: 'M6 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm12-10a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM8 17h7.5a3.5 3.5 0 0 0 0-7h-7a3.5 3.5 0 0 1 0-7H16',
  steering: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zm0-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM3.5 10.5l5.6 1M20.5 10.5l-5.6 1M12 15v6',
  car: 'M5 17h14M5 17a2 2 0 1 0 4 0m6 0a2 2 0 1 0 4 0M3 17v-5l2-5h14l2 5v5M3 12h18',
  users: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m7-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm13 10v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8',
  student: 'M22 9 12 4 2 9l10 5 10-5zM6 11v5c3 2.5 9 2.5 12 0v-5M22 9v6',
  school: 'M3 21h18M5 21V9l7-5 7 5v12M9 21v-6h6v6M12 10h.01',
  calendar: 'M4 5h16v16H4zM4 10h16M8 3v4m8-4v4',
  utensils: 'M4 3v8a2 2 0 0 0 2 2h1v8M8 3v8M6 3v5m11-5c-2 0-3 2-3 5v5h3v8V3z',
  wallet: 'M3 7a2 2 0 0 1 2-2h13v4M3 7v11a2 2 0 0 0 2 2h15V9H5a2 2 0 0 1-2-2zm14 7h.01',
  coins: 'M9 14c-3.9 0-7-1.3-7-3s3.1-3 7-3 7 1.3 7 3-3.1 3-7 3zm-7-3v4c0 1.7 3.1 3 7 3s7-1.3 7-3v-4m-1-5.8C19.4 5.6 22 6.8 22 8.5v4c0 1.3-1.6 2.4-4 2.9',
  chart: 'M3 3v18h18M7 16v-4m5 4V8m5 8v-7',
  alert: 'M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4m0 4h.01',
  settings: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm7.4-3a7.4 7.4 0 0 0-.1-1.2l2-1.6-2-3.4-2.4 1a7.3 7.3 0 0 0-2-1.2L14.5 3h-4l-.4 2.6a7.3 7.3 0 0 0-2 1.2l-2.4-1-2 3.4 2 1.6a7.4 7.4 0 0 0 0 2.4l-2 1.6 2 3.4 2.4-1a7.3 7.3 0 0 0 2 1.2l.4 2.6h4l.4-2.6a7.3 7.3 0 0 0 2-1.2l2.4 1 2-3.4-2-1.6c.1-.4.1-.8.1-1.2z',
  shield: 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10zm-3-10 2 2 4-4',
  scan: 'M3 7V5a2 2 0 0 1 2-2h2m10 0h2a2 2 0 0 1 2 2v2m0 10v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M7 12h10',
  bell: 'M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0',
  file: 'M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9zM14 3v6h6M8 13h8M8 17h6',
  upload: 'M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2M7 9l5-5 5 5M12 4v12',
  list: 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
  history: 'M3 12a9 9 0 1 0 3-6.7L3 8m0-5v5h5m4-1v5l3 3',
  grid: 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z',
  user: 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2m8-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
  logout: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4m7 14 5-5-5-5m5 5H9',
  menu: 'M3 6h18M3 12h18M3 18h18',
  close: 'M18 6 6 18M6 6l12 12',
  chevron: 'm6 9 6 6 6-6',
  chevronRight: 'm9 6 6 6-6 6',
  plus: 'M12 5v14M5 12h14',
  refresh: 'M21 12a9 9 0 0 1-15.5 6.3L3 16m0 5v-5h5M3 12a9 9 0 0 1 15.5-6.3L21 8m0-5v5h-5',
  check: 'M20 6 9 17l-5-5',
  clock: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zm0-14v5l3 3',
  sun: 'M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10zM12 1v2m0 18v2M4.2 4.2l1.4 1.4m12.8 12.8 1.4 1.4M1 12h2m18 0h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4',
  moon: 'M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z',
  satellite: 'M13 7 9 3 5 7l4 4m6 6 4 4 4-4-4-4m-6-2 3 3M16 8l-8 8m12 3a8 8 0 0 0-8-8',
  arrowDown: 'M12 5v14m-6-6 6 6 6-6',
  search: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zm10 2-4.3-4.3',
  eye: 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12zm11 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
}

export default function Icon({ name, size = 20, className = '', title }) {
  const d = P[name] || P.grid
  return (
    <svg className={`icon ${className}`} width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"
      strokeLinecap="round" strokeLinejoin="round" aria-hidden={title ? undefined : 'true'} role={title ? 'img' : undefined} focusable="false">
      {title && <title>{title}</title>}
      <path d={d} />
    </svg>
  )
}
