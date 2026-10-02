import { useCallback, useEffect, useState } from 'react'
import { Alert } from '../ui/Alert'
import { Badge, type BadgeTone } from '../ui/Badge'
import { Card, CardHeader } from '../ui/Card'
import { PaginationBar } from '../ui/PaginationBar'
import { errorMessage } from '../../services/apiClient'
import { randomizerService, type ParticipantPage, type ParticipantSource, type RandomizerType } from '../../services/randomizerService'
import { formatTime } from '../../utils/format'

const SOURCES: Record<ParticipantSource, { label: string; tone: BadgeTone }> = {
  registration: { label: 'Registration', tone: 'brand' },
  manual: { label: 'Manual', tone: 'warning' },
}

/** Today's eligible pool with each participant's source. */
export function ParticipantsCard({ type, refreshKey }: { type: RandomizerType; refreshKey: number }) {
  const [search, setSearch] = useState('')
  const [term, setTerm] = useState('')
  const [page, setPage] = useState(1)
  const [data, setData] = useState<ParticipantPage | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setTerm(search.trim())
      setPage(1)
    }, 300)
    return () => window.clearTimeout(timer)
  }, [search])

  const load = useCallback(() => {
    randomizerService.participants(type, term, page).then(
      (result) => {
        setData(result)
        setError(null)
      },
      (e: unknown) => setError(errorMessage(e)),
    )
  }, [type, term, page])

  // Refresh as check-ins arrive at the entrance.
  useEffect(() => {
    load()
    const interval = window.setInterval(load, 30000)
    return () => window.clearInterval(interval)
  }, [load, refreshKey])

  return (
    <Card className="overflow-hidden">
      <CardHeader
        title="Today’s participants"
        description={data ? `${data.eventDay.displayName} · ${data.eligibleCount} in the draw pool (today's winners of this draw are not listed)` : 'Draw pool for the current event day'}
        actions={
          <input
            type="search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search participants"
            aria-label="Search participants"
            className="h-8 w-56 rounded-md border-0 px-2.5 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-brand-600"
          />
        }
      />
      {error && <Alert tone="error" className="m-4">{error}</Alert>}
      {data && data.items.length === 0 ? (
        <p className="px-5 py-8 text-center text-sm text-slate-500">{term ? 'No participant matches.' : 'No eligible participants for today yet.'}</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-slate-200 text-sm" data-testid="participants">
            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
              <tr>
                <th scope="col" className="px-5 py-3">Code</th>
                <th scope="col" className="px-5 py-3">Name</th>
                <th scope="col" className="px-5 py-3">Company</th>
                <th scope="col" className="px-5 py-3">Cluster</th>
                <th scope="col" className="px-5 py-3">Source</th>
                <th scope="col" className="px-5 py-3">Added</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {data?.items.map((row) => (
                <tr key={`${row.id}-${row.source}`}>
                  <td className="whitespace-nowrap px-5 py-2.5 font-mono text-xs text-slate-700">{row.attendeeCode}</td>
                  <td className="px-5 py-2.5 font-medium text-slate-900">{row.fullName}</td>
                  <td className="px-5 py-2.5 text-slate-600">{row.company ?? '—'}</td>
                  <td className="px-5 py-2.5 text-slate-600">{row.department ?? '—'}</td>
                  <td className="px-5 py-2.5">
                    <Badge tone={SOURCES[row.source].tone}>{SOURCES[row.source].label}</Badge>
                    {row.reason && <span className="ml-2 text-xs text-slate-500">{row.reason}</span>}
                  </td>
                  <td className="whitespace-nowrap px-5 py-2.5 text-xs text-slate-500">
                    {formatTime(row.addedAt)}
                    {row.addedBy && ` · ${row.addedBy}`}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {data && data.pagination.total > data.pagination.perPage && <PaginationBar pagination={data.pagination} onPageChange={setPage} />}
    </Card>
  )
}
