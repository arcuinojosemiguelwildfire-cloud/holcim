import type { EventDay } from '../types/eventDay'
import { apiClient } from './apiClient'

export interface ScannerOperator {
  id: number
  name: string
  username: string
  status: 'active' | 'inactive'
  lastLoginAt: string | null
  createdAt: string
  /** Successful check-ins on the current event day. */
  scansToday: number
}

export interface ScannerOperatorInput {
  name: string
  username: string
  password: string
  password_confirmation: string
  status: 'active' | 'inactive'
}

export const settingsService = {
  scannerOperators: () => apiClient.get<{ eventDay: EventDay | null; operators: ScannerOperator[] }>('/settings/scanner-operators'),
  async createScannerOperator(input: ScannerOperatorInput): Promise<ScannerOperator> {
    return (await apiClient.post<{ operator: ScannerOperator }>('/settings/scanner-operators', input)).operator
  },
  async setScannerOperatorStatus(id: number, status: 'active' | 'inactive'): Promise<ScannerOperator> {
    return (await apiClient.patch<{ operator: ScannerOperator }>(`/settings/scanner-operators/${id}`, { status })).operator
  },
  resetScannerOperatorPassword: (id: number, password: string, passwordConfirmation: string) =>
    apiClient.post<null>(`/settings/scanner-operators/${id}/password`, { password, password_confirmation: passwordConfirmation }),
}

/** Row counts shown on Settings > System Reset. */
export interface SystemResetCounts {
  events: number
  event_days: number
  attendees: number
  registration_scans: number
  randomizer_draws: number
  import_batches: number
  manual_participants: number
  non_admin_users: number
  admin_users: number
}

export const systemResetService = {
  summary: () => apiClient.get<{ counts: SystemResetCounts; confirmationPhrase: string }>('/settings/system-reset'),
  /** DESTRUCTIVE. Server re-checks admin role, the phrase and the admin's current password. */
  reset: (confirmation: string, password: string) =>
    apiClient.post<{ deleted: Record<string, number>; adminsPreserved: number }>('/settings/system-reset', { confirmation, password }),
}

export type RandomizerResetScope = 'minor' | 'major' | 'both'

export interface RandomizerResetWinner {
  attendeeCode: string
  fullName: string
  company: string | null
  department: string | null
  drawnAt: string
}

export interface RandomizerResetPreview {
  event: { id: number; name: string }
  eventDay: EventDay
  phrases: Record<RandomizerResetScope, string>
  minor: { count: number; winners: RandomizerResetWinner[] }
  major: { count: number; winners: RandomizerResetWinner[] }
}

export const randomizerResetService = {
  preview: (eventId: number, eventDayId: number) =>
    apiClient.get<RandomizerResetPreview>(`/settings/randomizer-reset?event_id=${eventId}&event_day_id=${eventDayId}`),
  /** Server re-checks admin role, the scope's phrase and the admin's password; draws are kept. */
  reset: (input: { event_id: number; event_day_id: number; randomizer: RandomizerResetScope; confirmation: string; password: string }) =>
    apiClient.post<{ affected: Partial<Record<'minor' | 'major', number>>; total: number; message: string }>('/settings/randomizer-reset', input),
}
