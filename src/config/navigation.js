// Menu lateral SHIPP : regroupe les ecrans existants par domaine.
// Chaque entree reprend exactement la condition d'affichage du tableau de bord
// historique : aucun droit n'est elargi, aucun ecran n'est ajoute ni retire.

const bureau = (scopeOf, m) => ['GLOBAL', 'SCHOOL'].includes(scopeOf(m))

export function construireMenu({ can, isAdmin, scopeOf, chauffeurId, parentEleveIds }) {
  const parent = parentEleveIds && parentEleveIds.length > 0
  const groupes = [
    {
      cle: 'accueil', titre: 'Accueil', items: [
        { to: '/', label: 'Tableau de bord', icon: 'home', exact: true, ok: true },
      ],
    },
    {
      cle: 'perso', titre: 'Mon espace', items: [
        { to: '/mon-activite', label: 'Mon trajet', icon: 'bus', ok: !!chauffeurId },
        { to: chauffeurId ? `/chauffeurs/${chauffeurId}` : '/', label: 'Mon dossier', icon: 'file', ok: !!chauffeurId, exact: true },
        { to: '/suivi-enfants', label: 'Suivi de mes enfants', icon: 'student', ok: parent },
      ],
    },
    {
      cle: 'transport', titre: 'Transport', icon: 'bus', items: [
        { to: '/fleet', label: "Centre d'exploitation", icon: 'grid', ok: can('affectations_chauffeur', 'can_read') },
        { to: '/suivi-flotte', label: 'Suivi flottes', icon: 'map', ok: can('suivi_gps', 'can_read') },
        { to: '/modules/circuits', label: 'Circuits', icon: 'pin', ok: can('circuits', 'can_read'), aussi: ['/circuits/'] },
        { to: '/trajets', label: 'Trajets', icon: 'route', ok: can('trajets', 'can_read') },
        { to: '/chauffeurs', label: 'Chauffeurs', icon: 'steering', ok: can('chauffeurs', 'can_read') && bureau(scopeOf, 'chauffeurs'), aussi: ['/chauffeurs/'] },
        { to: '/modules/vehicules', label: 'Véhicules', icon: 'car', ok: can('vehicules', 'can_read'), aussi: ['/vehicules/'] },
        { to: '/scanner', label: 'Présences (scanner)', icon: 'scan', ok: can('scans', 'can_create') },
        { to: '/incidents', label: 'Incidents', icon: 'alert', ok: can('incidents', 'can_read') && bureau(scopeOf, 'incidents'), aussi: ['/incidents/'] },
        { to: '/alertes', label: 'Alertes', icon: 'bell', ok: (can('chauffeur_documents', 'can_read') || can('incidents', 'can_edit')) && bureau(scopeOf, 'chauffeurs') },
        { to: '/modules/transport', label: 'Inscriptions transport', icon: 'list', ok: can('transport', 'can_read') },
      ],
    },
    {
      cle: 'cantine', titre: 'Cantine', icon: 'utensils', items: [
        { to: '/cantine-service', label: 'Service cantine', icon: 'utensils', ok: can('scans', 'can_create') },
        { to: '/modules/menus', label: 'Menus', icon: 'list', ok: can('menus', 'can_read') },
        { to: '/modules/cantine', label: 'Repas', icon: 'calendar', ok: can('cantine', 'can_read') },
      ],
    },
    {
      cle: 'eleves', titre: 'Élèves', icon: 'student', items: [
        { to: '/modules/eleves', label: 'Élèves', icon: 'student', ok: can('eleves', 'can_read'), aussi: ['/eleves/'] },
        { to: '/parents', label: 'Parents (comptes)', icon: 'users', ok: isAdmin },
        { to: '/modules/parents_eleves', label: 'Parents (fiches)', icon: 'users', ok: can('parents_eleves', 'can_read') },
        { to: '/abonnements', label: 'Abonnements', icon: 'file', ok: can('abonnements', 'can_read') },
        { to: '/modules/ecoles', label: 'Écoles', icon: 'school', ok: can('ecoles', 'can_read') },
        { to: '/annees-scolaires', label: 'Années scolaires', icon: 'calendar', ok: isAdmin },
      ],
    },
    {
      cle: 'finance', titre: 'Finance', icon: 'wallet', items: [
        { to: '/finance', label: 'Vue financière', icon: 'wallet', ok: can('finance', 'can_read') },
        { to: '/modules/finance', label: 'Écritures', icon: 'list', ok: can('finance', 'can_read') },
        { to: '/suivi-paiements', label: 'Paiements transport', icon: 'coins', ok: can('echeances_transport', 'can_read') && bureau(scopeOf, 'echeances_transport') },
        { to: '/remunerations', label: 'Rémunérations', icon: 'coins', ok: can('chauffeur_contracts', 'can_read') && bureau(scopeOf, 'chauffeur_contracts') },
      ],
    },
    {
      cle: 'reporting', titre: 'Reporting', icon: 'chart', items: [
        { to: '/reporting', label: 'Reporting', icon: 'chart', ok: can('reporting', 'can_read') && bureau(scopeOf, 'reporting') },
        { to: '/modules/rapports', label: 'Rapports', icon: 'file', ok: can('rapports', 'can_read') },
        { to: '/imports', label: 'Import ENKO', icon: 'upload', ok: can('imports', 'can_create') },
        { to: '/journal-activite', label: "Journal d'activité", icon: 'history', ok: isAdmin },
      ],
    },
    {
      cle: 'admin', titre: 'Administration', icon: 'settings', items: [
        { to: '/modules/utilisateurs', label: 'Utilisateurs', icon: 'user', ok: can('utilisateurs', 'can_read') },
        { to: '/rights', label: 'Droits et rôles', icon: 'shield', ok: isAdmin },
        { to: '/modules-ecole', label: 'Modules par école', icon: 'grid', ok: isAdmin },
        { to: '/modeles-contrat', label: 'Modèles de contrat', icon: 'file', ok: can('contract_templates', 'can_read') },
        { to: '/notifications', label: 'Notifications', icon: 'bell', ok: can('notifications', 'can_read') },
        { to: '/modules/notifications', label: 'Notifications (fiches)', icon: 'list', ok: can('notifications', 'can_read') },
      ],
    },
  ]
  return groupes.map((g) => ({ ...g, items: g.items.filter((i) => i.ok) })).filter((g) => g.items.length)
}

/** L'entree est-elle active pour ce chemin ? */
export function estActif(item, chemin) {
  if (item.exact) return chemin === item.to
  if (chemin === item.to || chemin.startsWith(item.to + '/')) return true
  return (item.aussi || []).some((p) => chemin.startsWith(p))
}
