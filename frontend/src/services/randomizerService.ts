import type { DrawWinner } from '../components/randomizer/RandomizerStage'
import { apiClient } from './apiClient'

export interface RecentWinner extends DrawWinner {
  drawId: number
  selectedAt: string
  drawnBy: string | null
}

export interface MinorSummary {
  event: { id: number; name: string }
  eligibleCount: number
  recentWinners: RecentWinner[]
}

export interface MinorDrawResult {
  drawId: number
  selectedAt: string
  eligibleCount: number
  winner: DrawWinner
  rollNames: string[]
  recentWinners: RecentWinner[]
}

export const randomizerService = {
  minorSummary: () => apiClient.get<MinorSummary>('/randomizers/minor'),
  minorDraw: () => apiClient.post<MinorDrawResult>('/randomizers/minor/draw'),
}
