import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client.js'
import { useAuth } from '../context/AuthContext.jsx'
import { Badge, CATEGORIES, GRAVITES, RESPONSABILITES, STATUTS_INCIDENT, dateHeure } from './incidentsCommun.jsx'

// Registre des incidents et accidents, filtre cote serveur.
// Liste reutilisable (dossier chauffeur) via la prop `filtreFixe`.
export function ListeIncidents({ filtreFixe = {}, compact = false }) {
  const { accessLoading } = useAuth()
  const [filtres, setFiltres] = useState({ date_debut: '', date_fin: '', categorie: '', statut: '', gravite: '', responsabilite: '', q: '' })
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  async function load() {
    setLoading(true)
    setError(null)
    try {
      const p = new URLSearchParams()
      Object.entries({ ...filtres, ...filtreFixe }).forEach(([k, v]) => { if (v) p.set(k, v) })
      if (!filtres.date_debut && !filtres.date_fin && filtreFixe.chauffeur_id) p.set('annee_scolaire_id', 'toutes')
      const res = await api.get('/incidents.php?' + p.toString())
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
  }, [accessLoading, JSON.stringify(filtreFixe)])

  const set = (k) => (e) => setFiltres({ ...filtres, [k]: e.target.value })

  return (
    <div>
      {error && <p className="error-banner">{error}</p>}
      <form className="ch-filtres" onSubmit={(e) => { e.preventDefault(); load() }}>
        <label className="inc-filtre-date">Du <input type="date" value={filtres.date_debut} onChange={set('date_debut')} /></label>
        <label className="inc-filtre-date">au <input type="date" value={filtres.date_fin} onChange={set('date_fin')} /></label>
        <select value={filtres.categorie} onChange={set('categorie')}><option value="">Incidents et accidents</option>{Object.entries(CATEGORIES).map(([k, l]) => <option key={k} value={k}>{l}s</option>)}</select>
        <select value={filtres.statut} onChange={set('statut')}><option value="">Tous statuts</option>{Object.entries(STATUTS_INCIDENT).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
        {!compact && (
          <>
            <select value={filtres.gravite} onChange={set('gravite')}><option value="">Toutes gravites</option>{Object.entries(GRAVITES).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
            <select value={filtres.responsabilite} onChange={set('responsabilite')}><option value="">Toute responsabilite</option>{Object.entries(RESPONSABILITES).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
            <input type="search" placeholder="Recherche" value={filtres.q} onChange={set('q')} />
          </>
        )}
        <button type="submit">Filtrer</button>
      </form>
      {loading ? <p>Chargement...</p> : (
        <div className="module-table-wrap">
          <table className="module-table">
            <thead>
              <tr><th>Date</th><th>Nature</th><th>Titre</th>{!compact && <th>Chauffeur</th>}<th>Vehicule</th><th>Gravite</th><th>Responsabilite</th><th>Statut</th></tr>
            </thead>
            <tbody>
              {rows.map((i) => (
                <tr key={i.id}>
                  <td>{dateHeure(i.survenu_at || i.date_incident || i.created_at)}</td>
                  <td><Badge value={i.categorie} map={CATEGORIES} prefix="inc-cat" /></td>
                  <td><Link to={`/incidents/${i.id}`} className="module-table-link">{i.titre}</Link>{Number(i.nb_eleves) > 0 && <span className="ma-muted"> · {i.nb_eleves} eleve(s)</span>}</td>
                  {!compact && <td>{i.chauffeur_nom || '-'}</td>}
                  <td>{i.immatriculation || '-'}</td>
                  <td><Badge value={i.gravite} map={GRAVITES} prefix="inc-grav" /></td>
                  <td>{RESPONSABILITES[i.responsabilite] || i.responsabilite}{i.qualifiee_at ? '' : ' (a qualifier)'}</td>
                  <td><Badge value={i.statut} map={STATUTS_INCIDENT} prefix="inc-st" /></td>
                </tr>
              ))}
              {rows.length === 0 && <tr><td colSpan={8}>Aucun incident sur la periode.</td></tr>}
            </tbody>
          </table>
        </div>
      )}
      {!filtres.date_debut && !filtres.date_fin && !filtreFixe.chauffeur_id && <p className="ma-muted">Sans dates, seule l'annee scolaire active est affichee.</p>}
    </div>
  )
}

export default function Incidents() {
  const { can } = useAuth()
  return (
    <div className="page">
      <p><Link to="/">&larr; Tableau de bord</Link></p>
      <h1>Incidents et accidents</h1>
      {can('incidents', 'can_create') && <p><Link to="/incidents/nouveau" className="module-link">Declarer un incident ou un accident</Link></p>}
      <ListeIncidents />
    </div>
  )
}
