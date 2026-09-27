import { Fragment, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client.js'
import { useAuth } from '../context/AuthContext.jsx'
import { ListeIncidents } from './Incidents.jsx'
import { Fichiers } from './incidentsCommun.jsx'
import { RetenuesChauffeur } from './Remunerations.jsx'

// Onglets du dossier chauffeur (lot 3) : documents, contrat d'utilisation de
// vehicule, incidents, alertes. Les droits sont appliques cote serveur ; ces
// ecrans se contentent de masquer ce qui n'est pas autorise.

const TYPES_DOC = {
  permis: 'Permis de conduire', cni: "Carte d'identite", passeport: 'Passeport', visite_medicale: 'Visite medicale',
  attestation_residence: 'Attestation de residence', casier_judiciaire: 'Casier judiciaire', photo: 'Photo', autre: 'Autre',
}
const ETATS_DOC = { a_verifier: 'A verifier', valide: 'Valide', refuse: 'Refuse', remplace: 'Remplace', expire: 'Expire', expire_bientot: 'Expire bientot' }
const STATUTS_CONTRAT = { brouillon: 'Brouillon', actif: 'Actif', suspendu: 'Suspendu', termine: 'Termine', resilie: 'Resilie' }
const NIVEAUX = { critique: 'Critique', alerte: 'Alerte', info: 'Info' }

function fcfa(v) {
  return v === null || v === undefined || v === '' ? '-' : `${Number(v).toLocaleString('fr-FR')} FCFA`
}

// ------------------------------------------------------------------ Documents
export function OngletDocuments({ chauffeur }) {
  const { can } = useAuth()
  const [docs, setDocs] = useState([])
  const [error, setError] = useState(null)
  const [info, setInfo] = useState(null)
  const [ouvert, setOuvert] = useState(null)
  const vide = { type: 'permis', numero: '', categorie_permis: '', date_delivrance: '', date_expiration: '', autorite: '' }
  const [form, setForm] = useState(vide)
  const peutCreer = can('chauffeur_documents', 'can_create')
  const peutValider = can('chauffeur_documents', 'can_validate')

  async function load() {
    try {
      const res = await api.get(`/chauffeur-documents.php?chauffeur_id=${chauffeur.id}`)
      setDocs(res.data || [])
    } catch (e) {
      setError(e.message)
    }
  }

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [chauffeur.id])

  async function run(fn, message) {
    setError(null)
    setInfo(null)
    try {
      const res = await fn()
      setInfo(message)
      await load()
      return res
    } catch (e) {
      setError(e.message)
      return null
    }
  }

  async function ajouter(e) {
    e.preventDefault()
    const res = await run(() => api.post('/chauffeur-documents.php', { chauffeur_id: chauffeur.id, ...form }), 'Document ajoute : joignez le scan puis faites-le verifier')
    if (res) {
      setForm(vide)
      setOuvert(res.id)
    }
  }

  return (
    <div>
      {error && <p className="error-banner">{error}</p>}
      {info && <p className="ma-info">{info}</p>}
      {peutCreer && (
        <form onSubmit={ajouter} className="module-form">
          <h3>Ajouter un document</h3>
          <label className="module-form-field"><span>Type</span>
            <select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>{Object.entries(TYPES_DOC).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
          </label>
          <label className="module-form-field"><span>Numero</span><input value={form.numero} onChange={(e) => setForm({ ...form, numero: e.target.value })} /></label>
          {form.type === 'permis' && <label className="module-form-field"><span>Categorie(s)</span><input value={form.categorie_permis} onChange={(e) => setForm({ ...form, categorie_permis: e.target.value })} placeholder="B, C, D..." /></label>}
          <label className="module-form-field"><span>Delivre le</span><input type="date" value={form.date_delivrance} onChange={(e) => setForm({ ...form, date_delivrance: e.target.value })} /></label>
          <label className="module-form-field"><span>Expire le</span><input type="date" value={form.date_expiration} onChange={(e) => setForm({ ...form, date_expiration: e.target.value })} /></label>
          <label className="module-form-field"><span>Autorite</span><input value={form.autorite} onChange={(e) => setForm({ ...form, autorite: e.target.value })} /></label>
          <div className="module-form-actions"><button type="submit" className="btn-transport">Ajouter</button></div>
        </form>
      )}
      <div className="module-table-wrap">
        <table className="module-table">
          <thead><tr><th>Document</th><th>Numero</th><th>Expiration</th><th>Etat</th><th>Verification</th><th /></tr></thead>
          <tbody>
            {docs.map((d) => (
              <Fragment key={d.id}>
                <tr className={d.statut === 'remplace' ? 'dc-remplace' : ''}>
                  <td>{TYPES_DOC[d.type] || d.type}{d.categorie_permis && ` (${d.categorie_permis})`}</td>
                  <td>{d.numero || '-'}</td>
                  <td>{d.date_expiration || '-'}</td>
                  <td><span className={`inc-badge dc-etat-${d.etat}`}>{ETATS_DOC[d.etat] || d.etat}</span></td>
                  <td>{d.verifie_par_nom ? `${d.verifie_par_nom}, ${String(d.verifie_at).slice(0, 10)}` : '-'}</td>
                  <td>
                    <button type="button" className="inc-lien" onClick={() => setOuvert(ouvert === d.id ? null : d.id)}>{d.fichier_nom ? 'Scan' : 'Joindre'}</button>
                    {peutValider && d.statut === 'a_verifier' && (
                      <>
                        <button type="button" className="inc-lien" onClick={() => run(() => api.put('/chauffeur-documents.php', { id: d.id, action: 'valider' }), 'Document valide')}>Valider</button>
                        <button type="button" className="inc-lien" onClick={() => {
                          const c = window.prompt('Motif du refus')
                          if (c) run(() => api.put('/chauffeur-documents.php', { id: d.id, action: 'refuser', commentaire: c }), 'Document refuse')
                        }}>Refuser</button>
                      </>
                    )}
                  </td>
                </tr>
                {ouvert === d.id && (
                  <tr><td colSpan={6}>
                    <Fichiers entite="chauffeur_document" entiteId={d.id} peutDeposer={d.statut !== 'remplace' && peutCreer} titre="Scan du document" onUpload={() => load()} />
                  </td></tr>
                )}
              </Fragment>
            ))}
            {docs.length === 0 && <tr><td colSpan={6}>Aucun document.</td></tr>}
          </tbody>
        </table>
      </div>
      <p className="ma-muted">Un document renouvele et valide remplace l'ancien, qui reste consultable.</p>
    </div>
  )
}

// ------------------------------------------------------------------ Contrats
export function OngletContrats({ chauffeur }) {
  const { can, scopeOf } = useAuth()
  const gestion = ['GLOBAL', 'SCHOOL'].includes(scopeOf('chauffeur_contracts'))
  const [contrats, setContrats] = useState([])
  const [visible, setVisible] = useState(false)
  const [modeles, setModeles] = useState([])
  const [vehicules, setVehicules] = useState([])
  const [error, setError] = useState(null)
  const [info, setInfo] = useState(null)
  const [detail, setDetail] = useState(null)
  const [showForm, setShowForm] = useState(false)
  const vide = { template_id: '', vehicle_id: '', date_debut: new Date().toISOString().slice(0, 10), duree_mois: '', date_fin: '', remuneration_montant: '', remuneration_periodicite: 'mois', preavis_jours: '', penalites: '', conditions_particulieres: '' }
  const [form, setForm] = useState(vide)

  async function load() {
    try {
      const res = await api.get(`/chauffeur-contracts.php?chauffeur_id=${chauffeur.id}`)
      setContrats(res.data || [])
      setVisible(!!res.remuneration_visible)
      if (gestion && can('chauffeur_contracts', 'can_create')) {
        const m = await api.get('/contract-templates.php?statut=actif').catch(() => ({ data: [] }))
        setModeles(m.data || [])
        const v = await api.get('/crud.php?module=vehicules').catch(() => ({ data: [] }))
        setVehicules(v.data || [])
      }
    } catch (e) {
      setError(e.message)
    }
  }

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [chauffeur.id])

  function choisirModele(id) {
    const m = modeles.find((x) => String(x.id) === String(id))
    const defauts = m?.valeurs_defaut || {}
    // Les valeurs du modele pre-remplissent seulement : elles restent modifiables.
    setForm({
      ...form,
      template_id: id,
      remuneration_montant: defauts.remuneration_montant ?? form.remuneration_montant,
      remuneration_periodicite: defauts.remuneration_periodicite ?? form.remuneration_periodicite,
      preavis_jours: defauts.preavis_jours ?? form.preavis_jours,
      duree_mois: defauts.duree_mois ?? form.duree_mois,
      penalites: defauts.penalites ?? form.penalites,
    })
  }

  async function run(fn, message) {
    setError(null)
    setInfo(null)
    try {
      const res = await fn()
      const manq = res?.variables_manquantes || []
      setInfo(message + (manq.length ? ` — champs du modele non renseignes : ${manq.join(', ')}` : ''))
      await load()
      return res
    } catch (e) {
      setError(e.message)
      return null
    }
  }

  async function creer(e) {
    e.preventDefault()
    const body = { chauffeur_id: chauffeur.id, ...form, template_id: form.template_id ? Number(form.template_id) : undefined, vehicle_id: form.vehicle_id ? Number(form.vehicle_id) : null }
    const res = await run(() => api.post('/chauffeur-contracts.php', body), 'Contrat cree en brouillon')
    if (res) {
      setForm(vide)
      setShowForm(false)
    }
  }

  const action = (c, act, extra = {}) => run(() => api.put('/chauffeur-contracts.php', { id: c.id, action: act, ...extra }), `Contrat : ${act}`)
  const avecMotif = (c, act) => {
    const motif = window.prompt('Motif (obligatoire)')
    if (motif) action(c, act, { motif })
  }

  return (
    <div>
      {error && <p className="error-banner">{error}</p>}
      {info && <p className="ma-info">{info}</p>}
      <p className="ma-muted">Contrat d'utilisation de vehicule par le chauffeur (ce n'est pas un contrat de travail).{!visible && ' Les montants ne sont visibles que par les gestionnaires habilites.'}</p>
      {gestion && can('chauffeur_contracts', 'can_create') && (
        <p><button type="button" className="btn-transport" onClick={() => setShowForm(!showForm)}>Nouveau contrat</button></p>
      )}
      {showForm && (
        <form onSubmit={creer} className="module-form">
          <label className="module-form-field"><span>Modele</span>
            <select value={form.template_id} onChange={(e) => choisirModele(e.target.value)}>
              <option value="">-- Sans modele --</option>
              {modeles.map((m) => <option key={m.id} value={m.id}>{m.titre} (v{m.version})</option>)}
            </select>
          </label>
          <label className="module-form-field"><span>Vehicule</span>
            <select value={form.vehicle_id} onChange={(e) => setForm({ ...form, vehicle_id: e.target.value })}>
              <option value="">-- Aucun --</option>
              {vehicules.map((v) => <option key={v.id} value={v.id}>{v.immatriculation} {v.modele || ''}</option>)}
            </select>
          </label>
          <label className="module-form-field"><span>Debut (obligatoire pour activer)</span><input type="date" value={form.date_debut} onChange={(e) => setForm({ ...form, date_debut: e.target.value })} /></label>
          <label className="module-form-field"><span>Duree (mois)</span><input type="number" min="1" value={form.duree_mois} onChange={(e) => setForm({ ...form, duree_mois: e.target.value })} /></label>
          <label className="module-form-field"><span>ou fin le</span><input type="date" value={form.date_fin} onChange={(e) => setForm({ ...form, date_fin: e.target.value })} /></label>
          <label className="module-form-field"><span>Montant (FCFA)</span><input type="number" min="0" value={form.remuneration_montant} onChange={(e) => setForm({ ...form, remuneration_montant: e.target.value })} /></label>
          <label className="module-form-field"><span>Periodicite</span><input value={form.remuneration_periodicite} onChange={(e) => setForm({ ...form, remuneration_periodicite: e.target.value })} /></label>
          <label className="module-form-field"><span>Preavis (jours)</span><input type="number" min="0" value={form.preavis_jours} onChange={(e) => setForm({ ...form, preavis_jours: e.target.value })} /></label>
          <label className="module-form-field"><span>Penalites</span><textarea rows={2} value={form.penalites} onChange={(e) => setForm({ ...form, penalites: e.target.value })} /></label>
          <label className="module-form-field"><span>Conditions particulieres</span><textarea rows={2} value={form.conditions_particulieres} onChange={(e) => setForm({ ...form, conditions_particulieres: e.target.value })} /></label>
          <p className="ma-muted">Le montant propose par le modele n'est qu'une valeur de depart : saisissez celui convenu avec ce chauffeur.</p>
          <div className="module-form-actions"><button type="submit" className="btn-transport">Creer le brouillon</button><button type="button" onClick={() => setShowForm(false)}>Annuler</button></div>
        </form>
      )}
      <div className="module-table-wrap">
        <table className="module-table">
          <thead><tr><th>Reference</th><th>Vehicule</th><th>Periode</th>{visible && <th>Montant</th>}<th>Signe</th><th>Statut</th><th /></tr></thead>
          <tbody>
            {contrats.map((c) => (
              <tr key={c.id}>
                <td><button type="button" className="inc-lien" onClick={() => setDetail(detail?.id === c.id ? null : c)}>{c.reference}</button></td>
                <td>{c.immatriculation || '-'}</td>
                <td>{c.date_debut} → {c.date_fin || 'indeterminee'}</td>
                {visible && <td>{fcfa(c.remuneration_montant)}{c.remuneration_periodicite ? ` / ${c.remuneration_periodicite}` : ''}</td>}
                <td>{c.signe_le || 'non'}</td>
                <td>{STATUTS_CONTRAT[c.statut] || c.statut}</td>
                <td>
                  {gestion && c.statut === 'brouillon' && can('chauffeur_contracts', 'can_validate') && <button type="button" className="inc-lien" onClick={() => action(c, 'activer')}>Activer</button>}
                  {gestion && c.statut === 'actif' && can('chauffeur_contracts', 'can_validate') && <button type="button" className="inc-lien" onClick={() => avecMotif(c, 'suspendre')}>Suspendre</button>}
                  {gestion && c.statut === 'suspendu' && can('chauffeur_contracts', 'can_validate') && <button type="button" className="inc-lien" onClick={() => avecMotif(c, 'reprendre')}>Reprendre</button>}
                  {gestion && ['actif', 'suspendu'].includes(c.statut) && can('chauffeur_contracts', 'can_edit') && (
                    <button type="button" className="inc-lien" onClick={() => { const f = window.prompt('Date de fin (AAAA-MM-JJ)', new Date().toISOString().slice(0, 10)); if (f) action(c, 'terminer', { date_fin: f }) }}>Terminer</button>
                  )}
                  {gestion && !['termine', 'resilie'].includes(c.statut) && can('chauffeur_contracts', 'can_validate') && (
                    <button type="button" className="inc-lien" onClick={() => avecMotif(c, 'resilier')}>Resilier</button>
                  )}
                </td>
              </tr>
            ))}
            {contrats.length === 0 && <tr><td colSpan={7}>Aucun contrat.</td></tr>}
          </tbody>
        </table>
      </div>
      {detail && (
        <section className="inc-section">
          <h3>Contrat {detail.reference}</h3>
          <p className="ma-muted">
            {detail.template_titre ? `Modele : ${detail.template_titre} v${detail.template_version}` : 'Sans modele'}
            {detail.preavis_jours && ` · preavis ${detail.preavis_jours} jours`}
            {detail.motif_resiliation && ` · resilie le ${detail.resilie_le} : ${detail.motif_resiliation}`}
          </p>
          {detail.penalites && <p><strong>Penalites :</strong> {detail.penalites}</p>}
          {detail.conditions_particulieres && <p><strong>Conditions particulieres :</strong> {detail.conditions_particulieres}</p>}
          {visible && detail.contenu_genere && <pre className="dc-contrat">{detail.contenu_genere}</pre>}
          {visible && (
            <Fichiers entite="chauffeur_contract" entiteId={detail.id} categorie="contrat_signe" titre="Contrat signe (scan)"
              peutDeposer={!['termine', 'resilie'].includes(detail.statut) && can('chauffeur_contracts', 'can_edit')}
              onUpload={(fid) => action(detail, 'signer', { fichier_id: fid, signe_le: detail.signe_le || new Date().toISOString().slice(0, 10) })} />
          )}
        </section>
      )}
      {visible && <RetenuesChauffeur chauffeur={chauffeur} />}
    </div>
  )
}

