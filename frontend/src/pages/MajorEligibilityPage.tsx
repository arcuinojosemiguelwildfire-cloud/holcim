import { useCallback, useEffect, useState } from 'react'
import { FileUp, Search, Trophy } from 'lucide-react'
import { Alert } from '../components/ui/Alert'
import { ButtonLink } from '../components/ui/Button'
import { Card, CardHeader } from '../components/ui/Card'
import { EmptyState } from '../components/ui/EmptyState'
import { PageHeader } from '../components/ui/PageHeader'
import { PaginationBar } from '../components/ui/PaginationBar'
import { Spinner } from '../components/ui/Spinner'
import { useApiQuery } from '../hooks/useApiQuery'
import { useAuth } from '../hooks/useAuth'
import { Badge } from '../components/ui/Badge'
import { majorService } from '../services/majorService'
import { formatDateTime, formatNumber } from '../utils/format'

const PAGE_SIZES = [25, 50, 100]
const fetchImports = () => majorService.imports()

export function MajorEligibilityPage() {
  const { hasRole } = useAuth()
  const canImport = hasRole('admin')
  const [query, setQuery] = useState({ search: '', department: '', page: 1, perPage: 25 })
  const [searchInput, setSearchInput] = useState('')

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setQuery((current) => (current.search === searchInput.trim() ? current : { ...current, search: searchInput.trim(), page: 1 }))
    }, 300)
    return () => window.clearTimeout(timer)
  }, [searchInput])

  const fetcher = useCallback(() => majorService.list(query), [query])
  const list = useApiQuery(fetcher)
  const history = useApiQuery(fetchImports)
  const data = list.data

  return (
    <>
      <PageHeader
        title="Major Eligibility"
        description="Attendees eligible for the Major draw on the current event day (imported form responses or manual additions)."
        actions={
          canImport && (
            <ButtonLink to="/major-eligibility/import" icon={<FileUp className="size-4" aria-hidden />}>
              Import responses
            </ButtonLink>
          )
        }
      />

      {list.error && <Alert tone="error" className="mb-4">{list.error}</Alert>}

      <div className="mb-6 grid gap-4 sm:grid-cols-[220px_minmax(0,1fr)]">
        <Card className="p-5">
          <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Major eligible</p>
          <p className="mt-1 text-4xl font-bold tabular-nums text-slate-900">{data ? formatNumber(data.eligibleCount) : '—'}</p>
          <p className="mt-1 text-xs text-slate-500" data-testid="major-day">
            {data ? `${data.event.name} — ${data.eventDay.displayName}` : 'Current event day'}
          </p>
        </Card>
        <Card className="p-5 text-sm text-slate-600">
          <p className="font-medium text-slate-900">How to update</p>
          <ol className="mt-2 list-decimal space-y-1 pl-5">
            <li>In Google Sheets, open the responses sheet and choose File › Download › CSV or Microsoft Excel (.xlsx).</li>
            <li>Import the file here and map its columns (email, employee ID, name and department…).</li>
            <li>Only rows that match exactly one attendee become eligible. Re-importing the same file is safe.</li>
            <li>Eligibility is for the current event day only; earlier days keep their own lists.</li>
          </ol>
        </Card>
      </div>

      <Card className="mb-6 overflow-hidden">
        <div className="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
          <label className="relative min-w-0 flex-1 basis-64">
            <span className="sr-only">Search</span>
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" aria-hidden />
            <input
              type="search"
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              placeholder="Search code, name, department or email"
              className="h-10 w-full rounded-lg border-0 bg-white pl-9 pr-3 text-sm shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-brand-600"
            />
          </label>
          <select
            aria-label="Department"
            value={query.department}
            onChange={(e) => setQuery((c) => ({ ...c, department: e.target.value, page: 1 }))}
            className="h-10 rounded-lg border-0 bg-white py-0 pl-3 pr-8 text-sm text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-brand-600"
          >
            <option value="">All departments</option>
            {(data?.departments ?? []).map((d) => <option key={d} value={d}>{d}</option>)}
          </select>
        </div>
        {list.loading && !data ? (
          <Spinner />
        ) : data && data.items.length === 0 ? (
          <EmptyState
            icon={<Trophy className="size-6" aria-hidden />}
            title={query.search || query.department ? 'No matching attendees' : 'No Major Eligible attendees yet'}
            description={canImport ? 'Import the exported form responses to make attendees eligible.' : 'An administrator needs to import the form responses.'}
          />
        ) : data ? (
          <>
            <div className={`overflow-x-auto ${list.loading ? 'opacity-60' : ''}`}>
              <table className="min-w-full divide-y divide-slate-200 text-sm">
                <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <tr>
                    <th scope="col" className="px-5 py-3">Code</th>
                    <th scope="col" className="px-5 py-3">Name</th>
                    <th scope="col" className="px-5 py-3">Department</th>
                    <th scope="col" className="px-5 py-3">Email</th>
                    <th scope="col" className="px-5 py-3">Source</th>
                    <th scope="col" className="px-5 py-3">Added</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {data.items.map((row) => (
                    <tr key={row.id}>
                      <td className="whitespace-nowrap px-5 py-3 font-mono text-xs text-slate-700">{row.attendeeCode}</td>
                      <td className="px-5 py-3 font-medium text-slate-900">{row.fullName}</td>
                      <td className="px-5 py-3 text-slate-600">{row.department ?? '—'}</td>
                      <td className="px-5 py-3 text-slate-600">{row.email ?? '—'}</td>
                      <td className="px-5 py-3">
                        <Badge tone={row.source === 'manual' ? 'warning' : 'neutral'}>{row.source === 'manual' ? 'Manual' : 'Import'}</Badge>
                        {row.reason && <span className="ml-2 text-xs text-slate-500">{row.reason}</span>}
                      </td>
                      <td className="whitespace-nowrap px-5 py-3 text-slate-500">
                        {formatDateTime(row.importedAt)}
                        {row.addedBy && <span className="block text-xs">by {row.addedBy}</span>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <PaginationBar
              pagination={data.pagination}
              pageSizes={PAGE_SIZES}
              onPageChange={(page) => setQuery((c) => ({ ...c, page }))}
              onPageSizeChange={(perPage) => setQuery((c) => ({ ...c, perPage, page: 1 }))}
            />
          </>
        ) : null}
      </Card>

      <Card className="overflow-hidden">
        <CardHeader title="Import history" description="Latest 20 response imports for the current event day." />
        {history.data && history.data.length === 0 ? (
          <p className="px-5 py-6 text-center text-sm text-slate-500">No imports yet.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                <tr>
                  <th scope="col" className="px-5 py-3">When</th>
                  <th scope="col" className="px-5 py-3">File</th>
                  <th scope="col" className="px-5 py-3">By</th>
                  <th scope="col" className="px-5 py-3 text-right">Rows</th>
                  <th scope="col" className="px-5 py-3 text-right">Matched</th>
                  <th scope="col" className="px-5 py-3 text-right">New</th>
                  <th scope="col" className="px-5 py-3 text-right">Unmatched</th>
                  <th scope="col" className="px-5 py-3 text-right">Ambiguous</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 tabular-nums">
                {history.data?.map((row) => (
                  <tr key={row.id}>
                    <td className="whitespace-nowrap px-5 py-3 text-slate-500">{formatDateTime(row.importedAt)}</td>
                    <td className="max-w-56 truncate px-5 py-3 text-slate-900">{row.filename}</td>
                    <td className="px-5 py-3 text-slate-600">{row.importedBy ?? '—'}</td>
                    <td className="px-5 py-3 text-right">{row.totalRows}</td>
                    <td className="px-5 py-3 text-right">{row.matched}</td>
                    <td className="px-5 py-3 text-right font-semibold text-emerald-700">{row.newlyEligible}</td>
                    <td className="px-5 py-3 text-right">{row.unmatched}</td>
                    <td className="px-5 py-3 text-right">{row.ambiguous}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </>
  )
}
