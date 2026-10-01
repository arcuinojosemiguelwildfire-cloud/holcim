import type { AuthUser, LoginCredentials, LoginResponse, SessionResponse } from '../types/auth'
import { apiClient, setCsrfToken } from './apiClient'

export const authService = {
  /** Current session state + CSRF token. Never throws 401. */
  async getSession(): Promise<SessionResponse> {
    const session = await apiClient.get<SessionResponse>('/auth/session')
    setCsrfToken(session.csrfToken)
    return session
  },

  async login(credentials: LoginCredentials): Promise<AuthUser> {
    const result = await apiClient.post<LoginResponse>('/auth/login', credentials)
    setCsrfToken(result.csrfToken)
    return result.user
  },

  async logout(): Promise<void> {
    await apiClient.post<null>('/auth/logout')
    setCsrfToken(null)
  },
}
