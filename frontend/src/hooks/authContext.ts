import { createContext } from 'react'
import type { AuthUser, LoginCredentials, UserRole } from '../types/auth'

export type AuthStatus = 'loading' | 'authenticated' | 'unauthenticated'

export interface AuthContextValue {
  status: AuthStatus
  user: AuthUser | null
  /** Set when the initial session check failed (e.g. API unreachable). */
  bootError: string | null
  login: (credentials: LoginCredentials) => Promise<void>
  logout: () => Promise<void>
  retry: () => void
  hasRole: (...roles: UserRole[]) => boolean
}

export const AuthContext = createContext<AuthContextValue | null>(null)
