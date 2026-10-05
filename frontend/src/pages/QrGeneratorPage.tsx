import { useCallback, useEffect, useState } from 'react'
import { FileArchive, Printer, QrCode, Search, Sparkles } from 'lucide-react'
import { AttendeeDetailModal } from '../components/attendees/AttendeeDetailModal'
import { ExportQrModal } from '../components/qr/ExportQrModal'
import { Alert } from '../components/ui/Alert'
import { Badge } from '../components/ui/Badge'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { EmptyState } from '../components/ui/EmptyState'
import { PageHeader } from '../components/ui/PageHeader'
import { PaginationBar } from '../components/ui/PaginationBar'
import { Spinner } from '../components/ui/Spinner'
import { useApiQuery } from '../hooks/useApiQuery'
import { useAuth } from '../hooks/useAuth'
import { errorMessage } from '../services/apiClient'
import { qrService, type QrStatusFilter } from '../services/qrService'
import { formatNumber, formatShortDate } from '../utils/format'
import { openQrPrintSheet } from '../utils/qr'

const PAGE_SIZES = [25, 50, 100]
const SELECT_CLASS =
  'h-10 rounded-lg border-0 bg-white py-0 pl-3 pr-8 text-sm text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-brand-600'

interface Query {
  search: string
  department: string
  qrStatus: QrStatusFilter
  page: number
  perPage: number
}

const fetchSummary = () => qrService.summary()

