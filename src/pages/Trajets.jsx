import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client.js'
import { useAuth } from '../context/AuthContext.jsx'

const STATUT_LABELS = {
  planifie: 'Planifie',
  en_cours: 'En cours',
  termine: 'Termine',
  annule: 'Annule',
}

const API_URL = import.meta.env.VITE_API_URL || '/api'

export default function Trajets() {
  const { can, accessLoading, scopeOf } = useAuth()
  const [gen, setGen] = useState({ date: new Date().toISOString().slice(0, 10), sens: 'les_deux' })
  const [genInfo, setGenInfo] = useState(null)
  const [circuits, setCircuits] = useState([])
  const [etapes, setEtapes] = useState([])
  const [trajets, setTrajets] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [form, setForm] = useState({ circuit_id: '', date_trajet: '' })
  const [saving, setSaving] = useState(false)
  const [acting, setActing] = useState(null)

  const canCreate = can('trajets', 'can_create')
  const canEdit = can('trajets', 'can_edit')

  async function load() {
    setLoading(true)
    setError(null)
    try {
      const [circuitsRes, etapesRes, trajetsRes] = await Promise.all([
        can('circuits', 'can_read') ? api.get('/crud.php?module=circuits') : Promise.resolve({ data: [] }),
        can('etapes', 'can_read') ? api.get('/crud.php?module=etapes') : Promise.resolve({ data: [] }),
        api.get('/crud.php?module=trajets'),
      ])
      setCircuits(circuitsRes.data || [])
      setEtapes(etapesRes.data || [])
      setTrajets(trajetsRes.data || [])
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
  }, [accessLoading])

  function circuitNom(circuitId) {
    const c = circuits.find((c) => String(c.id) === String(circuitId))
    return c ? c.nom : `Circuit #${circuitId}`
  }

  function etapeNom(etapeId) {
    if (!etapeId) return '-'
    const e = etapes.find((e) => String(e.id) === String(etapeId))
    return e ? e.nom : `Etape #${etapeId}`
  }

  async function createTrajet(e) {
    e.preventDefault()
    if (!form.circuit_id || !form.date_trajet) return
    setSaving(true)
    setError(null)
    try {
      await api.post('/crud.php?module=trajets', {
        circuit_id: form.circuit_id,
        date_trajet: form.date_trajet,
        statut: 'planifie',
      })
      setForm({ circuit_id: '', date_trajet: '' })
      await load()
    } catch (e) {
      setError(e.message)
    } finally {
      setSaving(false)
    }
  }

  async function generer(e) {
    e.preventDefault()
    setError(null)
    setGenInfo(null)
    try {
      const res = await api.post('/trajets-generer.php', gen)
      setGenInfo(`${res.crees.length} trajet(s) cree(s), ${res.deja_existants} deja existant(s).`)
      await load()
    } catch (err) {
      setError(err.message)
    }
  }

  async function doAction(trajetId, action) {
    setActing(trajetId)
    setError(null)
    try {
      const token = localStorage.getItem('shipp_token')
      const res = await fetch(`${API_URL}/trajet-avancer.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        body: JSON.stringify({ trajet_id: trajetId, action }),
      })
      const data = await res.json()
      if (!res.ok) throw new Error(data.message || 'Erreur')
      await load()
    } catch (e) {
      setError(e.message)
    } finally {
      setActing(null)
    }
  }

  return (
    <div className="page">
      <p>
        <Link to="/">&larr; Tableau de bord</Link>
      </p>
      <h1>Trajets</h1>
      {error && <p className="error-banner">{error}</p>}

      {canCreate && ['GLOBAL', 'SCHOOL'].includes(scopeOf('trajets')) && (
        <form onSubmit={generer} className="module-form">
          <h2>Generer les trajets d'une journee</h2>
          <label className="module-form-field"><span>Date</span><input type="date" value={gen.date} onChange={(e) => setGen({ ...gen, date: e.target.value })} /></label>
          <label className="module-form-field">
            <span>Sens</span>
            <select value={gen.sens} onChange={(e) => setGen({ ...gen, sens: e.target.value })}>
              <option value="les_deux">Aller et retour</option>
              <option value="aller">Aller</option>
              <option value="retour">Retour</option>
            </select>
          </label>
          <div className="module-form-actions"><button type="submit" className="btn-transport">Generer pour tous les circuits actifs</button></div>
          {genInfo && <p className="ma-info">{genInfo}</p>}
        </form>
      )}

      {canCreate && (
        <form onSubmit={createTrajet} className="module-form">
          <label className="module-form-field">
            <span>Circuit</span>
            <select value={form.circuit_id} onChange={(e) => setForm({ ...form, circuit_id: e.target.value })}>
              <option value="">-- Choisir --</option>
              {circuits.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.nom}
                </option>
              ))}
            </select>
          </label>
          <label className="module-form-field">
            <span>Date</span>
            <input
              type="date"
              value={form.date_trajet}
              onChange={(e) => setForm({ ...form, date_trajet: e.target.value })}
            />
          </label>
          <div className="module-form-actions">
            <button type="submit" className="btn-transport" disabled={saving}>
              {saving ? 'Creation...' : 'Planifier le trajet'}
            </button>
          </div>
        </form>
      )}

      {loading ? (
        <p>Chargement...</p>
      ) : (
        <div className="module-table-wrap">
          <table className="module-table">
            <thead>
              <tr>
                <th>Circuit</th>
                <th>Date</th>
                <th>Sens</th>
                <th>Statut</th>
                <th>Etape courante</th>
                {canEdit && <th>Actions</th>}
              </tr>
            </thead>
            <tbody>
              {trajets.map((t) => (
                <tr key={t.id}>
                  <td>{circuitNom(t.circuit_id)}</td>
                  <td>{t.date_trajet}</td>
                  <td>{t.sens || '-'}</td>
                  <td>{STATUT_LABELS[t.statut] || t.statut}</td>
                  <td>{etapeNom(t.etape_courante_id)}</td>
                  {canEdit && (
                    <td className="module-table-actions">
                      {t.statut === 'planifie' && (
                        <button type="button" className="btn-transport" disabled={acting === t.id} onClick={() => doAction(t.id, 'demarrer')}>
                          Demarrer le trajet
                        </button>
                      )}
                      {t.statut === 'en_cours' && (
                        <>
                          <button type="button" className="btn-transport" disabled={acting === t.id} onClick={() => doAction(t.id, 'avancer')}>
                            Etape suivante
                          </button>
                          <button type="button" className="btn-transport" disabled={acting === t.id} onClick={() => doAction(t.id, 'cloturer')}>
                            Cloturer le trajet
                          </button>
                        </>
                      )}
                      {t.statut === 'planifie' && (
                        <button type="button" className="btn-transport" disabled={acting === t.id} onClick={() => doAction(t.id, 'annuler')}>
                          Annuler le trajet
                        </button>
                      )}
                    </td>
                  )}
                </tr>
              ))}
              {trajets.length === 0 && (
                <tr>
                  <td colSpan={canEdit ? 6 : 5}>Aucun trajet.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
