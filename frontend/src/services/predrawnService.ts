import type { EventDay } from '../types/eventDay'
import { apiClient } from './apiClient'

/**
 * The Randomizer = Pre-Drawn Winner Reveal: winners chosen outside the system
 * (Spin the Wheel) are revealed by letter. The rolling names come from the
 * registration sessions an admin selects for the event day (the "pool").
 */
export type PredrawnStatus = 'assigned' | 'revealed' | 'voided'

export interface PredrawnAssignment {
  id: number
  /** Live rows: is the winner registered in the selected sessions? false = reveal blocked. null = not computed (voided rows). */
  inPool: boolean | null
  /** Always "Pre-Drawn Winner" (never a random draw). */
  category: string
  letter: string
  prizeLabel: string | null
  status: PredrawnStatus
  attendee: {
    id: number
    attendeeCode: string
    fullName: string
    company: string | null
    department: string | null
    active: boolean
  }
  assignedAt: string
  assignedBy: string | null
  revealedAt: string | null
  revealedBy: string | null
  voidedAt: string | null
  voidedBy: string | null
  voidReason: string | null
}

/** A registration session of the event day, as shown in the Randomizer session picker. */
export interface PoolSession {
  id: number
  label: string
  sessionDate: string
  startTime: string | null
  enabled: boolean
  current: boolean
  selected: boolean
  /** Active registrations of active attendees in this session. */
  registered: number
}

export interface RandomizerPool {
  sessions: PoolSession[]
  selectedSessionIds: number[]
  /** false = no session selected: the presentation cannot start. */
  configured: boolean
  /** Unique active attendees registered in at least one selected session. */
  size: number
}

/** An assignment whose winner is outside the (new) pool. */
export interface PoolConflict {
  letter: string
  fullName: string
  attendeeCode: string
  status: PredrawnStatus
}

export interface PoolCandidate {
  id: number
  attendeeCode: string
  fullName: string
  company: string | null
  department: string | null
  externalIdentifier: string | null
  /** Letter already assigned to this attendee today (live), if any. */
  assignedLetter: string | null
}

export interface PredrawnOverview {
  event: { id: number; name: string }
  eventDay: EventDay
  category: string
  pool: RandomizerPool
  /** Live assignments (assigned or revealed), by letter. */
  assignments: PredrawnAssignment[]
  /** Voided reveals (kept for the audit trail). */
  voided: PredrawnAssignment[]
}

export interface PredrawnAssignmentInput {
  letter: string
  attendee_id: number
  prize_label: string | null
}

/** What the LED screen shows after a reveal. */
export interface PredrawnWinnerDisplay {
  fullName: string
  prizeLabel: string | null
}

export const predrawnService = {
  overview: () => apiClient.get<PredrawnOverview>('/predrawn'),
  async rollNames(): Promise<string[]> {
    return (await apiClient.get<{ names: string[] }>('/predrawn/roll-names')).names
  },
  reveal: (letter: string) => apiClient.post<{ reveal: PredrawnAssignment; winner: PredrawnWinnerDisplay }>('/predrawn/reveal', { letter }),
  async assign(input: PredrawnAssignmentInput): Promise<PredrawnAssignment> {
    return (await apiClient.post<{ assignment: PredrawnAssignment }>('/predrawn/assignments', input)).assignment
  },
  async update(id: number, input: PredrawnAssignmentInput): Promise<PredrawnAssignment> {
    return (await apiClient.put<{ assignment: PredrawnAssignment }>(`/predrawn/assignments/${id}`, input)).assignment
  },
  remove: (id: number) => apiClient.delete<null>(`/predrawn/assignments/${id}`),
  async voidReveal(id: number, reason: string): Promise<PredrawnAssignment> {
    return (await apiClient.post<{ assignment: PredrawnAssignment }>(`/predrawn/assignments/${id}/void`, { reason })).assignment
  },
  /**
   * Admin: replace the day's selected sessions. 409 POOL_CONFLICTS (details.conflicts)
   * when unrevealed assignments would leave the pool, unless confirmConflicts.
   */
  savePool: (sessionIds: number[], confirmConflicts = false) =>
    apiClient.put<{ pool: RandomizerPool; outOfPool: PoolConflict[] }>('/predrawn/pool', { session_ids: sessionIds, confirm_conflicts: confirmConflicts }),
  /** Admin: pool attendees matching a search (assignment picker). */
  async poolCandidates(search: string): Promise<PoolCandidate[]> {
    return (await apiClient.get<{ items: PoolCandidate[] }>(`/predrawn/pool/candidates?search=${encodeURIComponent(search)}`)).items
  },
}

/* ---------- Operator notices (never shown on the LED screen) ---------- */

export interface OperatorNotice {
  id: number
  at: string
  tone: 'info' | 'success' | 'error'
  text: string
}

const CHANNEL = 'holcim-predrawn-operator'

/**
 * Notices from the presentation screen to the operator. They are sent over a
 * BroadcastChannel, so a second window of this page on the operator's laptop
 * shows them while the first window is fullscreen on the LED wall. The
 * fullscreen screen itself never displays them.
 */
export function createOperatorChannel(onNotice: (notice: OperatorNotice) => void): { post: (notice: Omit<OperatorNotice, 'id' | 'at'>) => void; close: () => void } {
  const channel = typeof BroadcastChannel !== 'undefined' ? new BroadcastChannel(CHANNEL) : null
  let counter = 0
  const make = (notice: Omit<OperatorNotice, 'id' | 'at'>): OperatorNotice => ({ ...notice, id: Date.now() * 100 + (counter++ % 100), at: new Date().toISOString() })
  if (channel) channel.onmessage = (event: MessageEvent<OperatorNotice>) => onNotice(event.data)
  return {
    post(notice) {
      const full = make(notice)
      onNotice(full) // this window
      channel?.postMessage(full) // other windows (operator laptop)
    },
    close: () => channel?.close(),
  }
}
