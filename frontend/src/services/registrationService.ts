import type { Pagination } from '../types/attendee'
import type { EventDay } from '../types/eventDay'
import { apiClient } from './apiClient'

export interface RegistrationCounts {
  total: number
  registered: number
  remaining: number
}

/** Successful check-ins today: by the signed-in user / by everyone. */
export interface ScanCounts {
  mine: number
  all: number
}

export interface ScanSuccess {
  status: 'registered' | 'already_registered'
  eventDay: EventDay
  attendee: { code: string; fullName: string; company: string | null; department: string | null }
  registeredAt: string | null
  registeredBy: string | null
  minorEligible: boolean
  counts: RegistrationCounts
  scanCounts: ScanCounts
}

export interface RecentRegistration {
  registeredAt: string
  attendeeCode: string
  fullName: string
  company: string | null
  department: string | null
  scannedBy: string | null
}

export interface RegistrationSummary {
  event: { id: number; name: string }
  eventDay: EventDay
  counts: RegistrationCounts
  scanCounts: ScanCounts
  recent: RecentRegistration[]
}

export type ScanLogResult = 'registered' | 'already_registered' | 'invalid_qr' | 'wrong_event' | 'attendee_inactive'
export type ScanView = 'mine' | 'all'
export type ScanFilter = 'all' | 'successful' | 'already_registered' | 'invalid'

export interface ScanLogRow {
  id: number
  result: ScanLogResult
  scannedAt: string
  attendeeCode: string | null
  fullName: string | null
  company: string | null
  department: string | null
  scannedBy: string | null
}

export interface ScanLogPage {
  eventDay: EventDay
  view: ScanView
  filter: ScanFilter
  items: ScanLogRow[]
  pagination: Pagination
}

export interface AttendeeStatusRow {
  attendeeCode: string
  fullName: string
  company: string | null
  department: string | null
  attendeeStatus: 'active' | 'archived'
  registeredToday: boolean
  registeredAt: string | null
  registeredBy: string | null
}

/**
 * Scanned value -> token. Current labels contain only the token (sent as is);
 * older labels contain "http(s)://host/q/{token}" (the token after "/q/" is
 * sent). Any other URL is sent unchanged so the server rejects it as an
 * invalid QR. The server re-validates everything.
 */
export function extractToken(value: string): string {
  const trimmed = value.trim()
  if (/^https?:\/\//i.test(trimmed)) {
    try {
      const segments = new URL(trimmed).pathname.split('/').filter(Boolean)
      return segments.length >= 2 && segments[segments.length - 2] === 'q' ? (segments[segments.length - 1] ?? trimmed) : trimmed
    } catch {
      return trimmed
    }
  }
  return trimmed
}

export const registrationService = {
  summary: () => apiClient.get<RegistrationSummary>('/registration/summary'),
  scan: (scannedValue: string) => apiClient.post<ScanSuccess>('/registration/scan', { token: extractToken(scannedValue) }),
  scans: (view: ScanView, filter: ScanFilter, page: number) =>
    apiClient.get<ScanLogPage>(`/registration/scans?${new URLSearchParams({ view, result: filter, page: String(page) }).toString()}`),
  searchAttendees: (search: string) =>
    apiClient.get<{ eventDay: EventDay; items: AttendeeStatusRow[] }>(`/registration/attendees?${new URLSearchParams({ search }).toString()}`),
}
