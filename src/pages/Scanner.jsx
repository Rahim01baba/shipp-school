import { useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api } from '../api/client.js'
import { useAuth } from '../context/AuthContext.jsx'

const API_URL = import.meta.env.VITE_API_URL || '/api'
const JSQR_URL = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js'

const TYPE_LABELS = {
  transport_embarquement: 'Montee vehicule',
  transport_debarquement: 'Descente vehicule',
  cantine: 'Cantine',
}

let jsQrLoadPromise = null
function loadJsQR() {
  if (window.jsQR) return Promise.resolve()
  if (!jsQrLoadPromise) {
    jsQrLoadPromise = new Promise((resolve, reject) => {
      const script = document.createElement('script')
      script.src = JSQR_URL
      script.onload = () => resolve()
      script.onerror = () => reject(new Error('Impossible de charger le lecteur QR'))
      document.head.appendChild(script)
    })
  }
  return jsQrLoadPromise
}

export default function Scanner() {
  const { can, accessLoading } = useAuth()
  const [searchParams] = useSearchParams()
  const trajetParam = searchParams.get('trajet_id')
  const [method, setMethod] = useState(trajetParam ? 'camera' : 'recherche')
  const [eleves, setEleves] = useState([])
  const [abonnements, setAbonnements] = useState([])
  const [scans, setScans] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [searchTerm, setSearchTerm] = useState('')
  const [codeValue, setCodeValue] = useState('')
  const [selectedEleve, setSelectedEleve] = useState(null)
  const [scanType, setScanType] = useState('transport_embarquement')
  const [validating, setValidating] = useState(false); const [warning, setWarning] = useState(null)
  const [cameraError, setCameraError] = useState(null)
  const videoRef = useRef(null)
  const canvasRef = useRef(null)
  const elevesRef = useRef([])

  const canCreate = can('scans', 'can_create')

  async function load() {
    setLoading(true)
    setError(null)
    try {
      const [elevesRes, abosRes, scansRes] = await Promise.all([
        api.get('/crud.php?module=eleves'),
        can('abonnements', 'can_read') ? api.get('/crud.php?module=abonnements') : Promise.resolve({ data: [] }),
        can('scans', 'can_read') ? api.get('/crud.php?module=scans') : Promise.resolve({ data: [] }),
      ])
      setEleves(elevesRes.data || [])
      setAbonnements(abosRes.data || [])
      const today = new Date().toISOString().slice(0, 10)
      const todays = (scansRes.data || []).filter((s) => (s.scanned_at || '').slice(0, 10) === today)
      setScans(todays)
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

  useEffect(() => {
    elevesRef.current = eleves
  }, [eleves])

  function findByCode(value) {
    const v = value.trim().toLowerCase()
    if (!v) return null
    return elevesRef.current.find((e) => (e.qr_code || '').toLowerCase() === v || (e.code_dr || '').toLowerCase() === v)
  }

  function handleCodeSubmit(e) {
    e.preventDefault()
    const found = findByCode(codeValue)
    if (found) {
      setSelectedEleve(found)
      setError(null)
    } else {
      setError('Aucun eleve ne correspond a ce code')
    }
  }

  function handleCameraDetected(text) {
    const found = findByCode(text)
    if (found) {
      setSelectedEleve(found)
      setError(null)
    } else {
      setError('QR code non reconnu : ' + text)
    }
  }

  useEffect(() => {
    if (method !== 'camera') return
    let stream = null
    let raf = null
    let active = true
    setCameraError(null)

    async function tick() {
      if (!active) return
      const video = videoRef.current
      const canvas = canvasRef.current
      if (video && canvas && video.readyState === video.HAVE_ENOUGH_DATA) {
        canvas.width = video.videoWidth
        canvas.height = video.videoHeight
        const ctx = canvas.getContext('2d')
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height)
        const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height)
        if (window.jsQR) {
          const code = window.jsQR(imageData.data, imageData.width, imageData.height)
          if (code && code.data) {
            handleCameraDetected(code.data)
            return
          }
        }
      }
      raf = requestAnimationFrame(tick)
    }

    async function start() {
      try {
        await loadJsQR()
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
        if (!active) {
          stream.getTracks().forEach((t) => t.stop())
          return
        }
        videoRef.current.srcObject = stream
        await videoRef.current.play()
        tick()
      } catch (e) {
        setCameraError(e.message || 'Camera inaccessible')
      }
    }

    start()

    return () => {
      active = false
      if (raf) cancelAnimationFrame(raf)
      if (stream) stream.getTracks().forEach((t) => t.stop())
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [method])

  async function validateScan() {
    if (!selectedEleve) return
    setValidating(true); setWarning(null)
    setError(null)
    try {
      const token = localStorage.getItem('shipp_token')
      const res = await fetch(`${API_URL}/scan-enregistrer.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        body: JSON.stringify({
          eleve_id: selectedEleve.id,
          type: scanType,
          methode: method,
          ...(trajetParam ? { trajet_id: Number(trajetParam) } : {}),
        }),
      })
      const data = await res.json()
      if (!res.ok) throw new Error(data.message || 'Erreur lors de la validation du scan'); const avert = []
      if (data.abonnement_warning) avert.push(`abonnement ${data.abonnement_warning.replace('abonnement_', '')}`)
      if ((data.alertes || []).includes('eleve_non_affecte')) avert.push('eleve non affecte a ce circuit')
      setWarning(avert.length ? `Scan enregistre. Attention : ${avert.join(', ')}.` : null)
      setSelectedEleve(null)
      setCodeValue('')
      setSearchTerm('')
      await load()
    } catch (e) {
      setError(e.message)
    } finally {
      setValidating(false)
    }
  }

  const filteredEleves = searchTerm.trim()
    ? eleves.filter((e) => {
        const q = searchTerm.trim().toLowerCase()
        return (
          (e.nom || '').toLowerCase().includes(q) ||
          (e.prenom || '').toLowerCase().includes(q) ||
          (e.code_dr || '').toLowerCase().includes(q)
        )
      })
    : []

  const eleveAbonnements = selectedEleve
    ? abonnements.filter((a) => String(a.eleve_id) === String(selectedEleve.id))
    : []

  function scanEleveNom(scan) {
    const e = eleves.find((e) => String(e.id) === String(scan.eleve_id))
    return e ? `${e.nom} ${e.prenom}` : `Eleve #${scan.eleve_id}`
  }

  return (
    <div className="page">
      <p>
        <Link to="/">&larr; Tableau de bord</Link>
      </p>
      <h1>Scanner</h1>
      {trajetParam && <p><Link to="/mon-activite">&larr; Retour a mon trajet</Link></p>}
      {error && <p className="error-banner">{error}</p>}{warning && <p className="scanner-abo-warning">{warning}</p>}

      <div className="scanner-tabs">
        <button type="button" className={method === 'camera' ? 'active' : ''} onClick={() => setMethod('camera')}>
          Camera
        </button>
        <button
          type="button"
          className={method === 'recherche' ? 'active' : ''}
          onClick={() => setMethod('recherche')}
        >
          Recherche
        </button>
        <button type="button" className={method === 'code' ? 'active' : ''} onClick={() => setMethod('code')}>
          Code
        </button>
      </div>

      {method === 'camera' && (
        <div className="scanner-camera">
          {cameraError ? (
            <p className="error-banner">{cameraError}</p>
          ) : (
            <>
              <video ref={videoRef} className="scanner-video" muted playsInline />
              <canvas ref={canvasRef} style={{ display: 'none' }} />
              <p>Pointez la camera vers le QR code de l'eleve.</p>
            </>
          )}
        </div>
      )}

      {method === 'recherche' && (
        <div className="scanner-search">
          <input
            type="text"
            placeholder="Nom, prenom ou code DR"
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
          />
          {filteredEleves.length > 0 && (
            <ul className="scanner-search-results">
              {filteredEleves.map((e) => (
                <li key={e.id}>
                  <button type="button" onClick={() => setSelectedEleve(e)}>
                    {e.nom} {e.prenom} ({e.code_dr || '-'})
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}

      {method === 'code' && (
        <form onSubmit={handleCodeSubmit} className="scanner-code">
          <input
            type="text"
            placeholder="Code DR ou code QR"
            value={codeValue}
            onChange={(e) => setCodeValue(e.target.value)}
          />
          <button type="submit">Valider le code</button>
        </form>
      )}

      {selectedEleve && (
        <div className="scanner-confirmation">
          <h2>Confirmation</h2>
          <p>
            {selectedEleve.nom} {selectedEleve.prenom} — {selectedEleve.classe || '-'}
          </p>
          <p>Code DR : {selectedEleve.code_dr || '-'}</p>
          {eleveAbonnements.map((a) => (
            <p key={a.id} className={a.statut !== 'actif' ? 'scanner-abo-warning' : ''}>
              Abonnement {a.type} : {a.statut}
            </p>
          ))}

          {canCreate ? (
            <>
              <label className="module-form-field">
                <span>Type de scan</span>
                <select value={scanType} onChange={(e) => setScanType(e.target.value)}>
                  {Object.entries(TYPE_LABELS).map(([value, label]) => (
                    <option key={value} value={value}>
                      {label}
                    </option>
                  ))}
                </select>
              </label>
              <div className="module-form-actions">
                <button type="button" className={scanType.startsWith('transport') ? 'btn-transport' : 'btn-cantine'} disabled={validating} onClick={validateScan}>
                  {validating ? 'Validation...' : 'Valider le scan'}
                </button>
                <button type="button" onClick={() => setSelectedEleve(null)}>
                  Annuler
                </button>
              </div>
            </>
          ) : (
            <p>Vous n'avez pas le droit d'enregistrer un scan.</p>
          )}
        </div>
      )}

      <h2>Historique du jour</h2>
      {loading ? (
        <p>Chargement...</p>
      ) : (
        <div className="module-table-wrap">
          <table className="module-table">
            <thead>
              <tr>
                <th>Eleve</th>
                <th>Type</th>
                <th>Methode</th>
                <th>Heure</th>
              </tr>
            </thead>
            <tbody>
              {scans.map((s) => (
                <tr key={s.id}>
                  <td>{scanEleveNom(s)}</td>
                  <td>{TYPE_LABELS[s.type] || s.type}</td>
                  <td>{s.methode}</td>
                  <td>{(s.scanned_at || '').slice(11, 16)}</td>
                </tr>
              ))}
              {scans.length === 0 && (
                <tr>
                  <td colSpan={4}>Aucun scan aujourd'hui.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
