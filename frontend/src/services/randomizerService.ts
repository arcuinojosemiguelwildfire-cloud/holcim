import type { DrawWinner } from '../components/randomizer/RandomizerStage'
import type { Pagination } from '../types/attendee'
import type { EventDay } from '../types/eventDay'
import { apiClient } from './apiClient'

export interface RecentWinner extends DrawWinner {
  drawId: number
  selectedAt: string
  drawnBy: string | null
  status: 'valid' | 'void'
  voidReason: string | null
  voidedBy: string | null
}

export type RandomizerType = 'minor' | 'major'

export interface RandomizerSummary {
  event: { id: number; name: string }
  eventDay: EventDay
  eligibleCount: number
  recentWinners: RecentWinner[]
}

export interface DrawResult {
  drawId: number
  selectedAt: string
  eligibleCount: number
  winner: DrawWinner
  rollNames: string[]
  recentWinners: RecentWinner[]
}

/** How an attendee got into today's pool. */
export type ParticipantSource = 'registration' | 'manual'

export interface PoolParticipant {
  id: number
  attendeeCode: string
  fullName: string
  company: string | null
  department: string | null
  source: ParticipantSource
  addedAt: string
  addedBy: string | null
  reason: string | null
}

export interface ParticipantPage {
  eventDay: EventDay
  eligibleCount: number
  items: PoolParticipant[]
  pagination: Pagination
}

export interface ParticipantCandidate {
  id: number
  attendeeCode: string
  fullName: string
  company: string | null
  department: string | null
  email: string | null
  alreadyEligible: boolean
  /** Has a valid (not void) draw of this randomizer today. */
  alreadyWon: boolean
}

export interface AddParticipantResult {
  attendee: { id: number; attendeeCode: string; fullName: string; company: string | null; department: string | null }
  source: 'manual'
  eventDay: EventDay
  eligibleCount: number
}

export const randomizerService = {
  participants: (type: RandomizerType, search: string, page: number) =>
    apiClient.get<ParticipantPage>(`/randomizers/${type}/participants?${new URLSearchParams({ search, page: String(page) }).toString()}`),
  async candidates(type: RandomizerType, search: string): Promise<ParticipantCandidate[]> {
    const data = await apiClient.get<{ items: ParticipantCandidate[] }>(`/randomizers/${type}/candidates?${new URLSearchParams({ search }).toString()}`)
    return data.items
  },
  addParticipant: (type: RandomizerType, attendeeId: number, reason: string) =>
    apiClient.post<AddParticipantResult>(`/randomizers/${type}/participants`, { attendee_id: attendeeId, reason }),
  summary: (type: RandomizerType) => apiClient.get<RandomizerSummary>(`/randomizers/${type}`),
  draw: (type: RandomizerType) => apiClient.post<DrawResult>(`/randomizers/${type}/draw`),
  voidDraw: (drawId: number, reason: string) =>
    apiClient.post<{ drawId: number; status: 'void'; recentWinners: RecentWinner[] }>(`/randomizers/draws/${drawId}/void`, { reason }),
}
