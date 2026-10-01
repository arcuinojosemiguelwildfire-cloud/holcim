import type { UserRole } from '../types/auth'
import type { EventStatus } from '../types/event'

export const ROLE_LABELS: Record<UserRole, string> = {
  admin: 'Administrator',
  registration_staff: 'Registration Staff',
  event_operator: 'Event Operator',
  scanner_operator: 'Scanner Operator',
}

export const EVENT_STATUS_LABELS: Record<EventStatus, string> = {
  draft: 'Draft',
  active: 'Active',
  completed: 'Completed',
  archived: 'Archived',
}
