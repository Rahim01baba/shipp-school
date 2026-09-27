import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { suiviGps, viderFilesEnAttente } from '../lib/suiviGps.js'

// Bandeau persistant pendant une course, sur tous les ecrans de l'application.
// Reprend aussi une course en cours apres rechargement et envoie les positions en attente.
export default function GpsBandeau() {
  const [etat, setEtat] = useState(suiviGps.etat)
  const [maintenant, setMaintenant] = useState(Date.now())

  useEffect(() => {
    const fin = suiviGps.abonner(setEtat)
    const t = setInterval(() => setMaintenant(Date.now()), 1000)
    let jeton = null
    try {
      jeton = localStorage.getItem('shipp_token')
    } catch {
      jeton = null
    }
    if (jeton) {
      const course = suiviGps.courseMemorisee()
      if (course && !suiviGps.etat.actif) suiviGps.demarrer(course)
      viderFilesEnAttente()
    }
    return () => { fin(); clearInterval(t) }
  }, [])

  if (!etat.actif) return null
  const age = etat.derniereMesure ? (maintenant - etat.derniereMesure.t) / 1000 : null
  let niveau = 'actif'
  if (['refuse', 'indisponible'].includes(etat.gps) || (age != null && age > 120)) niveau = 'perdu'
  else if (etat.gps === 'attente' || etat.gps === 'precision_faible' || (age != null && age > 30)) niveau = 'ancienne'
  const texte = {
    actif: '🟢 GPS ACTIF · COURSE EN COURS',
    ancienne: etat.gps === 'attente' ? '🟠 GPS — RECHERCHE DU SIGNAL' : '🟠 GPS — POSITION ANCIENNE',
    perdu: '🔴 GPS — SIGNAL PERDU',
  }[niveau]
  return (
    <div className={`gps-bandeau etat-${niveau}`} role="status">
      <span>{texte}</span>
      {etat.enAttente > 1 && <span>· {etat.enAttente} positions en attente</span>}
      <Link to="/mon-activite">Voir ma course</Link>
    </div>
  )
}
