import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client.js'
import { useAuth } from '../context/AuthContext.jsx'
import Icon from '../components/Icon.jsx'
import StatutBadge from '../components/StatutBadge.jsx'
import { Vide } from './Dashboard.jsx'

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
  const [panneau, setPanneau] = useState(null)

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

  const jour = new Date().toISOString().slice(0, 10)
  const duJour = trajets.filter((t) => t.date_trajet === jour && t.statut !== 'annule')
    .sort((a, b) => (a.sens === 'retour') - (b.sens === 'retour') || circuitNom(a.circuit_id).localeCompare(circuitNom(b.circuit_id)))
  const arretsDe = (t) => {
    const l = etapes.filter((e) => String(e.circuit_id) === String(t.circuit_id) && (e.statut || 'active') === 'active').sort((a, b) => Number(a.ordre) - Number(b.ordre))
    return t.sens === 'retour' ? l.reverse() : l
  }
  const heureDe = (t) => {
    const c = circuits.find((x) => String(x.id) === String(t.circuit_id))
    const h = t.sens === 'retour' ? c?.heure_retour : c?.heure_depart
    return h ? String(h).slice(0, 5) : null
  }
  const peutGenerer = canCreate && ['GLOBAL', 'SCHOOL'].includes(scopeOf('trajets'))

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <h1>Trajets</h1>
          <p className="page-header-sous">Exécution quotidienne des circuits : matin (domicile → école) et soir (école → domicile).</p>
        </div>
        <div className="section-tete">
          {peutGenerer && <button type="button" className="btn btn-primary" aria-expanded={panneau === 'generer'} onClick={() => setPanneau(panneau === 'generer' ? null : 'generer')}><Icon name="refresh" size={18} />Générer une journée</button>}
          {canCreate && <button type="button" className="btn btn-secondary" aria-expanded={panneau === 'planifier'} onClick={() => setPanneau(panneau === 'planifier' ? null : 'planifier')}><Icon name="plus" size={18} />Planifier un trajet</button>}
        </div>
      </header>
      {error && <p className="error-banner">{error}</p>}
      {genInfo && <p className="ma-info">{genInfo}</p>}

      {peutGenerer && panneau === 'generer' && (
        <form onSubmit={generer} className="module-form">
          <h2 className="form-titre">Générer les trajets d'une journée</h2>
          <label className="module-form-field"><span>Date *</span><input type="date" required value={gen.date} onChange={(e) => setGen({ ...gen, date: e.target.value })} /></label>
          <label className="module-form-field">
            <span>Sens</span>
            <select value={gen.sens} onChange={(e) => setGen({ ...gen, sens: e.target.value })}>
              <option value="les_deux">Matin et soir</option>
              <option value="aller">Matin (aller)</option>
              <option value="retour">Soir (retour)</option>
            </select>
          </label>
          <div className="module-form-actions">
            <button type="button" className="btn btn-ghost" onClick={() => setPanneau(null)}>Annuler</button>
            <button type="submit" className="btn-transport">Générer pour tous les circuits actifs</button>
          </div>
        </form>
      )}

      {canCreate && panneau === 'planifier' && (
        <form onSubmit={createTrajet} className="module-form">
          <h2 className="form-titre">Planifier un trajet</h2>
          <label className="module-form-field">
            <span>Circuit *</span>
            <select required value={form.circuit_id} onChange={(e) => setForm({ ...form, circuit_id: e.target.value })}>
              <option value="">-- Choisir --</option>
              {circuits.map((c) => <option key={c.id} value={c.id}>{c.nom}</option>)}
            </select>
          </label>
          <label className="module-form-field">
            <span>Date *</span>
            <input type="date" required value={form.date_trajet} onChange={(e) => setForm({ ...form, date_trajet: e.target.value })} />
          </label>
          <div className="module-form-actions">
            <button type="button" className="btn btn-ghost" onClick={() => setPanneau(null)}>Annuler</button>
            <button type="submit" className="btn-transport" disabled={saving}>{saving ? 'Création...' : 'Planifier le trajet'}</button>
          </div>
        </form>
      )}

      <section className="section">
        <h2 className="titre-section">Aujourd'hui</h2>
        {loading ? <div className="tj-jour">{[1, 2].map((n) => <div key={n} className="tj-carte"><span className="squelette squelette-titre" /><span className="squelette squelette-valeur" /></div>)}</div>
          : duJour.length === 0 ? <div className="card"><Vide icone="route" titre="Aucun trajet aujourd'hui" texte={peutGenerer ? 'Générez les trajets du jour pour les circuits actifs.' : "Aucun trajet n'est prévu aujourd'hui."} /></div>
          : (
            <div className="tj-jour">
              {duJour.map((t) => {
                const arrets = arretsDe(t)
                const iCourant = arrets.findIndex((a) => String(a.id) === String(t.etape_courante_id))
                const h = heureDe(t)
                return (
                  <article key={t.id} className="tj-carte">
                    <div className="tj-tete">
                      <div>
                        <span className="tj-moment"><Icon name={t.sens === 'retour' ? 'moon' : 'sun'} size={16} />{t.sens === 'retour' ? 'Soir' : t.sens === 'aller' ? 'Matin' : 'Trajet'}{h && ` · ${h}`}</span>
                        <p className="tj-circuit">{circuitNom(t.circuit_id)}</p>
                      </div>
                      <StatutBadge valeur={t.statut} />
                    </div>
                    {arrets.length > 0 ? (
                      <ol className="tj-sequence">
                        {arrets.map((a, i) => (
                          <li key={a.id} className={[i === iCourant && t.statut === 'en_cours' ? 'tj-courant' : '', iCourant >= 0 && i < iCourant ? 'tj-passe' : '', /[ée]cole/i.test(a.nom) ? 'tj-ecole' : ''].join(' ')}>
                            {a.nom}{t.sens !== 'retour' && a.heure_estimee ? <span className="texte-discret"> · {String(a.heure_estimee).slice(0, 5)}</span> : null}
                          </li>
                        ))}
                      </ol>
                    ) : <p className="texte-discret">Aucun arrêt défini pour ce circuit.</p>}
                    {can('suivi_gps', 'can_read') && ['en_cours', 'termine'].includes(t.statut) && <Link className="btn btn-ghost btn-sm" to={`/suivi-flotte/trajet/${t.id}`}><Icon name="map" size={16} />Suivi GPS</Link>}
                  </article>
                )
              })}
            </div>
          )}
      </section>

      <h2 className="titre-section">Tous les trajets</h2>
      {loading ? (
        <p className="texte-discret">Chargement...</p>
      ) : (
        <div className="module-table-wrap">
          <table className="module-table">
            <thead>
              <tr>
                <th>Circuit</th>
                <th>Date</th>
                <th>Trajet</th>
                <th>Statut</th>
                <th>Étape courante</th>
                {canEdit && <th>Actions</th>}
              </tr>
            </thead>
            <tbody>
              {trajets.map((t) => (
                <tr key={t.id}>
                  <td><strong>{circuitNom(t.circuit_id)}</strong></td>
                  <td>{t.date_trajet}</td>
                  <td>{t.sens === 'retour' ? <span className="badge badge-neutral">Soir · retour</span> : t.sens === 'aller' ? <span className="badge badge-info">Matin · aller</span> : '—'}</td>
                  <td>
                    <StatutBadge valeur={t.statut} />
                    {can('suivi_gps', 'can_read') && ['en_cours', 'termine'].includes(t.statut) && <> <Link className="module-table-link" to={`/suivi-flotte/trajet/${t.id}`}>GPS</Link></>}
                  </td>
                  <td>{etapeNom(t.etape_courante_id)}</td>
                  {canEdit && (
                    <td className="module-table-actions">
                      {t.statut === 'planifie' && (
                        <button type="button" className="btn-transport" disabled={acting === t.id} onClick={() => doAction(t.id, 'demarrer')}>Démarrer</button>
                      )}
                      {t.statut === 'en_cours' && (
                        <>
                          <button type="button" className="btn-transport" disabled={acting === t.id} onClick={() => doAction(t.id, 'avancer')}>Étape suivante</button>
                          <button type="button" className="btn-transport" disabled={acting === t.id} onClick={() => doAction(t.id, 'cloturer')}>Clôturer</button>
                        </>
                      )}
                      {t.statut === 'planifie' && (
                        <button type="button" disabled={acting === t.id} onClick={() => doAction(t.id, 'annuler')}>Annuler le trajet</button>
                      )}
                    </td>
                  )}
                </tr>
              ))}
              {trajets.length === 0 && (
                <tr><td className="module-table-vide" colSpan={canEdit ? 6 : 5}>Aucun trajet enregistré.</td></tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
