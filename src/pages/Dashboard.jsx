import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../context/AuthContext.jsx'
import { api } from '../api/client.js'
import { MODULES } from '../config/modules.js'

export default function Dashboard() {
  const { user, logout, isAdmin, can, accessLoading, parentEleveIds, chauffeurId, scopeOf } = useAuth()
  const [status, setStatus] = useState('Verification de l\'API...')
  const [widgets, setWidgets] = useState(null)
  const [notifCount, setNotifCount] = useState(0)
  const [myChildren, setMyChildren] = useState([])

  useEffect(() => {
    const apiUrl = import.meta.env.VITE_API_URL || '/api'
    fetch(`${apiUrl}/health.php`)
      .then((r) => r.json())
      .then((data) => setStatus(`API OK — base de donnees: ${data.db ? 'connectee' : 'indisponible'}`))
      .catch(() => setStatus('API injoignable'))
  }, [])

  useEffect(() => {
    if (accessLoading) return
    async function loadWidgets() {
      const today = new Date().toISOString().slice(0, 10)
      const [eleves, abonnements, trajets, scans, notifications] = await Promise.all([
        can('eleves', 'can_read') ? api.get('/crud.php?module=eleves') : Promise.resolve(null),
        can('abonnements', 'can_read') ? api.get('/crud.php?module=abonnements') : Promise.resolve(null),
        can('trajets', 'can_read') ? api.get('/crud.php?module=trajets') : Promise.resolve(null),
        can('scans', 'can_read') ? api.get('/crud.php?module=scans') : Promise.resolve(null),
        can('notifications', 'can_read') ? api.get('/crud.php?module=notifications') : Promise.resolve(null),
      ])

      const w = {}
      if (eleves) {
        const list = eleves.data || []
        w.eleves = { total: list.length, actifs: list.filter((e) => e.statut === 'actif').length }
      }
      if (abonnements) {
        const list = abonnements.data || []
        w.abonnements = { total: list.length, actifs: list.filter((a) => a.statut === 'actif').length }
      }
      if (trajets) {
        const list = (trajets.data || []).filter((t) => t.date_trajet === today)
        w.trajets = {
          total: list.length,
          enCours: list.filter((t) => t.statut === 'en_cours').length,
        }
      }
      if (scans) {
        const list = (scans.data || []).filter((s) => (s.scanned_at || '').slice(0, 10) === today)
        w.scans = { total: list.length }
      }
      setWidgets(w)
      if (parentEleveIds && parentEleveIds.length > 0 && eleves) {
        setMyChildren(eleves.data || [])
      }

      // Nombre de notifications non lues de l'utilisateur (lecture individuelle).
      try {
        const mine = await api.get('/notifications-moi.php')
        setNotifCount(mine.non_lues || 0)
      } catch {
        if (notifications) {
          setNotifCount((notifications.data || []).filter((n) => n.statut !== 'lue').length)
        }
      }
    }
    loadWidgets()
  }, [accessLoading, can])

  const visibleModules = MODULES.filter((m) => can(m.key, 'can_read'))

  return (
    <div className="page">
      <div className="dashboard-header">
        <div>
          <h1>SHIPP</h1>
          <p>{status}</p>
          {user && (
            <p>
              Connecte en tant que {user.name} ({user.email})
            </p>
          )}
        </div>
        <button type="button" onClick={logout}>
          Se deconnecter
        </button>
      </div>

      {parentEleveIds && parentEleveIds.length > 0 && (
        <>
          <h2>Mes enfants</h2>
          <div className="module-links">
            {myChildren.map((e) => (
              <Link key={e.id} to={`/eleves/${e.id}`} className="module-link">
                {e.nom} {e.prenom}
              </Link>
            ))}
          </div>
        </>
      )}

      {widgets && (
        <div className="dashboard-widgets">
          {widgets.eleves && (
            <div className="dashboard-widget">
              <span className="dashboard-widget-value">{widgets.eleves.actifs}</span>
              <span className="dashboard-widget-label">Eleves actifs / {widgets.eleves.total}</span>
            </div>
          )}
          {widgets.abonnements && (
            <div className="dashboard-widget">
              <span className="dashboard-widget-value">{widgets.abonnements.actifs}</span>
              <span className="dashboard-widget-label">Abonnements actifs / {widgets.abonnements.total}</span>
            </div>
          )}
          {widgets.trajets && (
            <div className="dashboard-widget">
              <span className="dashboard-widget-value">{widgets.trajets.enCours}</span>
              <span className="dashboard-widget-label">Trajets en cours ({widgets.trajets.total} aujourd'hui)</span>
            </div>
          )}
          {widgets.scans && (
            <div className="dashboard-widget">
              <span className="dashboard-widget-value">{widgets.scans.total}</span>
              <span className="dashboard-widget-label">Scans aujourd'hui</span>
            </div>
          )}
        </div>
      )}

      {!accessLoading && (chauffeurId || (parentEleveIds && parentEleveIds.length > 0)) && (
        <div className="module-links ma-raccourcis">
          {chauffeurId && (
            <Link to="/mon-activite" className="module-link ma-raccourci">Mon activite aujourd'hui</Link>
          )}
          {chauffeurId && (
            <Link to={`/chauffeurs/${chauffeurId}`} className="module-link ma-raccourci">Mon dossier (permis, contrat, alertes)</Link>
          )}
          {parentEleveIds && parentEleveIds.length > 0 && (
            <Link to="/suivi-enfants" className="module-link ma-raccourci">Suivi de mes enfants</Link>
          )}
        </div>
      )}

      <h2>Modules</h2>
      {accessLoading ? (
        <p>Chargement des droits...</p>
      ) : (
        <div className="module-links">
          {isAdmin && (
            <Link to="/rights" className="module-link">
              Gestion des droits
            </Link>
          )}
          {isAdmin && (
            <Link to="/annees-scolaires" className="module-link">
              Annees scolaires
            </Link>
          )}
          {isAdmin && (
            <Link to="/parents" className="module-link">
              Parents
            </Link>
          )}
          {isAdmin && (
            <Link to="/journal-activite" className="module-link">
              Journal d'activite
            </Link>
          )}
          {isAdmin && (
            <Link to="/modules-ecole" className="module-link">
              Modules par ecole
            </Link>
          )}
          {can('chauffeurs', 'can_read') && ['GLOBAL', 'SCHOOL'].includes(scopeOf('chauffeurs')) && (
            <Link to="/chauffeurs" className="module-link">
              Chauffeurs
            </Link>
          )}
          {can('incidents', 'can_read') && ['GLOBAL', 'SCHOOL'].includes(scopeOf('incidents')) && (
            <Link to="/incidents" className="module-link">
              Incidents et accidents
            </Link>
          )}
          {(can('chauffeur_documents', 'can_read') || can('incidents', 'can_edit')) && ['GLOBAL', 'SCHOOL'].includes(scopeOf('chauffeurs')) && (
            <Link to="/alertes" className="module-link">
              Alertes
            </Link>
          )}
          {can('reporting', 'can_read') && ['GLOBAL', 'SCHOOL'].includes(scopeOf('reporting')) && (
            <Link to="/reporting" className="module-link">
              Reporting
            </Link>
          )}
          {can('echeances_transport', 'can_read') && ['GLOBAL', 'SCHOOL'].includes(scopeOf('echeances_transport')) && (
            <Link to="/suivi-paiements" className="module-link">
              Suivi des paiements transport
            </Link>
          )}
          {can('chauffeur_contracts', 'can_read') && ['GLOBAL', 'SCHOOL'].includes(scopeOf('chauffeur_contracts')) && (
            <Link to="/remunerations" className="module-link">
              Remunerations chauffeurs
            </Link>
          )}
          {can('imports', 'can_create') && (
            <Link to="/imports" className="module-link">
              Import du suivi ENKO
            </Link>
          )}
          {can('contract_templates', 'can_read') && (
            <Link to="/modeles-contrat" className="module-link">
              Modeles de contrat
            </Link>
          )}
          {can('trajets', 'can_read') && (
            <Link to="/trajets" className="module-link">
              Trajets
            </Link>
          )}
          {can('affectations_chauffeur', 'can_read') && (
            <Link to="/fleet" className="module-link">
              Centre d'exploitation Fleet
            </Link>
          )}
          {can('scans', 'can_create') && (
            <Link to="/scanner" className="module-link">
              Scanner
            </Link>
          )}
          {can('scans', 'can_create') && (
            <Link to="/cantine-service" className="module-link">
              Service Cantine
            </Link>
          )}
          {can('abonnements', 'can_read') && (
                          <Link to="/abonnements" className="module-link">
                                            Abonnements
                                          </Link>
                                        )}
                            {can('finance', 'can_read') && (<Link to="/finance" className="module-link">Finance</Link>)}{can('notifications', 'can_read') && (
            <Link to="/notifications" className="module-link">
              Notifications{notifCount > 0 ? ` (${notifCount})` : ''}
            </Link>
          )}
          {visibleModules.map((m) => (
            <Link key={m.key} to={`/modules/${m.key}`} className="module-link">
              {m.label}
            </Link>
          ))}
          {visibleModules.length === 0 && !isAdmin && <p>Aucun module accessible avec votre compte.</p>}
        </div>
      )}
    </div>
  )
}
