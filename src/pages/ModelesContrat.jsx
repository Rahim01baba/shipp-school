import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client.js'
import { useAuth } from '../context/AuthContext.jsx'

// Modeles de contrat d'utilisation de vehicule, versionnes.
// Une version activee ou utilisee n'est jamais modifiee : on cree une nouvelle version.
const STATUTS = { brouillon: 'Brouillon', actif: 'Actif', archive: 'Archive' }

export default function ModelesContrat() {
  const { can } = useAuth()
  const [rows, setRows] = useState([])
  const [variables, setVariables] = useState([])
  const [error, setError] = useState(null)
  const [info, setInfo] = useState(null)
  const [edit, setEdit] = useState(null)
  const vide = { code: 'CUV', titre: "Contrat d'utilisation de vehicule par le chauffeur", contenu: '', source_document: '', montant: '', periodicite: 'mois', preavis: '' }
  const [form, setForm] = useState(vide)

  async function load() {
    try {
      const res = await api.get('/contract-templates.php')
      setRows(res.data || [])
      setVariables(res.variables || [])
    } catch (e) {
      setError(e.message)
    }
  }

  useEffect(() => { load() }, [])

  async function run(fn, message) {
    setError(null)
    setInfo(null)
    try {
      await fn()
      setInfo(message)
      await load()
      return true
    } catch (e) {
      setError(e.message)
      return false
    }
  }

  function valeurs() {
    const v = {}
    if (form.montant !== '') v.remuneration_montant = Number(form.montant)
    if (form.periodicite) v.remuneration_periodicite = form.periodicite
    if (form.preavis !== '') v.preavis_jours = Number(form.preavis)
    return v
  }

  async function enregistrer(e) {
    e.preventDefault()
    const body = { code: form.code, titre: form.titre, contenu: form.contenu, source_document: form.source_document, valeurs_defaut: valeurs() }
    const ok = edit
      ? await run(() => api.put('/contract-templates.php', { id: edit, action: 'modifier', ...body }), 'Brouillon mis a jour')
      : await run(() => api.post('/contract-templates.php', body), 'Nouvelle version creee (brouillon)')
    if (ok) {
      setEdit(null)
      setForm(vide)
    }
  }

  function repartirDe(t, modifier) {
    const v = t.valeurs_defaut || {}
    setEdit(modifier ? t.id : null)
    setForm({ code: t.code, titre: t.titre, contenu: t.contenu || '', source_document: t.source_document || '', montant: v.remuneration_montant ?? '', periodicite: v.remuneration_periodicite ?? '', preavis: v.preavis_jours ?? '' })
    window.scrollTo(0, 0)
  }

  return (
    <div className="page">
      <p><Link to="/chauffeurs">&larr; Chauffeurs</Link></p>
      <h1>Modeles de contrat</h1>
      {error && <p className="error-banner">{error}</p>}
      {info && <p className="ma-info">{info}</p>}
      {can('contract_templates', 'can_create') && (
        <form onSubmit={enregistrer} className="module-form">
          <h2>{edit ? 'Modifier le brouillon' : 'Nouvelle version'}</h2>
          <label className="module-form-field"><span>Code</span><input value={form.code} disabled={!!edit} onChange={(e) => setForm({ ...form, code: e.target.value })} required /></label>
          <label className="module-form-field"><span>Titre</span><input value={form.titre} onChange={(e) => setForm({ ...form, titre: e.target.value })} required /></label>
          <label className="module-form-field"><span>Document source</span><input value={form.source_document} onChange={(e) => setForm({ ...form, source_document: e.target.value })} placeholder="Nom du fichier Word d'origine" /></label>
          <label className="module-form-field"><span>Texte du contrat</span><textarea rows={14} value={form.contenu} onChange={(e) => setForm({ ...form, contenu: e.target.value })} /></label>
          <p className="ma-muted">Variables : {variables.map((v) => `{{${v}}}`).join(' ')}</p>
          <fieldset className="inc-fieldset">
            <legend>Valeurs proposees par defaut (modifiables sur chaque contrat)</legend>
            <label className="module-form-field"><span>Montant (FCFA)</span><input type="number" min="0" value={form.montant} onChange={(e) => setForm({ ...form, montant: e.target.value })} /></label>
            <label className="module-form-field"><span>Periodicite</span><input value={form.periodicite} onChange={(e) => setForm({ ...form, periodicite: e.target.value })} /></label>
            <label className="module-form-field"><span>Preavis (jours)</span><input type="number" min="0" value={form.preavis} onChange={(e) => setForm({ ...form, preavis: e.target.value })} /></label>
          </fieldset>
          <div className="module-form-actions">
            <button type="submit" className="btn-transport">{edit ? 'Enregistrer' : 'Creer la version'}</button>
            {edit && <button type="button" onClick={() => { setEdit(null); setForm(vide) }}>Annuler</button>}
          </div>
        </form>
      )}
      <div className="module-table-wrap">
        <table className="module-table">
          <thead><tr><th>Code</th><th>Version</th><th>Titre</th><th>Statut</th><th>Contrats</th><th>Cree</th><th /></tr></thead>
          <tbody>
            {rows.map((t) => (
              <tr key={t.id}>
                <td>{t.code}</td>
                <td>v{t.version}</td>
                <td>{t.titre}</td>
                <td>{STATUTS[t.statut]}</td>
                <td>{t.nb_contrats}</td>
                <td>{String(t.created_at).slice(0, 10)} {t.created_by_nom || ''}</td>
                <td>
                  {can('contract_templates', 'can_edit') && t.statut !== 'actif' && <button type="button" className="inc-lien" onClick={() => run(() => api.put('/contract-templates.php', { id: t.id, action: 'activer' }), 'Version activee')}>Activer</button>}
                  {can('contract_templates', 'can_edit') && t.statut === 'brouillon' && Number(t.nb_contrats) === 0 && <button type="button" className="inc-lien" onClick={() => repartirDe(t, true)}>Modifier</button>}
                  {can('contract_templates', 'can_create') && <button type="button" className="inc-lien" onClick={() => repartirDe(t, false)}>Nouvelle version</button>}
                  {can('contract_templates', 'can_edit') && t.statut === 'actif' && <button type="button" className="inc-lien" onClick={() => run(() => api.put('/contract-templates.php', { id: t.id, action: 'archiver' }), 'Version archivee')}>Archiver</button>}
                </td>
              </tr>
            ))}
            {rows.length === 0 && <tr><td colSpan={7}>Aucun modele. Saisissez le texte du contrat Word d'origine pour creer la version 1.</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}
