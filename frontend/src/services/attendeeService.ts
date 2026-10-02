import type { AttendeeQr } from '../types/qr'
import type {
  AttendeeCreateInput,
  AttendeeDetail,
  AttendeeListQuery,
  AttendeeListResponse,
  AttendeeStatus,
  AttendeeUpdateInput,
  ColumnMapping,
  ImportPreview,
  ImportResult,
  ParsedFile,
} from '../types/attendee'
import { apiClient } from './apiClient'

interface ImportPayload {
  filename: string
  headers: string[]
  rows: ParsedFile['rows']
  mapping: ColumnMapping
}

export const attendeeService = {
  list(query: AttendeeListQuery): Promise<AttendeeListResponse> {
    const params = new URLSearchParams({
      search: query.search,
      department: query.department,
      status: query.status,
      page: String(query.page),
      per_page: String(query.perPage),
    })
    return apiClient.get<AttendeeListResponse>(`/attendees?${params.toString()}`)
  },

  async get(id: number): Promise<AttendeeDetail> {
    return (await apiClient.get<{ attendee: AttendeeDetail }>(`/attendees/${id}`)).attendee
  },

  /** Manual Add Attendee (admin / event operator): creates the attendee and their QR. */
  create(input: AttendeeCreateInput): Promise<{ attendee: AttendeeDetail; qr: AttendeeQr }> {
    return apiClient.post<{ attendee: AttendeeDetail; qr: AttendeeQr }>('/attendees', input)
  },

  async update(id: number, input: AttendeeUpdateInput): Promise<AttendeeDetail> {
    return (await apiClient.put<{ attendee: AttendeeDetail }>(`/attendees/${id}`, input)).attendee
  },

  async setStatus(id: number, status: AttendeeStatus): Promise<AttendeeDetail> {
    return (await apiClient.patch<{ attendee: AttendeeDetail }>(`/attendees/${id}/status`, { status })).attendee
  },

  parseFile(file: File): Promise<ParsedFile> {
    const form = new FormData()
    form.append('file', file)
    return apiClient.upload<ParsedFile>('/attendees/import/parse', form)
  },

  preview(payload: ImportPayload): Promise<ImportPreview> {
    return apiClient.post<ImportPreview>('/attendees/import/preview', payload)
  },

  commit(payload: ImportPayload): Promise<ImportResult> {
    return apiClient.post<ImportResult>('/attendees/import', payload)
  },
}
