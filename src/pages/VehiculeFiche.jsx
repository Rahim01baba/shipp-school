import { Fragment, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api } from '../api/client.js'
import { useAuth } from '../context/AuthContext.jsx'
import { Fichiers } from './incidentsCommun.jsx'

// Fiche vehicule (lot 5) : identification (immatriculation, marque, annee, type),
// documents (assurance, carte grise, visite technique) et historique des chauffeurs.
const TYPES = { assurance: 'Assurance', carte_grise: 'Carte grise', visite_technique: 'Visite technique', vignette: 'Vignette', autre: 'Autre' }
const ETATS = { a_verifier: 'A verifier', valide: 'Valide', refuse: 'Refuse', remplace: 'Remplace', expire: 'Expire', expire_bientot: 'Expire bientot' }
const CHAMPS = [
  ['immatriculation', 'Immatriculation', 'text'], ['marque', 'Marque', 'text'], ['modele', 'Modele', 'text'], ['annee', 'Annee', 'number'],
  ['type_vehicule', 'Type (ex. Van 10 pax, Berline)', 'text'], ['capacite', 'Places', 'number'], ['proprietaire', 'Proprietaire', 'text'], ['statut', 'Statut', 'text'],
]

export default function VehiculeFiche() {
  const { id } = useParams()
  const { can } = useAuth()
  const [vehicule, setVehicule] = useState(null)
  const [form, setForm] = useState({})
  const [docs, setDocs] = useState([])
  const [hist, setHist] = useState([])
  const [ouvert, setOuvert] = useState(null)
  const [error, setError] = useState(null)
  const [info, setInfo] = useState(null)
  const vide = { type: 'assurance', numero: '', organisme: '', date_debut: '', date_expiration: '' }
  const [doc, setDoc] = useState(vide)
  const peutEditer = can('vehicules', 'can_edit')

  async function load() {
    try {
      const v = await api.get('/crud.php?module=vehicules')
      const found = (v.data || []).find((x) => String(x.id) === String(id))
      setVehicule(found || null)
      setForm(found || {})
      if (can('vehicle_documents', 'can_read')) setDocs((await api.get(`/vehicle-documents.php?vehicle_id=${id}`)).data || [])
      if (can('vehicle_assignments', 'can_read')) setHist((await api.get(`/vehicle-assignments.php?vehicle_id=${id}`)).data || [])
    } catch (e) {
      setError(e.message)
    }
  }

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  async function run(fn, message) {
    setError(null)
    setInfo(null)
    try {
      const r = await fn()
      setInfo(message)
      await load()
      return r
    } catch (e) {
      setError(e.message)
      return null
    }
  }

  if (!vehicule) {
    return (
      <div className="page">
        <p><Link to="/modules/vehicules">&larr; Vehicules</Link></p>
        {error ? <p className="error-banner">{error}</p> : <p>Chargement...</p>}
      </div>
    )
  }

  return (
    <div className="page">
      <p><Link to="/modules/vehicules">&larr; Vehicules</Link></p>
      <h1>{vehicule.immatriculation}</h1>
      <p className="ma-muted">{[vehicule.marque, vehicule.modele, vehicule.annee, vehicule.type_vehicule].filter(Boolean).join(' · ')}</p>
      {error && <p className="error-banner">{error}</p>}
      {info && <p className="ma-info">{info}</p>}

      <section className="inc-section">
        <h2>Identification</h2>
        <form className="module-form" onSubmit={(e) => {
          e.preventDefault()
          const payload = { id: Number(id) }
          CHAMPS.forEach(([k]) => { payload[k] = form[k] === '' ? null : form[k] })
          run(() => api.put('/crud.php?module=vehicules', payload), 'Vehicule enregistre')
        }}>
          {CHAMPS.map(([k, l, t]) => (
            <label key={k} className="module-form-field"><span>{l}</span><input type={t} value={form[k] ?? ''} disabled={!peutEditer} onChange={(e) => setForm({ ...form, [k]: e.target.value })} /></label>
          ))}
          {peutEditer && <div className="module-form-actions"><button type="submit" className="btn-transport">Enregistrer</button></div>}
        </form>
      </section>

      {can('vehicle_documents', 'can_read') && (
        <section className="inc-section">
          <h2>Documents du vehicule</h2>
          {can('vehicle_documents', 'can_create') && (
            <form className="module-form" onSubmit={async (e) => {
              e.preventDefault()
              const r = await run(() => api.post('/vehicle-documents.php', { vehicle_id: Number(id), ...doc }), 'Document ajoute : joignez le scan')
              if (r) { setDoc(vide); setOuvert(r.id) }
            }}>
              <label className="module-form-field"><span>Type</span><select value={doc.type} onChange={(e) => setDoc({ ...doc, type: e.target.value })}>{Object.entries(TYPES).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></label>
              <label className="module-form-field"><span>Numero / police</span><input value={doc.numero} onChange={(e) => setDoc({ ...doc, numero: e.target.value })} /></label>
              <label className="module-form-field"><span>Organisme (assureur, centre)</span><input value={doc.organisme} onChange={(e) => setDoc({ ...doc, organisme: e.target.value })} /></label>
              <label className="module-form-field"><span>Valable du</span><input type="date" value={doc.date_debut} onChange={(e) => setDoc({ ...doc, date_debut: e.target.value })} /></label>
              <label className="module-form-field"><span>Expire le</span><input type="date" value={doc.date_expiration} onChange={(e) => setDoc({ ...doc, date_expiration: e.target.value })} /></label>
              <div className="module-form-actions"><button type="submit" className="btn-transport">Ajouter</button></div>
            </form>
          )}
          <div className="module-table-wrap">
            <table className="module-table">
              <thead><tr><th>Document</th><th>Numero</th><th>Organisme</th><th>Expiration</th><th>Etat</th><th /></tr></thead>
              <tbody>
                {docs.map((d) => (
                  <Fragment key={d.id}>
                    <tr className={d.statut === 'remplace' ? 'dc-remplace' : ''}>
                      <td>{TYPES[d.type] || d.type}</td>
                      <td>{d.numero || '-'}</td>
                      <td>{d.organisme || '-'}</td>
                      <td>{d.date_expiration || '-'}</td>
                      <td><span className={`inc-badge dc-etat-${d.etat}`}>{ETATS[d.etat] || d.etat}</span></td>
                      <td>
                        <button type="button" className="inc-lien" onClick={() => setOuvert(ouvert === d.id ? null : d.id)}>{d.fichier_nom ? 'Scan' : 'Joindre'}</button>
                        {can('vehicle_documents', 'can_validate') && d.statut === 'a_verifier' && (
                          <button type="button" className="inc-lien" onClick={() => run(() => api.put('/vehicle-documents.php', { id: d.id, action: 'valider' }), 'Document valide')}>Valider</button>
                        )}
                      </td>
                    </tr>
                    {ouvert === d.id && (
                      <tr><td colSpan={6}><Fichiers entite="vehicle_document" entiteId={d.id} peutDeposer={d.statut !== 'remplace' && can('vehicle_documents', 'can_create')} titre="Scan" onUpload={() => load()} /></td></tr>
                    )}
                  </Fragment>
                ))}
                {docs.length === 0 && <tr><td colSpan={6}>Aucun document : assurance, carte grise et visite technique a enregistrer.</td></tr>}
              </tbody>
            </table>
          </div>
        </section>
      )}

      {can('vehicle_assignments', 'can_read') && (
        <section className="inc-section">
          <h2>Chauffeurs affectes</h2>
          <ul>
            {hist.map((h) => (
              <li key={h.id}><Link to={`/chauffeurs/${h.chauffeur_id}`}>{h.chauffeur_nom}</Link> <span className="ma-muted">du {h.date_debut} au {h.date_fin || 'aujourd\'hui'} · {h.statut}</span></li>
            ))}
            {hist.length === 0 && <li className="ma-muted">Aucune affectation.</li>}
          </ul>
        </section>
      )}
    </div>
  )
}
