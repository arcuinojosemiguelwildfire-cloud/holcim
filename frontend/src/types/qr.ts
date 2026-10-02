export interface AttendeeQr {
  attendeeId: number
  attendeeCode: string
  fullName: string
  company: string | null
  department: string | null
  attendeeStatus: 'active' | 'archived'
  status: 'generated' | 'missing'
  generatedAt: string | null
  qrPayload: string | null
}

export interface QrSummary {
  event: { id: number; name: string }
  activeAttendees: number
  generated: number
  missing: number
  qrBaseUrl: string
}

export interface QrPrintItem {
  id: number
  attendeeCode: string
  fullName: string
  company: string | null
  department: string | null
  qrPayload: string
}

export interface QrPrintData {
  event: { id: number; name: string }
  items: QrPrintItem[]
}
