import { Fragment, useEffect, useState } from 'react'
import { api } from '../api/client.js'

/**
 * Gestion des droits — matrice type Jenkins (Prompt 02) + rôles (Prompt 03).
 *
 * Une seule grande table : chaque module est un groupe de 4 colonnes
 * (C = Création, E = Édition, S = Suppression, L = Lecture).
 * Les lignes du haut sont les 3 rôles par défaut (Eleves, Parent, Admin) :
 * elles portent les permissions de base appliquées à tout le monde dans ce
 * rôle. Les lignes du bas sont des utilisateurs individuels identifiés par
 * email, qui servent d'exceptions/dérogations au-dessus du rôle. Chaque
 * case a cocher sauvegarde immédiatement en base (pas de bouton
 * "Enregistrer" global).
 */
export default function Rights() {
  const [users, setUsers] = useState([])
  const [modules, setModules] = useState([])
  const [grid, setGrid] = useState({})
  const [roles, setRoles] = useState([])
  const [roleGrid, setRoleGrid] = useState({})
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [savingUserId, setSavingUserId] = useState(null)
  const [savingRoleId, setSavingRoleId] = useState(null)
  const emptyNewUser = { name: '', email: '', telephone: '', password: '', role_key: '' }
  const [newUser, setNewUser] = useState(emptyNewUser)
  // Roles attribues aux utilisateurs (roles.php) : { [userId]: ['parent', ...] }
  const [userRoles, setUserRoles] = useState({})
  const [rolesAvailable, setRolesAvailable] = useState(true)

  async function loadMatrix() {
    setLoading(true)
    setError(null)
    try {
      const data = await api.get('/permissions-matrix.php')
      setUsers(data.users)
      setModules(data.modules)
      setGrid(data.grid || {})
      setRoles(data.roles || [])
      setRoleGrid(data.roleGrid || {})
      try {
        const r = await api.get('/roles.php')
        const map = {}
        ;(r.user_roles || []).forEach((ur) => {
          map[ur.user_id] = [...(map[ur.user_id] || []), ur.role_key]
        })
        setUserRoles(map)
        setRolesAvailable(true)
      } catch {
        setRolesAvailable(false)
      }
    } catch (e) {
      setError(e.message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadMatrix()
  }, [])

  function cellValue(userId, moduleId, field) {
    return !!grid[userId]?.[moduleId]?.[field]
  }

  function roleCellValue(roleId, moduleId, field) {
    return !!roleGrid[roleId]?.[moduleId]?.[field]
  }

  async function toggle(userId, moduleId, field) {
    const prevGrid = grid
    const userGrid = grid[userId] || {}
    const current = userGrid[moduleId] || {
      can_read: false,
      can_create: false,
      can_edit: false,
      can_delete: false,
    }
    const updatedCell = { ...current, [field]: !current[field] }
    const nextUserGrid = { ...userGrid, [moduleId]: updatedCell }
    const nextGrid = { ...grid, [userId]: nextUserGrid }
    setGrid(nextGrid)
    setSavingUserId(userId)
    setError(null)
    try {
      const permissions = modules.map((mod) => {
        const cell = nextUserGrid[mod.id] || {
          can_read: false,
          can_create: false,
          can_edit: false,
          can_delete: false,
        }
        return {
          permission_id: mod.id,
          can_read: !!cell.can_read,
          can_create: !!cell.can_create,
          can_edit: !!cell.can_edit,
          can_delete: !!cell.can_delete,
        }
      })
      await api.post('/permissions-update.php', { user_id: userId, permissions })
    } catch (e) {
      setError(e.message)
      setGrid(prevGrid)
    } finally {
      setSavingUserId(null)
    }
  }

  async function toggleRole(roleId, moduleId, field) {
    const prevRoleGrid = roleGrid
    const roleGridForRole = roleGrid[roleId] || {}
    const current = roleGridForRole[moduleId] || {
      can_read: false,
      can_create: false,
      can_edit: false,
      can_delete: false,
    }
    const updatedCell = { ...current, [field]: !current[field] }
    const nextRoleGridForRole = { ...roleGridForRole, [moduleId]: updatedCell }
    const nextRoleGrid = { ...roleGrid, [roleId]: nextRoleGridForRole }
    setRoleGrid(nextRoleGrid)
    setSavingRoleId(roleId)
    setError(null)
    try {
      const permissions = modules.map((mod) => {
        const cell = nextRoleGridForRole[mod.id] || {
          can_read: false,
          can_create: false,
          can_edit: false,
          can_delete: false,
        }
        return {
          permission_id: mod.id,
          can_read: !!cell.can_read,
          can_create: !!cell.can_create,
          can_edit: !!cell.can_edit,
          can_delete: !!cell.can_delete,
        }
      })
      await api.post('/permissions-update.php', { role_id: roleId, permissions })
    } catch (e) {
      setError(e.message)
      setRoleGrid(prevRoleGrid)
    } finally {
      setSavingRoleId(null)
    }
  }

  async function changeUserRole(userId, roleKey) {
    setError(null)
    setSavingUserId(userId)
    try {
      for (const current of userRoles[userId] || []) {
        if (current !== roleKey) {
          await api.del('/roles.php', { user_id: userId, role_key: current })
        }
      }
      if (roleKey) {
        await api.post('/roles.php', { user_id: userId, role_key: roleKey })
      }
      await loadMatrix()
    } catch (e) {
      setError(e.message)
    } finally {
      setSavingUserId(null)
    }
  }

  async function createUser(e) {
    e.preventDefault()
    if (!newUser.name.trim() || (!newUser.email.trim() && !newUser.telephone.trim())) {
      setError('Nom et e-mail ou telephone requis')
      return
    }
    setError(null)
    try {
      await api.post('/users.php', newUser)
      setNewUser(emptyNewUser)
      await loadMatrix()
    } catch (e) {
      setError(e.message)
    }
  }

  if (loading) {
    return <div className="page">Chargement...</div>
  }

  const colCount = 1 + modules.length * 4

  return (
    <div className="page rights-page">
      <h1>Gestion des droits</h1>
      {error && <p className="error-banner">{error}</p>}

      <div className="rights-matrix-wrap">
        <table className="rights-matrix-table">
          <thead>
            <tr>
              <th className="col-user" rowSpan={2}>
                Utilisateur / Groupe
              </th>
              {modules.map((mod) => (
                <th key={mod.id} className="col-module-group" colSpan={4}>
                  {mod.module_label}
                </th>
              ))}
            </tr>
            <tr>
              {modules.map((mod) => (
                <Fragment key={mod.id}>
                  <th className="col-sub" title="Création">
                    C
                  </th>
                  <th className="col-sub" title="Édition">
                    E
                  </th>
                  <th className="col-sub" title="Suppression">
                    S
                  </th>
                  <th className="col-sub col-sub-last" title="Lecture">
                    L
                  </th>
                </Fragment>
              ))}
            </tr>
          </thead>
          <tbody>
            {roles.map((role) => (
              <tr
                key={`role-${role.id}`}
                className={`role-row${savingRoleId === role.id ? ' row-saving' : ''}`}
              >
                <td className="col-user">
                  <div className="user-name">{role.role_label}</div>
                  <div className="email">Rôle par défaut</div>
                </td>
                {modules.map((mod) => (
                  <Fragment key={mod.id}>
                    <td className="col-sub">
                      <input
                        type="checkbox"
                        checked={roleCellValue(role.id, mod.id, 'can_create')}
                        onChange={() => toggleRole(role.id, mod.id, 'can_create')}
                      />
                    </td>
                    <td className="col-sub">
                      <input
                        type="checkbox"
                        checked={roleCellValue(role.id, mod.id, 'can_edit')}
                        onChange={() => toggleRole(role.id, mod.id, 'can_edit')}
                      />
                    </td>
                    <td className="col-sub">
                      <input
                        type="checkbox"
                        checked={roleCellValue(role.id, mod.id, 'can_delete')}
                        onChange={() => toggleRole(role.id, mod.id, 'can_delete')}
                      />
                    </td>
                    <td className="col-sub col-sub-last">
                      <input
                        type="checkbox"
                        checked={roleCellValue(role.id, mod.id, 'can_read')}
                        onChange={() => toggleRole(role.id, mod.id, 'can_read')}
                      />
                    </td>
                  </Fragment>
                ))}
              </tr>
            ))}
            {users.map((u) => (
              <tr key={u.id} className={savingUserId === u.id ? 'row-saving' : ''}>
                <td className="col-user">
                  <div className="user-name">{u.name}</div>
                  <div className="email">{u.email || u.telephone || ''}</div>
                  {rolesAvailable && (
                    <select
                      className="user-role-select"
                      value={(userRoles[u.id] || [])[0] || ''}
                      disabled={savingUserId === u.id}
                      onChange={(e) => changeUserRole(u.id, e.target.value)}
                      title="Role de l'utilisateur (determine son perimetre de donnees)"
                    >
                      <option value="">-- Aucun role --</option>
                      {roles.map((r) => (
                        <option key={r.id} value={r.role_key}>
                          {r.role_label}
                        </option>
                      ))}
                    </select>
                  )}
                </td>
                {modules.map((mod) => (
                  <Fragment key={mod.id}>
                    <td className="col-sub">
                      <input
                        type="checkbox"
                        checked={cellValue(u.id, mod.id, 'can_create')}
                        onChange={() => toggle(u.id, mod.id, 'can_create')}
                      />
                    </td>
                    <td className="col-sub">
                      <input
                        type="checkbox"
                        checked={cellValue(u.id, mod.id, 'can_edit')}
                        onChange={() => toggle(u.id, mod.id, 'can_edit')}
                      />
                    </td>
                    <td className="col-sub">
                      <input
                        type="checkbox"
                        checked={cellValue(u.id, mod.id, 'can_delete')}
                        onChange={() => toggle(u.id, mod.id, 'can_delete')}
                      />
                    </td>
                    <td className="col-sub col-sub-last">
                      <input
                        type="checkbox"
                        checked={cellValue(u.id, mod.id, 'can_read')}
                        onChange={() => toggle(u.id, mod.id, 'can_read')}
                      />
                    </td>
                  </Fragment>
                ))}
              </tr>
            ))}
            {users.length === 0 && roles.length === 0 && (
              <tr>
                <td colSpan={colCount}>Aucun rôle ni utilisateur. Ajoutes-en un ci-dessous.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <form onSubmit={createUser} className="add-user-bar">
        <span className="add-user-label">Nouvel utilisateur :</span>
        <input
          type="text"
          placeholder="Nom"
          value={newUser.name}
          onChange={(e) => setNewUser({ ...newUser, name: e.target.value })}
        />
        <input
          type="email"
          placeholder="Email (facultatif si telephone)"
          value={newUser.email}
          onChange={(e) => setNewUser({ ...newUser, email: e.target.value })}
        />
        <input
          type="tel"
          placeholder="Telephone"
          value={newUser.telephone}
          onChange={(e) => setNewUser({ ...newUser, telephone: e.target.value })}
        />
        <input
          type="password"
          placeholder="Mot de passe initial (8 car. min.)"
          value={newUser.password}
          onChange={(e) => setNewUser({ ...newUser, password: e.target.value })}
        />
        <select value={newUser.role_key} onChange={(e) => setNewUser({ ...newUser, role_key: e.target.value })}>
          <option value="">-- Role --</option>
          {roles.map((r) => (
            <option key={r.id} value={r.role_key}>
              {r.role_label}
            </option>
          ))}
        </select>
        <button type="submit">Ajouter</button>
      </form>
    </div>
  )
}
