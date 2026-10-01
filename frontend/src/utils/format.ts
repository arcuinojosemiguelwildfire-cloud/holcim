const dateFormatter = new Intl.DateTimeFormat('en-PH', {
  year: 'numeric',
  month: 'long',
  day: 'numeric',
  weekday: 'long',
})

const shortDateFormatter = new Intl.DateTimeFormat('en-PH', {
  year: 'numeric',
  month: 'short',
  day: 'numeric',
})

/** Parses "YYYY-MM-DD" as a local calendar date (no time-zone shift). */
function parseDateOnly(value: string): Date | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (!match) return null
  return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
}

/** "Saturday, December 12, 2026" */
export function formatLongDate(value: string): string {
  const date = parseDateOnly(value)
  return date ? dateFormatter.format(date) : value
}

/** "Dec 12, 2026" */
export function formatShortDate(value: string): string {
  const date = parseDateOnly(value)
  return date ? shortDateFormatter.format(date) : value
}

export function formatNumber(value: number): string {
  return value.toLocaleString('en-PH')
}

/** "2:05:09 PM" from "YYYY-MM-DD HH:MM:SS" (server local time). */
export function formatTime(value: string | null): string {
  if (!value) return ''
  const date = new Date(value.replace(' ', 'T'))
  return Number.isNaN(date.getTime()) ? value : date.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', second: '2-digit' })
}

/** "Dec 12, 2:05 PM" from "YYYY-MM-DD HH:MM:SS". */
export function formatDateTime(value: string | null): string {
  if (!value) return ''
  const date = new Date(value.replace(' ', 'T'))
  return Number.isNaN(date.getTime())
    ? value
    : date.toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}
