import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { AuthContext, type AuthContextValue, type AuthStatus } from '../../hooks/authContext'
import { errorMessage, onUnauthorized } from '../../services/apiClient'
import { authService } from '../../services/authService'
import type { AuthUser, LoginCredentials, UserRole } from '../../types/auth'

interface AuthState {
  status: AuthStatus
  user: AuthUser | null
  bootError: string | null
}

/**
 * Owns the signed-in user. On load it asks the API for the current session
 * (which also provides the CSRF token). The user object only lives in memory;
 * nothing about the session or password is written to browser storage.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<AuthState>({ status: 'loading', user: null, bootError: null })
  const [bootAttempt, setBootAttempt] = useState(0)

  useEffect(() => {
    let cancelled = false
    authService.getSession().then(
      (session) => {
        if (cancelled) return
        setState({
          status: session.authenticated && session.user ? 'authenticated' : 'unauthenticated',
          user: session.user,
          bootError: null,
        })
      },
      (error: unknown) => {
        if (!cancelled) setState({ status: 'unauthenticated', user: null, bootError: errorMessage(error) })
      },
    )
    return () => {
      cancelled = true
    }
  }, [bootAttempt])

  // Session expired / revoked server-side: drop to the login screen.
  useEffect(() => {
    onUnauthorized(() => {
      setState({ status: 'unauthenticated', user: null, bootError: null })
      void authService.getSession().catch(() => undefined)
    })
    return () => onUnauthorized(null)
  }, [])

  const login = useCallback(async (credentials: LoginCredentials) => {
    const user = await authService.login(credentials)
    setState({ status: 'authenticated', user, bootError: null })
  }, [])

  const logout = useCallback(async () => {
    try {
      await authService.logout()
    } finally {
      setState({ status: 'unauthenticated', user: null, bootError: null })
      // Fetch a fresh CSRF token for the next login.
      void authService.getSession().catch(() => undefined)
    }
  }, [])

  const retry = useCallback(() => {
    setState({ status: 'loading', user: null, bootError: null })
    setBootAttempt((attempt) => attempt + 1)
  }, [])

  const hasRole = useCallback(
    (...roles: UserRole[]) => state.user !== null && roles.includes(state.user.role),
    [state.user],
  )

  const value = useMemo<AuthContextValue>(
    () => ({ ...state, login, logout, retry, hasRole }),
    [state, login, logout, retry, hasRole],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
