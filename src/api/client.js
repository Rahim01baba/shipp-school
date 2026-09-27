const API_URL = import.meta.env.VITE_API_URL || '/api'

function getToken() {
  return localStorage.getItem('shipp_token')
}

export function setToken(token) {
  if (token) localStorage.setItem('shipp_token', token)
  else localStorage.removeItem('shipp_token')
}

async function request(path, options = {}) {
  const token = getToken()
  const headers = {
    'Content-Type': 'application/json',
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    ...options.headers
  }

  const res = await fetch(`${API_URL}${path}`, { ...options, headers })

  if (res.status === 401) {
    setToken(null)
  }

  const contentType = res.headers.get('content-type') || ''
  const data = contentType.includes('application/json') ? await res.json() : null

  if (!res.ok) {
    const message = data?.message || `Erreur API (${res.status})`
    throw new Error(message)
  }

  return data
}

// Depot de fichier (multipart) : le navigateur fixe lui-meme le Content-Type.
async function upload(path, fields, file) {
  const token = getToken()
  const form = new FormData()
  Object.entries(fields).forEach(([k, v]) => { if (v !== undefined && v !== null) form.append(k, v) })
  form.append('fichier', file)
  const res = await fetch(`${API_URL}${path}`, { method: 'POST', body: form, headers: token ? { Authorization: `Bearer ${token}` } : {} })
  if (res.status === 401) setToken(null)
  const data = (res.headers.get('content-type') || '').includes('application/json') ? await res.json() : null
  if (!res.ok) throw new Error(data?.message || `Erreur API (${res.status})`)
  return data
}

// Ouverture d'un fichier prive : telecharge avec le jeton puis ouvre une URL locale.
async function openFile(id) {
  const token = getToken()
  const res = await fetch(`${API_URL}/fichier.php?id=${id}`, { headers: token ? { Authorization: `Bearer ${token}` } : {} })
  if (!res.ok) throw new Error(res.status === 404 ? 'Fichier introuvable ou non autorise' : `Erreur API (${res.status})`)
  const url = URL.createObjectURL(await res.blob())
  window.open(url, '_blank', 'noopener')
  setTimeout(() => URL.revokeObjectURL(url), 60000)
}

// Telechargement d'un export (CSV...) avec le jeton.
async function download(path, filename) {
  const token = getToken()
  const res = await fetch(`${API_URL}${path}`, { headers: token ? { Authorization: `Bearer ${token}` } : {} })
  if (!res.ok) {
    const data = (res.headers.get('content-type') || '').includes('application/json') ? await res.json() : null
    throw new Error(data?.message || `Erreur API (${res.status})`)
  }
  const url = URL.createObjectURL(await res.blob())
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 10000)
}

export const api = {
  upload,
  openFile,
  download,
  get: (path) => request(path, { method: 'GET' }),
  post: (path, body) => request(path, { method: 'POST', body: JSON.stringify(body) }),
  put: (path, body) => request(path, { method: 'PUT', body: JSON.stringify(body) }),
  del: (path, body) => request(path, { method: 'DELETE', ...(body ? { body: JSON.stringify(body) } : {}) })
}
