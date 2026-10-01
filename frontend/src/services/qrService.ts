import type { AttendeeListQuery, AttendeeListResponse } from '../types/attendee'
import type { AttendeeQr, QrPrintData, QrSummary } from '../types/qr'
import { apiClient } from './apiClient'

export type QrStatusFilter = 'all' | 'generated' | 'missing'

export const qrService = {
  summary: () => apiClient.get<QrSummary>('/qr-codes/summary'),

  list(query: Omit<AttendeeListQuery, 'status'> & { qrStatus: QrStatusFilter }): Promise<AttendeeListResponse> {
    const params = new URLSearchParams({
      search: query.search,
      department: query.department,
      qr_status: query.qrStatus,
      page: String(query.page),
      per_page: String(query.perPage),
    })
    return apiClient.get<AttendeeListResponse>(`/qr-codes?${params.toString()}`)
  },

  generateMissing: () => apiClient.post<QrSummary & { created: number }>('/qr-codes/generate-missing'),

  async forAttendee(id: number): Promise<AttendeeQr> {
    return (await apiClient.get<{ qr: AttendeeQr }>(`/attendees/${id}/qr`)).qr
  },

  async generate(id: number): Promise<AttendeeQr> {
    return (await apiClient.post<{ qr: AttendeeQr }>(`/attendees/${id}/qr`)).qr
  },

  async regenerate(id: number): Promise<AttendeeQr> {
    return (await apiClient.post<{ qr: AttendeeQr }>(`/attendees/${id}/qr/regenerate`)).qr
  },

  printData(ids: number[] | null): Promise<QrPrintData> {
    return apiClient.get<QrPrintData>(`/qr-codes/print${ids && ids.length > 0 ? `?ids=${ids.join(',')}` : ''}`)
  },
}
