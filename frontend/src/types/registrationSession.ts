/** Registration session of an event day (Phase 10) - configuration data. */
export interface RegistrationSession {
  id: number
  eventDayId: number
  /** "Oct 15 - 5:00 PM" */
  label: string
  /** YYYY-MM-DD */
  sessionDate: string
  /** "17:00" (24-hour); null for a default session created before sessions were configured */
  startTime: string | null
  description: string | null
  sortOrder: number
  enabled: boolean
  /** A registration in this session makes the attendee eligible for that day's Major Randomizer. */
  countsForMajor: boolean
  /** The session scans are recorded in (one per day). */
  current: boolean
  /** "October 15" */
  dateLabel: string
  /** "5:00 PM" */
  timeLabel: string
  updatedAt: string
  /** Active registrations (admin list only). */
  registrations?: number
}

/** What the registration desk shows about a session (no ids, no eligibility internals). */
export interface DeskSession {
  label: string
  dateLabel: string
  timeLabel: string
  sessionDate: string
  startTime: string | null
}

export interface RegistrationSessionInput {
  label: string
  session_date: string
  start_time: string
  description: string
  sort_order: number
  enabled: boolean
  counts_for_major: boolean
}

export type RegistrationSource = 'scan' | 'manual'

export interface AttendeeSessionRegistration {
  status: 'active' | 'removed'
  source: RegistrationSource
  registeredAt: string
  registeredBy: string | null
  removedAt: string | null
  removedBy: string | null
  removalReason: string | null
  createdAt: string
  updatedAt: string
}

export interface AttendeeRegistrationDay {
  day: import('./eventDay').EventDay
  sessions: { session: RegistrationSession; registration: AttendeeSessionRegistration | null }[]
  registered: boolean
  minorEligible: boolean
  majorEligible: boolean
  majorSource: 'registration' | 'manual' | null
}

export interface RegistrationHistoryItem {
  action: 'scanned' | 'manually_added' | 'restored' | 'removed'
  statusAfter: 'active' | 'removed'
  reason: string | null
  at: string
  by: string | null
  sessionLabel: string
  dayNumber: number
}

export interface AttendeeRegistrations {
  attendee: { id: number; attendeeCode: string; fullName: string; company: string | null; status: 'active' | 'archived' }
  days: AttendeeRegistrationDay[]
  history: RegistrationHistoryItem[]
  applied?: { action: 'added' | 'restored' | 'removed'; sessionLabel: string }[]
}
