import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../context/AuthContext.jsx'
import { api } from '../api/client.js'

// Le parent saisit le CODE PARENT remis par SHIPP pour chacun de ses enfants.
// Le serveur verifie le code, limite les essais et le nombre de comptes par enfant.
export default function AjouterEnfant() {
  const { refreshAccess } = useAuth()
  const [code, setCode] = useState('')
  const [lien, setLien] = useState('autre')
  const [enfants, setEnfants] = useState(null)
  const [message, setMessage] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  function charger() {
    api.get('/parent-lier.php').then((r) => setEnfants(r.data || [])).catch((e) => setErreur(e.message))
  }
  useEffect(charger, [])

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    setMessage(null)
    setEnvoi(true)
    try {
      const r = await api.post('/parent-lier.php', { code, lien })
      setMessage(r.deja_lie ? `${r.eleve.nom} est déjà dans votre suivi.` : `${r.eleve.nom} a été ajouté(e) à votre suivi.`)
      setCode('')
      charger()
      if (refreshAccess) refreshAccess()
    } catch (err) {
      setErreur(err.message)
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <div className="g-page">
      <header className="g-entete">
        <div>
          <h1>Ajouter mon enfant</h1>
          <p className="g-sous-titre">Saisissez le code parent remis par SHIPP (forme SHP-XXXX-XXXX). Un code par enfant.</p>
        </div>
      </header>

      <form onSubmit={soumettre} className="login-form" style={{ maxWidth: 420 }}>
        {erreur && <p className="error-banner">{erreur}</p>}
        {message && <p className="success-banner" role="status">{message}</p>}
        <label className="module-form-field">
          <span>Code parent</span>
          <input type="text" value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} placeholder="SHP-XXXX-XXXX"
            autoComplete="off" autoCapitalize="characters" spellCheck={false} required maxLength={20} />
        </label>
        <label className="module-form-field">
          <span>Vous êtes</span>
          <select value={lien} onChange={(e) => setLien(e.target.value)}>
            <option value="mere">La mère</option>
            <option value="pere">Le père</option>
            <option value="tuteur">Le tuteur / la tutrice</option>
            <option value="autre">Autre</option>
          </select>
        </label>
        <button type="submit" disabled={envoi}>{envoi ? 'Vérification...' : 'Ajouter cet enfant'}</button>
      </form>

      <section style={{ marginTop: 24 }}>
        <h2>Mes enfants suivis</h2>
        {enfants === null && !erreur && <p className="g-vide">Chargement...</p>}
        {enfants && enfants.length === 0 && <p className="g-vide">Aucun enfant pour le moment.</p>}
        {enfants && enfants.length > 0 && (
          <>
            <ul>
              {enfants.map((e) => <li key={e.eleve_id}>{e.prenom} {e.nom}{e.classe ? ` — ${e.classe}` : ''}</li>)}
            </ul>
            <Link className="g-btn g-btn-primaire" to="/suivi-enfants">Voir le suivi de mes enfants</Link>
          </>
        )}
      </section>
      <p className="g-sous-titre" style={{ marginTop: 24 }}>Code perdu ou refusé ? Contactez SHIPP : nous vérifierons votre identité avant de vous donner l'accès.</p>
    </div>
  )
}
