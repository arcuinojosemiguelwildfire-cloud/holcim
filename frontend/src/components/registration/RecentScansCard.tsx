import { useCallback, useEffect, useState } from 'react'
import { Alert } from '../ui/Alert'
import { Badge, type BadgeTone } from '../ui/Badge'
import { Card, CardHeader } from '../ui/Card'
import { PaginationBar } from '../ui/PaginationBar'
import { errorMessage } from '../../services/apiClient'
import {
  registrationService,
  type ScanFilter,
  type ScanLogPage,
  type ScanLogResult,
  type ScanView,
} from '../../services/registrationService'
import { cn } from '../../utils/cn'
import { formatTime } from '../../utils/format'

const RESULT_LABELS: Record<ScanLogResult, { label: string; tone: BadgeTone }> = {
  registered: { label: 'Registered', tone: 'success' },
  already_registered: { label: 'Already registered', tone: 'warning' },
  invalid_qr: { label: 'Invalid QR', tone: 'danger' },
  wrong_event: { label: 'Wrong event', tone: 'danger' },
  attendee_inactive: { label: 'Inactive', tone: 'danger' },
}

const FILTERS: { value: ScanFilter; label: string }[] = [
  { value: 'all', label: 'All results' },
  { value: 'successful', label: 'Successful' },
  { value: 'already_registered', label: 'Already registered' },
  { value: 'invalid', label: 'Invalid' },
]

interface RecentScansCardProps {
  /** Increment to reload (e.g. after a scan). */
  refreshKey: number
}

/** Recent scans of the current Event Day: My Scans / All Scans, result filter, paginated. */
export function RecentScansCard({ refreshKey }: RecentScansCardProps) {
  const [view, setView] = useState<ScanView>('mine')
  const [filter, setFilter] = useState<ScanFilter>('all')
  const [page, setPage] = useState(1)
  const [data, setData] = useState<ScanLogPage | null>(null)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(() => {
    registrationService.scans(view, filter, page).then(
      (result) => {
        setData(result)
        setError(null)
      },
      (e: unknown) => setError(errorMessage(e)),
    )
  }, [view, filter, page])

  useEffect(() => {
    load()
    const interval = window.setInterval(load, 15000)
    return () => window.clearInterval(interval)
  }, [load, refreshKey])

  const tabClass = (active: boolean) =>
    cn(
      'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
      active ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-800',
    )

  return (
    <Card className="flex max-h-[640px] flex-col overflow-hidden">
      <CardHeader
        title="Recent scans"
        description={data ? `${data.eventDay.displayName}` : 'Current event day'}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <div className="flex rounded-lg bg-slate-100 p-0.5" role="tablist" aria-label="Scans view">
              <button type="button" role="tab" aria-selected={view === 'mine'} className={tabClass(view === 'mine')} onClick={() => { setView('mine'); setPage(1) }}>
                My Scans
              </button>
              <button type="button" role="tab" aria-selected={view === 'all'} className={tabClass(view === 'all')} onClick={() => { setView('all'); setPage(1) }}>
                All Scans
              </button>
            </div>
            <label className="sr-only" htmlFor="scan-filter">Result</label>
            <select
              id="scan-filter"
              value={filter}
              onChange={(e) => { setFilter(e.target.value as ScanFilter); setPage(1) }}
              className="h-8 rounded-md border-0 bg-white py-0 pl-2 pr-7 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-brand-600"
            >
              {FILTERS.map((f) => (
                <option key={f.value} value={f.value}>{f.label}</option>
              ))}
            </select>
          </div>
        }
      />
      {error && <Alert tone="error" className="m-4">{error}</Alert>}
      {data && data.items.length === 0 ? (
        <p className="px-5 py-8 text-center text-sm text-slate-500">No scans to show.</p>
      ) : (
        <ul className="divide-y divide-slate-100 overflow-y-auto" data-testid="recent-scans">
          {data?.items.map((row) => {
            const meta = RESULT_LABELS[row.result]
            return (
              <li key={row.id} className="flex items-center gap-3 px-5 py-2.5 text-sm">
                <span className="w-20 shrink-0 tabular-nums text-slate-500">{formatTime(row.scannedAt)}</span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate font-medium text-slate-900">{row.fullName ?? 'Unknown QR'}</span>
                  <span className="block truncate text-xs text-slate-500">
                    {row.attendeeCode && <span className="font-mono">{row.attendeeCode}</span>}
                    {row.company && ` · ${row.company}`}{row.department && ` · ${row.department}`}
                    {view === 'all' && row.scannedBy && ` · by ${row.scannedBy}`}
                  </span>
                </span>
                <Badge tone={meta.tone}>{meta.label}</Badge>
              </li>
            )
          })}
        </ul>
      )}
      {data && data.pagination.total > data.pagination.perPage && (
        <PaginationBar pagination={data.pagination} onPageChange={setPage} />
      )}
    </Card>
  )
}
