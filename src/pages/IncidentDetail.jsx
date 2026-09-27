import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api } from '../api/client.js'
import { useAuth } from '../context/AuthContext.jsx'
import { Badge, CATEGORIES, Fichiers, GRAVITES, RESPONSABILITES, STATUTS_INCIDENT, TYPES_INCIDENT, dateHeure } from './incidentsCommun.jsx'

// Fiche incident / accident : faits, eleves, pieces jointes, qualification
// humaine de la responsabilite, suivi (couts, actions), cloture, historique.
export default function IncidentDetail() {
  const { id } = useParams()
  const { can } = useAuth()
  const [d, setD] = useState(null)
  const [error, setError] = useState(null)
  const [info, setInfo] = useState(null)
  const [qualif, setQualif] = useState({ responsabilite: 'non_determinee', commentaire: '' })
  const [suivi, setSuivi] = useState({})
  const [acc, setAcc] = useState({})
  const [action, setAction] = useState({ description: '', echeance: '' })
  const [motif, setMotif] = useState('')

  async function load() {
    try {
      const res = await api.get(`/incidents.php?id=${id}`)
      setD(res)
      setQualif({ responsabilite: res.data.responsabilite, commentaire: '' })
      setSuivi({ action_corrective: res.data.action_corrective || '', cout_estime: res.data.cout_estime || '', cout_reel: res.data.cout_reel || '' })
      setAcc(res.accident || {})
    } catch (e) {
      setError(e.message)
    }
  }

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  async function put(body, message) {
    setError(null)
    setInfo(null)
    try {
      await api.put('/incidents.php', { id: Number(id), ...body })
      setInfo(message)
      setMotif('')
      await load()
    } catch (e) {
      setError(e.message)
    }
  }

  if (!d) {
    return (
      <div className="page">
        <p><Link to="/incidents">&larr; Incidents</Link></p>
        {error ? <p className="error-banner">{error}</p> : <p>Chargement...</p>}
      </div>
    )
  }

  const i = d.data
  const termine = ['clos', 'annule'].includes(i.statut)
  const peutModifier = d.droits.modifier && !termine
  const peutQualifier = d.droits.qualifier && !termine

  return (
    <div className="page">
      <p><Link to="/incidents">&larr; Incidents</Link></p>
      <h1>{i.titre}</h1>
      <p>
        <Badge value={i.categorie} map={CATEGORIES} prefix="inc-cat" /> <Badge value={i.gravite} map={GRAVITES} prefix="inc-grav" />{' '}
        <Badge value={i.statut} map={STATUTS_INCIDENT} prefix="inc-st" />
      </p>
      {error && <p className="error-banner">{error}</p>}
      {info && <p className="ma-info">{info}</p>}

      <section className="inc-section">
        <h2>Faits</h2>
        <dl className="inc-dl">
          <dt>Survenu le</dt><dd>{dateHeure(i.survenu_at || i.date_incident)}</dd>
          <dt>Type</dt><dd>{TYPES_INCIDENT[i.type] || i.type}</dd>
          <dt>Lieu</dt><dd>{i.lieu || '-'}{i.latitude && <span className="ma-muted"> ({i.latitude}, {i.longitude})</span>}</dd>
          <dt>Chauffeur</dt><dd>{i.chauffeur_id ? <Link to={`/chauffeurs/${i.chauffeur_id}`}>{i.chauffeur_nom}</Link> : '-'}</dd>
          <dt>Vehicule</dt><dd>{i.immatriculation || '-'}</dd>
          <dt>Circuit</dt><dd>{i.circuit_nom || '-'}{i.trajet_id && <span className="ma-muted"> · trajet #{i.trajet_id}</span>}</dd>
          <dt>Declare par</dt><dd>{i.declare_par_nom || '-'} <span className="ma-muted">{dateHeure(i.created_at)}</span></dd>
        </dl>
        {i.description && <p className="inc-texte">{i.description}</p>}
        {i.mesures_immediates && <p><strong>Mesures immediates :</strong> {i.mesures_immediates}</p>}
      </section>

      {d.eleves.length > 0 && (
        <section className="inc-section">
          <h2>Eleves concernes</h2>
          <ul>
            {d.eleves.map((e) => (
              <li key={e.id}>
                <Link to={`/eleves/${e.eleve_id}`}>{e.prenom} {e.nom}</Link> <span className="ma-muted">{e.classe}</span> — {e.role}
                {e.blessure && ` (${e.blessure})`}
                <span className="ma-muted">{e.parents_notifies_at ? ` · parents prevenus ${dateHeure(e.parents_notifies_at)}` : ' · parents non prevenus'}</span>
              </li>
            ))}
          </ul>
        </section>
      )}

      <section className="inc-section">
        <Fichiers entite="incident" entiteId={Number(id)} peutDeposer={!termine && (can('incidents', 'can_edit') || can('incidents', 'can_create'))} titre="Photos et documents" />
      </section>

      {i.categorie === 'accident' && (
        <section className="inc-section">
          <h2>Details de l'accident</h2>
          <div className="module-form">
            {[['tiers_implique', 'Tiers implique'], ['constat_amiable', 'Constat amiable'], ['rapport_police', 'Rapport de police'], ['blesses', 'Blesses'], ['vehicule_immobilise', 'Vehicule immobilise']].map(([k, l]) => (
              <label key={k} className="ch-check"><input type="checkbox" disabled={!peutModifier} checked={!!Number(acc[k] || 0)} onChange={(e) => setAcc({ ...acc, [k]: e.target.checked ? 1 : 0 })} /> {l}</label>
            ))}
            {[['tiers_nom', 'Nom du tiers'], ['tiers_telephone', 'Telephone du tiers'], ['tiers_immatriculation', 'Immatriculation du tiers'], ['tiers_assurance', 'Assurance du tiers'],
              ['reference_police', 'Reference police'], ['nb_blesses', 'Nombre de blesses'], ['degats_vehicule', 'Degats'], ['assurance_declaree_at', 'Declare a l\'assurance le'],
              ['reference_sinistre', 'Reference sinistre'], ['franchise', 'Franchise (FCFA)']].map(([k, l]) => (
              <label key={k} className="module-form-field"><span>{l}</span>
                <input type={k === 'assurance_declaree_at' ? 'date' : ['nb_blesses', 'franchise'].includes(k) ? 'number' : 'text'} disabled={!peutModifier} value={acc[k] ?? ''} onChange={(e) => setAcc({ ...acc, [k]: e.target.value })} />
              </label>
            ))}
            {peutModifier && <div className="module-form-actions"><button type="button" className="btn-transport" onClick={() => put({ action: 'accident', accident: acc }, 'Details enregistres')}>Enregistrer les details</button></div>}
          </div>
        </section>
      )}

      <section className="inc-section">
        <h2>Responsabilite</h2>
        <p>
          <strong>{RESPONSABILITES[i.responsabilite]}</strong>
          {i.qualifiee_at ? <span className="ma-muted"> · qualifiee le {dateHeure(i.qualifiee_at)}</span> : <span className="scanner-abo-warning"> · non qualifiee</span>}
        </p>
        {i.responsabilite_commentaire && <p className="inc-texte">{i.responsabilite_commentaire}</p>}
        {peutQualifier && (
          <div className="module-form">
            <label className="module-form-field"><span>Qualification</span>
              <select value={qualif.responsabilite} onChange={(e) => setQualif({ ...qualif, responsabilite: e.target.value })}>
                {Object.entries(RESPONSABILITES).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
              </select>
            </label>
            <label className="module-form-field"><span>Justification (obligatoire)</span><textarea rows={2} value={qualif.commentaire} onChange={(e) => setQualif({ ...qualif, commentaire: e.target.value })} /></label>
            <div className="module-form-actions"><button type="button" className="btn-transport" onClick={() => put({ action: 'qualifier', ...qualif }, 'Qualification enregistree')}>Qualifier</button></div>
            <p className="ma-muted">Decision humaine : l'application ne deduit jamais la responsabilite.</p>
          </div>
        )}
      </section>

      <section className="inc-section">
        <h2>Suivi</h2>
        <div className="module-form">
          <label className="module-form-field"><span>Action corrective</span><textarea rows={2} disabled={!peutModifier} value={suivi.action_corrective || ''} onChange={(e) => setSuivi({ ...suivi, action_corrective: e.target.value })} /></label>
          <label className="module-form-field"><span>Cout estime (FCFA)</span><input type="number" min="0" disabled={!peutModifier} value={suivi.cout_estime} onChange={(e) => setSuivi({ ...suivi, cout_estime: e.target.value })} /></label>
          <label className="module-form-field"><span>Cout reel (FCFA)</span><input type="number" min="0" disabled={!peutModifier} value={suivi.cout_reel} onChange={(e) => setSuivi({ ...suivi, cout_reel: e.target.value })} /></label>
          {peutModifier && <div className="module-form-actions"><button type="button" className="btn-transport" onClick={() => put({ action: 'suivi', ...suivi }, 'Suivi enregistre')}>Enregistrer le suivi</button></div>}
        </div>
        <h3>Actions</h3>
        <ul>
          {d.actions.map((a) => (
            <li key={a.id}>
              {a.description} <span className="ma-muted">{a.echeance ? `· echeance ${a.echeance}` : ''} · {a.statut}</span>
              {peutModifier && a.statut !== 'faite' && <button type="button" className="inc-lien" onClick={() => put({ action: 'action_statut', action_id: a.id, statut: 'faite' }, 'Action terminee')}>Marquer faite</button>}
            </li>
          ))}
          {d.actions.length === 0 && <li className="ma-muted">Aucune action.</li>}
        </ul>
        {peutModifier && (
          <div className="ch-filtres">
            <input placeholder="Nouvelle action" value={action.description} onChange={(e) => setAction({ ...action, description: e.target.value })} />
            <input type="date" value={action.echeance} onChange={(e) => setAction({ ...action, echeance: e.target.value })} />
            <button type="button" onClick={() => { put({ action: 'action_ajouter', ...action }, 'Action ajoutee'); setAction({ description: '', echeance: '' }) }}>Ajouter</button>
          </div>
        )}
      </section>

      {d.droits.modifier && (
        <section className="inc-section">
          <h2>Statut</h2>
          <div className="ch-filtres">
            <input placeholder="Motif (obligatoire pour annuler ou rouvrir)" value={motif} onChange={(e) => setMotif(e.target.value)} />
            {!termine && i.statut !== 'en_cours' && <button type="button" onClick={() => put({ action: 'statut', statut: 'en_cours', motif }, 'Incident pris en charge')}>Prendre en charge</button>}
            {!termine && i.statut !== 'resolu' && <button type="button" onClick={() => put({ action: 'statut', statut: 'resolu', motif }, 'Incident resolu')}>Resolu</button>}
            {!termine && <button type="button" className="btn-transport" onClick={() => put({ action: 'statut', statut: 'clos', motif }, 'Incident clos')}>Clore</button>}
            {!termine && <button type="button" onClick={() => put({ action: 'statut', statut: 'annule', motif }, 'Incident annule')}>Annuler (erreur)</button>}
            {termine && <button type="button" onClick={() => put({ action: 'statut', statut: 'ouvert', motif }, 'Incident rouvert')}>Rouvrir</button>}
          </div>
        </section>
      )}

      <section className="inc-section">
        <h2>Historique</h2>
        <ul className="inc-historique">
          {d.historique.map((h) => (
            <li key={h.id}><span className="ma-muted">{dateHeure(h.created_at)}</span> {h.action.replace(/_/g, ' ')} — {h.user_nom || ''}{h.motif ? ` : ${h.motif}` : ''}</li>
          ))}
        </ul>
      </section>
    </div>
  )
}
