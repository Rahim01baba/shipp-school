import { useEffect, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { api } from '../api/client.js'
import { useAuth } from '../context/AuthContext.jsx'

// Eleves affectes au circuit, avec arret de montee et de depose (lot 2).
function ElevesDuCircuit({ circuitId, etapes }) {
  const { can } = useAuth()
  const [affectations, setAffectations] = useState([])
  const [eleves, setEleves] = useState([])
  const [form, setForm] = useState({ eleve_id: '', etape_montee_id: '', etape_depose_id: '', sens: 'aller_retour' })
  const [error, setError] = useState(null)
  const canRead = can('eleve_affectations', 'can_read')
  const canCreate = can('eleve_affectations', 'can_create')

  async function load() {
    if (!canRead) return
    try {
      const res = await api.get(`/eleve-affectations.php?circuit_id=${circuitId}`)
      setAffectations(res.data || [])
      if (canCreate) {
        const el = await api.get('/crud.php?module=eleves')
        setEleves(el.data || [])
      }
    } catch (e) {
      setError(e.message)
    }
  }

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [circuitId, canRead])

  if (!canRead) return null

  async function affecter(e) {
    e.preventDefault()
    setError(null)
    try {
      await api.post('/eleve-affectations.php', {
        eleve_id: Number(form.eleve_id),
        circuit_id: Number(circuitId),
        etape_montee_id: form.etape_montee_id ? Number(form.etape_montee_id) : null,
        etape_depose_id: form.etape_depose_id ? Number(form.etape_depose_id) : null,
        sens: form.sens,
      })
      setForm({ ...form, eleve_id: '' })
      await load()
    } catch (err) {
      setError(err.message)
    }
  }

  async function retirer(id) {
    if (!window.confirm('Retirer cet eleve du circuit ? (l\'historique est conserve)')) return
    try {
      await api.put('/eleve-affectations.php', { id, action: 'terminer' })
      await load()
    } catch (err) {
      setError(err.message)
    }
  }

  const dejaAffectes = new Set(affectations.map((a) => String(a.eleve_id)))
  return (
    <>
      <h2>Eleves du circuit ({affectations.length})</h2>
      {error && <p className="error-banner">{error}</p>}
      <div className="module-table-wrap">
        <table className="module-table">
          <thead><tr><th>Eleve</th><th>Classe</th><th>Montee</th><th>Depose</th><th>Sens</th>{canCreate && <th />}</tr></thead>
          <tbody>
            {affectations.map((a) => (
              <tr key={a.id}>
                <td>{a.prenom} {a.nom}</td>
                <td>{a.classe || '-'}</td>
                <td>{a.arret_montee || <span className="scanner-abo-warning">non defini</span>}</td>
                <td>{a.arret_depose || '-'}</td>
                <td>{a.sens}</td>
                {canCreate && <td><button type="button" onClick={() => retirer(a.id)}>Retirer</button></td>}
              </tr>
            ))}
            {affectations.length === 0 && <tr><td colSpan={6}>Aucun eleve affecte.</td></tr>}
          </tbody>
        </table>
      </div>
      {canCreate && (
        <form onSubmit={affecter} className="module-form">
          <h3>Affecter un eleve</h3>
          <label className="module-form-field">
            <span>Eleve</span>
            <select value={form.eleve_id} onChange={(e) => setForm({ ...form, eleve_id: e.target.value })} required>
              <option value="">-- Choisir --</option>
              {eleves.filter((el) => !dejaAffectes.has(String(el.id))).map((el) => (
                <option key={el.id} value={el.id}>{el.nom} {el.prenom} {el.classe ? `(${el.classe})` : ''}</option>
              ))}
            </select>
          </label>
          <label className="module-form-field">
            <span>Arret de montee</span>
            <select value={form.etape_montee_id} onChange={(e) => setForm({ ...form, etape_montee_id: e.target.value })}>
              <option value="">-- Non defini --</option>
              {etapes.map((et) => <option key={et.id} value={et.id}>{et.ordre}. {et.nom}</option>)}
            </select>
          </label>
          <label className="module-form-field">
            <span>Arret de depose</span>
            <select value={form.etape_depose_id} onChange={(e) => setForm({ ...form, etape_depose_id: e.target.value })}>
              <option value="">-- Non defini --</option>
              {etapes.map((et) => <option key={et.id} value={et.id}>{et.ordre}. {et.nom}</option>)}
            </select>
          </label>
          <label className="module-form-field">
            <span>Sens</span>
            <select value={form.sens} onChange={(e) => setForm({ ...form, sens: e.target.value })}>
              <option value="aller_retour">Aller et retour</option>
              <option value="aller">Aller seulement</option>
              <option value="retour">Retour seulement</option>
            </select>
          </label>
          <div className="module-form-actions"><button type="submit" className="btn-transport">Affecter</button></div>
        </form>
      )}
    </>
  )
}


