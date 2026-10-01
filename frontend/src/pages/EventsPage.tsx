import { useState } from 'react'
import { CalendarDays, CalendarPlus, Pencil } from 'lucide-react'
import { EventFormModal } from '../components/events/EventFormModal'
import { EventStatusBadge } from '../components/events/EventStatusBadge'
import { Alert } from '../components/ui/Alert'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { EmptyState } from '../components/ui/EmptyState'
import { PageHeader } from '../components/ui/PageHeader'
import { Spinner } from '../components/ui/Spinner'
import { useApiQuery } from '../hooks/useApiQuery'
import { useAuth } from '../hooks/useAuth'
import { errorMessage } from '../services/apiClient'
import { eventService } from '../services/eventService'
import { EVENT_STATUSES, type EventRecord, type EventStatus } from '../types/event'
import { formatShortDate } from '../utils/format'
import { EVENT_STATUS_LABELS } from '../utils/labels'

const fetchEvents = () => eventService.list()

type FormState = { open: false } | { open: true; event: EventRecord | null }

export function EventsPage() {
  const { hasRole } = useAuth()
  const canManage = hasRole('admin')
  const { data: events, error, loading, reload } = useApiQuery(fetchEvents)

  const [form, setForm] = useState<FormState>({ open: false })
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const [statusSaving, setStatusSaving] = useState<number | null>(null)

  const handleSaved = (event: EventRecord, created: boolean) => {
    setForm({ open: false })
    setNotice({ tone: 'success', text: `${created ? 'Created' : 'Updated'} "${event.name}".` })
    reload()
  }

  const handleStatusChange = async (event: EventRecord, status: EventStatus) => {
    if (status === event.status) return
    setStatusSaving(event.id)
    setNotice(null)
    try {
      const updated = await eventService.setStatus(event.id, status)
      setNotice({ tone: 'success', text: `"${updated.name}" is now ${EVENT_STATUS_LABELS[updated.status].toLowerCase()}.` })
      reload()
    } catch (caught) {
      setNotice({ tone: 'error', text: errorMessage(caught) })
    } finally {
      setStatusSaving(null)
    }
  }

  return (
    <>
      <PageHeader
        title="Events"
        description="The active event drives the dashboard, registration and randomizers."
        actions={
          canManage && (
            <Button onClick={() => setForm({ open: true, event: null })} icon={<CalendarPlus className="size-4" aria-hidden />}>
              New event
            </Button>
          )
        }
      />

      {notice && (
        <Alert tone={notice.tone} className="mb-4" onDismiss={() => setNotice(null)}>
          {notice.text}
        </Alert>
      )}
      {error && (
        <Alert tone="error" title="Could not load events" className="mb-4">
          {error}
        </Alert>
      )}

      <Card className="overflow-hidden">
        {loading && !events ? (
          <Spinner />
        ) : events && events.length === 0 ? (
          <EmptyState
            icon={<CalendarDays className="size-6" aria-hidden />}
            title="No events yet"
            description={canManage ? 'Create your first event to get started.' : 'An administrator has not created any events yet.'}
            action={
              canManage && (
                <Button onClick={() => setForm({ open: true, event: null })} icon={<CalendarPlus className="size-4" aria-hidden />}>
                  New event
                </Button>
              )
            }
          />
        ) : events ? (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                <tr>
                  <th scope="col" className="px-5 py-3">Event</th>
                  <th scope="col" className="px-5 py-3">Date</th>
                  <th scope="col" className="px-5 py-3">Status</th>
                  {canManage && <th scope="col" className="px-5 py-3 text-right"><span className="sr-only">Actions</span></th>}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {events.map((event) => (
                  <tr key={event.id} className={event.status === 'active' ? 'bg-emerald-50/40' : undefined}>
                    <td className="max-w-md px-5 py-4">
                      <p className="font-medium text-slate-900">{event.name}</p>
                      {event.description && <p className="mt-0.5 truncate text-slate-500">{event.description}</p>}
                    </td>
                    <td className="whitespace-nowrap px-5 py-4 text-slate-600">{formatShortDate(event.eventDate)}</td>
                    <td className="whitespace-nowrap px-5 py-4">
                      {canManage ? (
                        <div className="inline-flex items-center gap-2">
                          <EventStatusBadge status={event.status} />
                          <select
                            value=""
                            disabled={statusSaving === event.id}
                            onChange={(e) => handleStatusChange(event, e.target.value as EventStatus)}
                            className="h-8 rounded-md border-0 bg-white py-0 pl-2 pr-7 text-xs text-slate-600 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-brand-600 disabled:opacity-60"
                            aria-label={`Change status for ${event.name}`}
                          >
                            <option value="" disabled>
                              {statusSaving === event.id ? 'Saving…' : 'Change status…'}
                            </option>
                            {EVENT_STATUSES.filter((status) => status !== event.status).map((status) => (
                              <option key={status} value={status}>
                                {EVENT_STATUS_LABELS[status]}
                              </option>
                            ))}
                          </select>
                        </div>
                      ) : (
                        <EventStatusBadge status={event.status} />
                      )}
                    </td>
                    {canManage && (
                      <td className="whitespace-nowrap px-5 py-4 text-right">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setForm({ open: true, event })}
                          icon={<Pencil className="size-4" aria-hidden />}
                        >
                          Edit
                        </Button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
      </Card>

      {form.open && (
        <EventFormModal
          key={form.event?.id ?? 'new'}
          open
          event={form.event}
          onClose={() => setForm({ open: false })}
          onSaved={handleSaved}
        />
      )}
    </>
  )
}
