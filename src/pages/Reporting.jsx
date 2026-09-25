import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api } from '../api/client.js'
import { useAuth } from '../context/AuthContext.jsx'

// Reporting : tous les calculs sont faits cote serveur sur la periode
// date debut -> date fin (obligatoire). Un clic sur une ligne ouvre le detail.

const RAPPORTS = {
  synthese: 'Synthese',
  par_jour: 'Par jour',
  chauffeurs: 'Chauffeurs',
  vehicules: 'Vehicules',
  circuits: 'Circuits',
  eleves: 'Eleves',
  incidents: 'Incidents',
  paiements: 'Paiements',
}
const LISTES = { trajets: 'Trajets', retards: 'Retards aux arrets', embarquements: 'Embarquements', absences: 'Absences', repas: 'Repas servis', incidents: 'Incidents' }
// Indicateur de synthese -> liste de detail.
const DETAIL_INDICATEUR = {
  trajets_prevus: ['trajets', {}], trajets_termines: ['trajets', { statut: 'termine' }], trajets_annules: ['trajets', { statut: 'annule' }],
  arrets_en_retard: ['retards', {}], embarquements: ['embarquements', {}], eleves_transportes: ['embarquements', {}],
  absences: ['absences', {}], repas_servis: ['repas', {}], incidents: ['incidents', { categorie: 'incident' }], accidents: ['incidents', { categorie: 'accident' }],
}

function debutMois() {
  const d = new Date()
  return new Date(d.getFullYear(), d.getMonth(), 1).toISOString().slice(0, 10)
}

function fmt(v) {
  if (v === null || v === undefined || v === '') return '—'
  if (typeof v === 'number') return v.toLocaleString('fr-FR')
  return String(v)
}

function Tableau({ colonnes, data, onLigne }) {
  return (
    <div className="module-table-wrap">
      <table className="module-table rep-table">
        <thead><tr>{Object.entries(colonnes).map(([k, l]) => <th key={k}>{l}</th>)}</tr></thead>
        <tbody>
          {data.map((r, i) => (
            <tr key={r.id || r.cle || r.chauffeur_id || r.circuit_id || r.vehicule_id || r.eleve_id || r.jour || i} className={onLigne ? 'rep-cliquable' : ''} onClick={onLigne ? () => onLigne(r) : undefined}>
              {Object.keys(colonnes).map((k) => <td key={k}>{fmt(r[k])}</td>)}
            </tr>
          ))}
          {data.length === 0 && <tr><td colSpan={Object.keys(colonnes).length}>Aucune donnee sur la periode.</td></tr>}
        </tbody>
      </table>
    </div>
  )
}

