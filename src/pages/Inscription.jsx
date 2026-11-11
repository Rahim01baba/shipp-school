import { useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext.jsx'
import { api } from '../api/client.js'

// Inscription autonome d'un parent (page publique). Le compte cree ne voit
// aucun enfant : le parent saisit ensuite le code parent de chaque enfant.
export default function Inscription() {
  const { user, login, loading } = useAuth()
  const navigate = useNavigate()
  const [f, setF] = useState({ nom: '', telephone: '', email: '', password: '', confirmation: '' })
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  if (!loading && user) {
    return <Navigate to="/ajouter-enfant" replace />
  }

  const maj = (k) => (e) => setF({ ...f, [k]: e.target.value })

  async function soumettre(e) {
    e.preventDefault()
    setErreur(null)
    if (f.password !== f.confirmation) {
      setErreur('Les deux mots de passe sont différents.')
      return
    }
    setEnvoi(true)
    try {
      const r = await api.post('/auth-register.php', { nom: f.nom, telephone: f.telephone, email: f.email, password: f.password })
      login(r.user, r.token)
      navigate('/ajouter-enfant', { replace: true })
    } catch (err) {
      setErreur(err.message)
    } finally {
      setEnvoi(false)
    }
  }

  return (
    <div className="page page-center">
      <h1>SHIPP</h1>
      <p>Créez votre compte parent pour suivre le transport de votre enfant.</p>
      <form onSubmit={soumettre} className="login-form">
        {erreur && <p className="error-banner">{erreur}</p>}
        <label className="module-form-field">
          <span>Nom et prénom</span>
          <input type="text" autoComplete="name" value={f.nom} onChange={maj('nom')} required minLength={2} maxLength={150} />
        </label>
        <label className="module-form-field">
          <span>Téléphone (sert d'identifiant)</span>
          <input type="tel" autoComplete="tel" inputMode="tel" placeholder="07 00 00 00 00" value={f.telephone} onChange={maj('telephone')} required />
        </label>
        <label className="module-form-field">
          <span>E-mail (facultatif)</span>
          <input type="email" autoComplete="email" value={f.email} onChange={maj('email')} />
        </label>
        <label className="module-form-field">
          <span>Mot de passe (8 caractères minimum)</span>
          <input type="password" autoComplete="new-password" value={f.password} onChange={maj('password')} required minLength={8} />
        </label>
        <label className="module-form-field">
          <span>Confirmer le mot de passe</span>
          <input type="password" autoComplete="new-password" value={f.confirmation} onChange={maj('confirmation')} required minLength={8} />
        </label>
        <button type="submit" disabled={envoi}>{envoi ? 'Création...' : 'Créer mon compte'}</button>
        <p><Link to="/login">J'ai déjà un compte : me connecter</Link></p>
      </form>
    </div>
  )
}