// Type de circuit (lot 5, D-28) : domicile <-> ecole, ou navette d'activite
// (sport, sortie) generee seulement les jours de la semaine choisis.
const JOURS = [['1', 'Lun'], ['2', 'Mar'], ['3', 'Mer'], ['4', 'Jeu'], ['5', 'Ven'], ['6', 'Sam']]
function CircuitType({ circuit, onSaved }) {
  const { can } = useAuth()
  const [f, setF] = useState({
    type_circuit: circuit.type_circuit || 'domicile', activite: circuit.activite || '', destination: circuit.destination || '',
    jours_semaine: circuit.jours_semaine || '', heure_depart: (circuit.heure_depart || '').slice(0, 5), heure_retour: (circuit.heure_retour || '').slice(0, 5),
  })
  const [msg, setMsg] = useState(null)
  const jours = f.jours_semaine ? f.jours_semaine.split(',') : []
  const editable = can('circuits', 'can_edit')
  async function save(e) {
    e.preventDefault()
    setMsg(null)
    try {
      await api.put('/crud.php?module=circuits', { id: circuit.id, ...f, heure_depart: f.heure_depart || null, heure_retour: f.heure_retour || null })
      setMsg('Enregistre')
      onSaved && onSaved()
    } catch (err) {
      setMsg(err.message)
    }
  }
  return (
    <form className="module-form" onSubmit={save}>
      <label className="module-form-field"><span>Type</span>
        <select value={f.type_circuit} disabled={!editable} onChange={(e) => setF({ ...f, type_circuit: e.target.value })}>
          <option value="domicile">Domicile - ecole</option>
          <option value="activite">Navette d'activite</option>
        </select>
      </label>
      {f.type_circuit === 'activite' && (
        <>
          <label className="module-form-field"><span>Activite</span><input value={f.activite} disabled={!editable} onChange={(e) => setF({ ...f, activite: e.target.value })} placeholder="EPS, natation..." /></label>
          <label className="module-form-field"><span>Destination</span><input value={f.destination} disabled={!editable} onChange={(e) => setF({ ...f, destination: e.target.value })} /></label>
          <div className="module-form-field"><span>Jours</span>
            <div className="ch-filtres">
              {JOURS.map(([k, l]) => (
                <label key={k} className="ch-check"><input type="checkbox" disabled={!editable} checked={jours.includes(k)}
                  onChange={(e) => setF({ ...f, jours_semaine: (e.target.checked ? [...jours, k] : jours.filter((j) => j !== k)).sort().join(',') })} /> {l}</label>
              ))}
            </div>
          </div>
          <label className="module-form-field"><span>Depart</span><input type="time" value={f.heure_depart} disabled={!editable} onChange={(e) => setF({ ...f, heure_depart: e.target.value })} /></label>
          <label className="module-form-field"><span>Retour</span><input type="time" value={f.heure_retour} disabled={!editable} onChange={(e) => setF({ ...f, heure_retour: e.target.value })} /></label>
          <p className="ma-muted">Les eleves affectes a une navette gardent leur circuit domicile.</p>
        </>
      )}
      {editable && <div className="module-form-actions"><button type="submit" className="btn-transport">Enregistrer</button></div>}
      {msg && <p className="ma-muted">{msg}</p>}
    </form>
  )
}

