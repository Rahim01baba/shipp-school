import { useEffect, useMemo, useRef, useState } from 'react'
import { api } from '../api/client.js'
import CarteShipp from './CarteShipp.jsx'
import { estEcole } from '../lib/gpsAffichage.js'

// Carte du circuit : cliquer sur la carte pour creer un arret, le glisser pour le deplacer,
// le renommer, le reordonner, le supprimer. L'ordre saisi est celui du trajet ALLER
// (domicile -> ecole) ; le RETOUR suit automatiquement l'ordre inverse.
// Les arrets sans coordonnees restent sans coordonnees tant qu'on ne les place pas.

let compteur = 0
const cleNouvelle = () => `n${++compteur}`
const round = (v) => Math.round(v * 1e7) / 1e7

export default function EditeurCarteCircuit({ circuitId, peutModifier, peutCreer, peutSupprimer, peutVoirEleves, onSaved }) {
  const [arrets, setArrets] = useState([])
  const [coordonnees, setCoordonnees] = useState(true)
  const [eleves, setEleves] = useState({})
  const [sel, setSel] = useState(null)
  const [modifie, setModifie] = useState(false)
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)
  const [info, setInfo] = useState(null)
  const [charge, setCharge] = useState(false)
  const nomRefs = useRef({})

  async function charger() {
    setErreur(null)
    try {
      const r = await api.get(`/circuit-arrets.php?circuit_id=${circuitId}`)
      setCoordonnees(r.coordonnees)
      setArrets(r.data.map((a) => ({ ...a, cle: `e${a.id}`, heure_estimee: a.heure_estimee || '' })))
      setModifie(false)
      setSel(null)
      setCharge(true)
      if (peutVoirEleves) {
        const af = await api.get(`/eleve-affectations.php?circuit_id=${circuitId}`).catch(() => ({ data: [] }))
        const n = {}
        ;(af.data || []).forEach((x) => {
          ;[x.etape_montee_id, x.etape_depose_id].filter(Boolean).forEach((id) => { n[id] = (n[id] || 0) + 1 })
        })
        setEleves(n)
      }
    } catch (e) {
      setErreur(e.message)
    }
  }
  useEffect(() => { charger() }, [circuitId]) // eslint-disable-line react-hooks/exhaustive-deps

  const maj = (cle, champs) => {
    setArrets((l) => l.map((a) => (a.cle === cle ? { ...a, ...champs } : a)))
    setModifie(true)
    setInfo(null)
  }

  function inserer(nouveau) {
    setArrets((l) => (l.length && estEcole(l[l.length - 1].nom) ? [...l.slice(0, -1), nouveau, l[l.length - 1]] : [...l, nouveau]))
    setSel(nouveau.cle)
    setModifie(true)
    setInfo(null)
    setTimeout(() => nomRefs.current[nouveau.cle]?.select(), 60)
  }

  function ajouterArret() {
    inserer({ cle: cleNouvelle(), id: null, nom: `Arrêt ${arrets.length + 1}`, latitude: null, longitude: null, heure_estimee: '' })
  }

  function clicCarte(lat, lng) {
    if (!peutModifier) return
    const cible = arrets.find((a) => a.cle === sel)
    if (cible && cible.latitude == null) {
      maj(cible.cle, { latitude: round(lat), longitude: round(lng) })
      return
    }
    if (peutCreer) inserer({ cle: cleNouvelle(), id: null, nom: `Arrêt ${arrets.length + 1}`, latitude: round(lat), longitude: round(lng), heure_estimee: '' })
  }

  function deplacer(i, d) {
    setArrets((l) => {
      const n = [...l]
      const j = i + d
      if (j < 0 || j >= n.length) return l
      ;[n[i], n[j]] = [n[j], n[i]]
      return n
    })
    setModifie(true)
  }

  function retirer(a) {
    if (!window.confirm(`Supprimer l'arrêt « ${a.nom} » du circuit ?`)) return
    setArrets((l) => l.filter((x) => x.cle !== a.cle))
    setModifie(true)
  }

  async function enregistrer() {
    setBusy(true)
    setErreur(null)
    try {
      await api.put('/circuit-arrets.php', {
        circuit_id: Number(circuitId),
        arrets: arrets.map((a) => ({ id: a.id || undefined, nom: a.nom.trim(), latitude: a.latitude, longitude: a.longitude, heure_estimee: a.heure_estimee || null })),
      })
      await charger()
      setInfo('Circuit enregistré. Le trajet retour suit automatiquement l’ordre inverse.')
      onSaved?.()
    } catch (e) {
      setErreur(e.message)
    } finally {
      setBusy(false)
    }
  }

  const { lignes, marqueurs, cadre } = useMemo(() => {
    const pts = arrets.filter((a) => a.latitude != null)
    return {
      lignes: pts.length > 1 ? [{ id: 'circuit', points: pts.map((a) => [a.latitude, a.longitude]), couleur: '#475569', epaisseur: 3, pointille: true, fleches: true, titre: 'Trajet aller' }] : [],
      marqueurs: arrets.map((a, i) => ({
        id: a.cle, lat: a.latitude, lng: a.longitude, type: estEcole(a.nom) ? 'ecole' : 'arret', texte: estEcole(a.nom) ? '🏫' : String(i + 1),
        couleur: estEcole(a.nom) ? undefined : '#16a34a', libelle: a.nom, titre: `${i + 1}. ${a.nom}`, selectionne: a.cle === sel, deplacable: peutModifier,
        onClick: () => setSel(a.cle), onDeplace: (lat, lng) => maj(a.cle, { latitude: round(lat), longitude: round(lng) }),
      })),
      cadre: pts.map((a) => [a.latitude, a.longitude]),
    }
  }, [arrets, sel, peutModifier]) // eslint-disable-line react-hooks/exhaustive-deps

  if (!charge && !erreur) return <p className="g-vide">Chargement de la carte...</p>
  if (!coordonnees) return <p className="g-vide">Les positions GPS des arrêts ne sont pas activées sur le serveur.</p>
  const aPlacer = arrets.find((a) => a.cle === sel && a.latitude == null)
  const sansPosition = arrets.filter((a) => a.latitude == null).length

  return (
    <section aria-label="Carte du circuit">
      {erreur && <p className="error-banner">{erreur}</p>}
      {info && <p className="ma-info">{info}</p>}
      <div className="ci-barre">
        <p className="ci-aide">
          {peutModifier ? 'Cliquez sur la carte pour créer un arrêt · glissez un arrêt pour le déplacer.' : 'Consultation du circuit.'}
          {aPlacer && <strong> Cliquez sur la carte pour placer « {aPlacer.nom} ».</strong>}
          {!aPlacer && sansPosition > 0 && <span className="ci-sanspos"> {sansPosition} arrêt(s) sans position.</span>}
        </p>
        {peutModifier && (
          <div className="g-entete-actions">
            {peutCreer && <button type="button" className="g-btn" onClick={ajouterArret}>+ Ajouter un arrêt</button>}
            {modifie && <button type="button" className="g-btn g-btn-lien" disabled={busy} onClick={charger}>Annuler</button>}
            <button type="button" className="g-btn g-btn-primaire" disabled={busy || !modifie} onClick={enregistrer}>{busy ? 'Enregistrement...' : 'Enregistrer le circuit'}</button>
          </div>
        )}
      </div>

      <div className="ci-grille">
        <CarteShipp className="ci-carte" lignes={lignes} marqueurs={marqueurs} cadrer={cadre} cleCadrage={charge ? `c${circuitId}` : undefined} onClickCarte={clicCarte} />
        <div className="g-card">
          <div className="ci-sens">
            <div>
              <h3 className="g-card-titre">Trajet aller</h3>
              <Sequence arrets={arrets} />
            </div>
            <div>
              <h3 className="g-card-titre">Trajet retour</h3>
              <Sequence arrets={[...arrets].reverse()} />
            </div>
          </div>
          <p className="ci-aide">Le retour est généré automatiquement dans l'ordre inverse de l'aller.</p>
        </div>
      </div>

      <div className="g-table-wrap g-mt">
        <table className="g-table">
          <thead><tr><th>N°</th><th>Arrêt</th><th>Heure (aller)</th><th>Latitude</th><th>Longitude</th>{peutVoirEleves && <th>Élèves</th>}{peutModifier && <th />}</tr></thead>
          <tbody>
            {arrets.map((a, i) => (
              <tr key={a.cle} className={a.cle === sel ? 'g-ligne-sel' : ''} onClick={() => setSel(a.cle)}>
                <td className="g-num">{estEcole(a.nom) ? '🏫' : i + 1}</td>
                <td>
                  {peutModifier
                    ? <input aria-label={`Nom de l'arrêt ${i + 1}`} ref={(el) => { nomRefs.current[a.cle] = el }} value={a.nom} onChange={(e) => maj(a.cle, { nom: e.target.value })} />
                    : a.nom}
                  {a.statut === 'inactive' && <span className="g-badge etat-termine"> inactif</span>}
                </td>
                <td>{peutModifier ? <input type="time" aria-label="Heure estimée" value={a.heure_estimee || ''} onChange={(e) => maj(a.cle, { heure_estimee: e.target.value })} /> : (a.heure_estimee || '—')}</td>
                <td className="g-num">{a.latitude != null ? a.latitude.toFixed(6) : <span className="ci-sanspos">à placer</span>}</td>
                <td className="g-num">{a.longitude != null ? a.longitude.toFixed(6) : '—'}</td>
                {peutVoirEleves && <td className="g-num">{a.id ? eleves[a.id] || 0 : 0}</td>}
                {peutModifier && (
                  <td>
                    <div className="ci-actions">
                      <button type="button" aria-label="Monter" title="Monter" disabled={i === 0} onClick={(e) => { e.stopPropagation(); deplacer(i, -1) }}>↑</button>
                      <button type="button" aria-label="Descendre" title="Descendre" disabled={i === arrets.length - 1} onClick={(e) => { e.stopPropagation(); deplacer(i, 1) }}>↓</button>
                      <button type="button" aria-label="Placer sur la carte" title="Placer / replacer sur la carte" onClick={(e) => { e.stopPropagation(); setSel(a.cle); maj(a.cle, { latitude: null, longitude: null }) }}>📍</button>
                      {(peutSupprimer || !a.id) && <button type="button" aria-label="Supprimer" title="Supprimer" onClick={(e) => { e.stopPropagation(); retirer(a) }}>✕</button>}
                    </div>
                  </td>
                )}
              </tr>
            ))}
            {arrets.length === 0 && <tr><td colSpan={7} className="g-vide">Aucun arrêt : cliquez sur la carte pour créer le premier.</td></tr>}
          </tbody>
        </table>
      </div>
    </section>
  )
}

function Sequence({ arrets }) {
  if (!arrets.length) return <p className="g-vide">—</p>
  return (
    <ol className="ci-sequence">
      {arrets.map((a) => <li key={a.cle} className={estEcole(a.nom) ? 'ci-ecole' : ''}>{a.nom}</li>)}
    </ol>
  )
}
