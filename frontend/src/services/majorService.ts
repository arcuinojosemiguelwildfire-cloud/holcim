import type { ImportRow, Pagination } from '../types/attendee'
import type { EventDay } from '../types/eventDay'
import { apiClient } from './apiClient'

export const MAJOR_FIELDS = ['attendee_code', 'external_identifier', 'email', 'full_name', 'department'] as const
export type MajorField = (typeof MAJOR_FIELDS)[number]
export type MajorMapping = Record<MajorField, number | null>

export interface MajorParsedFile {
  filename: string
  fileType: 'csv' | 'xlsx'
  headers: string[]
  rows: ImportRow[]
  totalRows: number
  suggestedMapping: MajorMapping
}

export type MajorRowResult = 'matched' | 'already_eligible' | 'unmatched' | 'ambiguous' | 'inactive' | 'invalid'

export interface MajorPreviewRow {
  rowNumber: number
  values: Record<MajorField, string>
  result: MajorRowResult
  reason: string
  attendee: { attendeeCode: string; fullName: string; department: string | null } | null
}

export interface MajorSummary {
  total: number
  valid: number
  matched: number
  alreadyEligible: number
  unmatched: number
  ambiguous: number
  inactive: number
  invalid: number
}

export interface MajorPreview {
  eventDay?: EventDay
  summary: MajorSummary
  rows: MajorPreviewRow[]
}

export interface MajorImportResult {
  importBatchId: number
  newlyEligible: number
  summary: MajorSummary
  eventDay?: EventDay
}

export interface MajorEligibleRow {
  id: number
  attendeeCode: string
  fullName: string
  department: string | null
  email: string | null
  importedAt: string
  source: 'import' | 'manual'
  addedBy: string | null
  reason: string | null
}

export interface MajorEligibleList {
  event: { id: number; name: string }
  eventDay: EventDay
  eligibleCount: number
  items: MajorEligibleRow[]
  departments: string[]
  pagination: Pagination
}

export interface MajorImportHistoryRow {
  id: number
  filename: string
  importedAt: string
  importedBy: string | null
  totalRows: number
  newlyEligible: number
  matched: number
  unmatched: number
  ambiguous: number
  inactive: number
  invalid: number
}

interface Payload {
  filename: string
  headers: string[]
  rows: ImportRow[]
  mapping: MajorMapping
}

export const majorService = {
  list(query: { search: string; department: string; page: number; perPage: number }): Promise<MajorEligibleList> {
    const params = new URLSearchParams({
      search: query.search,
      department: query.department,
      page: String(query.page),
      per_page: String(query.perPage),
    })
    return apiClient.get<MajorEligibleList>(`/major-eligibility?${params.toString()}`)
  },
  async imports(): Promise<MajorImportHistoryRow[]> {
    return (await apiClient.get<{ imports: MajorImportHistoryRow[] }>('/major-eligibility/imports')).imports
  },
  parseFile(file: File): Promise<MajorParsedFile> {
    const form = new FormData()
    form.append('file', file)
    return apiClient.upload<MajorParsedFile>('/major-eligibility/import/parse', form)
  },
  preview: (payload: Payload) => apiClient.post<MajorPreview>('/major-eligibility/import/preview', payload),
  commit: (payload: Payload) => apiClient.post<MajorImportResult>('/major-eligibility/import', payload),
}
