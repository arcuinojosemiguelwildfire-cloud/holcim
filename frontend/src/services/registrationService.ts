import { apiClient } from './apiClient'

export interface RegistrationCounts {
  total: number
  registered: number
  remaining: number
}

export interface ScanSuccess {
  status: 'registered' | 'already_registered'
  attendee: { code: string; fullName: string; department: string | null }
  registeredAt: string | null
  minorEligible: boolean
  counts: RegistrationCounts
}

export interface RecentRegistration {
  registeredAt: string
  attendeeCode: string
  fullName: string
  department: string | null
  scannedBy: string | null
}

export interface RegistrationSummary {
  event: { id: number; name: string }
  counts: RegistrationCounts
  recent: RecentRegistration[]
}

/**
 * Pulls the token out of a scanned value: "{APP_URL}/q/{token}", any URL
 * ending in the token, or the bare token. The server re-validates everything.
 */
export function extractToken(value: string): string {
  const trimmed = value.trim()
  if (/^https?:\/\//i.test(trimmed)) {
    try {
      const segments = new URL(trimmed).pathname.split('/').filter(Boolean)
      return segments[segments.length - 1] ?? ''
    } catch {
      return trimmed
    }
  }
  return trimmed
}

export const registrationService = {
  summary: () => apiClient.get<RegistrationSummary>('/registration/summary'),
  scan: (scannedValue: string) => apiClient.post<ScanSuccess>('/registration/scan', { token: extractToken(scannedValue) }),
}
