import { Download, FileSpreadsheet, ScanLine, Trophy } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { Card } from '../components/ui/Card'
import { PageHeader } from '../components/ui/PageHeader'
import { useApiQuery } from '../hooks/useApiQuery'
import { API_BASE_URL } from '../services/apiClient'
import { dashboardService } from '../services/dashboardService'

const REPORTS: Array<{ path: string; title: string; description: string; columns: string; icon: LucideIcon }> = [
  {
    path: '/reports/registration.csv',
    title: 'Registration report',
    description: 'Every attendee with registration status, time and the staff member who scanned them.',
    columns: 'Attendee Code, Full Name, Department, Email, Registration Status, Registered At, Registered By, Attendee Status',
    icon: ScanLine,
  },
  {
    path: '/reports/major-eligibility.csv',
    title: 'Major eligibility report',
    description: 'Every attendee with Major Eligible (Yes/No) and when the eligibility was imported. Eligible first.',
    columns: 'Attendee Code, Full Name, Department, Email, Major Eligible, Imported At, Attendee Status',
    icon: FileSpreadsheet,
  },
  {
    path: '/reports/draws.csv',
    title: 'Draw winners report',
    description: 'All Minor and Major draws in order, including voided draws (Status = VOID with reason).',
    columns: 'Draw Type, Attendee Code, Full Name, Department, Drawn At, Drawn By, Status, Voided At, Voided By, Void Reason',
    icon: Trophy,
  },
]

const fetchSummary = () => dashboardService.getSummary()

export function ReportsPage() {
  const { data } = useApiQuery(fetchSummary)
  const eventName = data?.activeEvent?.name

  return (
    <>
      <PageHeader
        title="Reports"
        description={eventName ? `CSV exports for the active event: ${eventName}. Opens in Excel or Google Sheets.` : 'CSV exports for the active event.'}
      />
      <div className="grid gap-4 lg:grid-cols-3">
        {REPORTS.map(({ path, title, description, columns, icon: Icon }) => (
          <Card key={path} className="flex flex-col p-5">
            <span className="flex size-10 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
              <Icon className="size-5" aria-hidden />
            </span>
            <h2 className="mt-3 text-base font-semibold text-slate-900">{title}</h2>
            <p className="mt-1 flex-1 text-sm text-slate-500">{description}</p>
            <p className="mt-3 text-xs text-slate-400">Columns: {columns}</p>
            <a
              href={`${API_BASE_URL}${path}`}
              className="mt-4 inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-brand-700 px-4 text-sm font-medium text-white shadow-sm hover:bg-brand-800"
            >
              <Download className="size-4" aria-hidden /> Download CSV
            </a>
          </Card>
        ))}
      </div>
    </>
  )
}
