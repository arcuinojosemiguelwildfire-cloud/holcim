export const EVENT_STATUSES = ['draft', 'active', 'completed', 'archived'] as const

export type EventStatus = (typeof EVENT_STATUSES)[number]

export interface EventRecord {
  id: number
  name: string
  description: string | null
  /** YYYY-MM-DD */
  eventDate: string
  status: EventStatus
  createdAt: string
  updatedAt: string
}

/** Payload for create/update. Uses the API's snake_case field names. */
export interface EventInput {
  name: string
  description: string
  event_date: string
  status: EventStatus
}
