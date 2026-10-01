export type UserRole = 'admin' | 'registration_staff' | 'event_operator'

export type UserStatus = 'active' | 'inactive'

export interface AuthUser {
  id: number
  name: string
  email: string
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
  email: string
  password: string
}
