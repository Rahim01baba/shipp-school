import ChauffeurFiche from './ChauffeurFiche.jsx'
import { ONGLETS_LOT3 } from './DossierChauffeur.jsx'

// Fiche chauffeur avec les onglets du dossier : documents, contrat,
// incidents et alertes (lot 3).
export const ONGLETS_DOSSIER = ONGLETS_LOT3

export default function ChauffeurFicheComplete() {
  return <ChauffeurFiche extraTabs={ONGLETS_DOSSIER} />
}
