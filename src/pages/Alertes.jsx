import { Link } from 'react-router-dom'
import { ListeAlertes } from './DossierChauffeur.jsx'

// Toutes les alertes du perimetre de l'utilisateur (documents, contrats, vehicules, incidents).
export default function Alertes() {
  return (
    <div className="page">
      <p><Link to="/">&larr; Tableau de bord</Link></p>
      <h1>Alertes</h1>
      <p className="ma-muted">Calculees a l'ouverture de la page, selon vos droits.</p>
      <ListeAlertes />
    </div>
  )
}
