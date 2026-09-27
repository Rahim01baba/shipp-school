import { ListeAlertes } from './DossierChauffeur.jsx'

// Toutes les alertes du perimetre de l'utilisateur (documents, contrats, vehicules, incidents).
// Hierarchie : CRITIQUE (rouge) > ATTENTION (orange) > INFORMATION (bleu).
export default function Alertes() {
  return (
    <div className="page">
      <header className="page-header">
        <div>
          <h1>Alertes</h1>
          <p className="page-header-sous">Calculées à l'ouverture de la page, selon vos droits. Les alertes critiques apparaissent en premier.</p>
        </div>
      </header>
      <ListeAlertes avecResume />
    </div>
  )
}
