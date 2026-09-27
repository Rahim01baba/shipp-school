import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api } from '../api/client.js'
import CarteShipp from '../components/CarteShipp.jsx'
import { COULEURS, EVENEMENTS, calquesCircuit, dateFr, duree, heure } from '../lib/gpsAffichage.js'

// Historique GPS d'un trajet : ligne theorique (pointillee) + ligne reellement parcourue,
// evenements du trajet, et lecture pas a pas (« rejouer le trajet »).

export default function HistoriqueGps() {
  const { id } = useParams()
  const [h, setH] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [curseur, setCurseur] = useState(null)
  const [lecture, setLecture] = useState(false)
  const minuterie = useRef(null)

  useEffect(() => {
    api.get(`/flotte-gps.php?trajet_id=${id}`).then((r) => { setH(r); setCurseur(r.trace.length ? r.trace.length - 1 : null) })
      .catch((e) => setErreur(e.status === 503 ? "Le suivi GPS n'est pas activé sur le serveur." : e.message))
  }, [id])

  // Lecture automatique : environ 20 s pour tout le trajet.
  useEffect(() => {
    if (!lecture || !h) return undefined
    const pas = Math.max(1, Math.round(h.trace.length / 80))
    minuterie.current = setInterval(() => {
      setCurseur((c) => {
        const n = (c ?? 0) + pas
        if (n >= h.trace.length - 1) { setLecture(false); return h.trace.length - 1 }
        return n
      })
    }, 250)
    return () => clearInterval(minuterie.current)
  }, [lecture, h])

  const calques = useMemo(() => {
    if (!h) return { lignes: [], marqueurs: [], cadre: [] }
    const c = calquesCircuit(h, { accent: true })
    const L = [...c.lignes]
    const M = [...c.marqueurs]
    const cadre = c.marqueurs.filter((m) => m.lat != null).map((m) => [m.lat, m.lng])
    const jusque = curseur ?? h.trace.length - 1
    if (h.trace.length > 1) {
      L.push({ id: 'reel', points: h.trace.slice(0, jusque + 1).map((p) => [p.lat, p.lng]), couleur: COULEURS.reel, epaisseur: 5, opacite: 0.9, titre: 'Parcours réel' })
    }
    h.trace.forEach((p) => cadre.push([p.lat, p.lng]))
    h.stats.ecarts.slice(0, 50).forEach((e, i) => M.push({ id: `ecart-${i}`, lat: e.lat, lng: e.lng, type: 'point', couleur: COULEURS.perdu, titre: `Écart de ${e.distance_m} m à ${heure(e.recorded_at)}` }))
    h.stats.arrets_detectes.forEach((a, i) => M.push({ id: `stop-${i}`, lat: a.lat, lng: a.lng, type: 'point', couleur: COULEURS.ancienne, titre: `Immobilisation ${duree(a.duree_secondes)} à ${heure(a.debut, false)}${a.arret_prevu_proche ? ` (près de ${a.arret_prevu_proche})` : ' (hors arrêt prévu)'}` }))
    const p = curseur != null ? h.trace[curseur] : null
    if (p) M.push({ id: 'lecture', lat: p.lat, lng: p.lng, type: 'vehicule', texte: '🚐', rotation: p.cap, couleur: COULEURS.actif, libelle: heure(p.recorded_at), selectionne: true })
    return { lignes: L, marqueurs: M, cadre }
  }, [h, curseur])

  if (erreur) return <div className="g-page"><Link className="g-retour" to="/suivi-flotte">← Suivi flottes</Link><p className="error-banner">{erreur}</p></div>
  if (!h) return <div className="g-page"><p className="g-vide">Chargement...</p></div>
  const s = h.stats
  const p = curseur != null ? h.trace[curseur] : null
  const evenements = h.evenements.filter((e) => e.type !== 'GPS_PREMIER_PLAN')

  return (
    <div className="g-page">
      <Link className="g-retour" to="/suivi-flotte">← Suivi flottes</Link>
      <header className="g-entete">
        <div>
          <h1>Trajet du {dateFr(h.date_trajet)}</h1>
          <p className="g-sous-titre">{h.moment ? `Trajet du ${h.moment === 'SOIR' ? 'soir' : 'matin'} · ` : ''}
            <span className={`g-badge etat-${h.statut === 'en_cours' ? 'en_course' : 'termine'}`}>{h.statut === 'en_cours' ? 'EN COURS' : 'COURSE TERMINÉE'}</span>
          </p>
        </div>
      </header>

      <dl className="g-card g-champs hi-infos">
        <div><dt>Circuit</dt><dd>{h.circuit}</dd></div>
        <div><dt>Chauffeur</dt><dd>{h.chauffeur || '—'}</dd></div>
        <div><dt>Véhicule</dt><dd>{h.vehicule || '—'}</dd></div>
        <div><dt>Départ</dt><dd>{heure(h.depart, false)}</dd></div>
        <div><dt>Arrivée</dt><dd>{heure(h.fin, false)}</dd></div>
        <div><dt>Durée</dt><dd>{duree(s.duree_secondes)}</dd></div>
        <div><dt>Distance</dt><dd>{s.distance_km} km</dd></div>
        <div><dt>Positions reçues</dt><dd>{s.points}</dd></div>
        <div><dt>Vitesse max</dt><dd>{s.vitesse_max_kmh != null ? `${Math.round(s.vitesse_max_kmh)} km/h` : '—'}</dd></div>
        <div><dt>Écart max au circuit</dt><dd>{s.ecart_max_m ? `${s.ecart_max_m} m` : 'aucun'}</dd></div>
      </dl>

      <div className="hi-grille">
        <div>
          {h.trace.length === 0 && <p className="g-alerte">Aucune position GPS reçue pour ce trajet.</p>}
          <CarteShipp className="hi-carte" lignes={calques.lignes} marqueurs={calques.marqueurs} cadrer={calques.cadre} cleCadrage={id}
            legende={<>
              <span className="leg"><span className="leg-trait leg-pointille" />Ligne théorique</span>
              <span className="leg"><span className="leg-trait leg-reel" />Ligne réellement parcourue</span>
            </>} />
          {h.trace.length > 1 && (
            <div className="hi-lecture">
              <button type="button" className="g-btn g-btn-primaire" onClick={() => {
                if (!lecture && curseur >= h.trace.length - 1) setCurseur(0)
                setLecture(!lecture)
              }}>{lecture ? '⏸ Pause' : '▶ Rejouer le trajet'}</button>
              <input type="range" aria-label="Moment du trajet" min="0" max={h.trace.length - 1} value={curseur ?? 0} onChange={(e) => { setLecture(false); setCurseur(Number(e.target.value)) }} />
              <span className="g-num">{p ? `${heure(p.recorded_at)}${p.vitesse_kmh != null ? ` · ${Math.round(p.vitesse_kmh)} km/h` : ''}` : ''}</span>
            </div>
          )}
        </div>
        <section className="g-card" aria-label="Événements">
          <h2 className="g-card-titre">Événements</h2>
          <ol className="hi-evts">
            {evenements.map((e) => (
              <li key={e.id} className={e.type.startsWith('GPS_') ? 'hi-gps' : ''}>
                <time>{heure(e.survenu_at, false)}</time>
                <span>{e.type === 'ARRIVED_AT_STOP' && e.arret ? e.arret : (EVENEMENTS[e.type] || e.type)}{e.eleve ? ` — ${e.eleve}` : ''}{e.type === 'DELAY_DETECTED' && e.details ? ` — ${e.details}` : ''}</span>
              </li>
            ))}
            {evenements.length === 0 && <li className="g-vide">Aucun événement.</li>}
          </ol>
          {s.arrets_detectes.length > 0 && (
            <>
              <h2 className="g-card-titre g-mt">Immobilisations</h2>
              <ol className="hi-evts">
                {s.arrets_detectes.map((a, i) => <li key={i}><time>{heure(a.debut, false)}</time><span>{duree(a.duree_secondes)} · {a.arret_prevu_proche ? `près de ${a.arret_prevu_proche}` : 'hors arrêt prévu'}</span></li>)}
              </ol>
            </>
          )}
        </section>
      </div>
    </div>
  )
}
