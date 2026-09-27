import { useEffect, useState } from 'react'
import { api } from '../api/client.js'
import { useAuth } from '../context/AuthContext.jsx'

// Contacts des parents d'un eleve (lot 5). Un contact n'est pas un compte :
// il peut exister sans compte parent dans l'application.
const LIENS = { pere: 'Pere', mere: 'Mere', tuteur: 'Tuteur', autre: 'Autre' }

export default function ContactsEleve({ eleveId }) {
  const { can, scopeOf } = useAuth()
  const peutModifier = can('eleve_contacts', 'can_edit') && ['GLOBAL', 'SCHOOL'].includes(scopeOf('eleve_contacts'))
  const peutAjouter = can('eleve_contacts', 'can_create') && ['GLOBAL', 'SCHOOL'].includes(scopeOf('eleve_contacts'))
  const [liste, setListe] = useState([])
  const [error, setError] = useState(null)
  const vide = { nom: '', lien: 'mere', telephone: '', telephone2: '', email: '', principal: false }
  const [form, setForm] = useState(vide)
  const [edition, setEdition] = useState(null)

  async function load() {
    try {
      const res = await api.get(`/eleve-contacts.php?eleve_id=${eleveId}`)
      setListe(res.data || [])
    } catch (e) {
      setError(e.message)
    }
  }

  useEffect(() => {
    if (can('eleve_contacts', 'can_read')) load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [eleveId])

  if (!can('eleve_contacts', 'can_read')) return null

  async function enregistrer(e) {
    e.preventDefault()
    setError(null)
    try {
      if (edition) await api.put('/eleve-contacts.php', { id: edition, ...form })
      else await api.post('/eleve-contacts.php', { eleve_id: Number(eleveId), ...form })
      setForm(vide)
      setEdition(null)
      await load()
    } catch (err) {
      setError(err.message)
    }
  }

  function modifier(c) {
    setEdition(c.id)
    setForm({ nom: c.nom || '', lien: c.lien, telephone: c.telephone || '', telephone2: c.telephone2 || '', email: c.email || '', principal: !!Number(c.principal) })
  }

  return (
    <div className="eleve-detail-card">
      <h2>Contacts des parents</h2>
      {error && <p className="error-banner">{error}</p>}
      {liste.length === 0 && <p className="ma-muted">Aucun contact enregistre.</p>}
      <ul className="ec-liste">
        {liste.map((c) => (
          <li key={c.id}>
            <strong>{c.nom || 'Contact'}</strong> <span className="ma-muted">({LIENS[c.lien] || c.lien}{Number(c.principal) ? ', principal' : ''})</span>
            <div>
              {c.telephone && <a href={`tel:${c.telephone}`}>{c.telephone}</a>}
              {c.telephone2 && <> · <a href={`tel:${c.telephone2}`}>{c.telephone2}</a></>}
              {c.email && <> · <a href={`mailto:${c.email}`}>{c.email}</a></>}
            </div>
            {c.compte_nom && <div className="ma-muted">Compte : {c.compte_nom}</div>}
            {peutModifier && <button type="button" className="inc-lien" onClick={() => modifier(c)}>Modifier</button>}
          </li>
        ))}
      </ul>
      {(peutAjouter || (edition && peutModifier)) && (
        <form onSubmit={enregistrer} className="module-form">
          <h3>{edition ? 'Modifier le contact' : 'Ajouter un contact'}</h3>
          <label className="module-form-field"><span>Nom</span><input value={form.nom} onChange={(e) => setForm({ ...form, nom: e.target.value })} /></label>
          <label className="module-form-field"><span>Lien</span>
            <select value={form.lien} onChange={(e) => setForm({ ...form, lien: e.target.value })}>{Object.entries(LIENS).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
          </label>
          <label className="module-form-field"><span>Telephone</span><input type="tel" value={form.telephone} onChange={(e) => setForm({ ...form, telephone: e.target.value })} placeholder="07 00 00 00 00" /></label>
          <label className="module-form-field"><span>Autre telephone</span><input type="tel" value={form.telephone2} onChange={(e) => setForm({ ...form, telephone2: e.target.value })} /></label>
          <label className="module-form-field"><span>E-mail</span><input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></label>
          <label className="ch-check"><input type="checkbox" checked={form.principal} onChange={(e) => setForm({ ...form, principal: e.target.checked })} /> Contact principal</label>
          <div className="module-form-actions">
            <button type="submit" className="btn-transport">{edition ? 'Enregistrer' : 'Ajouter'}</button>
            {edition && <button type="button" onClick={() => { setEdition(null); setForm(vide) }}>Annuler</button>}
          </div>
        </form>
      )}
    </div>
  )
}
