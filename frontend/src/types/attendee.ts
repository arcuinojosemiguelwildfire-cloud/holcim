import type { EventRecord } from './event'

export type AttendeeStatus = 'active' | 'archived'

export interface Attendee {
  id: number
  attendeeCode: string
  fullName: string
  department: string | null
  email: string | null
  externalIdentifier: string | null
  status: AttendeeStatus
  createdAt: string
}

export interface AttendeeDetail extends Attendee {
  eventId: number
  archivedAt: string | null
  updatedAt: string
  importBatchId: number | null
  /** Spreadsheet columns that were not mapped to a field. */
  extraData: Record<string, string>
}

export interface Pagination {
  page: number
  perPage: number
  total: number
  totalPages: number
}

export interface AttendeeListResponse {
  event: EventRecord
  items: Attendee[]
  pagination: Pagination
  departments: string[]
}

export interface AttendeeListQuery {
  search: string
  department: string
  status: AttendeeStatus | 'all'
  page: number
  perPage: number
}

export interface AttendeeUpdateInput {
  full_name: string
  department: string
  email: string
  external_identifier: string
}

/* ---------- Import ---------- */

export const IMPORT_FIELDS = ['full_name', 'department', 'email', 'external_identifier'] as const
export type ImportField = (typeof IMPORT_FIELDS)[number]
export type ColumnMapping = Record<ImportField, number | null>

export interface ImportRow {
  rowNumber: number
  cells: string[]
}

export interface ParsedFile {
  filename: string
  fileType: 'csv' | 'xlsx'
  headers: string[]
  rows: ImportRow[]
  totalRows: number
  suggestedMapping: ColumnMapping
}

export interface ImportSummary {
  total: number
  valid: number
  invalid: number
  duplicates: number
  duplicatesExisting: number
  duplicatesInFile: number
}

interface RowDisplay {
  rowNumber: number
  fullName: string
  department: string
  email: string
  externalIdentifier: string
}

export interface InvalidRow extends RowDisplay {
  reasons: string[]
}

export type DuplicateMatch =
  | { type: 'existing'; attendeeCode: string; fullName: string; status: AttendeeStatus }
  | { type: 'file'; rowNumber: number; fullName: string }

export interface DuplicateRow extends RowDisplay {
  matchedBy: 'external_identifier' | 'email' | 'name_department'
  matches: DuplicateMatch
}

export interface ImportPreview {
  summary: ImportSummary
  invalid: InvalidRow[]
  duplicates: DuplicateRow[]
  newPreview: Array<{
    rowNumber: number
    fullName: string
    department: string
    email: string | null
    externalIdentifier: string | null
  }>
}

export interface ImportResult {
  importBatchId: number
  summary: ImportSummary
  imported: number
  created: Array<{ rowNumber: number; attendeeCode: string; fullName: string }>
}
