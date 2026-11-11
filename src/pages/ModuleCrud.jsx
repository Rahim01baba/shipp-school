import { useEffect, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { api } from '../api/client.js'
import { MODULES } from '../config/modules.js'
import { useAuth } from '../context/AuthContext.jsx'
import Icon from '../components/Icon.jsx'
import StatutBadge from '../components/StatutBadge.jsx'

/**
 * Page CRUD generique — pilotee par la config MODULES.
 * Un seul composant reutilise pour les 12 modules metier : liste + formulaire
 * d'ajout/edition + suppression, tous branches sur l'endpoint crud.php
 * cote API (parametre ?module=...). Les actions affichees sont filtrees
 * selon les droits reels de l'utilisateur (can_read/can_create/can_edit/can_delete).
 */

function emptyForm(fields) {
  return Object.fromEntries(fields.map((f) => [f.key, '']))
}

export default function ModuleCrud() {
  const { moduleKey } = useParams()
  const { can, accessLoading } = useAuth()
  const moduleDef = MODULES.find((m) => m.key === moduleKey)
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [form, setForm] = useState(moduleDef ? emptyForm(moduleDef.fields) : {})
  const [editingId, setEditingId] = useState(null)
  const [saving, setSaving] = useState(false)
  const [formOuvert, setFormOuvert] = useState(false)

  const canRead = can(moduleKey, 'can_read')
  const canCreate = can(moduleKey, 'can_create')
  const canEdit = can(moduleKey, 'can_edit')
  const canDelete = can(moduleKey, 'can_delete')
  const canValidate = can(moduleKey, 'can_validate')
  const canExport = can(moduleKey, 'can_export')
  const domainClass = moduleDef?.domain === 'transport' ? 'btn-transport' : moduleDef?.domain === 'cantine' ? 'btn-cantine' : ''

  async function load(def) {
    if (!def) return
    setLoading(true)
    setError(null)
    try {
      const data = await api.get(`/crud.php?module=${def.key}`)
      setRows(data.data || [])
    } catch (e) {
      setError(e.message)
    } finally {
      setLoading(false)
    }
  }

  function exportCsv() {
    if (!rows.length) return
    const keys = Object.keys(rows[0])
    const escape = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`
    const csv = [keys.join(','), ...rows.map((r) => keys.map((k) => escape(r[k])).join(','))].join('\n')
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `${moduleKey}.csv`
    a.click()
    URL.revokeObjectURL(url)
  }

  async function validateRow(row) {
    try {
      await api.put(`/crud.php?module=${moduleKey}`, { id: row.id, statut: 'actif' })
      await load(moduleDef)
    } catch (e) {
      setError(e.message)
    }
  }

  useEffect(() => {
    setForm(moduleDef ? emptyForm(moduleDef.fields) : {})
    setEditingId(null)
    setFormOuvert(false)
    if (accessLoading) return
    if (canRead) {
      load(moduleDef)
    } else {
      setLoading(false)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [moduleKey, accessLoading, canRead])

  if (!moduleDef) {
    return (
      <div className="page">
        <p>Module inconnu.</p>
        <Link to="/">Retour au tableau de bord</Link>
      </div>
    )
  }

  if (accessLoading) {
    return (
      <div className="page">
        <p>Chargement des droits...</p>
      </div>
    )
  }

  if (!canRead) {
    return (
      <div className="page">
        <p>
          <Link to="/">&larr; Tableau de bord</Link>
        </p>
        <p className="error-banner">Acces refuse : vous n'avez pas les droits pour consulter ce module.</p>
      </div>
    )
  }

  function startEdit(row) {
    const next = {}
    moduleDef.fields.forEach((f) => {
      next[f.key] = row[f.key] ?? ''
    })
    setForm(next)
    setEditingId(row.id)
    setFormOuvert(true)
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  function cancelEdit() {
    setForm(emptyForm(moduleDef.fields))
    setEditingId(null)
    setFormOuvert(false)
  }

  async function submit(e) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      if (editingId) {
        await api.put(`/crud.php?module=${moduleDef.key}`, { id: editingId, ...form })
      } else {
        await api.post(`/crud.php?module=${moduleDef.key}`, form)
      }
      cancelEdit()
      await load(moduleDef)
    } catch (e) {
      setError(e.message)
    } finally {
      setSaving(false)
    }
  }

  async function remove(id) {
    setError(null)
    try {
      await api.del(`/crud.php?module=${moduleDef.key}`, { id })
      await load(moduleDef)
    } catch (e) {
      setError(e.message)
    }
  }

  const showForm = editingId ? canEdit : canCreate
  const showActionsColumn = canEdit || canDelete || ['eleves', 'circuits', 'vehicules'].includes(moduleKey)

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <h1>{moduleDef.label}</h1>
          <p className="page-header-sous">{loading ? 'Chargement...' : `${rows.length} enregistrement${rows.length > 1 ? 's' : ''}`}</p>
        </div>
        <div className="section-tete">
          {canExport && rows.length > 0 && (
            <button type="button" className="btn btn-secondary" onClick={exportCsv}><Icon name="upload" size={18} />Exporter CSV</button>
          )}
          {canCreate && !editingId && (
            <button type="button" className="btn btn-primary" aria-expanded={formOuvert} onClick={() => setFormOuvert(!formOuvert)}><Icon name={formOuvert ? 'close' : 'plus'} size={18} />{formOuvert ? 'Fermer' : 'Ajouter'}</button>
          )}
        </div>
      </header>
      {error && <p className="error-banner">{error}</p>}

      {showForm && (formOuvert || editingId) && (
        <form onSubmit={submit} className="module-form">
          <h2 className="form-titre">{editingId ? `Modifier` : `Nouvel enregistrement`}</h2>
          {moduleDef.fields.map((f) => (
            <label key={f.key} className="module-form-field">
              <span>{f.label}</span>
              {f.type === 'select' ? (
                <select value={form[f.key] ?? ''} onChange={(e) => setForm({ ...form, [f.key]: e.target.value })}>
                  <option value="">-- Choisir --</option>
                  {(f.options || []).map((opt) => (
                    <option key={opt.value} value={opt.value}>
                      {opt.label}
                    </option>
                  ))}
                </select>
              ) : (
                <input
                  type={f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : f.type === 'email' ? 'email' : 'text'}
                  value={form[f.key] ?? ''}
                  onChange={(e) => setForm({ ...form, [f.key]: e.target.value })}
                />
              )}
            </label>
          ))}
          <div className="module-form-actions">
            <button type="button" className="btn btn-ghost" onClick={cancelEdit}>
              Annuler
            </button>
            <button type="submit" className={domainClass} disabled={saving}>
              {editingId ? 'Mettre a jour' : 'Ajouter'}
            </button>
          </div>
        </form>
      )}

      {loading ? (
        <div className="module-table-wrap"><div className="empty-state"><span className="spinner" aria-hidden="true" />Chargement...</div></div>
      ) : (
        <div className="module-table-wrap">
          <table className="module-table">
            <thead>
              <tr>
                {moduleDef.fields.map((f) => (
                  <th key={f.key}>{f.label}</th>
                ))}
                {showActionsColumn && <th>Actions</th>}
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.id}>
                  {moduleDef.fields.map((f) => (
                    <td key={f.key}>{f.key === 'statut' ? <StatutBadge valeur={row[f.key]} /> : f.key === moduleDef.fields[0].key ? <strong>{row[f.key]}</strong> : row[f.key]}</td>
                  ))}
                  {showActionsColumn && (
                    <td className="module-table-actions">
                      {moduleKey === 'eleves' && (
                        <Link to={`/eleves/${row.id}`} className="module-table-link">
                          Voir la fiche
                        </Link>
                      )}
                      {moduleKey === 'vehicules' && (
                        <Link to={`/vehicules/${row.id}`} className="module-table-link">
                          Voir la fiche
                        </Link>
                      )}
                      {moduleKey === 'circuits' && (
                        <Link to={`/circuits/${row.id}`} className="module-table-link">
                          Voir les etapes
                        </Link>
                      )}
                      {canEdit && (
                        <button type="button" className={domainClass} onClick={() => startEdit(row)}>
                          Modifier
                        </button>
                      )}
                      {moduleKey === 'abonnements' && canValidate && row.statut !== 'actif' && (
                        <button type="button" className={domainClass} onClick={() => validateRow(row)}>
                          Approuver
                        </button>
                      )}
                      {canDelete && (
                        <button type="button" onClick={() => remove(row.id)}>
                          Supprimer
                        </button>
                      )}
                    </td>
                  )}
                </tr>
              ))}
              {rows.length === 0 && (
                <tr>
                  <td className="module-table-vide" colSpan={moduleDef.fields.length + (showActionsColumn ? 1 : 0)}>
                    <strong>Aucun enregistrement pour le moment.</strong>
                    {canCreate && <><br /><button type="button" className="btn btn-secondary btn-sm" onClick={() => setFormOuvert(true)}><Icon name="plus" size={16} />Ajouter le premier</button></>}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
