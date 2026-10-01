import type { EventDay } from '../types/eventDay'
import { apiClient } from './apiClient'

export interface ScannerOperator {
  id: number
  name: string
  username: string
  status: 'active' | 'inactive'
  lastLoginAt: string | null
  createdAt: string
  /** Successful check-ins on the current event day. */
  scansToday: number
}

export interface ScannerOperatorInput {
  name: string
  username: string
  password: string
  password_confirmation: string
  status: 'active' | 'inactive'
}

export const settingsService = {
  scannerOperators: () => apiClient.get<{ eventDay: EventDay | null; operators: ScannerOperator[] }>('/settings/scanner-operators'),
  async createScannerOperator(input: ScannerOperatorInput): Promise<ScannerOperator> {
    return (await apiClient.post<{ operator: ScannerOperator }>('/settings/scanner-operators', input)).operator
  },
  async setScannerOperatorStatus(id: number, status: 'active' | 'inactive'): Promise<ScannerOperator> {
    return (await apiClient.patch<{ operator: ScannerOperator }>(`/settings/scanner-operators/${id}`, { status })).operator
  },
  resetScannerOperatorPassword: (id: number, password: string, passwordConfirmation: string) =>
    apiClient.post<null>(`/settings/scanner-operators/${id}/password`, { password, password_confirmation: passwordConfirmation }),
}
