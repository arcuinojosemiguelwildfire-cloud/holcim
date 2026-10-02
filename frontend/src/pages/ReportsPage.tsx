import { useCallback, useState } from 'react'
import { Download, FileSpreadsheet, ScanLine, Sheet, Trophy } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { Card, CardHeader } from '../components/ui/Card'
import { PageHeader } from '../components/ui/PageHeader'
import { useApiQuery } from '../hooks/useApiQuery'
import { API_BASE_URL } from '../services/apiClient'
import { dashboardService } from '../services/dashboardService'
import { eventDayService } from '../services/eventDayService'
import { cn } from '../utils/cn'

type Scope = 'day' | 'all'

const REPORTS: Array<{ path: string; title: string; description: string; columns: string; icon: LucideIcon }> = [
  {
    path: '/reports/registration.csv',
    title: 'Daily registration report',
    description: 'Every attendee with registration status for the day, time and the staff member who scanned them.',
    columns: 'Event Day, Attendee Code, Full Name, Cluster, Email, Registration Status, Registered At, Registered By, Attendee Status',
    icon: ScanLine,
  },
  {
    path: '/reports/eligibility.csv',
    title: 'Raffle eligibility report',
    description: 'Registration makes an attendee eligible for both Minor and Major. Shows eligibility, its source and whether they already won each draw.',
    columns: 'Event Day, Attendee Code, Full Name, Cluster, Email, Registered, Minor Eligible, Minor Source, Won Minor, Major Eligible, Major Source, Won Major, Attendee Status',
    icon: FileSpreadsheet,
  },
  {
    path: '/reports/draws.csv',
    title: 'Draw winners report',
    description: 'All Minor and Major draws in order, including voided draws (Status = VOID with reason).',
    columns: 'Event Day, Draw Type, Attendee Code, Full Name, Cluster, Drawn At, Drawn By, Status, Voided At, Voided By, Void Reason',
    icon: Trophy,
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
          description="Attendee list per event day (same attendees every day, with that day's registration status) and the winners list."
        />
        <div className="flex flex-wrap items-center gap-3 px-5 py-4" data-testid="excel-lists">
          <Sheet className="size-5 text-brand-700" aria-hidden />
          {(days ?? []).map((d) => (
            <a key={d.id} href={`${API_BASE_URL}/reports/day-attendees.xlsx?day_id=${d.id}`} className={linkClass}>
              <Download className="size-4" aria-hidden /> Day {d.dayNumber} Attendees.xlsx
            </a>
          ))}
          <a href={`${API_BASE_URL}/reports/winners.xlsx?scope=all`} className={linkClass}>
            <Download className="size-4" aria-hidden /> Winners.xlsx
          </a>
        </div>
      </Card>

      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-base font-semibold text-slate-900">CSV reports</h2>
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
      <div className="grid gap-4 lg:grid-cols-3">
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
    </>
  )
}
