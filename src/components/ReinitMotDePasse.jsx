import { useEffect, useRef, useState } from 'react'
import { api } from '../api/client.js'

// Reinitialisation du mot de passe d'un utilisateur par un administrateur.
// L'API existante (PUT /users.php) est reservee aux administrateurs et impose 8 caracteres minimum.
// Le mot de passe n'est jamais affiche apres enregistrement : l'admin le transmet lui-meme a l'utilisateur.

export default function ReinitMotDePasse({ utilisateur, onFermer }) {
  const [mdp, setMdp] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [visible, setVisible] = useState(false)
  const [erreur, setErreur] = useState(null)
  const [ok, setOk] = useState(false)
  const [busy, setBusy] = useState(false)
  const premier = useRef(null)

  const fermer = useRef(onFermer)
  fermer.current = onFermer
  useEffect(() => {
    premier.current?.focus()
    const echap = (e) => { if (e.key === 'Escape') fermer.current() }
    document.addEventListener('keydown', echap)
    return () => document.removeEventListener('keydown', echap)
  }, [])

  const tropCourt = mdp.length > 0 && mdp.length < 8
  const different = confirmation.length > 0 && confirmation !== mdp

  async function enregistrer(e) {
    e.preventDefault()
    setErreur(null)
    if (mdp.length < 8) return setErreur('8 caractères minimum.')
    if (mdp !== confirmation) return setErreur('Les deux saisies ne correspondent pas.')
    setBusy(true)
    try {
      await api.put('/users.php', { id: utilisateur.id, password: mdp })
      setMdp('')
      setConfirmation('')
      setOk(true)
    } catch (err) {
      setErreur(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="modale-fond" onMouseDown={(e) => { if (e.target === e.currentTarget) onFermer() }}>
      <div className="modale" role="dialog" aria-modal="true" aria-labelledby="titre-mdp">
        <h2 id="titre-mdp">Réinitialiser le mot de passe</h2>
        <p className="texte-discret">{utilisateur.name}{utilisateur.email || utilisateur.telephone ? ` · ${utilisateur.email || utilisateur.telephone}` : ''}</p>
        {ok ? (
          <>
            <p className="ma-info">Mot de passe enregistré. Communiquez-le à l'utilisateur par un canal sûr ; il pourra se connecter immédiatement.</p>
            <div className="modale-actions"><button type="button" className="btn btn-primary" onClick={onFermer}>Fermer</button></div>
          </>
        ) : (
          <form onSubmit={enregistrer} noValidate>
            <label className="champ">
              <span>Nouveau mot de passe *</span>
              <input ref={premier} type={visible ? 'text' : 'password'} autoComplete="new-password" value={mdp} onChange={(e) => setMdp(e.target.value)} aria-invalid={tropCourt} aria-describedby="aide-mdp" />
              <small id="aide-mdp" className={tropCourt ? 'texte-danger' : 'texte-discret'}>8 caractères minimum.</small>
            </label>
            <label className="champ">
              <span>Confirmer le mot de passe *</span>
              <input type={visible ? 'text' : 'password'} autoComplete="new-password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} aria-invalid={different} />
              {different && <small className="texte-danger">Les deux saisies ne correspondent pas.</small>}
            </label>
            <label className="champ-case"><input type="checkbox" checked={visible} onChange={(e) => setVisible(e.target.checked)} /> Afficher les caractères</label>
            {erreur && <p className="error-banner" role="alert">{erreur}</p>}
            <div className="modale-actions">
              <button type="button" className="btn btn-ghost" onClick={onFermer}>Annuler</button>
              <button type="submit" className="btn btn-primary" disabled={busy || mdp.length < 8 || mdp !== confirmation}>{busy ? 'Enregistrement...' : 'Enregistrer'}</button>
            </div>
          </form>
        )}
      </div>
    </div>
  )
}
