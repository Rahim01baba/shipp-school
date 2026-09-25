import { createContext, useContext, useEffect, useState } from 'react'
import { api, setToken } from '../api/client.js'

const AuthContext = createContext(null)

const emptyAccess = { is_admin: false, permissions: {}, parent_eleve_ids: [], chauffeur_circuit_ids: [], roles: [], scope: 'NONE', chauffeur_id: null }

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)
  const [access, setAccess] = useState(emptyAccess)
  const [accessLoading, setAccessLoading] = useState(true)

  function loadPermissions() {
    setAccessLoading(true)
    return api
      .get('/permissions-me.php')
      .then((data) =>
        setAccess({
          is_admin: !!data.is_admin,
          permissions: data.permissions || {},
          parent_eleve_ids: data.parent_eleve_ids || [], chauffeur_circuit_ids: data.chauffeur_circuit_ids || [],
          roles: data.roles || [],
          scope: data.scope || 'NONE',
          chauffeur_id: data.chauffeur_id || null,
        })
      )
      .catch(() => setAccess(emptyAccess))
      .finally(() => setAccessLoading(false))
  }

  useEffect(() => {
    const token = localStorage.getItem('shipp_token')
    if (!token) {
      setLoading(false)
      setAccessLoading(false)
      return
    }
    api
      .get('/auth-me.php')
      .then((data) => setUser(data.user))
      .catch(() => setToken(null))
      .finally(() => setLoading(false))
    loadPermissions()
  }, [])

  function login(userData, token) {
    setToken(token)
    setUser(userData)
    loadPermissions()
  }

  function logout() {
    setToken(null)
    setUser(null)
    setAccess(emptyAccess)
  }

  function can(moduleKey, action = 'can_read') {
    if (access.is_admin) return true
    const perm = access.permissions[moduleKey]
    return !!(perm && perm[action])
  }

  // Perimetre de donnees du module (GLOBAL, SCHOOL, ASSIGNED_ROUTE, CHILDREN, OWN, NONE).
  function scopeOf(moduleKey) {
    if (access.is_admin) return 'GLOBAL'
    return access.permissions[moduleKey]?.scope || 'NONE'
  }

  return (
    <AuthContext.Provider
      value={{
        user,
        login,
        logout,
        loading,
        isAdmin: access.is_admin,
        permissions: access.permissions,
        parentEleveIds: access.parent_eleve_ids, chauffeurCircuitIds: access.chauffeur_circuit_ids,
        roles: access.roles,
        scope: access.scope,
        chauffeurId: access.chauffeur_id,
        scopeOf,
        accessLoading,
        can,
      }}
    >
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  return useContext(AuthContext)
}
