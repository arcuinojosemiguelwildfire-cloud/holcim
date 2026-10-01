import type { EventRecord } from './event'
import type { EventDay } from './eventDay'

export interface DashboardMetric {
  value: number
  /** false when the module that produces this number is not built yet */
  available: boolean
}

export interface DashboardSummary {
  activeEvent: EventRecord | null
  activeDay: EventDay | null
  metrics: {
    totalAttendees: DashboardMetric
    registered: DashboardMetric
    minorEligible: DashboardMetric
    majorEligible: DashboardMetric
  }
}
