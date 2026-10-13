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
    /** Legacy (not shown): Minor / Major eligibility counts, kept in the API for compatibility. */
    minorEligible: DashboardMetric
    majorEligible: DashboardMetric
    /** Size of today's Randomizer name pool (selected registration sessions). */
    randomizerPool: DashboardMetric & { configured: boolean }
  }
}
