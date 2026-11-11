import { useEffect, useMemo, useState } from 'react'
import { api } from '../api/client.js'

// Codes parents (administration) : generation, regeneration, export pour la
// distribution, comptes lies a chaque enfant et retrait d'acces.
// Les codes sont des secrets : a remettre a chaque famille individuellement.
export default function CodesParents() {
  const [data, setData] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [info, setInfo] = useState(null)
  const [circuit, setCircuit] = useState('')
  const [q, setQ] = useState('')
  const [occupe, setOccupe] = useState(false)

  function charger() {
    api.get('/codes-parents.php').then(setData).catch((e) => setErreur(e.message))
  }
  useEffect(charger, [])

  async function action(corps, confirmation) {
    if (confirmation && !window.confirm(confirmation)) return
    setOccupe(true)
    setErreur(null)
    setInfo(null)
    try {
      const r = await api.post('/codes-parents.php', corps)
      if (corps.action === 'generer') setInfo(`${r.generes} code(s) généré(s).`)
      if (corps.action === 'regenerer') setInfo(`Nouveau code : ${r.code_parent}. L'ancien ne fonctionne plus.`)
      if (corps.action === 'retirer') setInfo('Accès retiré.')
      charger()
    } catch (e) {
      setErreur(e.message)
    } finally {
      setOccupe(false)
    }
  }

  const eleves = data?.data || []
  const circuits = useMemo(() => [...new Map(eleves.filter((e) => e.circuit_id).map((e) => [e.circuit_id, e.circuit])).entries()], [eleves])
  const visibles = eleves.filter((e) => (!circuit || String(e.circuit_id) === circuit)
    && (!q || `${e.nom} ${e.prenom} ${e.code_parent || ''}`.toLowerCase().includes(q.toLowerCase())))
  const sansCode = eleves.filter((e) => !e.code_parent).length
  const lies = eleves.filter((e) => e.comptes.length > 0).length
  const lienInscription = `${window.location.origin}/inscription`

  return (
    <div className="g-page">
      <header className="g-entete">
        <div>
          <h1>Codes parents</h1>
          <p className="g-sous-titre">
            Lien d'inscription à partager : <strong>{lienInscription}</strong>. Chaque famille reçoit ensuite le code de son enfant,
            individuellement (jamais dans un groupe).
          </p>
        </div>
        <div className="g-entete-actions">
          <button type="button" className="g-btn" disabled={occupe || sansCode === 0} onClick={() => action({ action: 'generer' })}>
            Générer les codes manquants ({sansCode})
          </button>
          <button type="button" className="g-btn" onClick={() => api.download('/codes-parents.php?format=csv', 'codes_parents.csv').catch((e) => setErreur(e.message))}>
            Exporter (CSV)
          </button>
        </div>
      </header>
      {erreur && <p className="error-banner">{erreur}</p>}
      {info && <p className="success-banner" role="status">{info}</p>}
      {data && (
        <p className="g-sous-titre">
          {eleves.length} élèves actifs · {lies} suivis par au moins un parent · maximum {data.max_comptes} comptes par enfant
          {!data.inscription_ouverte && ' · inscriptions fermées'}
        </p>
      )}
      <div className="g-filtres">
        <select aria-label="Circuit" value={circuit} onChange={(e) => setCircuit(e.target.value)}>
          <option value="">Tous les circuits</option>
          {circuits.map(([id, nom]) => <option key={id} value={id}>{nom}</option>)}
        </select>
        <input type="search" aria-label="Rechercher" placeholder="Rechercher un élève ou un code..." value={q} onChange={(e) => setQ(e.target.value)} />
      </div>
      <div className="g-table-wrap">
        <table className="g-table">
          <thead><tr><th>Élève</th><th>Classe</th><th>Circuit</th><th>Code parent</th><th>Comptes liés</th><th /></tr></thead>
          <tbody>
            {visibles.map((e) => (
              <tr key={e.id}>
                <td>{e.nom} {e.prenom}</td>
                <td>{e.classe || '—'}</td>
                <td>{e.circuit || '—'}</td>
                <td><code>{e.code_parent || '—'}</code></td>
                <td>
                  {e.comptes.length === 0 && <span className="g-vide">aucun</span>}
                  {e.comptes.map((c) => (
                    <div key={c.liaison_id}>
                      {c.name} {c.telephone ? `(${c.telephone})` : ''}
                      {' '}<button type="button" className="g-btn g-btn-lien" disabled={occupe}
                        onClick={() => action({ action: 'retirer', liaison_id: c.liaison_id }, `Retirer l'accès de ${c.name} à ${e.prenom} ${e.nom} ?`)}>retirer</button>
                    </div>
                  ))}
                </td>
                <td>
                  {e.code_parent && (
                    <button type="button" className="g-btn g-btn-lien" disabled={occupe}
                      onClick={() => action({ action: 'regenerer', eleve_id: e.id }, `Générer un nouveau code pour ${e.prenom} ${e.nom} ? L'ancien code ne fonctionnera plus (les parents déjà liés gardent l'accès).`)}>
                      Nouveau code
                    </button>
                  )}
                </td>
              </tr>
            ))}
            {data && visibles.length === 0 && <tr><td colSpan={6} className="g-vide">Aucun élève.</td></tr>}
            {!data && !erreur && <tr><td colSpan={6} className="g-vide">Chargement...</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}
