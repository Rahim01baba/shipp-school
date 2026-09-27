import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api } from '../api/client.js'
import { useAuth } from '../context/AuthContext.jsx'

// Liste des chauffeurs (fiches distinctes des comptes de connexion).
export default function Chauffeurs() {
  const { can, accessLoading } = useAuth()
  const navigate = useNavigate()
  const [rows, setRows] = useState([])
  const [q, setQ] = useState('')
  const [statut, setStatut] = useState('actif')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [showForm, setShowForm] = useState(false)
  const vide = { nom: '', prenom: '', telephone: '', email: '', date_entree: '', creer_compte: false, password: '' }
  const [form, setForm] = useState(vide)
  const canCreate = can('chauffeurs', 'can_create')

  async function load() {
    setLoading(true)
    setError(null)
    try {
      const params = new URLSearchParams()
      if (q.trim()) params.set('q', q.trim())
      if (statut) params.set('statut', statut)
      const res = await api.get('/chauffeurs.php?' + params.toString())
      setRows(res.data || [])
    } catch (e) {
      setError(e.message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    if (accessLoading) return
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [accessLoading, statut])

  async function creer(e) {
    e.preventDefault()
    setError(null)
    try {
      const res = await api.post('/chauffeurs.php', form)
      setForm(vide)
      setShowForm(false)
      navigate(`/chauffeurs/${res.id}`)
    } catch (err) {
      setError(err.message)
    }
  }

  return (
    <div className="page">
      <p><Link to="/">&larr; Tableau de bord</Link></p>
      <h1>Chauffeurs</h1>
      {error && <p className="error-banner">{error}</p>}
      <form className="ch-filtres" onSubmit={(e) => { e.preventDefault(); load() }}>
        <input type="search" placeholder="Nom ou telephone" value={q} onChange={(e) => setQ(e.target.value)} />
        <select value={statut} onChange={(e) => setStatut(e.target.value)}>
          <option value="actif">Actifs</option>
          <option value="suspendu">Suspendus</option>
          <option value="sorti">Sortis</option>
          <option value="">Tous</option>
        </select>
        <button type="submit">Rechercher</button>
        {canCreate && <button type="button" className="btn-transport" onClick={() => setShowForm(!showForm)}>Nouveau chauffeur</button>}
      </form>

      {showForm && (
        <form onSubmit={creer} className="module-form">
          <h2>Nouveau chauffeur</h2>
          <label className="module-form-field"><span>Nom *</span><input value={form.nom} onChange={(e) => setForm({ ...form, nom: e.target.value })} required /></label>
          <label className="module-form-field"><span>Prenom</span><input value={form.prenom} onChange={(e) => setForm({ ...form, prenom: e.target.value })} /></label>
          <label className="module-form-field"><span>Telephone *</span><input type="tel" value={form.telephone} onChange={(e) => setForm({ ...form, telephone: e.target.value })} required /></label>
          <label className="module-form-field"><span>E-mail (facultatif)</span><input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></label>
          <label className="module-form-field"><span>Date d'entree</span><input type="date" value={form.date_entree} onChange={(e) => setForm({ ...form, date_entree: e.target.value })} /></label>
          <label className="ch-check">
            <input type="checkbox" checked={form.creer_compte} onChange={(e) => setForm({ ...form, creer_compte: e.target.checked })} />
            Creer son compte de connexion (identifiant = telephone)
          </label>
          {form.creer_compte && (
            <label className="module-form-field"><span>Mot de passe initial (8 car. min.)</span><input type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} /></label>
          )}
          <div className="module-form-actions">
            <button type="submit" className="btn-transport">Creer la fiche</button>
            <button type="button" onClick={() => setShowForm(false)}>Annuler</button>
          </div>
        </form>
      )}

      {loading ? (
        <p>Chargement...</p>
      ) : (
        <div className="module-table-wrap">
          <table className="module-table">
            <thead>
              <tr><th>Chauffeur</th><th>Telephone</th><th>Vehicule actuel</th><th>Circuits</th><th>Compte</th><th>Statut</th></tr>
            </thead>
            <tbody>
              {rows.map((c) => (
                <tr key={c.id}>
                  <td><Link to={`/chauffeurs/${c.id}`} className="module-table-link">{[c.prenom, c.nom].filter(Boolean).join(' ')}</Link></td>
                  <td>{c.telephone || '-'}</td>
                  <td>{c.vehicule_actuel ? c.vehicule_actuel.immatriculation : <span className="scanner-abo-warning">aucun</span>}</td>
                  <td>{(c.circuits || []).map((x) => x.nom).join(', ') || '-'}</td>
                  <td>{c.a_un_compte ? 'Oui' : 'Non'}</td>
                  <td>{c.statut}</td>
                </tr>
              ))}
              {rows.length === 0 && <tr><td colSpan={6}>Aucun chauffeur.</td></tr>}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
