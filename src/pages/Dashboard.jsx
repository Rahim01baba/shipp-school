import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../context/AuthContext.jsx'
import { api } from '../api/client.js'
import { construireMenu } from '../config/navigation.js'
import Icon from '../components/Icon.jsx'
import CarteShipp from '../components/CarteShipp.jsx'
import { ETATS_GPS, etatSelonAge, texteAge } from '../lib/gpsAffichage.js'

// Tableau de bord = centre de pilotage. Uniquement des donnees deja exposees par l'API,
// chaque bloc n'apparait que si l'utilisateur a le droit correspondant.

const NIVEAUX = { critique: ['CRITIQUE', 'danger'], alerte: ['ATTENTION', 'warning'], info: ['INFO', 'info'] }
const aujourdhui = () => new Date().toISOString().slice(0, 10)
const fcfa = (n) => {
  if (n >= 1e6) return `${(n / 1e6).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} M FCFA`
  return `${Math.round(n).toLocaleString('fr-FR')} FCFA`
}

export default function Dashboard() {
  const auth = useAuth()
  const { user, can, accessLoading, parentEleveIds, chauffeurId, scopeOf, isAdmin } = auth
  const [api_ok, setApiOk] = useState(null)
  const [d, setD] = useState(null)

  useEffect(() => {
    const apiUrl = import.meta.env.VITE_API_URL || '/api'
    fetch(`${apiUrl}/health.php`).then((r) => r.json()).then((x) => setApiOk(!!x.db)).catch(() => setApiOk(false))
  }, [])

  useEffect(() => {
    if (accessLoading) return
    const bureau = (m) => ['GLOBAL', 'SCHOOL'].includes(scopeOf(m))
    const jour = aujourdhui()
    const sur = (cond, f) => (cond ? f().catch(() => null) : Promise.resolve(null))
    Promise.all([
      sur(can('eleves', 'can_read'), () => api.get('/crud.php?module=eleves')),
      sur(can('trajets', 'can_read'), () => api.get('/crud.php?module=trajets')),
      sur(can('finance', 'can_read'), () => api.get('/crud.php?module=finance')),
      sur(can('reporting', 'can_read') && bureau('reporting'), () => api.get(`/reporting.php?rapport=synthese&date_debut=${jour}&date_fin=${jour}`)),
      sur((can('chauffeur_documents', 'can_read') || can('incidents', 'can_edit')) && bureau('chauffeurs'), () => api.get('/alertes.php')),
      sur(can('suivi_gps', 'can_read'), () => api.get('/flotte-gps.php')),
      sur(can('annees_scolaires', 'can_read'), () => api.get('/crud.php?module=annees_scolaires')),
      sur(can('abonnements', 'can_read'), () => api.get('/crud.php?module=abonnements')),
    ]).then(([eleves, trajets, finance, synthese, alertes, flotte, annees, abonnements]) => {
      const x = { recuA: Date.now() }
      if (eleves) {
        const l = eleves.data || []
        x.eleves = { total: l.length, actifs: l.filter((e) => e.statut === 'actif').length }
        if (parentEleveIds?.length) x.enfants = l
      }
      if (trajets) {
        const l = (trajets.data || []).filter((t) => t.date_trajet === jour)
        x.trajets = { total: l.length, en_cours: l.filter((t) => t.statut === 'en_cours').length, planifies: l.filter((t) => t.statut === 'planifie').length, termines: l.filter((t) => t.statut === 'termine').length, annules: l.filter((t) => t.statut === 'annule').length }
      }
      if (finance) {
        const l = (finance.data || []).filter((f) => (f.type || 'recette') === 'recette')
        const somme = (st) => l.filter((f) => st.includes(f.statut)).reduce((s, f) => s + Number(f.montant || 0), 0)
        x.finance = { encaisse: somme(['payee']), attente: somme(['en_attente', 'en_retard']), retard: l.filter((f) => f.statut === 'en_retard').length }
      }
      if (synthese?.data) {
        const v = Object.fromEntries(synthese.data.map((r) => [r.cle, Number(r.valeur) || 0]))
        const base = v.eleves_transportes + v.absences
        x.presence = { presents: v.eleves_transportes, absents: v.absences, taux: base ? Math.round((v.eleves_transportes / base) * 100) : null, incidents: v.incidents + v.accidents }
      }
      if (alertes) {
        const l = alertes.data || []
        x.alertes = { liste: l, critiques: l.filter((a) => a.niveau === 'critique').length, attention: l.filter((a) => a.niveau === 'alerte').length }
      }
      if (abonnements) {
        const l = abonnements.data || []
        x.abonnements = { total: l.length, actifs: l.filter((a) => a.statut === 'actif').length }
      }
      if (flotte) x.flotte = flotte
      if (annees) x.annee = (annees.data || []).find((a) => a.statut === 'active')?.libelle || null
      setD(x)
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [accessLoading])

  const vehicules = useMemo(() => {
    if (!d?.flotte) return []
    return d.flotte.vehicules.map((v) => ({ ...v, etat: etatSelonAge(v.etat_gps, v.age_secondes, d.flotte.parametres) }))
  }, [d])
  const gps = useMemo(() => {
    const k = { actif: 0, ancienne: 0, perdu: 0 }
    vehicules.forEach((v) => { if (v.etat in k) k[v.etat]++ })
    return k
  }, [vehicules])

  const prenom = (user?.name || '').split(' ')[0]
  const dateLongue = new Date().toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })
  const raccourcis = accessLoading ? [] : construireMenu(auth).filter((g) => !['accueil', 'perso'].includes(g.cle)).map((g) => g.items[0])
  const enCirculation = d?.flotte ? d.flotte.kpi.courses_actives : d?.trajets?.en_cours
  const chargement = !d

  return (
    <div className="page-shell">
      <header className="page-header">
        <div>
          <h1>Bonjour {prenom}</h1>
          <p className="page-header-sous">
            <span className="capitalise">{dateLongue}</span>
            {d?.annee && <> · Année scolaire {d.annee}</>}
          </p>
        </div>
        <span className={`badge ${api_ok === false ? 'badge-danger' : api_ok ? 'badge-success' : 'badge-neutral'}`} role="status">
          {api_ok === false ? 'Service injoignable' : api_ok ? 'Service opérationnel' : 'Vérification...'}
        </span>
      </header>

      {/* Espaces personnels : chauffeur et parent vont droit a l'essentiel */}
      {!accessLoading && chauffeurId && (
        <Link to="/mon-activite" className="card card-action card-mise-en-avant">
          <span className="card-action-icone"><Icon name="bus" size={28} /></span>
          <span><strong>Mon trajet du jour</strong><small>Démarrer la course, les arrêts et les élèves</small></span>
          <Icon name="chevronRight" />
        </Link>
      )}
      {!accessLoading && parentEleveIds?.length > 0 && (
        <section className="section">
          <div className="section-tete"><h2>Mes enfants</h2><Link className="btn btn-secondary btn-sm" to="/suivi-enfants">Suivi du transport</Link></div>
          <div className="grille-cartes">
            {(d?.enfants || []).map((e) => (
              <Link key={e.id} to={`/eleves/${e.id}`} className="card card-action">
                <span className="card-action-icone"><Icon name="student" size={22} /></span>
                <span><strong>{e.prenom} {e.nom}</strong><small>{e.classe || 'Classe non renseignée'}</small></span>
                <Icon name="chevronRight" />
              </Link>
            ))}
          </div>
        </section>
      )}

      {/* Indicateurs */}
      <div className="kpi-grille" aria-busy={chargement}>
        {chargement && [1, 2, 3, 4].map((n) => <div key={n} className="kpi-card"><span className="squelette squelette-titre" /><span className="squelette squelette-valeur" /></div>)}
        {d?.eleves && <Kpi icone="student" titre="Élèves" valeur={d.eleves.actifs} detail={`${d.eleves.total} inscrits au total`} to="/modules/eleves" />}
        {enCirculation != null && (
          <Kpi icone="bus" titre="En circulation" valeur={enCirculation} ton={enCirculation ? 'success' : undefined}
            detail={d?.flotte ? (gps.perdu ? `${gps.perdu} GPS perdu(s)` : `${gps.actif} GPS actif(s)`) : `${d?.trajets?.total ?? 0} trajets aujourd'hui`}
            detailTon={d?.flotte && gps.perdu ? 'danger' : undefined} to={can('suivi_gps', 'can_read') ? '/suivi-flotte' : '/trajets'} />
        )}
        {d?.presence && <Kpi icone="check" titre="Présences transport" valeur={d.presence.taux != null ? `${d.presence.taux} %` : '—'} detail={`${d.presence.presents} présents · ${d.presence.absents} absents`} ton={d.presence.taux == null ? undefined : d.presence.taux >= 90 ? 'success' : 'warning'} to="/reporting" />}
        {d?.finance && <Kpi icone="wallet" titre="Finance encaissée" valeur={fcfa(d.finance.encaisse)} detail={`${fcfa(d.finance.attente)} en attente`} detailTon={d.finance.retard ? 'warning' : undefined} to="/finance" />}
        {d?.abonnements && <Kpi icone="file" titre="Abonnements actifs" valeur={d.abonnements.actifs} detail={`${d.abonnements.total} au total`} to="/abonnements" />}
        {d?.alertes && <Kpi icone="alert" titre="Alertes" valeur={d.alertes.critiques + d.alertes.attention} ton={d.alertes.critiques ? 'danger' : d.alertes.attention ? 'warning' : 'success'} detail={d.alertes.critiques ? `${d.alertes.critiques} critique(s)` : d.alertes.attention ? `${d.alertes.attention} à surveiller` : 'Aucun problème'} detailTon={d.alertes.critiques ? 'danger' : undefined} to="/alertes" />}
      </div>

      <div className="grille-dashboard">
        {/* Transport du jour */}
        {(d?.trajets || d?.presence) && (
          <section className="card">
            <div className="section-tete"><h2><Icon name="bus" /> Transport aujourd'hui</h2>{can('trajets', 'can_read') && <Link className="btn btn-ghost btn-sm" to="/trajets">Voir tout</Link>}</div>
            {d.trajets && (
              <ul className="liste-etats">
                <li><span className="status-dot status-success" /><strong>{d.trajets.en_cours}</strong> en circulation</li>
                <li><span className="status-dot status-warning" /><strong>{d.trajets.planifies}</strong> en attente de départ</li>
                <li><span className="status-dot status-neutral" /><strong>{d.trajets.termines}</strong> terminé(s)</li>
                {d.presence && <li><span className="status-dot status-danger" /><strong>{d.presence.incidents}</strong> incident(s) aujourd'hui</li>}
              </ul>
            )}
            {d.trajets?.total === 0 && <Vide icone="route" titre="Aucun trajet aujourd'hui" texte="Les trajets du jour se génèrent depuis l'écran Trajets." action={can('trajets', 'can_read') ? { to: '/trajets', label: 'Ouvrir les trajets' } : null} />}
            {d.presence && d.presence.taux != null && (
              <div className="progression" aria-label={`Présence ${d.presence.taux} %`}>
                <div className="progression-tete"><span>Présence du jour</span><strong>{d.presence.taux} %</strong></div>
                <div className="progression-barre"><span style={{ width: `${d.presence.taux}%` }} /></div>
                <div className="progression-legende"><span><span className="status-dot status-success" />{d.presence.presents} présents</span><span><span className="status-dot status-danger" />{d.presence.absents} absents</span></div>
              </div>
            )}
          </section>
        )}

        {/* Apercu Suivi flottes */}
        {d?.flotte && (
          <section className="card card-carte">
            <div className="section-tete"><h2><Icon name="map" /> Suivi flottes</h2></div>
            {vehicules.length > 0 ? (
              <>
                <CarteShipp className="apercu-carte" cadrer={vehicules.filter((v) => v.position).map((v) => [v.position.lat, v.position.lng])} cleCadrage="apercu"
                  marqueurs={vehicules.filter((v) => v.position).map((v) => ({ id: v.trajet_id, lat: v.position.lat, lng: v.position.lng, type: 'vehicule', texte: '🚐', libelle: v.vehicule, rotation: v.position.cap, couleur: ETATS_GPS[v.etat].couleur, titre: `${v.vehicule} — ${ETATS_GPS[v.etat].libelle}` }))} />
                <ul className="liste-compacte">
                  {vehicules.slice(0, 4).map((v) => (
                    <li key={v.trajet_id}><span className={`badge badge-${v.etat === 'actif' ? 'success' : v.etat === 'ancienne' ? 'warning' : v.etat === 'perdu' ? 'danger' : 'neutral'}`}>{ETATS_GPS[v.etat].court}</span><strong>{v.vehicule}</strong><span className="texte-discret">{v.circuit} · {texteAge(v.age_secondes)}</span></li>
                  ))}
                </ul>
              </>
            ) : <Vide icone="map" titre="Aucun véhicule en circulation" texte="Les véhicules apparaissent ici dès qu'un chauffeur démarre sa course." />}
            <div className="card-pied"><Link className="btn btn-primary" to="/suivi-flotte"><Icon name="map" size={18} />Ouvrir le suivi complet</Link></div>
          </section>
        )}

        {/* Alertes prioritaires */}
        {d?.alertes && (
          <section className="card">
            <div className="section-tete"><h2><Icon name="alert" /> Alertes prioritaires</h2><Link className="btn btn-ghost btn-sm" to="/alertes">Voir tout</Link></div>
            {d.alertes.liste.length === 0 ? <Vide icone="check" titre="Aucune alerte" texte="Documents, contrats, véhicules et incidents sont en ordre." /> : (
              <ul className="liste-alertes">
                {d.alertes.liste.slice(0, 5).map((a, n) => {
                  const [lib, ton] = NIVEAUX[a.niveau] || NIVEAUX.info
                  return (
                    <li key={n} className={`alerte-ligne alerte-${ton}`}>
                      <span className={`badge badge-${ton}`}>{lib}</span>
                      <span>{a.chauffeur_nom && <strong>{a.chauffeur_nom} · </strong>}{a.message}</span>
                    </li>
                  )
                })}
              </ul>
            )}
          </section>
        )}
      </div>

      {/* Acces rapide (memes droits que le menu) */}
      {raccourcis.length > 0 && (
        <section className="section">
          <h2 className="titre-section">Accès rapide</h2>
          <div className="grille-raccourcis">
            {raccourcis.map((r) => (
              <Link key={r.to} to={r.to} className="raccourci"><Icon name={r.icon} size={22} /><span>{r.label}</span></Link>
            ))}
          </div>
        </section>
      )}
      {!accessLoading && !isAdmin && raccourcis.length === 0 && !chauffeurId && !parentEleveIds?.length && (
        <Vide icone="shield" titre="Aucun module accessible" texte="Votre compte n'a encore aucun droit. Contactez l'administrateur SHIPP." />
      )}
    </div>
  )
}

function Kpi({ icone, titre, valeur, detail, ton, detailTon, to }) {
  const contenu = (
    <>
      <span className={`kpi-icone${ton ? ` kpi-icone-${ton}` : ''}`}><Icon name={icone} size={20} /></span>
      <span className="kpi-titre">{titre}</span>
      <span className="kpi-valeur">{valeur}</span>
      {detail && <span className={`kpi-detail${detailTon ? ` texte-${detailTon}` : ''}`}>{detail}</span>}
    </>
  )
  return to ? <Link to={to} className="kpi-card kpi-lien">{contenu}</Link> : <div className="kpi-card">{contenu}</div>
}

export function Vide({ icone = 'grid', titre, texte, action }) {
  return (
    <div className="empty-state">
      <span className="empty-state-icone"><Icon name={icone} size={26} /></span>
      <strong>{titre}</strong>
      {texte && <p>{texte}</p>}
      {action && <Link className="btn btn-secondary btn-sm" to={action.to}>{action.label}</Link>}
    </div>
  )
}
