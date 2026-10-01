import type { DrawWinner } from '../components/randomizer/RandomizerStage'
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

export const randomizerService = {
  summary: (type: RandomizerType) => apiClient.get<RandomizerSummary>(`/randomizers/${type}`),
  draw: (type: RandomizerType) => apiClient.post<DrawResult>(`/randomizers/${type}/draw`),
  voidDraw: (drawId: number, reason: string) =>
    apiClient.post<{ drawId: number; status: 'void'; recentWinners: RecentWinner[] }>(`/randomizers/draws/${drawId}/void`, { reason }),
}
