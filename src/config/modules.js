// Configuration centrale des 12 modules metier.
// Chaque entree pilote la page CRUD generique (ModuleCrud.jsx) : elle doit
// correspondre exactement a la whitelist de colonnes definie cote API dans
// crud.php ($modulesConfig).

export const MODULES = [
  {
    key: 'eleves',
    label: 'Eleves',
    fields: [
      { key: 'code_dr', label: 'Code DR', type: 'text' },
      { key: 'nom', label: 'Nom', type: 'text' },
      { key: 'prenom', label: 'Prenom', type: 'text' },
      { key: 'date_naissance', label: 'Date de naissance', type: 'date' },
      { key: 'classe', label: 'Classe', type: 'text' },
      { key: 'ecole', label: 'Ecole', type: 'text' },
      { key: 'statut', label: 'Statut', type: 'text' },
    ],
  },
  {
    key: 'parents_eleves',
    label: 'Parents',
    fields: [
      { key: 'nom', label: 'Nom', type: 'text' },
      { key: 'prenom', label: 'Prenom', type: 'text' },
      { key: 'email', label: 'Email', type: 'email' },
      { key: 'telephone', label: 'Telephone', type: 'text' },
      { key: 'adresse', label: 'Adresse', type: 'text' },
    ],
  },
  {
    key: 'transport',
    label: 'Transport',
    domain: 'transport',
    fields: [
      { key: 'eleve_nom', label: 'Eleve', type: 'text' },
      { key: 'circuit', label: 'Circuit', type: 'text' },
      { key: 'point_ramassage', label: 'Point de ramassage', type: 'text' },
      { key: 'statut', label: 'Statut', type: 'text' },
    ],
  },
  {
    key: 'cantine',
    label: 'Cantine',
    domain: 'cantine',
    fields: [
      { key: 'eleve_nom', label: 'Eleve', type: 'text' },
      { key: 'menu', label: 'Menu', type: 'text' },
      { key: 'date_repas', label: 'Date', type: 'date' },
      { key: 'statut', label: 'Statut', type: 'text' },
    ],
  },
  {
    key: 'vehicules',
    label: 'Vehicules',
    domain: 'transport',
    fields: [
      { key: 'immatriculation', label: 'Immatriculation', type: 'text' },
      { key: 'marque', label: 'Marque', type: 'text' },
      { key: 'modele', label: 'Modele', type: 'text' },
      { key: 'annee', label: 'Annee', type: 'number' },
      { key: 'type_vehicule', label: 'Type (Van 10 pax...)', type: 'text' },
      { key: 'capacite', label: 'Capacite', type: 'number' },
      { key: 'chauffeur', label: 'Chauffeur', type: 'text' },
      { key: 'statut', label: 'Statut', type: 'text' },
    ],
  },
  {
    key: 'circuits',
    label: 'Circuits',
    domain: 'transport',
    fields: [
      { key: 'nom', label: 'Nom', type: 'text' },
      { key: 'description', label: 'Description', type: 'text' },
      { key: 'vehicule', label: 'Vehicule', type: 'text' },
      { key: 'statut', label: 'Statut', type: 'text' },
    ],
  },
  {
    key: 'finance',
    label: 'Finance',
    fields: [
      { key: 'libelle', label: 'Libelle', type: 'text' },
      { key: 'montant', label: 'Montant', type: 'number' },
      { key: 'type', label: 'Type', type: 'text' },
      { key: 'date_echeance', label: 'Echeance', type: 'date' },
      { key: 'statut', label: 'Statut', type: 'text' },
    ],
  },
  {
    key: 'utilisateurs',
    label: 'Utilisateurs',
    fields: [
      { key: 'name', label: 'Nom', type: 'text' },
      { key: 'email', label: 'Email', type: 'email' },
      { key: 'status', label: 'Statut', type: 'text' },
    ],
  },
  {
    key: 'menus',
    label: 'Menus',
    domain: 'cantine',
    fields: [
      { key: 'date_menu', label: 'Date', type: 'date' },
      {
        key: 'periode',
        label: 'Periode',
        type: 'select',
        options: [
          { value: 'petit_dejeuner', label: 'Petit dejeuner' },
          { value: 'dejeuner', label: 'Dejeuner' },
          { value: 'gouter', label: 'Gouter' },
        ],
      },
      { key: 'libelle', label: 'Libelle', type: 'text' },
      { key: 'description', label: 'Description', type: 'text' },
    ],
  },
  {
    key: 'notifications',
    label: 'Notifications',
    fields: [
      { key: 'titre', label: 'Titre', type: 'text' },
      { key: 'message', label: 'Message', type: 'text' },
      { key: 'cible', label: 'Cible', type: 'text' },
      { key: 'statut', label: 'Statut', type: 'text' },
    ],
  },
  {
    key: 'ecoles',
    label: 'Ecoles',
    fields: [
      { key: 'nom', label: 'Nom', type: 'text' },
      { key: 'adresse', label: 'Adresse', type: 'text' },
      { key: 'telephone', label: 'Telephone', type: 'text' },
      { key: 'directeur', label: 'Directeur', type: 'text' },
    ],
  },
  {
    key: 'rapports',
    label: 'Rapports',
    fields: [
      { key: 'titre', label: 'Titre', type: 'text' },
      { key: 'type', label: 'Type', type: 'text' },
      { key: 'periode', label: 'Periode', type: 'text' },
      { key: 'statut', label: 'Statut', type: 'text' },
    ],
  },
]
