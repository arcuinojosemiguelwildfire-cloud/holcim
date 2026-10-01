import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { CalendarPlus, Check, Pencil, Play, X } from 'lucide-react'
import { Alert } from '../ui/Alert'
import { Badge, type BadgeTone } from '../ui/Badge'
import { Button } from '../ui/Button'
import { Modal } from '../ui/Modal'
import { Spinner } from '../ui/Spinner'
import { ApiError, errorMessage } from '../../services/apiClient'
import { eventDayService } from '../../services/eventDayService'
import type { EventRecord } from '../../types/event'
import type { EventDay, EventDayStatus } from '../../types/eventDay'
import { formatLongDate } from '../../utils/format'

const STATUS: Record<EventDayStatus, { label: string; tone: BadgeTone }> = {
  active: { label: 'Current day', tone: 'success' },
  upcoming: { label: 'Upcoming', tone: 'neutral' },
  completed: { label: 'Completed', tone: 'muted' },
}

function nextDate(days: EventDay[], fallback: string): string {
  const last = days[days.length - 1]?.eventDate ?? fallback
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(last)
  if (!match) return fallback
  const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]) + (days.length > 0 ? 1 : 0)))
  return date.toISOString().slice(0, 10)
}

interface EventDaysModalProps {
  event: EventRecord
  onClose: () => void
}

/**
 * Admin: days of an event. Add a day, edit its date/label, and set the
 * current (active) day. Switching days never deletes any day's records.
 */