export function QrGeneratorPage() {
  const { hasRole } = useAuth()
  const canManage = hasRole('admin')

  const summary = useApiQuery(fetchSummary)
  const [query, setQuery] = useState<Query>({ search: '', department: '', qrStatus: 'all', page: 1, perPage: 25 })
  const [searchInput, setSearchInput] = useState('')
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [openId, setOpenId] = useState<number | null>(null)
  const [generating, setGenerating] = useState(false)
  const [exporting, setExporting] = useState(false)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setQuery((current) => (current.search === searchInput.trim() ? current : { ...current, search: searchInput.trim(), page: 1 }))
    }, 300)
    return () => window.clearTimeout(timer)
  }, [searchInput])

  const fetcher = useCallback(() => qrService.list(query), [query])
  const list = useApiQuery(fetcher)
  const update = (patch: Partial<Query>) => setQuery((current) => ({ ...current, page: 1, ...patch }))

  const refresh = () => {
    summary.reload()
    list.reload()
  }

  const generateMissing = async () => {
    setGenerating(true)
    setNotice(null)
    try {
      const result = await qrService.generateMissing()
      setNotice({
        tone: 'success',
        text: result.created > 0
          ? `Generated ${formatNumber(result.created)} new QR code${result.created === 1 ? '' : 's'}. Existing QR codes were not changed.`
          : 'Every active attendee already has a QR code. Nothing was changed.',
      })
      refresh()
    } catch (caught) {
      setNotice({ tone: 'error', text: errorMessage(caught) })
    } finally {
      setGenerating(false)
    }
  }

  const toggle = (id: number) =>
    setSelected((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })

  const items = list.data?.items ?? []
  const selectableOnPage = items.filter((item) => item.qrGeneratedAt)
  const allOnPageSelected = selectableOnPage.length > 0 && selectableOnPage.every((item) => selected.has(item.id))
  const togglePage = () =>
    setSelected((current) => {
      const next = new Set(current)
      for (const item of selectableOnPage) {
        if (allOnPageSelected) next.delete(item.id)
        else next.add(item.id)
      }
      return next
    })

  const s = summary.data

  return (
    <>
      <PageHeader
        title="QR / ID Generator"
        description={s ? `QR labels for active attendees of ${s.event.name}` : 'QR labels for active attendees of the active event'}
        actions={
          <div className="flex flex-wrap gap-2">
            {canManage && (
              <Button variant="secondary" onClick={() => setExporting(true)} disabled={!s} icon={<FileArchive className="size-4" aria-hidden />}>
                Export QR codes
              </Button>
            )}
            <Button variant="secondary" onClick={() => openQrPrintSheet()} disabled={!s || s.generated === 0} icon={<Printer className="size-4" aria-hidden />}>
              Print all
            </Button>
          </div>
        }
      />

      {(summary.error || list.error) && (
        <Alert tone="error" className="mb-4">{summary.error ?? list.error}</Alert>
      )}
      {notice && (
        <Alert tone={notice.tone} className="mb-4" onDismiss={() => setNotice(null)}>{notice.text}</Alert>
      )}

      <Card className="mb-6">
        <div className="grid grid-cols-1 gap-px overflow-hidden rounded-t-xl bg-slate-200 sm:grid-cols-3">
          <Stat label="Active attendees" value={s?.activeAttendees} />
          <Stat label="QR generated" value={s?.generated} tone="good" />
          <Stat label="QR missing" value={s?.missing} tone={s && s.missing > 0 ? 'warn' : undefined} />
        </div>
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-4">
          <p className="max-w-2xl text-sm text-slate-500">
            “Generate missing” only creates QR codes for active attendees who don’t have one. Existing QR codes are never
            replaced, so labels that are already printed stay valid. Each QR contains only the attendee&apos;s secure token (no web
            address), so the same labels work offline and after moving to the online server.
          </p>
          {canManage && (
            <Button onClick={generateMissing} loading={generating} disabled={!s || s.missing === 0} icon={<Sparkles className="size-4" aria-hidden />}>
              Generate missing QR codes{s && s.missing > 0 ? ` (${formatNumber(s.missing)})` : ''}
            </Button>
          )}
        </div>
      </Card>

      <Card className="overflow-hidden">
        <div className="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
          <label className="relative min-w-0 flex-1 basis-64">
            <span className="sr-only">Search attendees</span>
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" aria-hidden />
            <input
              type="search"
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              placeholder="Search code, name or cluster"
              className="h-10 w-full rounded-lg border-0 bg-white pl-9 pr-3 text-sm shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-brand-600"
            />
          </label>
          <select aria-label="Cluster" value={query.department} onChange={(e) => update({ department: e.target.value })} className={SELECT_CLASS}>
            <option value="">All clusters</option>
            {(list.data?.departments ?? []).map((d) => <option key={d} value={d}>{d}</option>)}
          </select>
          <select aria-label="QR status" value={query.qrStatus} onChange={(e) => update({ qrStatus: e.target.value as QrStatusFilter })} className={SELECT_CLASS}>
            <option value="all">All QR statuses</option>
            <option value="generated">Generated</option>
            <option value="missing">Missing</option>
          </select>
          <Button
            variant="secondary"
            disabled={selected.size === 0}
            onClick={() => openQrPrintSheet([...selected])}
            icon={<Printer className="size-4" aria-hidden />}
          >
            Print selected ({formatNumber(selected.size)})
          </Button>
          {selected.size > 0 && (
            <button type="button" className="text-sm text-slate-500 hover:text-slate-800" onClick={() => setSelected(new Set())}>
              Clear
            </button>
          )}
        </div>

        {list.loading && !list.data ? (
          <Spinner />
        ) : items.length === 0 ? (
          <EmptyState icon={<QrCode className="size-6" aria-hidden />} title="No attendees found" description="Import attendees first, or change the search and filters." />
        ) : (
          <>
            <div className={`overflow-x-auto ${list.loading ? 'opacity-60' : ''}`}>
              <table className="min-w-full divide-y divide-slate-200 text-sm">
                <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <tr>
                    <th scope="col" className="w-10 px-5 py-3">
                      <input type="checkbox" aria-label="Select all on this page" checked={allOnPageSelected} onChange={togglePage}
                        disabled={selectableOnPage.length === 0} className="size-4 rounded border-slate-300 text-brand-700" />
                    </th>
                    <th scope="col" className="px-3 py-3">Code</th>
                    <th scope="col" className="px-5 py-3">Name</th>
                    <th scope="col" className="px-5 py-3">Company</th>
                    <th scope="col" className="px-5 py-3">Cluster</th>
                    <th scope="col" className="px-5 py-3">QR status</th>
                    <th scope="col" className="px-5 py-3"><span className="sr-only">Actions</span></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {items.map((item) => (
                    <tr key={item.id} className={selected.has(item.id) ? 'bg-brand-50/50' : undefined}>
                      <td className="px-5 py-3">
                        <input type="checkbox" aria-label={`Select ${item.fullName}`} checked={selected.has(item.id)} onChange={() => toggle(item.id)}
                          disabled={!item.qrGeneratedAt} className="size-4 rounded border-slate-300 text-brand-700 disabled:opacity-40" />
                      </td>
                      <td className="whitespace-nowrap px-3 py-3 font-mono text-xs text-slate-700">{item.attendeeCode}</td>
                      <td className="px-5 py-3 font-medium text-slate-900">{item.fullName}</td>
                      <td className="px-5 py-3 text-slate-600">{item.company ?? '—'}</td>
                      <td className="px-5 py-3 text-slate-600">{item.department ?? '—'}</td>
                      <td className="whitespace-nowrap px-5 py-3">
                        {item.qrGeneratedAt ? (
                          <span className="inline-flex items-center gap-2">
                            <Badge tone="success" dot>Generated</Badge>
                            <span className="text-xs text-slate-400">{formatShortDate(item.qrGeneratedAt.slice(0, 10))}</span>
                          </span>
                        ) : (
                          <Badge tone="warning" dot>Missing</Badge>
                        )}
                      </td>
                      <td className="whitespace-nowrap px-5 py-3 text-right">
                        <div className="inline-flex gap-1">
                          {item.qrGeneratedAt && (
                            <Button size="sm" variant="ghost" icon={<Printer className="size-4" aria-hidden />} onClick={() => openQrPrintSheet([item.id])}>
                              Print
                            </Button>
                          )}
                          <Button size="sm" variant="ghost" onClick={() => setOpenId(item.id)}>View</Button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {list.data && (
              <PaginationBar
                pagination={list.data.pagination}
                pageSizes={PAGE_SIZES}
                onPageChange={(page) => setQuery((current) => ({ ...current, page }))}
                onPageSizeChange={(perPage) => update({ perPage })}
              />
            )}
          </>
        )}
      </Card>

      {openId !== null && <AttendeeDetailModal key={openId} attendeeId={openId} onClose={() => { setOpenId(null); refresh() }} onChanged={refresh} />}
      {exporting && s && <ExportQrModal summary={s} onClose={() => setExporting(false)} />}
    </>
  )
}

function Stat({ label, value, tone }: { label: string; value: number | undefined; tone?: 'good' | 'warn' }) {
  return (
    <div className="bg-white px-5 py-4">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
      <p className={`mt-1 text-3xl font-semibold tabular-nums ${tone === 'good' ? 'text-emerald-700' : tone === 'warn' ? 'text-amber-700' : 'text-slate-900'}`}>
        {value === undefined ? '—' : formatNumber(value)}
      </p>
    </div>
  )
}
