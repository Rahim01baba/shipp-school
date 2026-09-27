import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { api } from '../api/client.js'
import { useAuth } from '../context/AuthContext.jsx'
import { GRAVITES, TYPES_INCIDENT } from './incidentsCommun.jsx'

// Declaration terrain (mobile d'abord). Depuis « Mon activite », le trajet est
// transmis : circuit, chauffeur et vehicule sont repris cote serveur.
// La responsabilite n'est jamais saisie ici : elle est qualifiee plus tard.
export default function IncidentNouveau() {
  const [params] = useSearchParams()
  const trajetId = params.get('trajet_id')
  const navigate = useNavigate()
  const { scopeOf } = useAuth()
  const terrain = scopeOf('incidents') === 'ASSIGNED_ROUTE'
  const [eleves, setEleves] = useState([])
  const [chauffeurs, setChauffeurs] = useState([])
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const [form, setForm] = useState({
    categorie: 'incident', type: 'autre', gravite: 'moyenne', titre: '', description: '', lieu: '',
    mesures_immediates: '', survenu_at: '', chauffeur_id: '', latitude: '', longitude: '',
  })
  const [concernes, setConcernes] = useState({})
  const [accident, setAccident] = useState({ tiers_implique: false, tiers_nom: '', tiers_telephone: '', tiers_immatriculation: '', constat_amiable: false, rapport_police: false, blesses: false, nb_blesses: '', degats_vehicule: '', vehicule_immobilise: false })

  useEffect(() => {
    async function load() {
      try {
        if (trajetId) {
          const jour = await api.get('/chauffeur-jour.php').catch(() => null)
          const t = jour?.trajets?.find((x) => String(x.id) === String(trajetId))
          if (t) setEleves(t.eleves.map((e) => ({ id: e.eleve_id, nom: `${e.prenom} ${e.nom}`, classe: e.classe })))
        } else {
          const res = await api.get('/crud.php?module=eleves').catch(() => null)
          if (res) setEleves((res.data || []).map((e) => ({ id: e.id, nom: `${e.prenom} ${e.nom}`, classe: e.classe })))
        }
        if (!terrain) {
          const ch = await api.get('/chauffeurs.php?statut=actif').catch(() => null)
          if (ch) setChauffeurs(ch.data || [])
        }
      } catch (e) {
        setError(e.message)
      }
    }
    load()
  }, [trajetId, terrain])

  function localiser() {
    if (!navigator.geolocation) return
    navigator.geolocation.getCurrentPosition(
      (p) => setForm((f) => ({ ...f, latitude: p.coords.latitude.toFixed(6), longitude: p.coords.longitude.toFixed(6) })),
      () => setError('Position indisponible'),
    )
  }

  async function envoyer(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const body = {
        ...form,
        trajet_id: trajetId ? Number(trajetId) : undefined,
        chauffeur_id: form.chauffeur_id ? Number(form.chauffeur_id) : undefined,
        eleves: Object.entries(concernes).filter(([, v]) => v.role).map(([id, v]) => ({ eleve_id: Number(id), role: v.role, blessure: v.blessure })),
        accident: form.categorie === 'accident' ? accident : undefined,
      }
      const res = await api.post('/incidents.php', body)
      navigate(`/incidents/${res.id}`, { replace: true })
    } catch (err) {
      setError(err.message)
      setBusy(false)
    }
  }

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value })
  const setA = (k) => (e) => setAccident({ ...accident, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value })

  return (
    <div className="page ma-page">
      <p><Link to={trajetId ? '/mon-activite' : '/incidents'}>&larr; Retour</Link></p>
      <h1>Signaler {form.categorie === 'accident' ? 'un accident' : 'un incident'}</h1>
      {error && <p className="error-banner">{error}</p>}
      <form onSubmit={envoyer} className="module-form">
        <div className="scanner-tabs">
          {['incident', 'accident'].map((c) => (
            <button key={c} type="button" className={form.categorie === c ? 'active' : ''}
              onClick={() => setForm({ ...form, categorie: c, gravite: c === 'accident' ? 'elevee' : 'moyenne', type: c === 'accident' ? 'collision' : form.type })}>
              {c === 'accident' ? 'Accident' : 'Incident'}
            </button>
          ))}
        </div>
        <label className="module-form-field"><span>Type</span>
          <select value={form.type} onChange={set('type')}>{Object.entries(TYPES_INCIDENT).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
        </label>
        <label className="module-form-field"><span>Gravite</span>
          <select value={form.gravite} onChange={set('gravite')}>{Object.entries(GRAVITES).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
        </label>
        <label className="module-form-field"><span>Titre *</span><input value={form.titre} onChange={set('titre')} required placeholder="Ex. : pneu creve avenue X" /></label>
        <label className="module-form-field"><span>Ce qui s'est passe</span><textarea rows={4} value={form.description} onChange={set('description')} /></label>
        <label className="module-form-field"><span>Quand (vide = maintenant)</span><input type="datetime-local" value={form.survenu_at} onChange={set('survenu_at')} /></label>
        <label className="module-form-field"><span>Lieu</span><input value={form.lieu} onChange={set('lieu')} /></label>
        <p><button type="button" onClick={localiser}>Utiliser ma position</button>{form.latitude && <span className="ma-muted"> {form.latitude}, {form.longitude}</span>}</p>
        <label className="module-form-field"><span>Mesures prises immediatement</span><textarea rows={2} value={form.mesures_immediates} onChange={set('mesures_immediates')} /></label>
        {!terrain && !trajetId && (
          <label className="module-form-field"><span>Chauffeur concerne</span>
            <select value={form.chauffeur_id} onChange={set('chauffeur_id')}>
              <option value="">-- Aucun / inconnu --</option>
              {chauffeurs.map((c) => <option key={c.id} value={c.id}>{[c.prenom, c.nom].filter(Boolean).join(' ')}</option>)}
            </select>
          </label>
        )}

        {form.categorie === 'accident' && (
          <fieldset className="inc-fieldset">
            <legend>Accident</legend>
            <label className="ch-check"><input type="checkbox" checked={accident.tiers_implique} onChange={setA('tiers_implique')} /> Un tiers est implique</label>
            {accident.tiers_implique && (
              <>
                <label className="module-form-field"><span>Nom du tiers</span><input value={accident.tiers_nom} onChange={setA('tiers_nom')} /></label>
                <label className="module-form-field"><span>Telephone du tiers</span><input type="tel" value={accident.tiers_telephone} onChange={setA('tiers_telephone')} /></label>
                <label className="module-form-field"><span>Immatriculation du tiers</span><input value={accident.tiers_immatriculation} onChange={setA('tiers_immatriculation')} /></label>
              </>
            )}
            <label className="ch-check"><input type="checkbox" checked={accident.constat_amiable} onChange={setA('constat_amiable')} /> Constat amiable rempli</label>
            <label className="ch-check"><input type="checkbox" checked={accident.rapport_police} onChange={setA('rapport_police')} /> Police / gendarmerie intervenue</label>
            <label className="ch-check"><input type="checkbox" checked={accident.blesses} onChange={setA('blesses')} /> Il y a des blesses</label>
            {accident.blesses && <label className="module-form-field"><span>Nombre de blesses</span><input type="number" min="0" value={accident.nb_blesses} onChange={setA('nb_blesses')} /></label>}
            <label className="module-form-field"><span>Degats sur le vehicule</span><textarea rows={2} value={accident.degats_vehicule} onChange={setA('degats_vehicule')} /></label>
            <label className="ch-check"><input type="checkbox" checked={accident.vehicule_immobilise} onChange={setA('vehicule_immobilise')} /> Vehicule immobilise</label>
          </fieldset>
        )}

        {eleves.length > 0 && (
          <fieldset className="inc-fieldset">
            <legend>Eleves concernes (les parents seront prevenus)</legend>
            {eleves.map((el) => {
              const v = concernes[el.id] || {}
              return (
                <div key={el.id} className="inc-eleve-ligne">
                  <span>{el.nom} <span className="ma-muted">{el.classe || ''}</span></span>
                  <select value={v.role || ''} onChange={(e) => setConcernes({ ...concernes, [el.id]: { ...v, role: e.target.value } })}>
                    <option value="">Non concerne</option>
                    <option value="implique">Implique</option>
                    <option value="blesse">Blesse</option>
                    <option value="temoin">Temoin</option>
                  </select>
                  {v.role === 'blesse' && (
                    <input placeholder="Blessure" value={v.blessure || ''} onChange={(e) => setConcernes({ ...concernes, [el.id]: { ...v, blessure: e.target.value } })} />
                  )}
                </div>
              )
            })}
          </fieldset>
        )}
        <p className="ma-muted">Les photos et documents se joignent juste apres l'envoi. La responsabilite sera qualifiee par un responsable.</p>
        <div className="module-form-actions"><button type="submit" className="btn-transport ma-big" disabled={busy}>{busy ? 'Envoi...' : 'Envoyer le signalement'}</button></div>
      </form>
    </div>
  )
}