export function EventDaysModal({ event, onClose }: EventDaysModalProps) {
  const [days, setDays] = useState<EventDay[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [confirmId, setConfirmId] = useState<number | null>(null)
  const [editing, setEditing] = useState<{ id: number; date: string; label: string } | null>(null)
  const [newDay, setNewDay] = useState<{ date: string; label: string } | null>(null)
  const [busy, setBusy] = useState(false)
  const [fieldError, setFieldError] = useState<string | null>(null)

  const load = useCallback(() => {
    eventDayService.list(event.id).then(setDays, (e: unknown) => setError(errorMessage(e)))
  }, [event.id])

  useEffect(load, [load])

  const run = async (action: () => Promise<string>) => {
    setBusy(true)
    setError(null)
    setFieldError(null)
    try {
      setNotice(await action())
      load()
      return true
    } catch (e) {
      if (e instanceof ApiError && Object.keys(e.fieldErrors).length > 0) {
        setFieldError(e.fieldError('event_date') ?? e.fieldError('label') ?? e.message)
      } else {
        setError(errorMessage(e))
      }
      return false
    } finally {
      setBusy(false)
    }
  }

  const activate = (day: EventDay) =>
    run(async () => {
      const updated = await eventDayService.activate(day.id)
      setConfirmId(null)
      return `Current event day is now ${updated.displayName}. Earlier days' records are kept.`
    })

  const saveEdit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    if (!editing) return
    const ok = await run(async () => {
      const updated = await eventDayService.update(editing.id, { event_date: editing.date, label: editing.label })
      return `Updated ${updated.displayName}.`
    })
    if (ok) setEditing(null)
  }

  const addDay = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    if (!newDay) return
    const ok = await run(async () => {
      const created = await eventDayService.create(event.id, { event_date: newDay.date, label: newDay.label })
      return `Added ${created.displayName}. Set it as the current day when that day starts.`
    })
    if (ok) setNewDay(null)
  }

  const inputClass = 'h-9 rounded-lg border-0 px-2.5 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-brand-600'

  return (
    <Modal open title={`Event days — ${event.name}`} description="The current day is used for registration, eligibility and draws." onClose={onClose}>
      <div className="space-y-4">
        {notice && <Alert tone="success" onDismiss={() => setNotice(null)}>{notice}</Alert>}
        {error && <Alert tone="error">{error}</Alert>}
        {!days ? (
          <Spinner />
        ) : (
          <ul className="divide-y divide-slate-100 rounded-lg ring-1 ring-slate-200" data-testid="event-days">
            {days.map((day) => (
              <li key={day.id} className="px-3 py-2.5">
                {editing?.id === day.id ? (
                  <form onSubmit={saveEdit} className="flex flex-wrap items-center gap-2">
                    <span className="w-14 text-sm font-semibold text-slate-900">Day {day.dayNumber}</span>
                    <input type="date" required value={editing.date} onChange={(e) => setEditing({ ...editing, date: e.target.value })} className={inputClass} aria-label="Date" />
                    <input value={editing.label} maxLength={100} placeholder="Label (optional)" onChange={(e) => setEditing({ ...editing, label: e.target.value })} className={`${inputClass} min-w-0 flex-1`} aria-label="Label" />
                    <Button key="save" type="submit" size="sm" loading={busy} icon={<Check className="size-4" aria-hidden />}>Save</Button>
                    <Button key="cancel" type="button" size="sm" variant="ghost" onClick={() => setEditing(null)} icon={<X className="size-4" aria-hidden />}>Cancel</Button>
                    {fieldError && <p className="w-full text-sm text-red-700">{fieldError}</p>}
                  </form>
                ) : (
                  <div className="flex flex-wrap items-center gap-2">
                    <div className="min-w-0 flex-1">
                      <p className="text-sm font-semibold text-slate-900">
                        Day {day.dayNumber}
                        {day.label && <span className="font-normal text-slate-500"> · {day.label}</span>}
                      </p>
                      <p className="text-xs text-slate-500">{formatLongDate(day.eventDate)}</p>
                    </div>
                    <Badge tone={STATUS[day.status].tone} dot={day.status === 'active'}>{STATUS[day.status].label}</Badge>
                    <Button
                      key={`edit-${day.id}`}
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => {
                        setFieldError(null)
                        setEditing({ id: day.id, date: day.eventDate, label: day.label ?? '' })
                      }}
                      icon={<Pencil className="size-4" aria-hidden />}
                    >
                      Edit
                    </Button>
                    {day.status !== 'active' &&
                      (confirmId === day.id ? (
                        <Button key={`confirm-${day.id}`} type="button" size="sm" loading={busy} onClick={() => void activate(day)}>
                          Confirm: make current
                        </Button>
                      ) : (
                        <Button key={`activate-${day.id}`} type="button" variant="secondary" size="sm" onClick={() => setConfirmId(day.id)} icon={<Play className="size-4" aria-hidden />}>
                          Set as current
                        </Button>
                      ))}
                  </div>
                )}
              </li>
            ))}
          </ul>
        )}

        {days && event.status !== 'active' && (
          <p className="text-xs text-slate-500">This event is not active. Its current day is used once the event is set to Active.</p>
        )}

        {days &&
          (newDay ? (
            <form onSubmit={addDay} className="flex flex-wrap items-center gap-2 rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200">
              <span className="w-14 text-sm font-semibold text-slate-900">Day {days.length > 0 ? Math.max(...days.map((d) => d.dayNumber)) + 1 : 1}</span>
              <input type="date" required value={newDay.date} onChange={(e) => setNewDay({ ...newDay, date: e.target.value })} className={inputClass} aria-label="New day date" />
              <input value={newDay.label} maxLength={100} placeholder="Label (optional)" onChange={(e) => setNewDay({ ...newDay, label: e.target.value })} className={`${inputClass} min-w-0 flex-1`} aria-label="New day label" />
              <Button key="add-save" type="submit" size="sm" loading={busy}>Add day</Button>
              <Button key="add-cancel" type="button" size="sm" variant="ghost" onClick={() => setNewDay(null)}>Cancel</Button>
              {fieldError && <p className="w-full text-sm text-red-700">{fieldError}</p>}
            </form>
          ) : (
            <Button
              key="add-open"
              type="button"
              variant="secondary"
              onClick={() => {
                setFieldError(null)
                setNewDay({ date: nextDate(days, event.eventDate), label: '' })
              }}
              icon={<CalendarPlus className="size-4" aria-hidden />}
            >
              Add day
            </Button>
          ))}
      </div>
    </Modal>
  )
}
