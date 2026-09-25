import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api } from '../api/client.js'
import { useAuth } from '../context/AuthContext.jsx'

// Dossier chauffeur : identite, affectations vehicule (historisees), et —
// selon les droits — documents, contrat d'utilisation, incidents, alertes.

const CHAMPS = [
  ['nom', 'Nom', 'text'], ['prenom', 'Prenom', 'text'], ['telephone', 'Telephone', 'tel'], ['email', 'E-mail', 'email'],
  ['adresse', 'Adresse', 'text'], ['date_naissance', 'Date de naissance', 'date'], ['urgence_nom', "Contact d'urgence", 'text'],
  ['urgence_telephone', "Telephone d'urgence", 'tel'], ['date_entree', "Date d'entree", 'date'], ['perimetre', 'Perimetre / etablissement', 'text'],
]

export function OngletVehicules({ chauffeur, onChange }) {
  const { can } = useAuth()
  const [hist, setHist] = useState([])
  const [vehicules, setVehicules] = useState([])
  const [form, setForm] = useState({ vehicle_id: '', date_debut: new Date().toISOString().slice(0, 10), motif: '' })
  const [error, setError] = useState(null)
  const canCreate = can('vehicle_assignments', 'can_create')

  async function load() {
    try {
      const res = await api.get(`/vehicle-assignments.php?chauffeur_id=${chauffeur.id}`)
      setHist(res.data || [])
      if (canCreate) {
        const v = await api.get('/crud.php?module=vehicules')
        setVehicules(v.data || [])
      }
    } catch (e) {
      setError(e.message)
    }
  }

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [chauffeur.id])

  async function affecter(e) {
    e.preventDefault()
    setError(null)
    try {
      await api.post('/vehicle-assignments.php', { chauffeur_id: chauffeur.id, ...form, vehicle_id: Number(form.vehicle_id) })
      setForm({ ...form, vehicle_id: '', motif: '' })
      await load()
      onChange && onChange()
    } catch (err) {
      setError(err.message)
    }
  }

  async function terminer(id) {
    setError(null)
    try {
      await api.put('/vehicle-assignments.php', { id, action: 'terminer' })
      await load()
      onChange && onChange()
    } catch (err) {
      setError(err.message)
    }
  }

  return (
    <div>
      {error && <p className="error-banner">{error}</p>}
      {canCreate && (
        <form onSubmit={affecter} className="module-form">
          <h3>Affecter un vehicule</h3>
          <label className="module-form-field">
            <span>Vehicule</span>
            <select value={form.vehicle_id} onChange={(e) => setForm({ ...form, vehicle_id: e.target.value })} required>
              <option value="">-- Choisir --</option>
              {vehicules.map((v) => (
                <option key={v.id} value={v.id}>{v.immatriculation} {v.modele ? `(${v.modele})` : ''} {v.statut !== 'actif' ? `- ${v.statut}` : ''}</option>
              ))}
            </select>
          </label>
          <label className="module-form-field"><span>A partir du</span><input type="date" value={form.date_debut} onChange={(e) => setForm({ ...form, date_debut: e.target.value })} required /></label>
          <label className="module-form-field"><span>Motif</span><input value={form.motif} onChange={(e) => setForm({ ...form, motif: e.target.value })} /></label>
          <div className="module-form-actions"><button type="submit" className="btn-transport">Affecter</button></div>
          <p className="ma-muted">L'affectation en cours sera cloturee la veille ; l'historique est conserve.</p>
        </form>
      )}
      <div className="module-table-wrap">
        <table className="module-table">
          <thead><tr><th>Vehicule</th><th>Du</th><th>Au</th><th>Statut</th><th>Motif</th>{canCreate && <th />}</tr></thead>
          <tbody>
            {hist.map((h) => (
              <tr key={h.id}>
                <td>{h.immatriculation} <span className="ma-muted">{h.modele}</span></td>
                <td>{h.date_debut}</td>
                <td>{h.date_fin || '—'}</td>
                <td>{h.statut}</td>
                <td>{h.motif || ''}</td>
                {canCreate && (
                  <td>{['active', 'planifiee'].includes(h.statut) && <button type="button" onClick={() => terminer(h.id)}>Terminer</button>}</td>
                )}
              </tr>
            ))}
            {hist.length === 0 && <tr><td colSpan={6}>Aucune affectation.</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}

export default function ChauffeurFiche({ extraTabs = [] }) {
  const { id } = useParams()
  const { can } = useAuth()
  const [chauffeur, setChauffeur] = useState(null)
  const [form, setForm] = useState({})
  const [onglet, setOnglet] = useState('identite')
  const [error, setError] = useState(null)
  const [info, setInfo] = useState(null)
  const canEdit = can('chauffeurs', 'can_edit')

  async function load() {
    try {
      const res = await api.get(`/chauffeurs.php?id=${id}`)
      setChauffeur(res.data)
      setForm(res.data)
    } catch (e) {
      setError(e.message)
    }
  }

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  async function enregistrer(e) {
    e.preventDefault()
    setError(null)
    setInfo(null)
    try {
      const payload = { id: Number(id) }
      CHAMPS.forEach(([k]) => { payload[k] = form[k] ?? '' })
      payload.statut = form.statut
      await api.put('/chauffeurs.php', payload)
      setInfo('Fiche enregistree')
      await load()
    } catch (err) {
      setError(err.message)
    }
  }

  if (!chauffeur) {
    return (
      <div className="page">
        <p><Link to="/chauffeurs">&larr; Chauffeurs</Link></p>
        {error ? <p className="error-banner">{error}</p> : <p>Chargement...</p>}
      </div>
    )
  }

  const tabs = [
    { key: 'identite', label: 'Identite' },
    { key: 'vehicules', label: 'Vehicules' },
    ...extraTabs.filter((t) => !t.visible || t.visible(can)),
  ]
  const courant = tabs.find((t) => t.key === onglet)

  return (
    <div className="page">
      <p><Link to="/chauffeurs">&larr; Chauffeurs</Link></p>
      <h1>{[chauffeur.prenom, chauffeur.nom].filter(Boolean).join(' ')}</h1>
      <p className="ma-muted">
        {chauffeur.statut} · {chauffeur.telephone || 'sans telephone'}
        {chauffeur.vehicule_actuel && ` · vehicule ${chauffeur.vehicule_actuel.immatriculation}`}
        {chauffeur.circuits?.length > 0 && ` · circuits : ${chauffeur.circuits.map((c) => c.nom).join(', ')}`}
        {chauffeur.a_un_compte ? ' · compte actif' : ' · sans compte'}
      </p>
      <div className="scanner-tabs">
        {tabs.map((t) => (
          <button key={t.key} type="button" className={onglet === t.key ? 'active' : ''} onClick={() => setOnglet(t.key)}>{t.label}</button>
        ))}
      </div>
      {error && <p className="error-banner">{error}</p>}
      {info && <p className="ma-info">{info}</p>}

      {onglet === 'identite' && (
        <form onSubmit={enregistrer} className="module-form">
          {CHAMPS.map(([k, label, type]) => (
            <label key={k} className="module-form-field">
              <span>{label}</span>
              <input type={type} value={form[k] ?? ''} disabled={!canEdit} onChange={(e) => setForm({ ...form, [k]: e.target.value })} />
            </label>
          ))}
          <label className="module-form-field">
            <span>Statut</span>
            <select value={form.statut || 'actif'} disabled={!canEdit} onChange={(e) => setForm({ ...form, statut: e.target.value })}>
              <option value="actif">Actif</option>
              <option value="suspendu">Suspendu</option>
              <option value="sorti">Sorti</option>
            </select>
          </label>
          {canEdit && <div className="module-form-actions"><button type="submit" className="btn-transport">Enregistrer</button></div>}
        </form>
      )}
      {onglet === 'vehicules' && <OngletVehicules chauffeur={chauffeur} onChange={load} />}
      {courant && courant.render && courant.render(chauffeur, load)}
    </div>
  )
}