export default function CircuitDetail() {
  const { id } = useParams()
  const { can, accessLoading } = useAuth()
  const [circuit, setCircuit] = useState(null)
  const [etapes, setEtapes] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [form, setForm] = useState({ nom: '', ordre: '', heure_estimee: '' })
  const [saving, setSaving] = useState(false)

  const canCreateEtape = can('etapes', 'can_create')
  const canDeleteEtape = can('etapes', 'can_delete')

  async function load() {
    setLoading(true)
    setError(null)
    try {
      const [circuitsRes, etapesRes] = await Promise.all([
        api.get('/crud.php?module=circuits'),
        can('etapes', 'can_read') ? api.get('/crud.php?module=etapes') : Promise.resolve({ data: [] }),
      ])
      const found = (circuitsRes.data || []).find((c) => String(c.id) === String(id))
      setCircuit(found || null)
      const mine = (etapesRes.data || [])
        .filter((e) => String(e.circuit_id) === String(id))
        .sort((a, b) => Number(a.ordre) - Number(b.ordre))
      setEtapes(mine)
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
  }, [id, accessLoading])

  async function addEtape(e) {
    e.preventDefault()
    if (!form.nom || !form.ordre) return
    setSaving(true)
    setError(null)
    try {
      await api.post('/crud.php?module=etapes', {
        circuit_id: id,
        nom: form.nom,
        ordre: Number(form.ordre),
        heure_estimee: form.heure_estimee || null,
        statut: 'active',
      })
      setForm({ nom: '', ordre: '', heure_estimee: '' })
      await load()
    } catch (e) {
      setError(e.message)
    } finally {
      setSaving(false)
    }
  }

  async function removeEtape(etapeId) {
    setError(null)
    try {
      await api.del('/crud.php?module=etapes', { id: etapeId })
      await load()
    } catch (e) {
      setError(e.message)
    }
  }

  if (loading) {
    return (
      <div className="page">
        <p>Chargement...</p>
      </div>
    )
  }

  if (!circuit) {
    return (
      <div className="page">
        <p>
          <Link to="/modules/circuits">&larr; Circuits</Link>
        </p>
        <p className="error-banner">Circuit introuvable ou acces non autorise.</p>
      </div>
    )
  }

  return (
    <div className="page">
      <p>
        <Link to="/modules/circuits">&larr; Circuits</Link>
      </p>
      <h1>{circuit.nom}</h1>
      {error && <p className="error-banner">{error}</p>}
      <p>{circuit.description}</p>
      <p>Vehicule : {circuit.vehicule || '-'}</p>
      <p>Statut : {circuit.statut}</p>
      <CircuitType circuit={circuit} onSaved={load} />

      <h2>Etapes (ordre du trajet)</h2>
      {etapes.length === 0 && <p>Aucune etape definie pour ce circuit.</p>}
      {etapes.length > 0 && (
        <ol className="etapes-list">
          {etapes.map((e) => (
            <li key={e.id} className="etapes-list-item">
              <span className="etapes-list-nom">{e.nom}</span>
              {e.heure_estimee && <span className="etapes-list-heure">{e.heure_estimee}</span>}
              {canDeleteEtape && (
                <button type="button" className="etapes-list-remove" onClick={() => { if (window.confirm('Supprimer cet arret ?')) removeEtape(e.id) }}>
                  Supprimer
                </button>
              )}
            </li>
          ))}
        </ol>
      )}

      <ElevesDuCircuit circuitId={id} etapes={etapes} />

      {canCreateEtape && (
        <form onSubmit={addEtape} className="module-form">
          <label className="module-form-field">
            <span>Nom de l'etape</span>
            <input type="text" value={form.nom} onChange={(e) => setForm({ ...form, nom: e.target.value })} />
          </label>
          <label className="module-form-field">
            <span>Ordre</span>
            <input
              type="number"
              min="1"
              value={form.ordre}
              onChange={(e) => setForm({ ...form, ordre: e.target.value })}
            />
          </label>
          <label className="module-form-field">
            <span>Heure estimee</span>
            <input
              type="time"
              value={form.heure_estimee}
              onChange={(e) => setForm({ ...form, heure_estimee: e.target.value })}
            />
          </label>
          <div className="module-form-actions">
            <button type="submit" className="btn-transport" disabled={saving}>
              {saving ? 'Ajout...' : "Ajouter l'etape"}
            </button>
          </div>
        </form>
      )}
    </div>
  )
}
