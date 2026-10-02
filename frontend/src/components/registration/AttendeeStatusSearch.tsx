import { useEffect, useState } from 'react'
import { Search } from 'lucide-react'
import { Badge } from '../ui/Badge'
import { Card, CardHeader } from '../ui/Card'
import { errorMessage } from '../../services/apiClient'
import { registrationService, type AttendeeStatusRow } from '../../services/registrationService'
import { formatTime } from '../../utils/format'

/** Read-only lookup: is this attendee registered today? */
export function AttendeeStatusSearch() {
  const [query, setQuery] = useState('')
  const [found, setFound] = useState<{ term: string; items: AttendeeStatusRow[] } | null>(null)
  const term = query.trim()
  // Show results only for the current search term (older responses are ignored).
  const items = term.length >= 2 && found?.term === term ? found.items : null
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (term.length < 2) return
    let cancelled = false
    const timer = window.setTimeout(() => {
      registrationService.searchAttendees(term).then(
        (data) => {
          if (!cancelled) {
            setFound({ term, items: data.items })
            setError(null)
          }
        },
        (e: unknown) => {
          if (!cancelled) setError(errorMessage(e))
        },
      )
    }, 250)
    return () => {
      cancelled = true
      window.clearTimeout(timer)
    }
  }, [term])

  return (
    <Card>
      <CardHeader title="Attendee lookup" description="Check whether an attendee is registered today. Read-only." />
      <div className="px-5 py-4">
        <label className="relative block">
          <span className="sr-only">Search attendees</span>
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" aria-hidden />
          <input
            type="search"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Code, name, cluster or email"
            autoComplete="off"
            className="h-10 w-full rounded-lg border-0 pl-9 pr-3 text-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-brand-600"
          />
        </label>
        {error && <p className="mt-3 text-sm text-red-700">{error}</p>}
        {items && items.length === 0 && <p className="mt-3 text-sm text-slate-500">No attendee matches “{query.trim()}”.</p>}
        {items && items.length > 0 && (
          <ul className="mt-3 divide-y divide-slate-100 rounded-lg ring-1 ring-slate-200" data-testid="attendee-lookup">
            {items.map((row) => (
              <li key={row.attendeeCode} className="flex items-center gap-3 px-3 py-2 text-sm">
                <span className="min-w-0 flex-1">
                  <span className="block truncate font-medium text-slate-900">{row.fullName}</span>
                  <span className="block truncate text-xs text-slate-500">
                    <span className="font-mono">{row.attendeeCode}</span>
                    {row.company && ` · ${row.company}`}{row.department && ` · ${row.department}`}
                    {row.registeredToday && ` · ${formatTime(row.registeredAt)}${row.registeredBy ? ` by ${row.registeredBy}` : ''}`}
                  </span>
                </span>
                {row.attendeeStatus !== 'active' ? (
                  <Badge tone="muted">Archived</Badge>
                ) : row.registeredToday ? (
                  <Badge tone="success">Registered today</Badge>
                ) : (
                  <Badge tone="neutral">Not yet registered</Badge>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>
    </Card>
  )
}
