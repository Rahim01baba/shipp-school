// Libelles et regles d'affichage communs a la carte flotte, a l'historique et au chauffeur.

export const COULEURS = { actif: '#16a34a', ancienne: '#d97706', perdu: '#dc2626', neutre: '#6b7280', route: '#64748b', reel: '#16a34a' }

export const ETATS_GPS = {
  actif: { libelle: 'GPS ACTIF', court: 'Actif', icone: '🟢', couleur: COULEURS.actif },
  ancienne: { libelle: 'POSITION ANCIENNE', court: 'Ancienne', icone: '🟠', couleur: COULEURS.ancienne },
  perdu: { libelle: 'GPS PERDU', court: 'Perdu', icone: '🔴', couleur: COULEURS.perdu },
  attente: { libelle: 'EN ATTENTE DU GPS', court: 'En attente', icone: '⚪', couleur: COULEURS.neutre },
  termine: { libelle: 'COURSE TERMINÉE', court: 'Terminée', icone: '⚪', couleur: COULEURS.neutre },
}

export const STATUTS_VEHICULE = {
  en_course: { libelle: 'EN COURSE', classe: 'etat-en_course' },
  arrete: { libelle: "À L'ARRÊT", classe: 'etat-arrete' },
  inconnu: { libelle: 'STATUT INCONNU', classe: 'etat-inconnu' },
}

export const SIGNAUX = {
  GPS_REFUSE: 'Localisation refusée sur le téléphone',
  GPS_INDISPONIBLE: 'Signal GPS indisponible sur le téléphone',
  GPS_PRECISION_FAIBLE: 'Précision GPS faible',
  GPS_ARRIERE_PLAN: 'Application en arrière-plan / écran verrouillé',
  GPS_PREMIER_PLAN: 'Application au premier plan',
  GPS_RETABLI: 'GPS rétabli',
}
export const SIGNAUX_PROBLEME = ['GPS_REFUSE', 'GPS_INDISPONIBLE', 'GPS_PRECISION_FAIBLE', 'GPS_ARRIERE_PLAN']

export const EVENEMENTS = {
  TRIP_STARTED: 'Départ', ARRIVED_AT_STOP: 'Arrêt', STUDENT_BOARDED: 'Embarquement', STUDENT_DROPPED: 'Dépose',
  STUDENT_ABSENT: 'Absence', TRIP_COMPLETED: 'Fin de course', DELAY_DETECTED: 'Retard', TRIP_CANCELLED: 'Course annulée',
  INCIDENT_DECLARED: 'Incident', ...SIGNAUX,
}

/** Etat recalcule chaque seconde : ne reste jamais « actif » quand la position vieillit. */
export function etatSelonAge(etatServeur, age, p) {
  if (etatServeur === 'termine') return 'termine'
  if (age == null) return etatServeur === 'attente' ? 'attente' : 'perdu'
  if (age <= p.ancienne) return 'actif'
  return age <= p.perdu ? 'ancienne' : 'perdu'
}

/** « il y a 8 s », « il y a 2 min 05 s » */
export function texteAge(age, long = false) {
  if (age == null) return 'aucune position'
  if (age < 60) return long ? `il y a ${age} seconde${age > 1 ? 's' : ''}` : `il y a ${age} s`
  if (age < 3600) return `il y a ${Math.floor(age / 60)} min${age % 60 ? ` ${String(age % 60).padStart(2, '0')} s` : ''}`
  return `il y a ${Math.floor(age / 3600)} h ${String(Math.floor((age % 3600) / 60)).padStart(2, '0')}`
}

export function heure(dt, secondes = true) {
  if (!dt) return '—'
  if (typeof dt === 'number') return new Date(dt).toLocaleTimeString('fr-FR', secondes ? undefined : { hour: '2-digit', minute: '2-digit' })
  return String(dt).slice(11, secondes ? 19 : 16)
}

export function dateFr(d) {
  if (!d) return '—'
  const [a, m, j] = String(d).slice(0, 10).split('-')
  return `${j}/${m}/${a}`
}

export function duree(s) {
  if (s == null) return '—'
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  if (h) return `${h} h ${String(m).padStart(2, '0')}`
  return m ? `${m} min` : `${s} s`
}

export const estEcole = (nom) => /[ée]cole|school/i.test(nom || '')

/** Calques du circuit prevu : ligne discrete en pointilles + arrets numerotes + ecole. */
export function calquesCircuit(v, { prefixe = 'c', accent = false } = {}) {
  const arrets = v.arrets || []
  const pts = arrets.filter((a) => a.latitude != null)
  const couleur = accent ? '#475569' : '#94a3b8'
  const lignes = pts.length > 1 ? [{ id: `${prefixe}-ligne-${v.trajet_id ?? v.circuit_id}`, points: pts.map((a) => [a.latitude, a.longitude]), couleur, epaisseur: 3, pointille: true, opacite: 0.9, fleches: accent, titre: `Parcours théorique : ${v.circuit}` }] : []
  const n = arrets.length
  const marqueurs = arrets.map((a, i) => {
    const ecole = estEcole(a.nom) || (v.sens !== 'retour' && i === n - 1) || (v.sens === 'retour' && i === 0)
    return {
      id: `${prefixe}-a-${v.trajet_id ?? v.circuit_id}-${a.id}`, lat: a.latitude, lng: a.longitude, type: ecole ? 'ecole' : 'arret',
      texte: ecole ? '🏫' : String(i + 1), couleur: accent ? '#475569' : '#94a3b8',
      titre: `${i + 1}. ${a.nom}${a.heure_estimee ? ` (${a.heure_estimee})` : ''}`,
    }
  })
  return { lignes, marqueurs }
}
