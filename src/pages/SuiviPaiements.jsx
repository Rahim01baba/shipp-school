import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client.js'
import { useAuth } from '../context/AuthContext.jsx'

// Suivi mensuel des paiements transport (lot 5, D-25), sur le modele du fichier ENKO :
// pour chaque eleve et chaque mois, « Enko » = l'etablissement a encaisse le mois,
// « Shipp » = SHIPP l'a recu ; ni l'un ni l'autre = « Non ». Les mois sont ceux de
// l'annee scolaire (reglables dans Annees scolaires).

const MOIS_COURTS = ['janv.', 'fevr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'aout', 'sept.', 'oct.', 'nov.', 'dec.']
const fcfa = (v) => `${Math.round(Number(v || 0)).toLocaleString('fr-FR')}`

function libelleMois(m) {
  const [y, mm] = m.split('-')
  return `${MOIS_COURTS[Number(mm) - 1]} ${y.slice(2)}`
}

function Cellule({ cell, modifiable, onToggle }) {
  const c = cell || {}
  const enko = Number(c.encaisse_enko) === 1
  const shipp = Number(c.recu_shipp) === 1
  const arret = Number(c.arret_service) === 1
  if (!cell && !modifiable) return <td className="sp-vide">—</td>
  const cls = arret ? 'sp-arret' : shipp ? 'sp-shipp' : enko ? 'sp-enko' : 'sp-non'
  return (
    <td className={`sp-cell ${cls}`} title={c.commentaire || ''}>
      {arret ? (
        <button type="button" className="sp-arret-btn" disabled={!modifiable} title="Reprendre le service ce mois" onClick={() => onToggle({ arret_service: false })}>Arret S/c</button>
      ) : (
        <div className="sp-toggles">
          <button type="button" disabled={!modifiable} className={enko ? 'on' : ''} onClick={() => onToggle({ encaisse_enko: !enko })}>Enko</button>
          <button type="button" disabled={!modifiable} className={shipp ? 'on' : ''} onClick={() => onToggle({ recu_shipp: !shipp })}>Shipp</button>
          {modifiable && <button type="button" className="sp-mini" title="Service arrete ce mois" onClick={() => { if (window.confirm('Marquer le service arrete pour ce mois ?')) onToggle({ arret_service: true }) }}>×</button>}
        </div>
      )}
    </td>
  )
}

export default function SuiviPaiements() {
  const { can, accessLoading } = useAuth()
  const [annees, setAnnees] = useState([])
  const [anneeId, setAnneeId] = useState('')
  const [q, setQ] = useState('')
  const [data, setData] = useState(null)
  const [tarifs, setTarifs] = useState([])
  const [tarif, setTarif] = useState({ zone: '', montant_mensuel: '' })
  const [error, setError] = useState(null)
  const [info, setInfo] = useState(null)

  async function load(a = anneeId) {
    setError(null)
    try {
      const p = new URLSearchParams()
      if (a) p.set('annee_scolaire_id', a)
      if (q.trim()) p.set('q', q.trim())
      const res = await api.get('/echeances-transport.php?' + p.toString())
      setData(res)
      if (!a) setAnneeId(String(res.annee.id))
      if (can('tarifs', 'can_read')) setTarifs((await api.get(`/tarifs.php?annee_scolaire_id=${res.annee.id}`)).data || [])
    } catch (e) {
      setError(e.message)
    }
  }

  useEffect(() => {
    if (accessLoading) return
    load()
    if (can('annees_scolaires', 'can_read')) api.get('/crud.php?module=annees_scolaires&annee_scolaire_id=toutes').then((r) => setAnnees(r.data || [])).catch(() => {})
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [accessLoading])

  async function toggle(eleveId, mois, champs) {
    try {
      await api.put('/echeances-transport.php', { eleve_id: eleveId, mois, ...champs })
      await load()
    } catch (e) {
      setError(e.message)
    }
  }

  async function generer() {
    setInfo(null)
    try {
      const r = await api.post('/echeances-transport.php', { action: 'generer', annee_scolaire_id: Number(anneeId) })
      setInfo(`${r.crees} mois crees${r.abonnements_sans_montant ? ` — ${r.abonnements_sans_montant} abonnement(s) sans montant ni tarif de zone` : ''}`)
      await load()
    } catch (e) {
      setError(e.message)
    }
  }

  async function ajouterTarif(e) {
    e.preventDefault()
    try {
      await api.post('/tarifs.php', { annee_scolaire_id: Number(anneeId), ...tarif })
      setTarif({ zone: '', montant_mensuel: '' })
      await load()
    } catch (err) {
      setError(err.message)
    }
  }

  const modifiable = data?.droits?.modifier

  return (
    <div className="page sp-page">
      <p><Link to="/">&larr; Tableau de bord</Link></p>
      <h1>Suivi mensuel des paiements transport</h1>
      <p className="ma-muted">Enko = l'etablissement a encaisse le mois. Shipp = SHIPP l'a recu. Aucun des deux = Non.</p>
      {error && <p className="error-banner">{error}</p>}
      {info && <p className="ma-info">{info}</p>}
      <form className="ch-filtres" onSubmit={(e) => { e.preventDefault(); load() }}>
        {annees.length > 0 && (
          <select value={anneeId} onChange={(e) => { setAnneeId(e.target.value); load(e.target.value) }}>
            {annees.map((a) => <option key={a.id} value={a.id}>{a.libelle}{a.statut === 'active' ? ' (active)' : ''}</option>)}
          </select>
        )}
        <input type="search" placeholder="Eleve" value={q} onChange={(e) => setQ(e.target.value)} />
        <button type="submit">Filtrer</button>
        {data?.droits?.generer && <button type="button" onClick={generer}>Creer les mois des abonnements actifs</button>}
      </form>

      {data && (
        <>
          <div className="module-table-wrap sp-wrap">
            <table className="module-table sp-table">
              <thead>
                <tr><th>Eleve</th><th>Zone</th><th>Mensuel</th>{data.mois.map((m) => <th key={m}>{libelleMois(m)}</th>)}</tr>
              </thead>
              <tbody>
                {data.data.map((e) => (
                  <tr key={e.id}>
                    <td><Link to={`/eleves/${e.id}`}>{e.nom} {e.prenom}</Link> <span className="ma-muted">{e.classe || ''}</span></td>
                    <td>{e.zone_tarifaire || '-'}</td>
                    <td>{e.montant_mensuel ? fcfa(e.montant_mensuel) : '-'}</td>
                    {data.mois.map((m) => <Cellule key={m} cell={e.mois[m]} modifiable={modifiable} onToggle={(ch) => toggle(e.id, m, ch)} />)}
                  </tr>
                ))}
                {data.data.length === 0 && <tr><td colSpan={data.mois.length + 3}>Aucun eleve avec abonnement transport sur cette annee.</td></tr>}
              </tbody>
              <tfoot>
                {[['du', 'Du'], ['enko', 'Encaisse Enko'], ['shipp', 'Recu SHIPP'], ['a_reverser', 'Enko, non recu SHIPP'], ['impaye', 'Ni Enko ni SHIPP (echu)']].map(([k, l]) => (
                  <tr key={k} className={`sp-total sp-total-${k}`}>
                    <td colSpan={3}>{l} (FCFA)</td>
                    {data.mois.map((m) => <td key={m}>{fcfa(data.totaux[m]?.[k])}</td>)}
                  </tr>
                ))}
              </tfoot>
            </table>
          </div>

          {can('tarifs', 'can_read') && (
            <section className="inc-section">
              <h2>Tarifs mensuels par zone ({data.annee.libelle})</h2>
              <ul>{tarifs.map((t) => <li key={t.id}>{t.zone} : {fcfa(t.montant_mensuel)} FCFA{t.source === 'import' ? ' (importe)' : ''}</li>)}</ul>
              {tarifs.length === 0 && <p className="ma-muted">Aucun tarif pour cette annee.</p>}
              {can('tarifs', 'can_create') && (
                <form className="ch-filtres" onSubmit={ajouterTarif}>
                  <input placeholder="Zone (ex. ABIDJAN NORD)" value={tarif.zone} onChange={(e) => setTarif({ ...tarif, zone: e.target.value })} required />
                  <input type="number" min="0" step="0.01" placeholder="Montant mensuel" value={tarif.montant_mensuel} onChange={(e) => setTarif({ ...tarif, montant_mensuel: e.target.value })} required />
                  <button type="submit">Ajouter</button>
                </form>
              )}
            </section>
          )}
        </>
      )}
    </div>
  )
}
