import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client.js'

// Espace parent : etat du transport de chaque enfant, a partir des evenements
// reels des trajets (aucune donnee statique). Rafraichissement automatique.

const STATUT_ELEVE = {
  attendu: { label: 'Attendu', cls: 'se-attendu' },
  embarque: { label: 'Dans le vehicule', cls: 'se-embarque' },
  depose: { label: 'Depose', cls: 'se-depose' },
  absent: { label: 'Absent', cls: 'se-absent' },
}
const STATUT_TRAJET = { planifie: 'Pas encore demarre', en_cours: 'Trajet en cours', termine: 'Trajet termine', annule: 'Trajet annule' }
const SENS = { aller: 'Aller', retour: 'Retour' }

function heure(dt) {
  return dt ? String(dt).slice(11, 16) : ''
}

export default function SuiviEnfants() {
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)

  async function load() {
    try {
      setData(await api.get('/parent-enfants.php'))
      setError(null)
    } catch (e) {
      setError(e.message)
    }
  }

  useEffect(() => {
    load()
    const t = setInterval(load, 30000)
    return () => clearInterval(t)
  }, [])

  if (!data) {
    return (
      <div className="page se-page">
        <p><Link to="/">&larr; Accueil</Link></p>
        {error ? <p className="error-banner">{error}</p> : <p>Chargement...</p>}
      </div>
    )
  }

  return (
    <div className="page se-page">
      <p><Link to="/">&larr; Accueil</Link></p>
      <h1>Suivi de mes enfants</h1>
      <p className="ma-muted">Mis a jour automatiquement toutes les 30 secondes.</p>
      {error && <p className="error-banner">{error}</p>}
      {data.data.length === 0 && <p>Aucun enfant n'est rattache a votre compte. Contactez l'etablissement.</p>}

      {data.data.map(({ eleve, abonnements, affectation, activites = [], paiements = [], trajets_du_jour: trajets, chronologie }) => (
        <section key={eleve.id} className="se-carte">
          <div className="se-carte-head">
            <h2>{eleve.prenom} {eleve.nom}</h2>
            <span className="ma-muted">{[eleve.classe, eleve.ecole].filter(Boolean).join(' · ')}</span>
          </div>

          <div className="se-grille">
            <div>
              <h3>Transport</h3>
              {affectation ? (
                <>
                  <p>Circuit : <strong>{affectation.circuit_nom}</strong></p>
                  <p>Arret : <strong>{affectation.arret_montee || 'non defini'}</strong>{affectation.heure_montee && ` (prevu ${affectation.heure_montee})`}</p>
                  {affectation.arret_depose && <p>Depose : {affectation.arret_depose}{affectation.heure_depose && ` (${affectation.heure_depose})`}</p>}
                </>
              ) : (
                <p className="ma-muted">Pas d'affectation transport.</p>
              )}
              {activites.map((a) => (
                <p key={a.circuit_id} className="ma-muted">
                  Navette : {a.activite || a.circuit_nom}{a.destination ? ` vers ${a.destination}` : ''}
                  {a.jours_semaine ? ` (${a.jours_semaine.split(',').map((j) => ['', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'][Number(j)]).join(', ')})` : ''}
                  {a.heure_depart ? ` · depart ${a.heure_depart}` : ''}{a.heure_retour ? ` · retour ${a.heure_retour}` : ''}
                </p>
              ))}
            </div>
            <div>
              <h3>Abonnements</h3>
              {abonnements.length === 0 && <p className="ma-muted">Aucun abonnement.</p>}
              {abonnements.map((a) => (
                <p key={a.type}>
                  {a.type === 'transport' ? 'Transport' : 'Cantine'} :{' '}
                  <span className={a.etat === 'actif' ? '' : 'scanner-abo-warning'}>{a.etat}</span>
                  {a.date_fin && ` jusqu'au ${a.date_fin}`}
                </p>
              ))}
            </div>
          </div>

          <h3>Aujourd'hui</h3>
          {trajets.length === 0 && <p className="ma-muted">Aucun trajet prevu aujourd'hui.</p>}
          {trajets.map((t) => {
            const st = STATUT_ELEVE[t.statut_eleve] || STATUT_ELEVE.attendu
            return (
              <div key={t.id} className="se-trajet">
                <div className="se-trajet-head">
                  <strong>{t.activite ? `${t.circuit_nom} · ` : ''}{SENS[t.sens] || 'Trajet'}</strong>
                  <span className={`se-statut ${st.cls}`}>{st.label}</span>
                </div>
                <ul className="se-etapes">
                  <li className={t.statut !== 'planifie' ? 'fait' : ''}>
                    {t.statut === 'planifie' ? 'Chauffeur pas encore en route' : `Trajet demarre${t.heure_debut ? ` a ${heure(t.heure_debut)}` : ''}`}
                  </li>
                  {t.embarque_at && <li className="fait">Monte dans le vehicule a {heure(t.embarque_at)}</li>}
                  {t.absent_at && <li className="alerte">Absence signalee a {heure(t.absent_at)}</li>}
                  {t.depose_at && <li className="fait">Depose a {heure(t.depose_at)}</li>}
                  {t.statut === 'en_cours' && t.arret_courant && <li>Vehicule actuellement a : {t.arret_courant}</li>}
                  {t.retard && <li className="alerte">{t.retard}</li>}
                  <li className={t.statut === 'termine' ? 'fait' : ''}>{STATUT_TRAJET[t.statut]}</li>
                </ul>
                <p className="ma-muted">
                  {t.chauffeur_nom && `Chauffeur : ${t.chauffeur_nom}`}
                  {t.immatriculation && ` · Vehicule : ${t.immatriculation}${t.modele ? ` (${t.modele})` : ''}`}
                </p>
              </div>
            )
          })}

          {paiements.length > 0 && (
            <details className="se-historique">
              <summary>Paiements transport</summary>
              <ul>
                {paiements.map((p) => (
                  <li key={p.mois}>
                    {String(p.mois).slice(0, 7)} :{' '}
                    {Number(p.arret_service) ? 'service arrete' : Number(p.recu_shipp) ? 'regle' : Number(p.encaisse_enko) ? "regle a l'etablissement" : 'non regle'}
                  </li>
                ))}
              </ul>
            </details>
          )}

          {chronologie.length > 0 && (
            <details className="se-historique">
              <summary>Historique recent</summary>
              <ul>
                {chronologie.map((c) => (
                  <li key={c.id}>
                    <span className="ma-muted">{String(c.survenu_at).slice(0, Number(c.heure_connue) ? 16 : 10).replace('T', ' ')}</span>{' '}
                    {c.libelle}{c.arret ? ` · ${c.arret}` : ''}
                  </li>
                ))}
              </ul>
            </details>
          )}
        </section>
      ))}
    </div>
  )
}
