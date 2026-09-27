import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client.js'
import { useAuth } from '../context/AuthContext.jsx'

// Remunerations et retenues chiffrees (lot 5, D-30). Donnees sensibles : admin et RH.
const fcfa = (v) => (v === null || v === undefined ? '-' : `${Math.round(Number(v)).toLocaleString('fr-FR')} FCFA`)
const STATUTS = { proposee: 'Proposee', validee: 'Validee', annulee: 'Annulee' }

// Retenues d'un chauffeur (onglet Contrat du dossier).
export function RetenuesChauffeur({ chauffeur }) {
  const { can } = useAuth()
  const [liste, setListe] = useState([])
  const [incidents, setIncidents] = useState([])
  const [error, setError] = useState(null)
  const vide = { periode: new Date().toISOString().slice(0, 7), montant: '', motif: '', date_fait: '', incident_id: '' }
  const [form, setForm] = useState(vide)

  async function load() {
    try {
      setListe((await api.get(`/chauffeur-retenues.php?chauffeur_id=${chauffeur.id}`)).data || [])
      if (can('incidents', 'can_read')) {
        const r = await api.get(`/incidents.php?chauffeur_id=${chauffeur.id}&annee_scolaire_id=toutes`).catch(() => ({ data: [] }))
        setIncidents((r.data || []).filter((i) => i.qualifiee_at && ['chauffeur', 'partagee'].includes(i.responsabilite)))
      }
    } catch (e) {
      setError(e.message)
    }
  }

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [chauffeur.id])

  async function act(fn) {
    setError(null)
    try {
      await fn()
      await load()
    } catch (e) {
      setError(e.message)
    }
  }

  return (
    <section className="inc-section">
      <h3>Retenues sur la remuneration</h3>
      {error && <p className="error-banner">{error}</p>}
      {can('chauffeur_contracts', 'can_edit') && (
        <form className="ch-filtres" onSubmit={(e) => {
          e.preventDefault()
          act(async () => {
            await api.post('/chauffeur-retenues.php', { chauffeur_id: chauffeur.id, ...form, incident_id: form.incident_id ? Number(form.incident_id) : undefined })
            setForm(vide)
          })
        }}>
          <input type="month" value={form.periode} onChange={(e) => setForm({ ...form, periode: e.target.value })} required />
          <input type="number" min="1" placeholder="Montant FCFA" value={form.montant} onChange={(e) => setForm({ ...form, montant: e.target.value })} required />
          <input placeholder="Motif" value={form.motif} onChange={(e) => setForm({ ...form, motif: e.target.value })} required />
          <input type="date" title="Date des faits" value={form.date_fait} onChange={(e) => setForm({ ...form, date_fait: e.target.value })} />
          <select value={form.incident_id} onChange={(e) => setForm({ ...form, incident_id: e.target.value })}>
            <option value="">Sans incident lie</option>
            {incidents.map((i) => <option key={i.id} value={i.id}>{String(i.date_incident || '').slice(0, 10)} {i.titre}</option>)}
          </select>
          <button type="submit">Proposer</button>
        </form>
      )}
      <p className="ma-muted">Seuls les incidents dont la responsabilite a ete qualifiee « chauffeur » ou « partagee » peuvent justifier une retenue. Une retenue n'est deduite qu'une fois validee.</p>
      <table className="module-table">
        <thead><tr><th>Mois</th><th>Motif</th><th>Montant</th><th>Statut</th><th /></tr></thead>
        <tbody>
          {liste.map((r) => (
            <tr key={r.id}>
              <td>{String(r.periode).slice(0, 7)}</td>
              <td>{r.motif}{r.incident_titre && <span className="ma-muted"> · incident : {r.incident_titre}</span>}{r.motif_annulation && <span className="ma-muted"> · annulee : {r.motif_annulation}</span>}</td>
              <td>{fcfa(r.montant)}</td>
              <td>{STATUTS[r.statut]}</td>
              <td>
                {can('chauffeur_contracts', 'can_validate') && r.statut === 'proposee' && <button type="button" className="inc-lien" onClick={() => act(() => api.put('/chauffeur-retenues.php', { id: r.id, action: 'valider' }))}>Valider</button>}
                {can('chauffeur_contracts', 'can_validate') && r.statut !== 'annulee' && (
                  <button type="button" className="inc-lien" onClick={() => { const m = window.prompt("Motif d'annulation"); if (m) act(() => api.put('/chauffeur-retenues.php', { id: r.id, action: 'annuler', motif: m })) }}>Annuler</button>
                )}
              </td>
            </tr>
          ))}
          {liste.length === 0 && <tr><td colSpan={5}>Aucune retenue.</td></tr>}
        </tbody>
      </table>
    </section>
  )
}

export default function Remunerations() {
  const [mois, setMois] = useState(new Date().toISOString().slice(0, 7))
  const [rows, setRows] = useState([])
  const [error, setError] = useState(null)

  useEffect(() => {
    setError(null)
    api.get(`/chauffeur-retenues.php?etat=${mois}`).then((r) => setRows(r.data || [])).catch((e) => setError(e.message))
  }, [mois])

  const total = (k) => rows.reduce((s, r) => s + Number(r[k] || 0), 0)

  return (
    <div className="page">
      <p><Link to="/chauffeurs">&larr; Chauffeurs</Link></p>
      <h1>Remunerations des chauffeurs</h1>
      {error && <p className="error-banner">{error}</p>}
      <p><input type="month" value={mois} onChange={(e) => setMois(e.target.value)} /></p>
      <div className="module-table-wrap">
        <table className="module-table">
          <thead><tr><th>Chauffeur</th><th>Contrat</th><th>Remuneration</th><th>Retenues validees</th><th>Net</th><th>A valider</th></tr></thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.contract_id}>
                <td><Link to={`/chauffeurs/${r.chauffeur_id}`}>{r.chauffeur}</Link></td>
                <td>{r.reference}</td>
                <td>{fcfa(r.remuneration_montant)}{r.remuneration_periodicite ? ` / ${r.remuneration_periodicite}` : ''}</td>
                <td>{fcfa(r.retenues)}</td>
                <td>{fcfa(r.net)}{r.depassement && <span className="scanner-abo-warning"> retenues superieures a la remuneration</span>}</td>
                <td>{Number(r.en_attente) || ''}</td>
              </tr>
            ))}
            {rows.length === 0 && <tr><td colSpan={6}>Aucun contrat en vigueur ce mois.</td></tr>}
          </tbody>
          {rows.length > 0 && (
            <tfoot><tr><td colSpan={2}>Total</td><td>{fcfa(total('remuneration_montant'))}</td><td>{fcfa(total('retenues'))}</td><td>{fcfa(total('net'))}</td><td /></tr></tfoot>
          )}
        </table>
      </div>
    </div>
  )
}
