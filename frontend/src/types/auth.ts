export type UserRole = 'admin' | 'registration_staff' | 'event_operator' | 'scanner_operator'

export type UserStatus = 'active' | 'inactive'

export interface AuthUser {
  id: number
  name: string
  email: string | null
  /** Set for scanner operators (username login). */
  username: string | null
  role: UserRole
  status: UserStatus
  lastLoginAt: string | null
}

export interface SessionResponse {
  authenticated: boolean
  user: AuthUser | null
  csrfToken: string
}

export interface LoginResponse {
  user: AuthUser
  csrfToken: string
}

export interface LoginCredentials {
  /** Email address or username. */
  login: string
  password: string
}
