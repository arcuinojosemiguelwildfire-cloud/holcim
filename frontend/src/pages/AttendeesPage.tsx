import { useCallback, useEffect, useState } from 'react'
import { FileUp, Search, Users } from 'lucide-react'
import { AttendeeDetailModal } from '../components/attendees/AttendeeDetailModal'
import { Alert } from '../components/ui/Alert'
import { Badge } from '../components/ui/Badge'
import { ButtonLink } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { EmptyState } from '../components/ui/EmptyState'
import { PageHeader } from '../components/ui/PageHeader'
import { PaginationBar } from '../components/ui/PaginationBar'
import { Spinner } from '../components/ui/Spinner'
import { useApiQuery } from '../hooks/useApiQuery'
import { useAuth } from '../hooks/useAuth'
import { attendeeService } from '../services/attendeeService'
import type { AttendeeListQuery } from '../types/attendee'
import { formatShortDate } from '../utils/format'

const PAGE_SIZES = [25, 50, 100]
const SELECT_CLASS =
  'h-10 rounded-lg border-0 bg-white py-0 pl-3 pr-8 text-sm text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-brand-600'

export function AttendeesPage() {
  const { hasRole } = useAuth()
  const canManage = hasRole('admin')

  const [query, setQuery] = useState<AttendeeListQuery>({ search: '', department: '', status: 'active', page: 1, perPage: 25 })
  const [searchInput, setSearchInput] = useState('')
  const [openId, setOpenId] = useState<number | null>(null)

  // Debounce typing into the search box.
  useEffect(() => {
    const timer = window.setTimeout(() => {
      setQuery((current) => (current.search === searchInput.trim() ? current : { ...current, search: searchInput.trim(), page: 1 }))
    }, 300)
    return () => window.clearTimeout(timer)
  }, [searchInput])

  const fetcher = useCallback(() => attendeeService.list(query), [query])
  const { data, error, loading, reload } = useApiQuery(fetcher)

  const update = (patch: Partial<AttendeeListQuery>) => setQuery((current) => ({ ...current, page: 1, ...patch }))
  const filtered = query.search !== '' || query.department !== '' || query.status !== 'active'

  return (
    <>
      <PageHeader
        title="Attendees"
        description={data ? `Attendees of ${data.event.name}` : 'Attendees of the active event'}
        actions={
          canManage && (
            <ButtonLink to="/attendees/import" icon={<FileUp className="size-4" aria-hidden />}>
              Import attendees
            </ButtonLink>
          )
        }
      />

      {error && (
        <Alert tone="error" title="Could not load attendees" className="mb-4">
          {error}
        </Alert>
      )}

      <Card className="overflow-hidden">
        <div className="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
          <label className="relative min-w-0 flex-1 basis-64">
            <span className="sr-only">Search attendees</span>
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" aria-hidden />
            <input
              type="search"
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              placeholder="Search code, name, cluster or email"
              className="h-10 w-full rounded-lg border-0 bg-white pl-9 pr-3 text-sm shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-brand-600"
            />
          </label>
          <select aria-label="Cluster" value={query.department} onChange={(e) => update({ department: e.target.value })} className={SELECT_CLASS}>
            <option value="">All clusters</option>
            {(data?.departments ?? []).map((department) => (
              <option key={department} value={department}>
                {department}
              </option>
            ))}
          </select>
          <select
            aria-label="Status"
            value={query.status}
            onChange={(e) => update({ status: e.target.value as AttendeeListQuery['status'] })}
            className={SELECT_CLASS}
          >
            <option value="active">Active</option>
            <option value="archived">Archived</option>
            <option value="all">All statuses</option>
          </select>
        </div>

        {loading && !data ? (
          <Spinner />
        ) : data && data.items.length === 0 ? (
          <EmptyState
            icon={<Users className="size-6" aria-hidden />}
            title={filtered ? 'No matching attendees' : 'No attendees yet'}
            description={filtered ? 'Try a different search or filter.' : 'Import the attendee list from an Excel or CSV file.'}
            action={
              !filtered && canManage && (
                <ButtonLink to="/attendees/import" icon={<FileUp className="size-4" aria-hidden />}>
                  Import attendees
                </ButtonLink>
              )
            }
          />
        ) : data ? (
          <>
            <div className={`overflow-x-auto transition-opacity ${loading ? 'opacity-60' : ''}`}>
              <table className="min-w-full divide-y divide-slate-200 text-sm">
                <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <tr>
                    <th scope="col" className="px-5 py-3">Code</th>
                    <th scope="col" className="px-5 py-3">Full name</th>
                    <th scope="col" className="px-5 py-3">Company</th>
                    <th scope="col" className="px-5 py-3">Cluster</th>
                    <th scope="col" className="px-5 py-3">Email</th>
                    <th scope="col" className="px-5 py-3">Status</th>
                    <th scope="col" className="px-5 py-3">Created</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {data.items.map((attendee) => (
                    <tr
                      key={attendee.id}
                      onClick={() => setOpenId(attendee.id)}
                      className="cursor-pointer hover:bg-slate-50"
                    >
                      <td className="whitespace-nowrap px-5 py-3 font-mono text-xs text-slate-700">
                        <button type="button" className="hover:text-brand-700 hover:underline" onClick={() => setOpenId(attendee.id)}>
                          {attendee.attendeeCode}
                        </button>
                      </td>
                      <td className="px-5 py-3 font-medium text-slate-900">{attendee.fullName}</td>
                      <td className="px-5 py-3 text-slate-600">{attendee.company ?? '—'}</td>
                      <td className="px-5 py-3 text-slate-600">{attendee.department ?? '—'}</td>
                      <td className="px-5 py-3 text-slate-600">{attendee.email ?? '—'}</td>
                      <td className="whitespace-nowrap px-5 py-3">
                        <Badge tone={attendee.status === 'active' ? 'success' : 'muted'} dot>
                          {attendee.status === 'active' ? 'Active' : 'Archived'}
                        </Badge>
                      </td>
                      <td className="whitespace-nowrap px-5 py-3 text-slate-500">{formatShortDate(attendee.createdAt.slice(0, 10))}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <PaginationBar
              pagination={data.pagination}
              pageSizes={PAGE_SIZES}
              onPageChange={(page) => setQuery((current) => ({ ...current, page }))}
              onPageSizeChange={(perPage) => update({ perPage })}
            />
          </>
        ) : null}
      </Card>

      {openId !== null && (
        <AttendeeDetailModal key={openId} attendeeId={openId} onClose={() => setOpenId(null)} onChanged={reload} />
      )}
    </>
  )
}
