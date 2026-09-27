import { useEffect, useState } from 'react'
import { api } from '../api/client.js'

// Libelles et composants partages par les ecrans incidents et le dossier chauffeur.

export const CATEGORIES = { incident: 'Incident', accident: 'Accident' }
export const GRAVITES = { faible: 'Faible', moyenne: 'Moyenne', elevee: 'Elevee', critique: 'Critique' }
export const STATUTS_INCIDENT = { ouvert: 'Ouvert', en_cours: 'En cours', resolu: 'Resolu', clos: 'Clos', annule: 'Annule' }
export const RESPONSABILITES = {
  non_determinee: 'Non determinee',
  chauffeur: 'Chauffeur',
  tiers: 'Tiers',
  partagee: 'Partagee',
  eleve: 'Eleve',
  autre: 'Autre',
}
export const TYPES_INCIDENT = {
  panne: 'Panne / probleme mecanique',
  retard: 'Retard important',
  comportement: "Comportement d'un eleve",
  blessure: 'Blessure / malaise',
  oubli: 'Eleve oublie / mauvais arret',
  collision: 'Collision / accrochage',
  autre: 'Autre',
}

export function dateHeure(v) {
  return v ? String(v).slice(0, 16).replace('T', ' ') : ''
}

export function Badge({ value, map, prefix }) {
  if (!value) return null
  return <span className={`inc-badge ${prefix}-${value}`}>{map[value] || value}</span>
}

// Liste et depot de fichiers prives rattaches a une entite (incident, document, contrat...).
export function Fichiers({ entite, entiteId, peutDeposer, categorie, onUpload, titre = 'Pieces jointes' }) {
  const [liste, setListe] = useState([])
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  async function load() {
    try {
      const res = await api.get(`/fichier.php?entite=${entite}&entite_id=${entiteId}`)
      setListe(res.data || [])
    } catch (e) {
      setError(e.message)
    }
  }

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [entite, entiteId])

  async function deposer(e) {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    setBusy(true)
    setError(null)
    try {
      const res = await api.upload('/fichier.php', { entite, entite_id: entiteId, categorie }, file)
      await load()
      onUpload && onUpload(res.id)
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  async function ouvrir(id) {
    try {
      await api.openFile(id)
    } catch (err) {
      setError(err.message)
    }
  }

  return (
    <div className="inc-fichiers">
      <h4>{titre}</h4>
      {error && <p className="error-banner">{error}</p>}
      {liste.length === 0 && <p className="ma-muted">Aucun fichier.</p>}
      <ul>
        {liste.map((f) => (
          <li key={f.id}>
            <button type="button" className="inc-lien" onClick={() => ouvrir(f.id)}>{f.nom_original}</button>
            <span className="ma-muted"> {Math.round(f.taille / 1024)} Ko · {dateHeure(f.created_at)}</span>
          </li>
        ))}
      </ul>
      {peutDeposer && (
        <label className="inc-upload">
          <input type="file" accept="application/pdf,image/jpeg,image/png,image/webp" capture="environment" disabled={busy} onChange={deposer} />
          <span>{busy ? 'Envoi...' : 'Ajouter une photo ou un PDF'}</span>
        </label>
      )}
    </div>
  )
}
