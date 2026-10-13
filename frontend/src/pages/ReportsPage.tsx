import { useCallback, useState } from 'react'
import { Archive, Download, ScanLine, Sheet } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { Card, CardHeader } from '../components/ui/Card'
import { PageHeader } from '../components/ui/PageHeader'
import { useApiQuery } from '../hooks/useApiQuery'
import { API_BASE_URL } from '../services/apiClient'
import { dashboardService } from '../services/dashboardService'
import { eventDayService } from '../services/eventDayService'
import { cn } from '../utils/cn'

type Scope = 'day' | 'all'

// Legacy Minor / Major reports: draws.csv and Winners.xlsx are offered under
// "Legacy Minor/Major History" below; eligibility.csv is no longer offered in
// the UI (its API stays available at /reports/eligibility.csv).
const REPORTS: Array<{ path: string; title: string; description: string; columns: string; icon: LucideIcon }> = [
  {
    path: '/reports/registration.csv',
    title: 'Daily registration report',
    description: 'Every attendee with registration status for the day, time and the staff member who scanned them.',
    columns: 'Event Day, Attendee Code, Full Name, Cluster, Email, Registration Status, Registered At, Registered By, Attendee Status',
    icon: ScanLine,
  },
]

const fetchSummary = () => dashboardService.getSummary()

const linkClass =
  'inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-brand-700 px-4 text-sm font-medium text-white shadow-sm hover:bg-brand-800'

export function ReportsPage() {
  const { data } = useApiQuery(fetchSummary)
  const [scope, setScope] = useState<Scope>('day')
  const eventName = data?.activeEvent?.name
  const eventId = data?.activeEvent?.id ?? null
  const day = data?.activeDay
  const daysFetcher = useCallback(() => (eventId ? eventDayService.list(eventId) : Promise.resolve([])), [eventId])
  const { data: days } = useApiQuery(daysFetcher)

  const tabClass = (active: boolean) =>
    cn(
      'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
      active ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-800',
    )

  return (
    <>
      <PageHeader
        title="Reports"
        description={eventName ? `Exports for the active event: ${eventName}. Open in Excel or Google Sheets.` : 'Exports for the active event.'}
      />

      <Card className="mb-6">
        <CardHeader
          title="Excel lists"
          description="Attendee list per event day (same attendees every day, with that day's registration status) and the Randomizer winners."
        />
        <div className="flex flex-wrap items-center gap-3 px-5 py-4" data-testid="excel-lists">
          <Sheet className="size-5 text-brand-700" aria-hidden />
          {(days ?? []).map((d) => (
            <a key={d.id} href={`${API_BASE_URL}/reports/day-attendees.xlsx?day_id=${d.id}`} className={linkClass}>
              <Download className="size-4" aria-hidden /> Day {d.dayNumber} Attendees.xlsx
            </a>
          ))}
          <a href={`${API_BASE_URL}/reports/predrawn-winners.xlsx?scope=all`} className={linkClass}>
            <Download className="size-4" aria-hidden /> Randomizer Winners (Pre-Drawn).xlsx
          </a>
        </div>
        <p className="px-5 pb-4 text-xs text-slate-500">
          Randomizer Winners (Pre-Drawn).xlsx lists the winners selected before the event (Spin the Wheel) and revealed with the Randomizer,
          including voided reveals. Every row has the category “Pre-Drawn Winner”: they were not drawn at random by this system.
        </p>
      </Card>

      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-base font-semibold text-slate-900">CSV report</h2>
        <div className="flex rounded-lg bg-slate-100 p-0.5" role="tablist" aria-label="Report scope">
          <button type="button" role="tab" aria-selected={scope === 'day'} className={tabClass(scope === 'day')} onClick={() => setScope('day')}>
            {day ? `Day ${day.dayNumber} only` : 'Current day'}
          </button>
          <button type="button" role="tab" aria-selected={scope === 'all'} className={tabClass(scope === 'all')} onClick={() => setScope('all')}>
            All days
          </button>
        </div>
      </div>
      <p className="mb-5 text-sm text-slate-600">
        {scope === 'day'
          ? `CSV exports cover ${day ? day.displayName : 'the current event day'}.`
          : 'CSV exports cover every day of the active event, one Event Day column per row.'}
      </p>
      <div className="grid gap-4 lg:grid-cols-2">
        {REPORTS.map(({ path, title, description, columns, icon: Icon }) => (
          <Card key={path} className="flex flex-col p-5">
            <span className="flex size-10 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
              <Icon className="size-5" aria-hidden />
            </span>
            <h3 className="mt-3 text-base font-semibold text-slate-900">{title}</h3>
            <p className="mt-1 flex-1 text-sm text-slate-500">{description}</p>
            <p className="mt-3 text-xs text-slate-400">Columns: {columns}</p>
            <a href={`${API_BASE_URL}${path}?scope=${scope}`} className={cn(linkClass, 'mt-4')}>
              <Download className="size-4" aria-hidden /> Download CSV {scope === 'all' ? '(all days)' : ''}
            </a>
          </Card>
        ))}
      </div>

      <Card className="mt-8" data-testid="legacy-reports">
        <CardHeader
          title="Legacy Minor/Major History"
          description="Records of the old Minor and Major Randomizer draws (no longer used). Kept unchanged for the record, including voided draws and reset marks."
        />
        <div className="flex flex-wrap items-center gap-3 px-5 py-4">
          <Archive className="size-5 text-slate-500" aria-hidden />
          <a href={`${API_BASE_URL}/reports/winners.xlsx?scope=all`} className={linkClass}>
            <Download className="size-4" aria-hidden /> Winners.xlsx
          </a>
          <a href={`${API_BASE_URL}/reports/draws.csv?scope=${scope}`} className={linkClass}>
            <Download className="size-4" aria-hidden /> draws.csv {scope === 'all' ? '(all days)' : `(${day ? `Day ${day.dayNumber}` : 'current day'})`}
          </a>
        </div>
        <p className="px-5 pb-4 text-xs text-slate-500">
          Winners.xlsx: Minor / Major draws of every day. draws.csv (follows the scope selected above): Event Day, Draw Type, Attendee Code, Full Name,
          Cluster, Drawn At, Drawn By, Status, Voided At, Voided By, Void Reason.
        </p>
      </Card>
    </>
  )
}