export default function Reporting() {
  const { isAdmin, accessLoading } = useAuth()
  const navigate = useNavigate()
  const [filtres, setFiltres] = useState({ date_debut: debutMois(), date_fin: new Date().toISOString().slice(0, 10), ecole_id: '', circuit_id: '', chauffeur_id: '', vehicule_id: '', classe: '' })
  const [rapport, setRapport] = useState('synthese')
  const [res, setRes] = useState(null)
  const [detail, setDetail] = useState(null)
  const [refs, setRefs] = useState({ circuits: [], chauffeurs: [], vehicules: [], ecoles: [] })
  const [error, setError] = useState(null)
  const [loading, setLoading] = useState(false)

  function params(extra = {}) {
    const p = new URLSearchParams()
    Object.entries({ ...filtres, ...extra }).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined) p.set(k, v) })
    return p
  }

  async function load(r = rapport) {
    setLoading(true)
    setError(null)
    setDetail(null)
    try {
      const p = params({ rapport: r })
      setRes(await api.get('/reporting.php?' + p.toString()))
    } catch (e) {
      setError(e.message)
      setRes(null)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    if (accessLoading) return
    load()
    Promise.all([
      api.get('/crud.php?module=circuits').catch(() => ({ data: [] })),
      api.get('/chauffeurs.php?statut=').catch(() => ({ data: [] })),
      api.get('/crud.php?module=vehicules').catch(() => ({ data: [] })),
      isAdmin ? api.get('/crud.php?module=ecoles').catch(() => ({ data: [] })) : Promise.resolve({ data: [] }),
    ]).then(([c, ch, v, e]) => setRefs({ circuits: c.data || [], chauffeurs: ch.data || [], vehicules: v.data || [], ecoles: e.data || [] }))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [accessLoading])

  async function ouvrirDetail(liste, extra, titre) {
    setError(null)
    try {
      const p = params({ liste, ...extra })
      const r = await api.get('/reporting.php?' + p.toString())
      setDetail({ ...r, liste, extra, titre })
      setTimeout(() => document.getElementById('rep-detail')?.scrollIntoView({ behavior: 'smooth' }), 50)
    } catch (e) {
      setError(e.message)
    }
  }

  function clicLigne(r) {
    if (rapport === 'synthese') {
      const d = DETAIL_INDICATEUR[r.cle]
      if (d) ouvrirDetail(d[0], d[1], r.indicateur)
    } else if (rapport === 'par_jour') {
      ouvrirDetail('trajets', { date_debut: r.jour, date_fin: r.jour }, `Trajets du ${r.jour}`)
    } else if (rapport === 'chauffeurs' && r.chauffeur_id) {
      ouvrirDetail('trajets', { chauffeur_id: r.chauffeur_id }, `Trajets de ${r.chauffeur}`)
    } else if (rapport === 'vehicules') {
      ouvrirDetail('trajets', { vehicule_id: r.vehicule_id }, `Trajets du vehicule ${r.vehicule}`)
    } else if (rapport === 'circuits') {
      ouvrirDetail('trajets', { circuit_id: r.circuit_id }, `Trajets du circuit ${r.circuit}`)
    } else if (rapport === 'eleves') {
      ouvrirDetail('embarquements', { eleve_id: r.eleve_id }, `Embarquements de ${r.eleve}`)
    } else if (rapport === 'paiements') {
      const fin = `${r.mois}-${String(new Date(Number(r.mois.slice(0, 4)), Number(r.mois.slice(5, 7)), 0).getDate()).padStart(2, '0')}`
      ouvrirDetail('a_reverser', { date_debut: `${r.mois}-01`, date_fin: fin }, `Encaisse Enko, non recu SHIPP : ${r.mois}`)
    } else if (rapport === 'incidents') {
      const champ = { Nature: 'categorie', Gravite: 'gravite', Statut: 'statut' }[r.dimension]
      ouvrirDetail('incidents', champ ? { [champ]: r.valeur } : {}, `Incidents : ${r.dimension} = ${r.valeur}`)
    }
  }

  async function exporter(extra, nom) {
    setError(null)
    try {
      const p = params({ ...extra, format: 'csv' })
      await api.download('/reporting.php?' + p.toString(), `shipp_${nom}_${filtres.date_debut}_${filtres.date_fin}.csv`)
    } catch (e) {
      setError(e.message)
    }
  }

  const set = (k) => (e) => setFiltres({ ...filtres, [k]: e.target.value })

  return (
    <div className="page rep-page">
      <p className="rep-noprint"><Link to="/">&larr; Tableau de bord</Link></p>
      <h1>Reporting</h1>
      <p className="rep-periode">Periode du <strong>{filtres.date_debut}</strong> au <strong>{filtres.date_fin}</strong></p>
      {error && <p className="error-banner">{error}</p>}

      <form className="ch-filtres rep-noprint" onSubmit={(e) => { e.preventDefault(); load() }}>
        <label className="inc-filtre-date">Du <input type="date" required value={filtres.date_debut} onChange={set('date_debut')} /></label>
        <label className="inc-filtre-date">au <input type="date" required value={filtres.date_fin} onChange={set('date_fin')} /></label>
        {refs.ecoles.length > 1 && (
          <select value={filtres.ecole_id} onChange={set('ecole_id')}><option value="">Tous etablissements</option>{refs.ecoles.map((e) => <option key={e.id} value={e.id}>{e.nom}</option>)}</select>
        )}
        <select value={filtres.circuit_id} onChange={set('circuit_id')}><option value="">Tous circuits</option>{refs.circuits.map((c) => <option key={c.id} value={c.id}>{c.nom}</option>)}</select>
        <select value={filtres.chauffeur_id} onChange={set('chauffeur_id')}><option value="">Tous chauffeurs</option>{refs.chauffeurs.map((c) => <option key={c.id} value={c.id}>{[c.prenom, c.nom].filter(Boolean).join(' ')}</option>)}</select>
        <select value={filtres.vehicule_id} onChange={set('vehicule_id')}><option value="">Tous vehicules</option>{refs.vehicules.map((v) => <option key={v.id} value={v.id}>{v.immatriculation}</option>)}</select>
        <input placeholder="Classe" value={filtres.classe} onChange={set('classe')} />
        <button type="submit" className="btn-transport">Actualiser</button>
      </form>

      <div className="scanner-tabs rep-noprint">
        {Object.entries(RAPPORTS).map(([k, l]) => (
          <button key={k} type="button" className={rapport === k ? 'active' : ''} onClick={() => { setRapport(k); load(k) }}>{l}</button>
        ))}
      </div>

      {loading && <p>Calcul en cours...</p>}
      {res && !loading && (
        <section>
          <div className="rep-actions rep-noprint">
            {res.export && <button type="button" onClick={() => exporter({ rapport }, rapport)}>Exporter (Excel / CSV)</button>}
            <button type="button" onClick={() => window.print()}>Imprimer / PDF</button>
          </div>
          {rapport === 'synthese' && res.indicateurs && (
            <div className="rep-kpis">
              {res.data.map((r) => (
                <button key={r.cle} type="button" className="rep-kpi" onClick={() => clicLigne(r)} disabled={!DETAIL_INDICATEUR[r.cle]}>
                  <span className="rep-kpi-val">{fmt(r.valeur)}</span>
                  <span className="rep-kpi-lib">{r.indicateur}</span>
                </button>
              ))}
            </div>
          )}
          {rapport !== 'synthese' && <Tableau colonnes={res.colonnes} data={res.data} onLigne={clicLigne} />}
          {res.note && <p className="ma-muted">{res.note}</p>}
        </section>
      )}

      {detail && (
        <section id="rep-detail" className="inc-section">
          <div className="rep-detail-head">
            <h2>{detail.titre} <span className="ma-muted">({detail.data.length}{detail.tronque ? '+' : ''})</span></h2>
            <div className="rep-noprint">
              {detail.export && <button type="button" onClick={() => exporter({ liste: detail.liste, ...detail.extra }, `detail_${detail.liste}`)}>Exporter</button>}
              <button type="button" onClick={() => setDetail(null)}>Fermer</button>
            </div>
          </div>
          <p className="ma-muted rep-noprint">
            Autres details :{' '}
            {Object.entries(LISTES).filter(([k]) => k !== detail.liste).map(([k, l]) => (
              <button key={k} type="button" className="inc-lien" onClick={() => ouvrirDetail(k, detail.extra, `${l}${detail.titre.includes(' de ') ? detail.titre.slice(detail.titre.indexOf(' de ')) : ''}`)}>{l}</button>
            ))}
          </p>
          <Tableau colonnes={detail.colonnes} data={detail.data} onLigne={detail.liste === 'incidents' ? (r) => navigate(`/incidents/${r.id}`) : undefined} />
        </section>
      )}
    </div>
  )
}
