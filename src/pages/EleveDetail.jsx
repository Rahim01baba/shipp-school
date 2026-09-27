import { useEffect, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { api } from '../api/client.js'
import { useAuth } from '../context/AuthContext.jsx'
import ContactsEleve from './ContactsEleve.jsx'

const ABONNEMENT_LABELS = {
  transport: 'Transport',
  cantine: 'Cantine',
}

const STATUT_LABELS = {
  actif: 'Actif',
  suspendu: 'Suspendu',
  en_attente: 'En attente',
}

const API_URL = import.meta.env.VITE_API_URL || '/api'

export default function EleveDetail() {
  const { id } = useParams()
  const { can, accessLoading } = useAuth()
  const [eleve, setEleve] = useState(null)
  const [abonnements, setAbonnements] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [photoFile, setPhotoFile] = useState(null)
  const [uploading, setUploading] = useState(false)
  const [savingAbo, setSavingAbo] = useState(null)

  const canEditEleve = can('eleves', 'can_edit')
  const canEditAbo = can('abonnements', 'can_edit')

  async function load() {
    setLoading(true)
    setError(null)
    try {
      const [elevesRes, abosRes] = await Promise.all([
        api.get('/crud.php?module=eleves'),
        can('abonnements', 'can_read') ? api.get('/crud.php?module=abonnements') : Promise.resolve({ data: [] }),
      ])
      const found = (elevesRes.data || []).find((e) => String(e.id) === String(id))
      setEleve(found || null)
      setAbonnements((abosRes.data || []).filter((a) => String(a.eleve_id) === String(id)))
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

  async function uploadPhoto(e) {
    e.preventDefault()
    if (!photoFile) return
    setUploading(true)
    setError(null)
    try {
      const token = localStorage.getItem('shipp_token')
      const fd = new FormData()
      fd.append('eleve_id', id)
      fd.append('photo', photoFile)
      const res = await fetch(`${API_URL}/eleve-photo.php`, {
        method: 'POST',
        headers: token ? { Authorization: `Bearer ${token}` } : {},
        body: fd,
      })
      const data = await res.json()
      if (!res.ok) throw new Error(data.message || 'Erreur upload photo')
      setPhotoFile(null)
      await load()
    } catch (e) {
      setError(e.message)
    } finally {
      setUploading(false)
    }
  }

  async function updateAbonnement(abo, newStatut) {
    setSavingAbo(abo.id)
    setError(null)
    try {
      await api.put('/crud.php?module=abonnements', { id: abo.id, statut: newStatut })
      await load()
    } catch (e) {
      setError(e.message)
    } finally {
      setSavingAbo(null)
    }
  }

  if (loading) {
    return (
      <div className="page">
        <p>Chargement...</p>
      </div>
    )
  }

  if (!eleve) {
    return (
      <div className="page">
        <p>
          <Link to="/modules/eleves">&larr; Eleves</Link>
        </p>
        <p className="error-banner">Eleve introuvable ou acces non autorise.</p>
      </div>
    )
  }

  const qrImageUrl = eleve.qr_code
    ? `https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=${encodeURIComponent(eleve.qr_code)}`
    : null
  const photoUrl = eleve.photo ? `${API_URL}/${eleve.photo}` : null

  return (
    <div className="page">
      <p>
        <Link to="/modules/eleves">&larr; Eleves</Link>
      </p>
      <h1>
        {eleve.nom} {eleve.prenom}
      </h1>
      {error && <p className="error-banner">{error}</p>}

      <div className="eleve-detail-grid">
        <div className="eleve-detail-card">
          <h2>Identite</h2>
          <p>Code DR : {eleve.code_dr || '-'}</p>
          <p>Classe : {eleve.classe || '-'}</p>
          <p>Ecole : {eleve.ecole || '-'}</p>
          <p>Statut : {eleve.statut || '-'}</p>
        </div>

        <div className="eleve-detail-card">
          <h2>QR Code</h2>
          {qrImageUrl ? (
            <img src={qrImageUrl} alt={`QR code de ${eleve.nom}`} width={180} height={180} />
          ) : (
            <p>Aucun QR code genere.</p>
          )}
          <p>{eleve.qr_code}</p>
        </div>

        <div className="eleve-detail-card">
          <h2>Photo</h2>
          {photoUrl ? (
            <img src={photoUrl} alt={`Photo de ${eleve.nom}`} width={150} />
          ) : (
            <p>Aucune photo.</p>
          )}
          {canEditEleve && (
            <form onSubmit={uploadPhoto} className="module-form-actions">
              <input
                type="file"
                accept="image/png, image/jpeg, image/webp"
                onChange={(e) => setPhotoFile(e.target.files?.[0] || null)}
              />
              <button type="submit" disabled={!photoFile || uploading}>
                {uploading ? 'Envoi...' : 'Televerser'}
              </button>
            </form>
          )}
        </div>

        <div className="eleve-detail-card">
          <h2>Abonnements</h2>
          {abonnements.length === 0 && <p>Aucun abonnement.</p>}
          {abonnements.map((abo) => (
            <div key={abo.id} className="abonnement-row">
              <span>{ABONNEMENT_LABELS[abo.type] || abo.type} :</span>
              {canEditAbo ? (
                <select
                  value={abo.statut}
                  disabled={savingAbo === abo.id}
                  onChange={(e) => updateAbonnement(abo, e.target.value)}
                >
                  {Object.entries(STATUT_LABELS).map(([value, label]) => (
                    <option key={value} value={value}>
                      {label}
                    </option>
                  ))}
                </select>
              ) : (
                <span>{STATUT_LABELS[abo.statut] || abo.statut}</span>
              )}
            </div>
          ))}
        </div>

        <ContactsEleve eleveId={id} />
      </div>
    </div>
  )
}
