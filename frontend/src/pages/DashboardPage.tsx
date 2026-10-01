import { CalendarDays, CalendarPlus, Dices, RefreshCw, Trophy, UserCheck, Users } from 'lucide-react'
import { StatCard } from '../components/dashboard/StatCard'
import { EventStatusBadge } from '../components/events/EventStatusBadge'
import { Alert } from '../components/ui/Alert'
import { Button, ButtonLink } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { EmptyState } from '../components/ui/EmptyState'
import { PageHeader } from '../components/ui/PageHeader'
import { Spinner } from '../components/ui/Spinner'
import { useApiQuery } from '../hooks/useApiQuery'
import { useAuth } from '../hooks/useAuth'
import { dashboardService } from '../services/dashboardService'
import { formatLongDate } from '../utils/format'
import type { DashboardSummary } from '../types/dashboard'

const fetchSummary = () => dashboardService.getSummary()

export function DashboardPage() {
  const { user } = useAuth()
  const { data, error, loading, reload } = useApiQuery(fetchSummary)

  return (
    <>
      <PageHeader
        title="Dashboard"
        description={user ? `Welcome back, ${user.name.split(' ')[0]}.` : undefined}
        actions={
          <Button variant="secondary" onClick={reload} loading={loading && data !== null} icon={<RefreshCw className="size-4" aria-hidden />}>
            Refresh
          </Button>
        }
      />

      {error && (
        <Alert tone="error" title="Could not load the dashboard" className="mb-6">
          {error}
        </Alert>
      )}

      {loading && !data ? <Spinner /> : data ? <DashboardContent summary={data} /> : null}
    </>
  )
}

function DashboardContent({ summary }: { summary: DashboardSummary }) {
  const { hasRole } = useAuth()
  const { activeEvent, metrics } = summary

  return (
    <div className="space-y-6">
      <Card>
        {activeEvent ? (
          <div className="flex flex-wrap items-start justify-between gap-4 p-5">
            <div className="flex items-start gap-4">
              <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-700 text-white">
                <CalendarDays className="size-5" aria-hidden />
              </span>
              <div>
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Current event</p>
                <h2 className="mt-0.5 text-lg font-semibold text-slate-900">{activeEvent.name}</h2>
                <p className="mt-0.5 text-sm text-slate-600">{formatLongDate(activeEvent.eventDate)}</p>
                {activeEvent.description && <p className="mt-2 max-w-2xl text-sm text-slate-500">{activeEvent.description}</p>}
              </div>
            </div>
            <div className="flex items-center gap-3">
              <span className="text-sm text-slate-500">Status</span>
              <EventStatusBadge status={activeEvent.status} />
            </div>
          </div>
        ) : (
          <EmptyState
            icon={<CalendarDays className="size-6" aria-hidden />}
            title="No active event"
            description="The dashboard, registration and randomizers all work on the active event. Create an event and set its status to Active."
            action={
              hasRole('admin') ? (
                <ButtonLink to="/events" icon={<CalendarPlus className="size-4" aria-hidden />}>
                  Manage events
                </ButtonLink>
              ) : (
                <p className="text-sm text-slate-500">Ask an administrator to activate an event.</p>
              )
            }
          />
        )}
      </Card>

      <section aria-label="Event statistics" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Total Attendees" metric={metrics.totalAttendees} icon={Users} hint="Imported for the active event" />
        <StatCard label="Registered" metric={metrics.registered} icon={UserCheck} hint="Checked in by QR scan" />
        <StatCard label="Minor Eligible" metric={metrics.minorEligible} icon={Dices} hint="Registered attendees in the minor draw" />
        <StatCard label="Major Eligible" metric={metrics.majorEligible} icon={Trophy} pendingNote="Available after the Major response import" />
      </section>
    </div>
  )
}
