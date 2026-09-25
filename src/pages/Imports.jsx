import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client.js'

// Import du fichier de suivi ENKO (lot 5). Rien n'est ecrit avant « Valider ».
// L'annee scolaire est choisie par vous : elle n'est jamais deduite du nom de la feuille.

const STATUTS = { ok: 'OK', avertissement: 'Avertissement', erreur: 'Erreur', insuffisant: 'Donnee insuffisante' }

export default function Imports() {
  const [lots, setLots] = useState([])
  const [annees, setAnnees] = useState([])
  const [chauffeurs, setChauffeurs] = useState([])
  const [lot, setLot] = useState(null)
  const [feuilles, setFeuilles] = useState([])
  const [choix, setChoix] = useState({ feuille: '', annee_scolaire_id: '', circuits_par_conducteur: false, creer_tarifs: true })
  const [prep, setPrep] = useState(null)
  const [detail, setDetail] = useState(null)
  const [corr, setCorr] = useState({})
  const [filtre, setFiltre] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const [info, setInfo] = useState(null)

  async function loadLots() {
    try {
      setLots((await api.get('/imports.php')).data || [])
    } catch (e) {
      setError(e.message)
    }
  }

  useEffect(() => {
    loadLots()
    api.get('/crud.php?module=annees_scolaires').then((r) => setAnnees(r.data || [])).catch(() => {})
    api.get('/chauffeurs.php?statut=').then((r) => setChauffeurs(r.data || [])).catch(() => {})
  }, [])

  async function run(fn) {
    setBusy(true)
    setError(null)
    setInfo(null)
    try {
      return await fn()
    } catch (e) {
      setError(e.message)
      return null
    } finally {
      setBusy(false)
    }
  }

  async function deposer(e) {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    const r = await run(() => api.upload('/imports.php', {}, file))
    if (r) {
      setLot(r.lot_id)
      setFeuilles(r.feuilles)
      setPrep(null)
      setDetail(null)
      const f = r.feuilles.find((x) => x.importable && !x.deja_importee)
      setChoix({ ...choix, feuille: f ? f.nom : '', annee_scolaire_id: f?.suggestion_annees?.[0]?.mois_couverts ? String(f.suggestion_annees[0].id) : '' })
      loadLots()
    }
  }

  async function preparer() {
    const r = await run(() => api.put('/imports.php', { lot_id: lot, action: 'preparer', feuille: choix.feuille, annee_scolaire_id: Number(choix.annee_scolaire_id),
      options: { circuits_par_conducteur: choix.circuits_par_conducteur, creer_tarifs: choix.creer_tarifs } }))
    if (r) {
      setPrep(r.stats)
      const c = {}
      ;(r.stats.conducteurs || []).forEach((x) => { c[x.valeur] = x.chauffeur_id ? String(x.chauffeur_id) : '' })
      setCorr(c)
      setDetail((await api.get(`/imports.php?lot_id=${lot}`)).lignes)
    }
  }

  async function enregistrerCorrespondances() {
    const items = Object.entries(corr).map(([valeur_source, cible_id]) => ({ type: 'conducteur', valeur_source, cible_id: cible_id ? Number(cible_id) : null }))
    const r = await run(() => api.put('/imports.php', { action: 'correspondances', items }))
    if (r) await preparer()
  }

  async function valider() {
    if (!window.confirm('Importer ce lot ? Les lignes en erreur sont ignorees ; les donnees insuffisantes sont importees sans relation automatique.')) return
    const r = await run(() => api.put('/imports.php', { lot_id: lot, action: 'valider' }))
    if (r) {
      setInfo(`Import termine : ${Object.entries(r.crees).map(([k, v]) => `${v} ${k}`).join(', ')}`)
      setLot(null)
      setPrep(null)
      setDetail(null)
      loadLots()
    }
  }

  async function annuler(id) {
    const motif = window.prompt("Motif de l'annulation")
    if (!motif) return
    const r = await run(() => api.put('/imports.php', { lot_id: id, action: 'annuler', motif }))
    if (r) {
      setInfo('Lot annule')
      loadLots()
    }
  }

  const feuille = feuilles.find((f) => f.nom === choix.feuille)
  const lignes = (detail || []).filter((l) => !filtre || l.statut === filtre)

  return (
    <div className="page">
      <p><Link to="/">&larr; Tableau de bord</Link></p>
      <h1>Import du suivi ENKO</h1>
      {error && <p className="error-banner">{error}</p>}
      {info && <p className="ma-info">{info}</p>}

      <section className="inc-section">
        <h2>1. Fichier</h2>
        <label className="inc-upload"><input type="file" accept=".xlsx" disabled={busy} onChange={deposer} /> <span>{busy ? 'Traitement...' : 'Choisir le fichier Excel (.xlsx)'}</span></label>
        <p className="ma-muted">Le fichier est conserve tel quel (copie privee et empreinte) ; il n'est jamais modifie.</p>
      </section>

      {lot && (
        <section className="inc-section">
          <h2>2. Feuille et annee scolaire</h2>
          <div className="module-table-wrap">
            <table className="module-table">
              <thead><tr><th>Feuille</th><th>Etat</th><th>Eleves</th><th>Mois lus</th><th>Annee la plus proche</th><th /></tr></thead>
              <tbody>
                {feuilles.map((f) => (
                  <tr key={f.nom}>
                    <td>{f.nom}</td>
                    <td>{f.etat === 'hidden' ? 'masquee' : 'visible'}</td>
                    <td>{f.lignes || '-'}</td>
                    <td>{f.mois.length ? `${f.mois[0]} → ${f.mois[f.mois.length - 1]}` : '-'}</td>
                    <td>{f.suggestion_annees?.[0]?.mois_couverts ? `${f.suggestion_annees[0].libelle} (${f.suggestion_annees[0].mois_couverts} mois)` : 'aucune annee ne couvre ces mois'}</td>
                    <td>{f.deja_importee ? 'deja importee' : f.importable ? <button type="button" className="inc-lien" onClick={() => setChoix({ ...choix, feuille: f.nom, annee_scolaire_id: f.suggestion_annees?.[0]?.mois_couverts ? String(f.suggestion_annees[0].id) : '' })}>Choisir</button> : 'non importable'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {feuille && (
            <div className="module-form">
              <p>Feuille choisie : <strong>{feuille.nom}</strong>. Le nom de la feuille n'est pas utilise pour l'annee.</p>
              <label className="module-form-field"><span>Annee scolaire du lot</span>
                <select value={choix.annee_scolaire_id} onChange={(e) => setChoix({ ...choix, annee_scolaire_id: e.target.value })}>
                  <option value="">-- Choisir --</option>
                  {annees.map((a) => <option key={a.id} value={a.id}>{a.libelle} ({a.date_debut} → {a.date_fin})</option>)}
                </select>
              </label>
              <p className="ma-muted">Seuls les mois compris dans cette annee sont importes. Creez l'annee dans « Annees scolaires » si elle manque.</p>
              <label className="ch-check"><input type="checkbox" checked={choix.circuits_par_conducteur} onChange={(e) => setChoix({ ...choix, circuits_par_conducteur: e.target.checked })} /> Creer un circuit par conducteur et y affecter ses eleves (arrets a completer ensuite)</label>
              <label className="ch-check"><input type="checkbox" checked={choix.creer_tarifs} onChange={(e) => setChoix({ ...choix, creer_tarifs: e.target.checked })} /> Creer les tarifs de zone observes (un seul montant par zone)</label>
              <div className="module-form-actions"><button type="button" className="btn-transport" disabled={busy || !choix.annee_scolaire_id} onClick={preparer}>Analyser la feuille</button></div>
            </div>
          )}
        </section>
      )}

      {prep && (
        <section className="inc-section">
          <h2>3. Verification</h2>
          <p>
            {prep.lignes} lignes : <strong>{prep.ok}</strong> OK, <strong>{prep.avertissement}</strong> avertissements, <strong>{prep.insuffisant}</strong> donnees insuffisantes, <strong>{prep.erreur}</strong> erreurs (ignorees).
            {prep.mois_hors_annee > 0 && ` ${prep.mois_hors_annee} cases de mois hors de l'annee choisie ne seront pas importees.`}
          </p>
          {(prep.conducteurs || []).length > 0 && (
            <>
              <h3>Conducteurs → fiches chauffeur</h3>
              <p className="ma-muted">Aucune correspondance n'est devinee. Choisissez la fiche de chaque conducteur (creez-la dans « Chauffeurs » si besoin).</p>
              <table className="module-table">
                <tbody>
                  {Object.keys(corr).map((v) => (
                    <tr key={v}>
                      <td>{v}</td>
                      <td>
                        <select value={corr[v]} onChange={(e) => setCorr({ ...corr, [v]: e.target.value })}>
                          <option value="">-- Donnee insuffisante --</option>
                          {chauffeurs.map((c) => <option key={c.id} value={c.id}>{[c.prenom, c.nom].filter(Boolean).join(' ')} {c.telephone ? `(${c.telephone})` : ''}</option>)}
                        </select>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              <p><button type="button" disabled={busy} onClick={enregistrerCorrespondances}>Enregistrer les correspondances et re-analyser</button></p>
            </>
          )}
          {(prep.tarifs || []).length > 0 && (
            <>
              <h3>Tarifs observes par zone</h3>
              <ul>{prep.tarifs.map((t) => <li key={t.zone}>{t.zone} : {t.propose !== null ? `${Number(t.propose).toLocaleString('fr-FR')} FCFA` : `plusieurs montants (${Object.keys(t.montants).join(' / ')}) — non cree`}</li>)}</ul>
            </>
          )}
          {prep.vehicules_types && Object.keys(prep.vehicules_types).length > 0 && (
            <p className="ma-muted">Types de vehicule lus : {Object.entries(prep.vehicules_types).map(([k, n]) => `${k} (${n})`).join(', ')}. Le vehicule reel (immatriculation) vient de l'affectation du chauffeur.</p>
          )}
          <div className="ch-filtres">
            <select value={filtre} onChange={(e) => setFiltre(e.target.value)}>
              <option value="">Toutes les lignes</option>
              {Object.entries(STATUTS).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
            </select>
            <button type="button" className="btn-transport" disabled={busy} onClick={valider}>Valider l'import</button>
          </div>
          <div className="module-table-wrap">
            <table className="module-table">
              <thead><tr><th>Ligne</th><th>Eleve</th><th>Classe</th><th>Zone</th><th>Mensuel</th><th>Conducteur</th><th>Statut</th><th>Messages</th></tr></thead>
              <tbody>
                {lignes.map((l) => (
                  <tr key={l.id} className={`imp-${l.statut}`}>
                    <td>{l.numero_ligne}</td>
                    <td>{l.donnees.nom} {l.donnees.prenom}</td>
                    <td>{l.donnees.classe || '-'}</td>
                    <td>{l.donnees.zone || '-'}</td>
                    <td>{l.donnees.montant ?? '-'}</td>
                    <td>{l.donnees.conducteur || '-'}</td>
                    <td>{STATUTS[l.statut]}</td>
                    <td className="ma-muted">{(l.messages || []).join(' ; ')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      <section className="inc-section">
        <h2>Lots</h2>
        <div className="module-table-wrap">
          <table className="module-table">
            <thead><tr><th>N°</th><th>Fichier</th><th>Feuille</th><th>Annee</th><th>Statut</th><th>Cree</th><th>Auteur</th><th /></tr></thead>
            <tbody>
              {lots.map((l) => (
                <tr key={l.id}>
                  <td>{l.id}</td><td>{l.nom_fichier}</td><td>{l.feuille || '-'}</td><td>{l.annee || '-'}</td><td>{l.statut}</td>
                  <td>{l.stats?.crees ? Object.entries(l.stats.crees).map(([k, v]) => `${v} ${k}`).join(', ') : String(l.created_at).slice(0, 16)}</td>
                  <td>{l.auteur}</td>
                  <td>{l.statut !== 'annule' && <button type="button" className="inc-lien" onClick={() => annuler(l.id)}>Annuler</button>}</td>
                </tr>
              ))}
              {lots.length === 0 && <tr><td colSpan={8}>Aucun lot.</td></tr>}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  )
}
