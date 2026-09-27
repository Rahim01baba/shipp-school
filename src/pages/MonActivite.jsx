import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client.js'

// Ecran chauffeur (mobile d'abord) : « Mon activite aujourd'hui ».
// Toutes les donnees viennent de /chauffeur-jour.php ; chaque action cree un
// evenement cote serveur, qui alimente le suivi des parents.

const STATUT_TRAJET = { planifie: 'Planifie', en_cours: 'En cours', termine: 'Termine', annule: 'Annule' }
const STATUT_ELEVE = { attendu: 'Attendu', embarque: 'Embarque', depose: 'Depose', absent: 'Absent' }
const SENS = { aller: 'Aller', retour: 'Retour' }

function heure(dt) {
  return dt ? String(dt).slice(11, 16) : ''
}

export default function MonActivite() {
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)
  const [info, setInfo] = useState(null)
  const [busy, setBusy] = useState(null)

  async function load() {
    setError(null)
    try {
      setData(await api.get('/chauffeur-jour.php'))
    } catch (e) {
      setError(e.message)
    }
  }

  useEffect(() => {
    load()
  }, [])

  async function run(key, fn, message) {
    setBusy(key)
    setError(null)
    setInfo(null)
    try {
      const res = await fn()
      const alertes = [...(res?.alertes || [])]
      if (res?.abonnement_warning) alertes.push(res.abonnement_warning.replace('abonnement_', 'abonnement '))
      setInfo(message + (alertes.length ? ` — attention : ${alertes.join(', ').replace('eleve_non_affecte', 'eleve non affecte a ce circuit')}` : ''))
      await load()
    } catch (e) {
      setError(e.message)
    } finally {
      setBusy(null)
    }
  }

  const avancer = (t, action) =>
    run(`${t.id}-${action}`, () => api.post('/trajet-avancer.php', { trajet_id: t.id, action }), {
      demarrer: 'Trajet demarre',
      avancer: 'Arret suivant enregistre',
      cloturer: 'Trajet termine',
    }[action])

  const scan = (t, e, type) =>
    run(`${t.id}-${e.eleve_id}-${type}`, () =>
      api.post('/scan-enregistrer.php', { eleve_id: e.eleve_id, type, methode: 'recherche', trajet_id: t.id }),
      `${e.prenom} ${e.nom} : ${type === 'transport_embarquement' ? 'monte' : 'depose'}`)

  const absent = (t, e) =>
    run(`${t.id}-${e.eleve_id}-absent`, () => api.post('/eleve-absent.php', { trajet_id: t.id, eleve_id: e.eleve_id }),
      `${e.prenom} ${e.nom} : absent signale`)

  if (error && !data) {
    return (
      <div className="page ma-page">
        <p><Link to="/">&larr; Accueil</Link></p>
        <p className="error-banner">{error}</p>
      </div>
    )
  }
  if (!data) return <div className="page ma-page">Chargement...</div>

  return (
    <div className="page ma-page">
      <p><Link to="/">&larr; Accueil</Link></p>
      <h1>Mon activite aujourd'hui</h1>
      <div className="ma-header">
        <div>
          <strong>{[data.chauffeur.prenom, data.chauffeur.nom].filter(Boolean).join(' ')}</strong>
          <div className="ma-muted">{data.date}</div>
        </div>
        <div className="ma-vehicule">
          {data.vehicule ? (
            <>
              <strong>{data.vehicule.immatriculation}</strong>
              <div className="ma-muted">{data.vehicule.modele}</div>
            </>
          ) : (
            <span className="scanner-abo-warning">Aucun vehicule affecte</span>
          )}
        </div>
      </div>
      {error && <p className="error-banner">{error}</p>}
      {info && <p className="ma-info">{info}</p>}
      {data.trajets.length === 0 && <p>Aucun trajet prevu pour vous aujourd'hui.</p>}

      {data.trajets.map((t) => {
        const enCours = t.statut === 'en_cours'
        const parArret = (arretId, champ) => t.eleves.filter((e) => e[champ] === arretId)
        const sansArret = t.eleves.filter((e) => !e.etape_montee_id)
        const ligneEleve = (e, mode) => (
          <li key={`${mode}-${e.eleve_id}`} className={`ma-eleve ma-eleve-${e.statut}`}>
            <div>
              <span className="ma-eleve-nom">{e.prenom} {e.nom}</span>
              <span className="ma-muted"> {e.classe || ''}</span>
              {e.abonnement_statut !== 'actif' && <span className="ma-badge-warn">abonnement {e.abonnement_statut}</span>}
              <div className="ma-muted">
                {STATUT_ELEVE[e.statut]}
                {e.embarque_at && ` · monte ${heure(e.embarque_at)}`}
                {e.depose_at && ` · depose ${heure(e.depose_at)}`}
                {e.absent_at && ` · absent ${heure(e.absent_at)}`}
              </div>
            </div>
            {enCours && (
              <div className="ma-actions">
                {mode === 'montee' && e.statut === 'attendu' && (
                  <>
                    <button type="button" className="btn-transport" disabled={!!busy} onClick={() => scan(t, e, 'transport_embarquement')}>Monte</button>
                    <button type="button" disabled={!!busy} onClick={() => absent(t, e)}>Absent</button>
                  </>
                )}
                {(mode === 'depose' || (mode === 'montee' && !e.etape_depose_id)) && e.statut === 'embarque' && (
                  <button type="button" className="btn-transport" disabled={!!busy} onClick={() => scan(t, e, 'transport_debarquement')}>Depose</button>
                )}
              </div>
            )}
          </li>
        )
        return (
          <section key={t.id} className="ma-trajet">
            <div className="ma-trajet-head">
              <div>
                <h2>{t.circuit_nom} {t.sens ? `· ${SENS[t.sens] || t.sens}` : ''}</h2>
                <div className="ma-muted">
                  {STATUT_TRAJET[t.statut]}
                  {t.heure_debut && ` · demarre ${heure(t.heure_debut)}`}
                  {t.heure_fin && ` · termine ${heure(t.heure_fin)}`}
                  {t.vehicule && ` · ${t.vehicule.immatriculation}`}
                </div>
              </div>
              <div className="ma-compteurs">
                <span><strong>{t.compteurs.embarques}</strong>/{t.compteurs.attendus} montes</span>
                <span><strong>{t.compteurs.deposes}</strong> deposes</span>
                <span><strong>{t.compteurs.absents}</strong> absents</span>
              </div>
            </div>

            <div className="ma-boutons">
              {t.statut === 'planifie' && (
                <button type="button" className="btn-transport ma-big" disabled={!!busy} onClick={() => avancer(t, 'demarrer')}>Demarrer le trajet</button>
              )}
              {enCours && (
                <>
                  <Link className="module-link" to={`/scanner?trajet_id=${t.id}`}>Scanner un QR code</Link>
                  <button type="button" className="btn-transport" disabled={!!busy} onClick={() => avancer(t, 'avancer')}>Arret suivant</button>
                  <button type="button" disabled={!!busy} onClick={() => {
                    if (window.confirm('Terminer le trajet ? Les eleves attendus non scannes seront marques absents.')) avancer(t, 'cloturer')
                  }}>Fin de trajet</button>
                </>
              )}
              <Link className="module-link" to={`/incidents/nouveau?trajet_id=${t.id}`}>Signaler un incident</Link>
            </div>

            <ol className="ma-arrets">
              {t.arrets.map((a) => {
                const montees = parArret(a.id, 'etape_montee_id')
                const deposes = parArret(a.id, 'etape_depose_id')
                return (
                  <li key={a.id} className={a.courant ? 'ma-arret ma-arret-courant' : 'ma-arret'}>
                    <div className="ma-arret-head">
                      <span className="ma-arret-nom">{a.nom}</span>
                      <span className="ma-muted">
                        {a.heure_estimee ? `prevu ${a.heure_estimee}` : ''}
                        {a.passage && ` · passe ${heure(a.passage.heure_reelle)}`}
                        {a.passage && a.passage.ecart_minutes !== null && Number(a.passage.ecart_minutes) > 0 && ` (+${a.passage.ecart_minutes} min)`}
                      </span>
                    </div>
                    {(montees.length > 0 || deposes.length > 0) && (
                      <ul className="ma-eleves">
                        {montees.map((e) => ligneEleve(e, 'montee'))}
                        {deposes.map((e) => ligneEleve(e, 'depose'))}
                      </ul>
                    )}
                  </li>
                )
              })}
            </ol>
            {sansArret.length > 0 && (
              <>
                <h3>Eleves sans arret defini</h3>
                <ul className="ma-eleves">{sansArret.map((e) => ligneEleve(e, e.statut === 'embarque' ? 'depose' : 'montee'))}</ul>
              </>
            )}
          </section>
        )
      })}
    </div>
  )
}
