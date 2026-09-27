import { useEffect, useState } from 'react'
import { api } from '../api/client.js'
import { demanderPosition, suiviGps } from '../lib/suiviGps.js'
import { texteAge } from '../lib/gpsAffichage.js'

// Ecran chauffeur : MON TRAJET -> DEMARRER -> conduire -> TERMINER.
// Le chauffeur n'a rien d'autre a comprendre : le GPS suit la course.

const MOMENT = { aller: 'MATIN', retour: 'SOIR' }
const ETAT_GPS = {
  actif: ['ACTIF', 'actif'], attente: ['RECHERCHE...', 'attente'], precision_faible: ['PRÉCISION FAIBLE', 'precision_faible'],
  refuse: ['REFUSÉ', 'refuse'], indisponible: ['INDISPONIBLE', 'indisponible'], arrete: ['ARRÊTÉ', 'termine'],
}
const hhmm = (dt) => (dt ? String(dt).slice(11, 16) : '—')

export default function CourseGps({ trajet, chauffeur, vehiculeJour, config, onChange }) {
  const [suivi, setSuivi] = useState(suiviGps.etat)
  const [busy, setBusy] = useState(false)
  const [erreur, setErreur] = useState(null)
  const [refusGps, setRefusGps] = useState(false)
  const [maintenant, setMaintenant] = useState(Date.now())
  useEffect(() => suiviGps.abonner(setSuivi), [])
  useEffect(() => {
    const t = setInterval(() => setMaintenant(Date.now()), 1000)
    return () => clearInterval(t)
  }, [])

  const vehicule = trajet.vehicule || vehiculeJour
  const monSuivi = suivi.actif && suivi.trajetId === trajet.id
  const autreCourse = suivi.actif && suivi.trajetId !== trajet.id
  const enCours = trajet.statut === 'en_cours'
  const nomChauffeur = [chauffeur?.prenom, chauffeur?.nom].filter(Boolean).join(' ')
  const options = { trajetId: trajet.id, vehicleId: vehicule?.id ?? null, intervalle: config.intervalle_secondes, precisionFaible: config.precision_faible_metres }

  // Course en cours sans suivi actif (page rechargee) : reprise automatique.
  useEffect(() => {
    if (enCours && !suiviGps.etat.actif) suiviGps.demarrer(options)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [enCours, trajet.id])

  async function demarrer(sansPosition = false) {
    setBusy(true)
    setErreur(null)
    let premiere = null
    if (!sansPosition) {
      const r = await demanderPosition()
      if (!r.ok) {
        setErreur(r.message)
        setRefusGps(true)
        setBusy(false)
        return
      }
      premiere = r.position
    }
    try {
      const res = await api.post('/trajet-avancer.php', { trajet_id: trajet.id, action: 'demarrer', gps: true })
      await suiviGps.demarrer({ ...options, vehicleId: res.vehicle_id ?? options.vehicleId })
      if (premiere) suiviGps.ajouterMesure(premiere)
      setRefusGps(false)
      await onChange?.()
    } catch (e) {
      setErreur(e.message)
    } finally {
      setBusy(false)
    }
  }

  async function terminer() {
    if (!window.confirm('Terminer la course ?\n\nLe suivi GPS s’arrête et les élèves attendus non scannés seront marqués absents.')) return
    setBusy(true)
    setErreur(null)
    await suiviGps.arreter({ vider: true })
    try {
      await api.post('/trajet-avancer.php', { trajet_id: trajet.id, action: 'cloturer' })
      await onChange?.()
    } catch (e) {
      setErreur(`${e.message} — la course continue, réessayez.`)
      await suiviGps.demarrer(options)
    } finally {
      setBusy(false)
    }
  }

  async function confirmerArret() {
    setBusy(true)
    try {
      await api.post('/trajet-avancer.php', { trajet_id: trajet.id, action: 'avancer' })
      await onChange?.()
    } catch (e) {
      setErreur(e.message)
    } finally {
      setBusy(false)
    }
  }

  const idx = trajet.arrets.findIndex((a) => a.courant)
  const prochain = enCours ? (idx >= 0 ? trajet.arrets[idx + 1] : null) : trajet.arrets[0]

  if (!enCours) {
    return (
      <section className="course" aria-label="Mon trajet">
        <p className="course-titre">Mon trajet</p>
        <dl className="g-champs">
          <div className="course-grand"><dt>Circuit</dt><dd>{trajet.circuit_nom}</dd></div>
          <div><dt>Véhicule</dt><dd>{vehicule ? vehicule.immatriculation : '—'}</dd></div>
          <div><dt>Trajet</dt><dd>{MOMENT[trajet.sens] || '—'}</dd></div>
          <div><dt>Départ prévu</dt><dd>{trajet.depart_prevu || '—'}</dd></div>
          <div><dt>Chauffeur</dt><dd>{nomChauffeur || '—'}</dd></div>
          <div className="course-grand"><dt>Prochain arrêt</dt><dd>{prochain ? `📍 ${prochain.nom}` : '—'}</dd></div>
        </dl>
        {!vehicule && <p className="g-alerte g-alerte-danger">Aucun véhicule ne vous est affecté : contactez le gestionnaire de flotte.</p>}
        {autreCourse && <p className="g-alerte">Terminez d'abord la course en cours.</p>}
        {erreur && <p className="g-alerte g-alerte-danger" role="alert">{erreur}</p>}
        <button type="button" className="g-btn g-btn-succes g-btn-geant" disabled={busy || !vehicule || autreCourse} onClick={() => demarrer(false)}>
          {busy ? 'Démarrage...' : '▶ DÉMARRER LA COURSE'}
        </button>
        {refusGps && (
          <button type="button" className="g-btn g-btn-geant" disabled={busy} onClick={() => demarrer(true)}>
            Démarrer sans GPS (le Back Office sera prévenu)
          </button>
        )}
      </section>
    )
  }

  const [libGps, etat] = ETAT_GPS[monSuivi ? suivi.gps : 'attente'] || ETAT_GPS.attente
  const ageEnvoi = monSuivi && suivi.dernierEnvoi ? Math.max(0, Math.floor((maintenant - suivi.dernierEnvoi) / 1000)) : null
  return (
    <section className="course course-active" aria-label="Course en cours">
      <p className="course-titre">🟢 Course en cours</p>
      <dl className="g-champs">
        <div className="course-grand"><dt>Circuit</dt><dd>{trajet.circuit_nom} · {MOMENT[trajet.sens] || ''}</dd></div>
        <div><dt>Véhicule</dt><dd>🚐 {vehicule ? vehicule.immatriculation : '—'}</dd></div>
        <div><dt>GPS</dt><dd><span className={`g-badge etat-${etat}`}>{libGps}</span></dd></div>
        <div><dt>Position</dt><dd>{ageEnvoi == null ? 'en attente' : `Actualisée ${texteAge(ageEnvoi)}`}</dd></div>
        <div><dt>Départ</dt><dd>{hhmm(trajet.heure_debut)}</dd></div>
        <div className="course-grand"><dt>Prochain arrêt</dt><dd>{prochain ? `📍 ${prochain.nom}` : 'Dernier arrêt atteint'}</dd></div>
      </dl>
      {monSuivi && suivi.message && <p className="g-alerte">{suivi.message}</p>}
      {monSuivi && suivi.enAttente > 1 && <p className="g-alerte">{suivi.enAttente} positions en attente : envoi automatique au retour du réseau.</p>}
      {monSuivi && suivi.aide?.proche_prochain_arret && prochain && suivi.aide.prochain_arret?.id === prochain.id && (
        <div className="g-alerte g-alerte-info">
          <span>Vous êtes à l'arrêt <strong>{prochain.nom}</strong> ?</span>
          <button type="button" className="g-btn g-btn-primaire g-btn-petit" disabled={busy} onClick={confirmerArret}>Confirmer l'arrivée</button>
        </div>
      )}
      {monSuivi && suivi.aide?.proche_fin && !prochain && <p className="g-alerte g-alerte-info">Arrivé au dernier arrêt : terminez la course une fois les élèves déposés.</p>}
      {monSuivi && !suivi.ecranMaintenu && <p className="g-alerte">Gardez SHIPP ouvert à l'écran pendant la course : écran verrouillé, le suivi peut s'interrompre.</p>}
      {erreur && <p className="g-alerte g-alerte-danger" role="alert">{erreur}</p>}
      <button type="button" className="g-btn g-btn-danger g-btn-geant" disabled={busy} onClick={terminer}>
        {busy ? 'Patientez...' : '⏹ TERMINER LA COURSE'}
      </button>
    </section>
  )
}
