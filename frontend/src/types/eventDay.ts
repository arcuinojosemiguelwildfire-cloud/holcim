export type EventDayStatus = 'upcoming' | 'active' | 'completed'

/** One day of a multi-day event (Phase 8). */
export interface EventDay {
  id: number
  eventId: number
  dayNumber: number
  /** YYYY-MM-DD */
  eventDate: string
  label: string | null
  status: EventDayStatus
  /** "Day 2 — October 11, 2026" */
  displayName: string
}

export interface CurrentEventDay {
  event: { id: number; name: string } | null
  eventDay: EventDay | null
}
