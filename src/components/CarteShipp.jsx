import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'

// Carte legere sans dependance (tuiles OpenStreetMap + calques SVG/HTML).
// - glisser pour deplacer, molette / double-clic / pincement / boutons pour zoomer ;
// - calques : lignes (circuit prevu, trace reelle) et marqueurs (arrets, ecole, vehicules) ;
// - les marqueurs « animes » glissent vers leur nouvelle position au lieu de sauter.
// Les tuiles peuvent etre remplacees par VITE_TILE_URL (ex. fournisseur sous contrat).

const TUILE = 256
const URL_TUILES = import.meta.env.VITE_TILE_URL || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'
const ZMIN = 3
const ZMAX = 19

export function versPixel(lat, lng, z) {
  const s = TUILE * 2 ** z
  const l = Math.max(-85.05112878, Math.min(85.05112878, lat))
  const sin = Math.sin((l * Math.PI) / 180)
  return { x: ((lng + 180) / 360) * s, y: (0.5 - Math.log((1 + sin) / (1 - sin)) / (4 * Math.PI)) * s }
}

export function versLatLng(x, y, z) {
  const s = TUILE * 2 ** z
  const lng = (x / s) * 360 - 180
  const n = Math.PI - (2 * Math.PI * y) / s
  return { lat: (180 / Math.PI) * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n))), lng }
}

function zoomPourCadrer(points, largeur, hauteur, marge = 48) {
  if (!points.length) return null
  const lats = points.map((p) => p[0])
  const lngs = points.map((p) => p[1])
  const centre = { lat: (Math.min(...lats) + Math.max(...lats)) / 2, lng: (Math.min(...lngs) + Math.max(...lngs)) / 2 }
  let z = ZMAX - 2
  for (; z > ZMIN; z--) {
    const a = versPixel(Math.max(...lats), Math.min(...lngs), z)
    const b = versPixel(Math.min(...lats), Math.max(...lngs), z)
    if (b.x - a.x <= largeur - 2 * marge && b.y - a.y <= hauteur - 2 * marge) break
  }
  if (points.length === 1) z = Math.min(z, 16)
  return { centre, zoom: z }
}

/**
 * props :
 *  centre [lat, lng], zoom, hauteur (CSS)
 *  lignes   [{id, points: [[lat, lng], ...], couleur, epaisseur, pointille, opacite, titre}]
 *  marqueurs [{id, lat, lng, type: 'arret'|'ecole'|'vehicule'|'point', texte, libelle, couleur,
 *              rotation, selectionne, anime, deplacable, titre, onClick, onDeplace(lat, lng)}]
 *  cadrer   : liste de points [[lat, lng]] a afficher en entier ; cleCadrage : recadre quand elle change
 *  onClickCarte(lat, lng)
 */
