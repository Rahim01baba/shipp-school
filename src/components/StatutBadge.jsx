// Badge de statut : couleur ET texte (jamais la couleur seule).
// Vert = actif / en cours / paye · Orange = attente / planifie · Rouge = probleme · Gris = termine / inactif

const TONS = {
  success: ['actif', 'active', 'en_cours', 'payee', 'paye', 'valide', 'validee', 'resolu', 'ok', 'present', 'embarque', 'lue', 'envoye', 'envoyee', 'operationnel', 'opérationnel'],
  warning: ['planifie', 'en_attente', 'attente', 'maintenance', 'suspendu', 'brouillon', 'proposee', 'a_verifier', 'expire_bientot', 'ouvert', 'en_panne', 'pause'],
  danger: ['en_retard', 'expire', 'refuse', 'critique', 'incident', 'absent', 'impaye', 'perdu', 'accident'],
  neutral: ['termine', 'terminee', 'inactif', 'inactive', 'archivee', 'annule', 'annulee', 'sorti', 'clos', 'non_lue'],
}
const LIBELLES = {
  actif: 'Actif', active: 'Active', en_cours: 'En cours', planifie: 'Planifié', termine: 'Terminé', terminee: 'Terminée', annule: 'Annulé', annulee: 'Annulée',
  en_attente: 'En attente', payee: 'Payée', en_retard: 'En retard', maintenance: 'Maintenance', suspendu: 'Suspendu', sorti: 'Sorti', inactif: 'Inactif',
  archivee: 'Archivée', brouillon: 'Brouillon', envoye: 'Envoyé', lue: 'Lue', proposee: 'Proposée', validee: 'Validée', clos: 'Clos', ouvert: 'Ouvert', resolu: 'Résolu',
}

const norme = (v) => String(v ?? '').trim().toLowerCase().replace(/[\s-]+/g, '_')

export function tonStatut(valeur) {
  const v = norme(valeur)
  for (const [ton, liste] of Object.entries(TONS)) if (liste.includes(v)) return ton
  return 'info'
}

export default function StatutBadge({ valeur, libelle }) {
  if (valeur === null || valeur === undefined || valeur === '') return <span className="texte-discret">—</span>
  const v = norme(valeur)
  return <span className={`badge badge-${tonStatut(valeur)}`}>{libelle || LIBELLES[v] || String(valeur).trim()}</span>
}
