import { useEffect, useMemo, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useAuth } from '../context/AuthContext.jsx'
import { api } from '../api/client.js'
import { construireMenu, estActif } from '../config/navigation.js'
import Icon from './Icon.jsx'

// Cadre commun de l'application : menu lateral groupe (repliable), barre du haut,
// menu mobile (tiroir). Aucun droit n'est modifie : le menu reprend les conditions existantes.

const CLE_REPLIS = 'shipp_menu_replis'
const ROLES = { admin: 'Administrateur', fleet_manager: 'Gestionnaire de flotte', chauffeur: 'Chauffeur', parent: 'Parent', rh: 'RH', restaurant: 'Restaurant', eleves: 'Élève' }

function lireReplis() {
  try {
    return JSON.parse(localStorage.getItem(CLE_REPLIS) || '{}')
  } catch {
    return {}
  }
}

export function Logo() {
  return (
    <span className="logo">
      <span className="logo-marque" aria-hidden="true">
        <svg viewBox="0 0 32 32" width="32" height="32"><rect width="32" height="32" rx="8" fill="currentColor" /><path d="M16 6c-4.4 0-8 3.4-8 7.7C8 19.6 16 26 16 26s8-6.4 8-12.3C24 9.4 20.4 6 16 6zm0 10.6a3 3 0 1 1 0-6 3 3 0 0 1 0 6z" fill="#fff" /><circle cx="16" cy="13.6" r="1.4" fill="#F28C28" /></svg>
      </span>
      <span className="logo-texte">SHIPP<span>School</span></span>
    </span>
  )
}

export default function AppShell({ children }) {
  const auth = useAuth()
  const { user, logout, accessLoading, roles } = auth
  const { pathname } = useLocation()
  const [ouvert, setOuvert] = useState(false)
  const [replis, setReplis] = useState(lireReplis)
  const [nonLues, setNonLues] = useState(0)

  const groupes = useMemo(() => (accessLoading ? [] : construireMenu(auth)),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [accessLoading, auth.isAdmin, auth.permissions, auth.chauffeurId, auth.parentEleveIds])

  useEffect(() => { setOuvert(false) }, [pathname])
  useEffect(() => {
    if (accessLoading || !user) return
    api.get('/notifications-moi.php').then((r) => setNonLues(r.non_lues || 0)).catch(() => {})
  }, [accessLoading, user])

  const actif = useMemo(() => {
    for (const g of groupes) for (const i of g.items) if (estActif(i, pathname)) return { g, i }
    return null
  }, [groupes, pathname])

  function basculer(cle) {
    const n = { ...replis, [cle]: !replis[cle] }
    setReplis(n)
    try { localStorage.setItem(CLE_REPLIS, JSON.stringify(n)) } catch { /* stockage indisponible */ }
  }

  const role = (roles || []).map((r) => ROLES[r.role_key || r] || r.role_label || r).filter(Boolean)[0]
  const initiales = (user?.name || '?').split(/\s+/).map((m) => m[0]).slice(0, 2).join('').toUpperCase()

  return (
    <div className={`shell${ouvert ? ' shell-menu-ouvert' : ''}`}>
      <a className="lien-evitement" href="#contenu">Aller au contenu</a>
      <aside className="sidebar" aria-label="Menu principal">
        <div className="sidebar-tete">
          <Link to="/" className="sidebar-logo" aria-label="SHIPP School, tableau de bord"><Logo /></Link>
          <button type="button" className="sidebar-fermer" aria-label="Fermer le menu" onClick={() => setOuvert(false)}><Icon name="close" /></button>
        </div>
        <nav className="sidebar-nav">
          {accessLoading && <div className="sidebar-squelette" aria-hidden="true">{[1, 2, 3, 4, 5, 6].map((n) => <span key={n} className="squelette" />)}</div>}
          {groupes.map((g) => {
            const contientActif = actif?.g.cle === g.cle
            const replie = g.titre !== 'Accueil' && replis[g.cle] && !contientActif
            return (
              <div key={g.cle} className="nav-groupe">
                {g.cle !== 'accueil' && (
                  <button type="button" className="nav-groupe-titre" aria-expanded={!replie} onClick={() => basculer(g.cle)}>
                    <span>{g.titre}</span>
                    <Icon name="chevron" size={16} className={replie ? 'nav-chevron-replie' : ''} />
                  </button>
                )}
                {!replie && (
                  <ul className="nav-liste">
                    {g.items.map((i) => {
                      const on = actif?.i === i
                      return (
                        <li key={i.to + i.label}>
                          <Link to={i.to} className={`nav-lien${on ? ' nav-lien-actif' : ''}`} aria-current={on ? 'page' : undefined} title={i.label}>
                            <Icon name={i.icon} size={18} />
                            <span className="nav-libelle">{i.label}</span>
                            {i.to === '/notifications' && nonLues > 0 && <span className="nav-compteur" aria-label={`${nonLues} non lues`}>{nonLues}</span>}
                          </Link>
                        </li>
                      )
                    })}
                  </ul>
                )}
              </div>
            )
          })}
        </nav>
      </aside>
      <div className="shell-voile" onClick={() => setOuvert(false)} aria-hidden="true" />

      <div className="shell-principal">
        <header className="topbar">
          <button type="button" className="topbar-menu" aria-label="Ouvrir le menu" onClick={() => setOuvert(true)}><Icon name="menu" /></button>
          <div className="topbar-fil" aria-label="Vous êtes ici">
            {actif && actif.g.cle !== 'accueil' && <><span className="topbar-fil-groupe">{actif.g.titre}</span><Icon name="chevronRight" size={14} /></>}
            <span className="topbar-fil-page">{actif ? actif.i.label : 'SHIPP School'}</span>
          </div>
          <div className="topbar-actions">
            {auth.can('notifications', 'can_read') && (
              <Link to="/notifications" className="topbar-icone" aria-label={`Notifications${nonLues ? ` (${nonLues} non lues)` : ''}`}>
                <Icon name="bell" />
                {nonLues > 0 && <span className="topbar-pastille">{nonLues > 9 ? '9+' : nonLues}</span>}
              </Link>
            )}
            <div className="topbar-utilisateur">
              <span className="avatar" aria-hidden="true">{initiales}</span>
              <span className="topbar-id">
                <strong>{user?.name}</strong>
                {role && <small>{role}</small>}
              </span>
            </div>
            <button type="button" className="btn btn-ghost btn-sm" onClick={logout} title="Se déconnecter">
              <Icon name="logout" size={18} /><span className="masque-mobile">Déconnexion</span>
            </button>
          </div>
        </header>
        <main id="contenu" className="shell-contenu" tabIndex={-1}>{children}</main>
      </div>
    </div>
  )
}