export default function CarteShipp({ centre = [5.345, -4.02], zoom: zoomInitial = 12, hauteur, lignes = [], marqueurs = [], cadrer, cleCadrage, focus, onClickCarte, legende, className = '', children }) {
  const boite = useRef(null)
  const [taille, setTaille] = useState({ l: 0, h: 0 })
  const [vue, setVue] = useState({ lat: centre[0], lng: centre[1], z: zoomInitial })
  const geste = useRef({ pointeurs: new Map(), depart: null, bouge: false, pinch: null })
  const [glisse, setGlisse] = useState(null) // marqueur en cours de deplacement {id, lat, lng}

  useLayoutEffect(() => {
    const el = boite.current
    if (!el) return undefined
    const maj = () => setTaille({ l: el.clientWidth, h: el.clientHeight })
    maj()
    const ro = new ResizeObserver(maj)
    ro.observe(el)
    return () => ro.disconnect()
  }, [])

  // Cadrage automatique (au chargement et quand cleCadrage change).
  const dejaCadre = useRef(null)
  useEffect(() => {
    if (!taille.l || !cadrer || !cadrer.length) return
    const cle = cleCadrage ?? 'init'
    if (dejaCadre.current === cle) return
    dejaCadre.current = cle
    const r = zoomPourCadrer(cadrer, taille.l, taille.h)
    if (r) setVue({ lat: r.centre.lat, lng: r.centre.lng, z: r.zoom })
  }, [taille.l, taille.h, cadrer, cleCadrage])

  // Centrage demande (clic sur un vehicule de la liste) : une fois par cle.
  const dejaFocus = useRef(null)
  useEffect(() => {
    if (!focus || focus.lat == null || dejaFocus.current === focus.cle) return
    dejaFocus.current = focus.cle
    setVue((v) => ({ lat: focus.lat, lng: focus.lng, z: Math.max(v.z, focus.zoom || 15) }))
  }, [focus])

  const c = versPixel(vue.lat, vue.lng, vue.z)
  // Ancre fixe par niveau de zoom : les deplacements de carte ne declenchent pas l'animation des marqueurs.
  const ancre = useRef({ z: null, x: 0, y: 0 })
  if (ancre.current.z !== vue.z) ancre.current = { z: vue.z, x: Math.round(c.x), y: Math.round(c.y) }
  const A = ancre.current
  const gauche = c.x - taille.l / 2
  const haut = c.y - taille.h / 2

  const ecranVersLatLng = useCallback((ex, ey) => versLatLng(gauche + ex, haut + ey, vue.z), [gauche, haut, vue.z])

  const zoomer = useCallback((delta, ex, ey) => {
    setVue((v) => {
      const z = Math.max(ZMIN, Math.min(ZMAX, v.z + delta))
      if (z === v.z) return v
      if (ex === undefined) return { ...v, z }
      // Zoom centre sur le point vise.
      const cc = versPixel(v.lat, v.lng, v.z)
      const gx = cc.x - taille.l / 2 + ex
      const gy = cc.y - taille.h / 2 + ey
      const vise = versLatLng(gx, gy, v.z)
      const p = versPixel(vise.lat, vise.lng, z)
      const nc = versLatLng(p.x - ex + taille.l / 2, p.y - ey + taille.h / 2, z)
      return { lat: nc.lat, lng: nc.lng, z }
    })
  }, [taille.l, taille.h])

  useEffect(() => {
    const el = boite.current
    if (!el) return undefined
    const roue = (e) => {
      e.preventDefault()
      const r = el.getBoundingClientRect()
      zoomer(e.deltaY < 0 ? 1 : -1, e.clientX - r.left, e.clientY - r.top)
    }
    el.addEventListener('wheel', roue, { passive: false })
    return () => el.removeEventListener('wheel', roue)
  }, [zoomer])

  function pos(e) {
    const r = boite.current.getBoundingClientRect()
    return { x: e.clientX - r.left, y: e.clientY - r.top }
  }

  function onPointerDown(e) {
    if (e.button !== undefined && e.button !== 0) return
    const g = geste.current
    boite.current.setPointerCapture?.(e.pointerId)
    g.pointeurs.set(e.pointerId, pos(e))
    if (g.pointeurs.size === 1) {
      g.depart = { ...pos(e), vue: { ...vue }, cx: c.x, cy: c.y }
      g.bouge = false
    } else if (g.pointeurs.size === 2) {
      const [a, b] = [...g.pointeurs.values()]
      g.pinch = { d: Math.hypot(a.x - b.x, a.y - b.y) }
    }
  }

  function onPointerMove(e) {
    const g = geste.current
    if (!g.pointeurs.has(e.pointerId)) return
    const p = pos(e)
    g.pointeurs.set(e.pointerId, p)
    if (glisse) {
      const ll = ecranVersLatLng(p.x, p.y)
      setGlisse({ ...glisse, lat: ll.lat, lng: ll.lng })
      return
    }
    if (g.pointeurs.size === 2 && g.pinch) {
      const [a, b] = [...g.pointeurs.values()]
      const d = Math.hypot(a.x - b.x, a.y - b.y)
      if (d / g.pinch.d > 1.35 || d / g.pinch.d < 0.74) {
        zoomer(d > g.pinch.d ? 1 : -1, (a.x + b.x) / 2, (a.y + b.y) / 2)
        g.pinch.d = d
      }
      g.bouge = true
      return
    }
    if (!g.depart) return
    const dx = p.x - g.depart.x
    const dy = p.y - g.depart.y
    if (Math.abs(dx) + Math.abs(dy) > 4) g.bouge = true
    if (g.bouge) {
      const ll = versLatLng(g.depart.cx - dx, g.depart.cy - dy, g.depart.vue.z)
      setVue({ lat: ll.lat, lng: ll.lng, z: g.depart.vue.z })
    }
  }

  function onPointerUp(e) {
    const g = geste.current
    const p = pos(e)
    g.pointeurs.delete(e.pointerId)
    if (g.pointeurs.size < 2) g.pinch = null
    if (glisse) {
      const m = marqueurs.find((x) => x.id === glisse.id)
      const bouge = Math.abs(p.x - glisse.x0) + Math.abs(p.y - glisse.y0) > 4
      if (bouge && m?.onDeplace) m.onDeplace(glisse.lat, glisse.lng)
      else if (!bouge) m?.onClick?.()
      setGlisse(null)
      g.depart = null
      return
    }
    if (g.pointeurs.size === 0) {
      if (!g.bouge && g.depart && onClickCarte) {
        const ll = ecranVersLatLng(p.x, p.y)
        onClickCarte(ll.lat, ll.lng)
      }
      g.depart = null
    }
  }

  // Tuiles visibles
  const tuiles = []
  if (taille.l) {
    const n = 2 ** vue.z
    const x0 = Math.floor(gauche / TUILE)
    const x1 = Math.floor((gauche + taille.l) / TUILE)
    const y0 = Math.max(0, Math.floor(haut / TUILE))
    const y1 = Math.min(n - 1, Math.floor((haut + taille.h) / TUILE))
    for (let x = x0; x <= x1; x++) {
      for (let y = y0; y <= y1; y++) {
        const xx = ((x % n) + n) % n
        tuiles.push({ cle: `${vue.z}/${x}/${y}`, src: URL_TUILES.replace('{z}', vue.z).replace('{x}', xx).replace('{y}', y), left: x * TUILE - A.x, top: y * TUILE - A.y })
      }
    }
  }

  const monde = { transform: `translate(${Math.round(A.x - gauche)}px, ${Math.round(A.y - haut)}px)` }
  const lignesPx = useMemo(() => lignes.filter((l) => l.points && l.points.length > 1).map((l) => ({
    ...l, px: l.points.map(([la, lo]) => { const p = versPixel(la, lo, vue.z); return [p.x - A.x, p.y - A.y] }),
  })), [lignes, vue.z, A.x, A.y])

  return (
    <div className={`carte ${className}`} style={hauteur ? { height: hauteur } : undefined} ref={boite}
      onPointerDown={onPointerDown} onPointerMove={onPointerMove} onPointerUp={onPointerUp} onPointerCancel={onPointerUp}
      onDoubleClick={(e) => { const p = pos(e); zoomer(1, p.x, p.y) }}
      role="application" aria-label="Carte">
      <div className="carte-monde" style={monde}>
        {tuiles.map((t) => <img key={t.cle} className="carte-tuile" src={t.src} alt="" draggable="false" style={{ left: t.left, top: t.top }} onError={(e) => { e.currentTarget.style.visibility = 'hidden' }} />)}
        <svg className="carte-svg" width="1" height="1" aria-hidden="true">
          <defs>
            <marker id="carte-fleche" viewBox="0 0 10 10" refX="5" refY="5" markerWidth="4" markerHeight="4" orient="auto-start-reverse">
              <path d="M0,0 L10,5 L0,10 z" fill="context-stroke" />
            </marker>
          </defs>
          {lignesPx.map((l) => (
            <polyline key={l.id} points={l.px.map((p) => p.join(',')).join(' ')} fill="none" stroke={l.couleur || '#1d4ed8'}
              strokeWidth={l.epaisseur || 4} strokeOpacity={l.opacite ?? 0.9} strokeLinejoin="round" strokeLinecap="round"
              strokeDasharray={l.pointille ? '8 7' : undefined} markerMid={l.fleches ? 'url(#carte-fleche)' : undefined}>
              {l.titre && <title>{l.titre}</title>}
            </polyline>
          ))}
        </svg>
        <div className="carte-marqueurs" key={vue.z}>
          {marqueurs.filter((m) => m.lat != null && m.lng != null).map((m) => {
            const g = glisse && glisse.id === m.id ? glisse : m
            const p = versPixel(g.lat, g.lng, vue.z)
            return (
              <button key={m.id} type="button" title={m.titre || m.libelle || ''} aria-label={m.titre || m.libelle || m.texte || 'marqueur'}
                className={`carte-marqueur carte-m-${m.type || 'point'}${m.selectionne ? ' carte-m-sel' : ''}${m.anime && !glisse ? ' carte-m-anime' : ''}${m.deplacable ? ' carte-m-deplacable' : ''}`}
                style={{ transform: `translate(${p.x - A.x}px, ${p.y - A.y}px)`, '--c': m.couleur || undefined, zIndex: m.selectionne ? 30 : m.type === 'vehicule' ? 20 : 10 }}
                onPointerDown={(e) => {
                  e.stopPropagation()
                  if (m.deplacable) {
                    boite.current.setPointerCapture?.(e.pointerId)
                    geste.current.pointeurs.set(e.pointerId, pos(e))
                    const q = pos(e)
                    setGlisse({ id: m.id, lat: m.lat, lng: m.lng, x0: q.x, y0: q.y })
                  }
                }}
                onPointerUp={(e) => { if (!m.deplacable) { e.stopPropagation(); m.onClick?.() } else onPointerUp(e) }}
                onClick={(e) => { e.stopPropagation(); if (e.detail === 0) m.onClick?.() }}
                onDoubleClick={(e) => e.stopPropagation()}>
                <span className="carte-m-corps">
                  {m.type === 'vehicule' && m.rotation != null && <span className="carte-m-cap" style={{ transform: `rotate(${m.rotation}deg)` }} />}
                  <span className="carte-m-texte">{m.texte}</span>
                </span>
                {m.libelle && <span className="carte-m-libelle">{m.libelle}</span>}
              </button>
            )
          })}
        </div>
      </div>
      <div className="carte-ctrl" onPointerDown={(e) => e.stopPropagation()} onDoubleClick={(e) => e.stopPropagation()}>
        <button type="button" aria-label="Zoom avant" onClick={() => zoomer(1)}>+</button>
        <button type="button" aria-label="Zoom arriere" onClick={() => zoomer(-1)}>−</button>
        {cadrer && cadrer.length > 0 && (
          <button type="button" aria-label="Tout afficher" title="Tout afficher" onClick={() => {
            const r = zoomPourCadrer(cadrer, taille.l, taille.h)
            if (r) setVue({ lat: r.centre.lat, lng: r.centre.lng, z: r.zoom })
          }}>⤢</button>
        )}
      </div>
      {legende && <div className="carte-legende" onPointerDown={(e) => e.stopPropagation()}>{legende}</div>}
      <div className="carte-attribution">© OpenStreetMap</div>
      {children}
    </div>
  )
}
