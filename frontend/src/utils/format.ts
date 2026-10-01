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
