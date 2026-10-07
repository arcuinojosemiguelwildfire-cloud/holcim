import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { Check, Pencil, Play, Plus, X } from 'lucide-react'
import { Alert } from '../ui/Alert'
import { Badge } from '../ui/Badge'
import { Button } from '../ui/Button'
import { Spinner } from '../ui/Spinner'
import { ApiError, errorMessage } from '../../services/apiClient'
import { eventDayService } from '../../services/eventDayService'
import type { EventDay } from '../../types/eventDay'
import type { RegistrationSession, RegistrationSessionInput } from '../../types/registrationSession'

interface DaySessionsPanelProps {
  day: EventDay
}

type Draft = RegistrationSessionInput & { id: number | null }

function toDraft(session: RegistrationSession): Draft {
  return {
    id: session.id,
    label: session.label,
    session_date: session.sessionDate,
    start_time: session.startTime ?? '',
    description: session.description ?? '',
    sort_order: session.sortOrder,
    enabled: session.enabled,
    counts_for_major: session.countsForMajor,
  }
}

/**
 * Admin: registration sessions of one event day (Phase 10). Sessions are
 * data - e.g. Oct 15 has "8:00 AM" (does not count for Major) and "5:00 PM"
 * (counts for Major). The CURRENT session is where scans are recorded;
 * switching it never changes registrations already made.
 */
