import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client.js'
import { useAuth } from '../context/AuthContext.jsx'
import CarteShipp from '../components/CarteShipp.jsx'
import { COULEURS, ETATS_GPS, SIGNAUX, SIGNAUX_PROBLEME, STATUTS_VEHICULE, calquesCircuit, dateFr, etatSelonAge, heure, texteAge } from '../lib/gpsAffichage.js'

// SUIVI FLOTTES : tous les vehicules en circulation sur une seule carte.
// Interrogation de l'API toutes les 5 s (sans recharger la page) ; l'anciennete des
// positions est recalculee chaque seconde, un vehicule ne reste jamais « actif » a tort.

const RAFRAICHISSEMENT = 5000
const ORDRE = { perdu: 0, ancienne: 1, attente: 2, actif: 3, termine: 4 }
const FILTRES_VIDES = { ecole: '', circuit: '', statut: '', moment: '', texte: '' }

export default function SuiviFlotte() {
  const { can, accessLoading } = useAuth()
  const [onglet, setOnglet] = useState('direct')
  const [data, setData] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [recuA, setRecuA] = useState(0)
  const [maintenant, setMaintenant] = useState(Date.now())
  const [sel, setSel] = useState(null)
  const [focus, setFocus] = useState(null)
  const [filtres, setFiltres] = useState(FILTRES_VIDES)
  const [tousCircuits, setTousCircuits] = useState(false)
  const [refs, setRefs] = useState({ ecoles: [], circuits: [] })
  const [charge, setCharge] = useState(false)
  const minuterie = useRef(null)

  const charger = useCallback(async () => {
    setCharge(true)
    try {
      const r = await api.get('/flotte-gps.php')
      setData(r)
      setRecuA(Date.now())
      setErreur(null)
    } catch (e) {
      setErreur(e.status === 503 ? "Le suivi GPS n'est pas activé sur le serveur." : e.message)
    } finally {
      setCharge(false)
    }
  }, [])

  useEffect(() => {
    if (accessLoading || onglet !== 'direct' || !can('suivi_gps', 'can_read')) return undefined
    let arret = false
    const boucle = async () => {
      if (document.visibilityState === 'visible') await charger()
      if (!arret) minuterie.current = setTimeout(boucle, RAFRAICHISSEMENT)
    }
    boucle()
    const vis = () => { if (document.visibilityState === 'visible') charger() }
    document.addEventListener('visibilitychange', vis)
    return () => { arret = true; clearTimeout(minuterie.current); document.removeEventListener('visibilitychange', vis) }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [accessLoading, onglet])

  // Ecoles et circuits : uniquement pour les filtres (lecture seule, droits existants).
  useEffect(() => {
    if (accessLoading) return
    Promise.all([
      can('ecoles', 'can_read') ? api.get('/crud.php?module=ecoles').catch(() => ({ data: [] })) : Promise.resolve({ data: [] }),
      can('circuits', 'can_read') ? api.get('/crud.php?module=circuits').catch(() => ({ data: [] })) : Promise.resolve({ data: [] }),
    ]).then(([e, c]) => setRefs({ ecoles: e.data || [], circuits: c.data || [] }))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [accessLoading])

  useEffect(() => {
    const t = setInterval(() => setMaintenant(Date.now()), 1000)
    return () => clearInterval(t)
  }, [])

  const ecoleDuCircuit = useMemo(() => Object.fromEntries(refs.circuits.map((c) => [Number(c.id), c.ecole_id ? Number(c.ecole_id) : null])), [refs.circuits])

  const tous = useMemo(() => {
    if (!data) return []
    const ecoule = Math.max(0, Math.floor((maintenant - recuA) / 1000))
    return data.vehicules.map((v) => {
      const age = v.age_secondes == null ? null : v.age_secondes + ecoule
      const etat = etatSelonAge(v.etat_gps, age, data.parametres)
      return { ...v, age, etat, ecole_id: ecoleDuCircuit[v.circuit_id] ?? null, statut_vehicule: ['perdu', 'attente'].includes(etat) ? 'inconnu' : v.statut_vehicule }
    }).sort((a, b) => ORDRE[a.etat] - ORDRE[b.etat] || String(a.vehicule).localeCompare(String(b.vehicule)))
  }, [data, maintenant, recuA, ecoleDuCircuit])

  const vehicules = useMemo(() => {
    const t = filtres.texte.trim().toLowerCase()
    return tous.filter((v) => (!filtres.ecole || String(v.ecole_id) === filtres.ecole)
      && (!filtres.circuit || String(v.circuit_id) === filtres.circuit)
      && (!filtres.moment || v.moment === filtres.moment)
      && (!filtres.statut || (['en_course', 'arrete'].includes(filtres.statut) ? v.statut_vehicule === filtres.statut : v.etat === filtres.statut))
      && (!t || `${v.vehicule || ''} ${v.chauffeur || ''} ${v.circuit || ''}`.toLowerCase().includes(t)))
  }, [tous, filtres])

  const kpi = useMemo(() => {
    const k = { en_course: 0, arrete: 0, actif: 0, ancienne: 0, perdu: 0 }
    vehicules.forEach((v) => {
      if (v.etat in k) k[v.etat]++
      if (v.statut_vehicule === 'en_course') k.en_course++
      if (v.statut_vehicule === 'arrete') k.arrete++
    })
    return k
  }, [vehicules])

  const choisi = vehicules.find((v) => v.trajet_id === sel) || null
  const circuitsFiltre = useMemo(() => {
    const m = new Map()
    tous.forEach((v) => m.set(v.circuit_id, v.circuit))
    refs.circuits.forEach((c) => { if (!filtres.ecole || String(c.ecole_id) === filtres.ecole) m.set(Number(c.id), c.nom) })
    return [...m.entries()].sort((a, b) => String(a[1]).localeCompare(String(b[1])))
  }, [tous, refs.circuits, filtres.ecole])

  const { lignes, marqueurs, cadre } = useMemo(() => {
    const L = []
    const M = []
    const cad = []
    vehicules.forEach((v) => {
      const estSel = v.trajet_id === sel
      if (estSel || tousCircuits) {
        const c = calquesCircuit(v, { accent: estSel })
        L.push(...c.lignes)
        M.push(...c.marqueurs)
      }
      if (estSel && v.trace_recente?.length > 1) {
        L.push({ id: `reel-${v.trajet_id}`, points: v.trace_recente.map((p) => [p.lat, p.lng]), couleur: COULEURS.reel, epaisseur: 5, opacite: 0.85, titre: `Parcours réel : ${v.vehicule}` })
      }
      if (v.position) {
        cad.push([v.position.lat, v.position.lng])
        M.push({
          id: `veh-${v.trajet_id}`, lat: v.position.lat, lng: v.position.lng, type: 'vehicule', texte: '🚐', libelle: v.vehicule || `Trajet ${v.trajet_id}`,
          rotation: v.position.cap, couleur: ETATS_GPS[v.etat].couleur, anime: true, selectionne: estSel,
          titre: `${v.vehicule} — ${ETATS_GPS[v.etat].libelle} — ${texteAge(v.age)}`, onClick: () => choisir(v, false),
        })
      }
    })
    return { lignes: L, marqueurs: M, cadre: cad }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [vehicules, sel, tousCircuits])

  function choisir(v, centrer = true) {
    setSel(v.trajet_id)
    if (centrer && v.position) setFocus({ cle: `${v.trajet_id}-${Date.now()}`, lat: v.position.lat, lng: v.position.lng, zoom: 15 })
  }
  const setF = (k) => (e) => setFiltres({ ...filtres, [k]: e.target.value })

  if (!accessLoading && !can('suivi_gps', 'can_read')) {
    return (
      <div className="g-page">
        <Link className="g-retour" to="/">← Tableau de bord</Link>
        <p className="error-banner">Accès réservé au suivi de flotte.</p>
      </div>
    )
  }

  return (
    <div className="g-page">
      <Link className="g-retour" to="/">← Tableau de bord</Link>
      <header className="g-entete">
        <div>
          <h1>Suivi flottes</h1>
          <p className="g-sous-titre">Suivi des véhicules actuellement en circulation</p>
        </div>
        {onglet === 'direct' && (
          <div className="g-entete-actions">
            <span className="g-sync" aria-live="polite">Dernière synchronisation : {recuA ? heure(recuA) : '—'}</span>
            <button type="button" className="g-btn" onClick={charger} disabled={charge}>🔄 Actualiser</button>
          </div>
        )}
      </header>
      <div className="g-onglets" role="tablist">
        <button type="button" role="tab" aria-selected={onglet === 'direct'} onClick={() => setOnglet('direct')}>En direct</button>
        <button type="button" role="tab" aria-selected={onglet === 'historique'} onClick={() => setOnglet('historique')}>Historique des trajets</button>
      </div>
      {erreur && <p className="error-banner">{erreur}</p>}

      {onglet === 'historique' && <ListeHistorique />}

      {onglet === 'direct' && (
        <>
          <div className="g-kpis">
            <Kpi valeur={kpi.en_course} libelle="En course" etat="en_course" />
            <Kpi valeur={kpi.arrete} libelle="À l'arrêt" etat="arrete" />
            <Kpi valeur={kpi.actif} libelle="GPS actifs" etat="actif" />
            <Kpi valeur={kpi.ancienne} libelle="GPS anciens" etat="ancienne" />
            <Kpi valeur={kpi.perdu} libelle="GPS perdus" etat="perdu" />
          </div>

          <div className="g-filtres" role="search">
            {refs.ecoles.length > 1 && (
              <select aria-label="École" value={filtres.ecole} onChange={setF('ecole')}>
                <option value="">Toutes les écoles</option>
                {refs.ecoles.map((e) => <option key={e.id} value={e.id}>{e.nom}</option>)}
              </select>
            )}
            <select aria-label="Circuit" value={filtres.circuit} onChange={setF('circuit')}>
              <option value="">Tous les circuits</option>
              {circuitsFiltre.map(([id, nom]) => <option key={id} value={id}>{nom}</option>)}
            </select>
            <select aria-label="Statut" value={filtres.statut} onChange={setF('statut')}>
              <option value="">Tous les statuts</option>
              <option value="en_course">En course</option>
              <option value="arrete">À l'arrêt</option>
              <option value="actif">GPS actif</option>
              <option value="ancienne">Position ancienne</option>
              <option value="perdu">GPS perdu</option>
            </select>
            <select aria-label="Trajet" value={filtres.moment} onChange={setF('moment')}>
              <option value="">Matin et soir</option>
              <option value="MATIN">Matin</option>
              <option value="SOIR">Soir</option>
            </select>
            <input type="search" aria-label="Rechercher" placeholder="Rechercher véhicule ou chauffeur..." value={filtres.texte} onChange={setF('texte')} />
            <label className="g-case"><input type="checkbox" checked={tousCircuits} onChange={(e) => setTousCircuits(e.target.checked)} /> Tous les circuits sur la carte</label>
            {Object.values(filtres).some(Boolean) && <button type="button" className="g-btn g-btn-lien" onClick={() => setFiltres(FILTRES_VIDES)}>Effacer</button>}
          </div>

          <div className="fl-grille">
            <div className="fl-carte-zone">
              <CarteShipp className="fl-carte" lignes={lignes} marqueurs={marqueurs} cadrer={cadre} cleCadrage={`${vehicules.length}`} focus={focus}
                onClickCarte={() => setSel(null)}
                legende={<>
                  <span className="leg"><span className="leg-trait leg-pointille" />Parcours théorique</span>
                  <span className="leg"><span className="leg-trait leg-reel" />Parcours réel</span>
                  <span className="leg">🚐 flèche = direction</span>
                </>} />
              {data && vehicules.length === 0 && <div className="fl-popup"><p className="g-vide">Aucun véhicule en circulation{Object.values(filtres).some(Boolean) ? ' pour ces filtres' : ''}.</p></div>}
              {choisi && <FicheVehicule v={choisi} onFermer={() => setSel(null)} />}
            </div>
            <aside className="fl-panneau" aria-label="Véhicules actifs">
              <div className="fl-panneau-titre">
                <h2>VÉHICULES ACTIFS</h2>
                <span className="g-sync">{vehicules.length}{data?.kpi ? ` · ${data.kpi.trajets_termines} terminé(s) aujourd'hui` : ''}</span>
              </div>
              <ul className="fl-liste">
                {vehicules.map((v) => (
                  <li key={v.trajet_id}>
                    <button type="button" className={`fl-item etat-${v.etat}`} aria-pressed={v.trajet_id === sel} onClick={() => choisir(v)}>
                      <span className="fl-point" aria-hidden="true" />
                      <span className="fl-item-plaque">{v.vehicule || 'Véhicule ?'}</span>
                      <span className="fl-item-age">{texteAge(v.age)}</span>
                      <span className="fl-item-ligne2">{v.circuit}{v.moment ? ` · ${v.moment === 'SOIR' ? 'Soir' : 'Matin'}` : ''} · {v.chauffeur || 'Chauffeur ?'}</span>
                      {(v.hors_circuit || (v.signal && SIGNAUX_PROBLEME.includes(v.signal.type))) && (
                        <span className="fl-item-alerte">⚠ {v.hors_circuit ? `Hors circuit (${v.ecart_circuit_m} m)` : SIGNAUX[v.signal.type]}</span>
                      )}
                    </button>
                  </li>
                ))}
                {data && vehicules.length === 0 && <li className="g-vide">Aucun véhicule.</li>}
                {!data && !erreur && <li className="g-vide">Chargement...</li>}
              </ul>
            </aside>
          </div>
        </>
      )}
    </div>
  )
}

function Kpi({ valeur, libelle, etat }) {
  return (
    <div className={`g-kpi etat-${etat}`}>
      <span className="g-kpi-val">{valeur}</span>
      <span className="g-kpi-lib">{libelle}</span>
    </div>
  )
}

function FicheVehicule({ v, onFermer }) {
  const s = STATUTS_VEHICULE[v.statut_vehicule] || STATUTS_VEHICULE.inconnu
  return (
    <div className="fl-popup" role="dialog" aria-label={`Véhicule ${v.vehicule || ''}`}>
      <div className="fl-popup-tete">
        <div>
          <h3>🚐 {v.vehicule || '—'}</h3>
          <span className={`g-badge ${s.classe}`}>{s.libelle}</span>
        </div>
        <button type="button" className="fl-fermer" aria-label="Fermer" onClick={onFermer}>×</button>
      </div>
      <dl className="g-champs">
        <div><dt>Chauffeur</dt><dd>{v.chauffeur || '—'}</dd></div>
        <div><dt>Circuit</dt><dd>{v.circuit}</dd></div>
        <div><dt>Trajet</dt><dd>{v.moment || '—'}{v.oublie ? ` (${dateFr(v.date_trajet)})` : ''}</dd></div>
        <div><dt>Vitesse</dt><dd>{v.position?.vitesse_kmh != null ? `${Math.round(v.position.vitesse_kmh)} km/h` : '—'}</dd></div>
        <div><dt>GPS</dt><dd><span className={`g-badge etat-${v.etat}`}>{ETATS_GPS[v.etat].court}</span></dd></div>
        <div><dt>Dernière position</dt><dd>{texteAge(v.age, true)}</dd></div>
        <div><dt>Prochain arrêt</dt><dd>{v.prochain_arret ? v.prochain_arret.nom : 'Dernier arrêt'}</dd></div>
        <div><dt>Départ</dt><dd>{heure(v.depart, false)}</dd></div>
        {v.chauffeur_telephone && <div><dt>Téléphone</dt><dd><a href={`tel:${v.chauffeur_telephone}`}>{v.chauffeur_telephone}</a></dd></div>}
        {v.position?.precision_m != null && <div><dt>Précision</dt><dd>{Math.round(v.position.precision_m)} m{v.precision_faible ? ' (faible)' : ''}</dd></div>}
      </dl>
      {v.hors_circuit && <p className="g-alerte">Hors du circuit prévu : {v.ecart_circuit_m} m.</p>}
      {v.signal && SIGNAUX_PROBLEME.includes(v.signal.type) && <p className="g-alerte">{SIGNAUX[v.signal.type]} ({heure(v.signal.at, false)})</p>}
      <div className="fl-popup-actions">
        <Link className="g-btn g-btn-primaire" to={`/suivi-flotte/trajet/${v.trajet_id}`}>Voir le trajet</Link>
      </div>
    </div>
  )
}

function ListeHistorique() {
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10))
  const [rows, setRows] = useState(null)
  const [erreur, setErreur] = useState(null)
  useEffect(() => {
    setErreur(null)
    api.get(`/flotte-gps.php?liste=1&date=${date}`).then((r) => setRows(r.data)).catch((e) => setErreur(e.message))
  }, [date])
  return (
    <section>
      <div className="g-filtres"><input type="date" aria-label="Date" value={date} onChange={(e) => setDate(e.target.value)} /></div>
      {erreur && <p className="error-banner">{erreur}</p>}
      <div className="g-table-wrap">
        <table className="g-table">
          <thead><tr><th>Circuit</th><th>Trajet</th><th>Véhicule</th><th>Chauffeur</th><th>Statut</th><th>Départ</th><th>Fin</th><th>Positions</th><th /></tr></thead>
          <tbody>
            {(rows || []).map((t) => (
              <tr key={t.trajet_id}>
                <td>{t.circuit}</td><td>{t.sens === 'retour' ? 'Soir' : t.sens === 'aller' ? 'Matin' : '—'}</td><td>{t.vehicule || '—'}</td><td>{t.chauffeur || '—'}</td>
                <td>{t.statut === 'termine' ? <span className="g-badge etat-termine">Terminé</span> : t.statut === 'en_cours' ? <span className="g-badge etat-en_course">En cours</span> : t.statut}</td>
                <td className="g-num">{heure(t.depart, false)}</td><td className="g-num">{heure(t.fin, false)}</td><td className="g-num">{t.positions}</td>
                <td>{t.statut !== 'planifie' && <Link to={`/suivi-flotte/trajet/${t.trajet_id}`}>Voir</Link>}</td>
              </tr>
            ))}
            {rows && rows.length === 0 && <tr><td colSpan={9} className="g-vide">Aucun trajet ce jour.</td></tr>}
          </tbody>
        </table>
      </div>
    </section>
  )
}
