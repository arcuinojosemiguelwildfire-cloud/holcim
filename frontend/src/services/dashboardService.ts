import type { DashboardSummary } from '../types/dashboard'
import { apiClient } from './apiClient'

export const dashboardService = {
  getSummary(): Promise<DashboardSummary> {
    return apiClient.get<DashboardSummary>('/dashboard/summary')
  },
}