export function DaySessionsPanel({ day }: DaySessionsPanelProps) {
  const [sessions, setSessions] = useState<RegistrationSession[] | null>(null)
  const [draft, setDraft] = useState<Draft | null>(null)
  const [confirmId, setConfirmId] = useState<number | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [notice, setNotice] = useState<string | null>(null)

  const load = useCallback(() => {
    eventDayService.sessions(day.id).then(setSessions, (e: unknown) => setError(errorMessage(e)))
  }, [day.id])

  useEffect(load, [load])

  const run = async (action: () => Promise<string>): Promise<boolean> => {
    setBusy(true)
    setError(null)
    setFieldErrors({})
    try {
      setNotice(await action())
      load()
      return true
    } catch (e) {
      if (e instanceof ApiError && Object.keys(e.fieldErrors).length > 0) {
        const fields: Record<string, string> = {}
        for (const key of Object.keys(e.fieldErrors)) fields[key] = e.fieldError(key) ?? ''
        setFieldErrors(fields)
      } else {
        setError(errorMessage(e))
      }
      return false
    } finally {
      setBusy(false)
    }
  }

  const save = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!draft) return
    const { id, ...input } = draft
    const ok = await run(async () => {
      const saved = id === null ? await eventDayService.createSession(day.id, input) : await eventDayService.updateSession(id, input)
      return id === null ? `Added "${saved.label}".` : `Saved "${saved.label}".`
    })
    if (ok) setDraft(null)
  }

  const activate = (session: RegistrationSession) =>
    run(async () => {
      const updated = await eventDayService.activateSession(session.id)
      setConfirmId(null)
      return `Scans for Day ${day.dayNumber} are now recorded in "${updated.label}". Earlier registrations are kept.`
    })

  const inputClass = 'h-9 w-full rounded-lg border-0 px-2.5 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-brand-600'
  const fieldError = (key: string) => (fieldErrors[key] ? <p className="mt-1 text-xs text-red-700">{fieldErrors[key]}</p> : null)

  return (
    <div className="mt-2 space-y-2 rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200" data-testid={`day-sessions-${day.dayNumber}`}>
      <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Registration sessions</p>
      {notice && <Alert tone="success" onDismiss={() => setNotice(null)}>{notice}</Alert>}
      {error && <Alert tone="error">{error}</Alert>}
      {!sessions ? (
        <Spinner />
      ) : (
        <ul className="space-y-1.5">
          {sessions.map((session) => (
            <li key={session.id} className="flex flex-wrap items-center gap-2 rounded-md bg-white px-2.5 py-2 ring-1 ring-slate-200">
              <div className="min-w-0 flex-1">
                <p className="text-sm font-medium text-slate-900">{session.label}</p>
                <p className="text-xs text-slate-500">
                  {session.dateLabel}
                  {session.timeLabel ? ` · ${session.timeLabel}` : ' · no start time set'}
                  {` · ${session.registrations ?? 0} registered`}
                </p>
              </div>
              <Badge tone={session.countsForMajor ? 'brand' : 'neutral'}>{session.countsForMajor ? 'Counts for Major' : 'Minor only'}</Badge>
              {session.current && <Badge tone="success" dot>Current</Badge>}
              {!session.enabled && <Badge tone="muted">Disabled</Badge>}
              <Button type="button" variant="ghost" size="sm" onClick={() => { setFieldErrors({}); setDraft(toDraft(session)) }} icon={<Pencil className="size-4" aria-hidden />}>
                Edit
              </Button>
              {!session.current && session.enabled &&
                (confirmId === session.id ? (
                  <Button key={`confirm-${session.id}`} type="button" size="sm" loading={busy} onClick={() => void activate(session)}>
                    Confirm: record scans here
                  </Button>
                ) : (
                  <Button key={`activate-${session.id}`} type="button" variant="secondary" size="sm" onClick={() => setConfirmId(session.id)} icon={<Play className="size-4" aria-hidden />}>
                    Make current
                  </Button>
                ))}
            </li>
          ))}
        </ul>
      )}

      {draft ? (
        <form onSubmit={save} className="grid grid-cols-2 gap-2 rounded-md bg-white p-3 ring-1 ring-slate-200" noValidate>
          <label className="col-span-2 text-xs font-medium text-slate-600">
            Session name
            <input className={inputClass} required maxLength={100} value={draft.label} placeholder="Oct 15 - 5:00 PM" onChange={(e) => setDraft({ ...draft, label: e.target.value })} />
            {fieldError('label')}
          </label>
          <label className="text-xs font-medium text-slate-600">
            Registration date
            <input type="date" className={inputClass} required value={draft.session_date} onChange={(e) => setDraft({ ...draft, session_date: e.target.value })} />
            {fieldError('session_date')}
          </label>
          <label className="text-xs font-medium text-slate-600">
            Start time
            <input type="time" className={inputClass} required value={draft.start_time} onChange={(e) => setDraft({ ...draft, start_time: e.target.value })} />
            {fieldError('start_time')}
          </label>
          <label className="text-xs font-medium text-slate-600">
            Order
            <input type="number" min={1} max={999} className={inputClass} value={draft.sort_order} onChange={(e) => setDraft({ ...draft, sort_order: Number(e.target.value) || 1 })} />
            {fieldError('sort_order')}
          </label>
          <label className="text-xs font-medium text-slate-600">
            Description (optional)
            <input className={inputClass} maxLength={255} value={draft.description} onChange={(e) => setDraft({ ...draft, description: e.target.value })} />
          </label>
          <label className="col-span-2 flex items-start gap-2 text-sm text-slate-800">
            <input type="checkbox" className="mt-0.5 size-4 rounded border-slate-300" checked={draft.counts_for_major} onChange={(e) => setDraft({ ...draft, counts_for_major: e.target.checked })} />
            <span>
              <b>Counts for the Major Randomizer</b>
              <span className="block text-xs text-slate-500">
                Attendees registered in this session can be drawn in this day&apos;s Major Randomizer. Every session counts for the Minor Randomizer.
              </span>
            </span>
          </label>
          <label className="col-span-2 flex items-center gap-2 text-sm text-slate-800">
            <input type="checkbox" className="size-4 rounded border-slate-300" checked={draft.enabled} onChange={(e) => setDraft({ ...draft, enabled: e.target.checked })} />
            Enabled (can be made current)
          </label>
          <div className="col-span-2 flex justify-end gap-2">
            <Button type="button" variant="ghost" size="sm" onClick={() => setDraft(null)} icon={<X className="size-4" aria-hidden />}>Cancel</Button>
            <Button type="submit" size="sm" loading={busy} icon={<Check className="size-4" aria-hidden />}>Save session</Button>
          </div>
        </form>
      ) : (
        sessions && (
          <Button
            type="button"
            variant="secondary"
            size="sm"
            onClick={() => {
              setFieldErrors({})
              setDraft({
                id: null, label: '', session_date: day.eventDate, start_time: '', description: '',
                sort_order: (sessions[sessions.length - 1]?.sortOrder ?? 0) + 1, enabled: true, counts_for_major: false,
              })
            }}
            icon={<Plus className="size-4" aria-hidden />}
          >
            Add session
          </Button>
        )
      )}
    </div>
  )
}
