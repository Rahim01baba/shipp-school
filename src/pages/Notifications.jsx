import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client.js'
import { useAuth } from '../context/AuthContext.jsx'

// Notifications de l'utilisateur, avec lecture individuelle (notifications-moi.php).
// Les administrateurs disposent en plus de la liste globale (lecture seule).
export default function Notifications() {
  const { isAdmin, accessLoading } = useAuth()
  const [onglet, setOnglet] = useState('miennes')
  const [miennes, setMiennes] = useState([])
  const [nonLues, setNonLues] = useState(0)
  const [toutes, setToutes] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [marking, setMarking] = useState(null)

  async function load() {
    setLoading(true)
    setError(null)
    try {
      const res = await api.get('/notifications-moi.php')
      setMiennes(res.data || [])
      setNonLues(res.non_lues || 0)
      if (isAdmin) {
        const all = await api.get('/crud.php?module=notifications')
        setToutes((all.data || []).slice().sort((a, b) => (a.created_at < b.created_at ? 1 : -1)))
      }
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
  }, [accessLoading, isAdmin])

  async function marquerLue(id) {
    setMarking(id)
    setError(null)
    try {
      await api.put('/notifications-moi.php', id === 'tout' ? { tout: true } : { id })
      await load()
    } catch (e) {
      setError(e.message)
    } finally {
      setMarking(null)
    }
  }

  const liste = onglet === 'miennes' ? miennes : toutes

  return (
    <div className="page">
      <p>
        <Link to="/">&larr; Tableau de bord</Link>
      </p>
      <h1>Notifications</h1>
      {error && <p className="error-banner">{error}</p>}
      <div className="scanner-tabs">
        <button type="button" className={onglet === 'miennes' ? 'active' : ''} onClick={() => setOnglet('miennes')}>
          Mes notifications{nonLues > 0 ? ` (${nonLues})` : ''}
        </button>
        {isAdmin && (
          <button type="button" className={onglet === 'toutes' ? 'active' : ''} onClick={() => setOnglet('toutes')}>
            Toutes (administration)
          </button>
        )}
      </div>
      {onglet === 'miennes' && nonLues > 0 && (
        <p>
          <button type="button" disabled={marking === 'tout'} onClick={() => marquerLue('tout')}>
            Tout marquer comme lu
          </button>
        </p>
      )}
      {loading ? (
        <p>Chargement...</p>
      ) : (
        <div className="notifications-list">
          {liste.map((n) => {
            const nonLue = onglet === 'miennes' ? !n.lu_at : n.statut !== 'lue'
            return (
              <div key={n.id} className={nonLue ? 'notification-card unread' : 'notification-card'}>
                <div>
                  <p className="notification-titre">{n.titre}</p>
                  <p>{n.message}</p>
                  <p className="notification-meta">
                    {String(n.created_at || '').slice(0, 16)}
                    {onglet === 'toutes' && n.cible ? ` · ${n.cible}` : ''}
                  </p>
                </div>
                {onglet === 'miennes' && nonLue && (
                  <button type="button" disabled={marking === n.id} onClick={() => marquerLue(n.id)}>
                    {marking === n.id ? '...' : 'Marquer lue'}
                  </button>
                )}
              </div>
            )
          })}
          {liste.length === 0 && <p>Aucune notification.</p>}
        </div>
      )}
    </div>
  )
}