// ------------------------------------------------------------------ Alertes
export function ListeAlertes({ chauffeurId }) {
  const [alertes, setAlertes] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    api.get('/alertes.php' + (chauffeurId ? `?chauffeur_id=${chauffeurId}` : ''))
      .then((r) => setAlertes(r.data || []))
      .catch((e) => setError(e.message))
  }, [chauffeurId])

  if (error) return <p className="error-banner">{error}</p>
  if (!alertes) return <p>Chargement...</p>
  if (alertes.length === 0) return <p>Aucune alerte.</p>
  return (
    <ul className="dc-alertes">
      {alertes.map((a, n) => (
        <li key={n} className={`dc-alerte dc-alerte-${a.niveau}`}>
          <span className="inc-badge">{NIVEAUX[a.niveau]}</span>{' '}
          {!chauffeurId && a.chauffeur_id && <><Link to={`/chauffeurs/${a.chauffeur_id}`}>{a.chauffeur_nom}</Link> — </>}
          {a.entite === 'incident' ? <Link to={`/incidents/${a.entite_id}`}>{a.message}</Link>
            : a.entite === 'vehicule' ? <Link to={`/vehicules/${a.entite_id}`}>{a.message}</Link>
            : a.entite === 'echeances' ? <Link to="/suivi-paiements">{a.message}</Link> : a.message}
        </li>
      ))}
    </ul>
  )
}

export const ONGLETS_LOT3 = [
  { key: 'documents', label: 'Permis & documents', visible: (can) => can('chauffeur_documents', 'can_read'), render: (c) => <OngletDocuments chauffeur={c} /> },
  { key: 'contrats', label: 'Contrat', visible: (can) => can('chauffeur_contracts', 'can_read'), render: (c) => <OngletContrats chauffeur={c} /> },
  { key: 'incidents', label: 'Incidents & accidents', visible: (can) => can('incidents', 'can_read'), render: (c) => <ListeIncidents filtreFixe={{ chauffeur_id: c.id }} compact /> },
  { key: 'alertes', label: 'Alertes', render: (c) => <ListeAlertes chauffeurId={c.id} /> },
]
