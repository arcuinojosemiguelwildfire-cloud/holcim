import { useCallback, useEffect, useState } from 'react'
import { ClipboardCheck, RotateCcw } from 'lucide-react'
import { Alert } from '../ui/Alert'
import { Badge } from '../ui/Badge'
import { Button } from '../ui/Button'
import { Spinner } from '../ui/Spinner'
import { ApiError, errorMessage } from '../../services/apiClient'
import { attendeeService } from '../../services/attendeeService'
import type { AttendeeRegistrations, RegistrationHistoryItem } from '../../types/registrationSession'
import { formatDateTime } from '../../utils/format'

interface AttendeeRegistrationsPanelProps {
  attendeeId: number
  /** Admin: may add / renew / remove registrations. Others see the history only. */
  canManage: boolean
}

const ACTIONS: Record<RegistrationHistoryItem['action'], string> = {
  scanned: 'Registered (QR scan)',
  manually_added: 'Added by admin',
  restored: 'Restored by admin',
  removed: 'Removed by admin',
}

/**
 * Registration history per session and the resulting raffle eligibility per
 * day (Phase 10). The admin only chooses WHICH SESSIONS the attendee is
 * registered for; eligibility is always derived by the server. The attendee,
 * attendee code and QR code are never changed here.
 */
export function AttendeeRegistrationsPanel({ attendeeId, canManage }: AttendeeRegistrationsPanelProps) {
  const [data, setData] = useState<AttendeeRegistrations | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [checked, setChecked] = useState<Record<number, boolean>>({})
  const [reason, setReason] = useState('')
  const [reasonError, setReasonError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const apply = useCallback((result: AttendeeRegistrations) => {
    setData(result)
    const next: Record<number, boolean> = {}
    for (const day of result.days) {
      for (const { session, registration } of day.sessions) next[session.id] = registration?.status === 'active'
    }
    setChecked(next)
  }, [])

  useEffect(() => {
    attendeeService.registrations(attendeeId).then(apply, (e: unknown) => setError(errorMessage(e)))
  }, [attendeeId, apply])

  if (error && !data) return <Alert tone="error">{error}</Alert>
  if (!data) return <Spinner />

  const original: Record<number, boolean> = {}
  for (const day of data.days) {
    for (const { session, registration } of day.sessions) original[session.id] = registration?.status === 'active'
  }
  const changes = Object.keys(checked)
    .map(Number)
    .filter((id) => checked[id] !== original[id])
    .map((id) => ({ session_id: id, registered: Boolean(checked[id]) }))
  const removing = changes.some((c) => !c.registered)

  const save = async () => {
    setSaving(true)
    setError(null)
    setReasonError(null)
    try {
      const result = await attendeeService.updateRegistrations(attendeeId, changes, reason.trim())
      apply(result)
      setReason('')
      const applied = result.applied ?? []
      setNotice(applied.length === 0 ? 'No changes.' : applied.map((a) => `${a.action === 'removed' ? 'Removed' : a.action === 'restored' ? 'Restored' : 'Added'}: ${a.sessionLabel}`).join(' · '))
    } catch (e) {
      if (e instanceof ApiError && e.fieldError('reason')) setReasonError(e.fieldError('reason') ?? null)
      else setError(errorMessage(e))
    } finally {
      setSaving(false)
    }
  }

  const rows = data.days.flatMap((day) =>
    day.sessions.flatMap(({ session, registration }) => (registration ? [{ day, session, registration }] : [])),
  )

  return (
    <section className="space-y-3 border-t border-slate-200 pt-4" data-testid="attendee-registrations">
      <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
        <ClipboardCheck className="size-4 text-brand-700" aria-hidden /> Registration &amp; raffle eligibility
      </h3>
      {notice && <Alert tone="success" onDismiss={() => setNotice(null)}>{notice}</Alert>}
      {error && <Alert tone="error">{error}</Alert>}

      <div className="grid gap-2 sm:grid-cols-2" data-testid="major-eligibility">
        {data.days.map((day) => (
          <div key={day.day.id} className="rounded-lg px-3 py-2 ring-1 ring-slate-200">
            <p className="text-xs font-medium text-slate-500">Day {day.day.dayNumber} · {day.day.eventDate}</p>
            <div className="mt-1 flex flex-wrap gap-1.5">
              <Badge tone={day.majorEligible ? 'success' : 'neutral'}>Major: {day.majorEligible ? 'Eligible' : 'Not eligible'}{day.majorSource === 'manual' ? ' (manual add)' : ''}</Badge>
              <Badge tone={day.minorEligible ? 'success' : 'neutral'}>Minor: {day.minorEligible ? 'Eligible' : 'Not eligible'}</Badge>
            </div>
          </div>
        ))}
      </div>

      {canManage ? (
        <div className="space-y-2 rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200" data-testid="manage-registrations">
          <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Registration sessions</p>
          {data.days.map((day) => (
            <fieldset key={day.day.id} className="space-y-1">
              <legend className="text-xs text-slate-500">Day {day.day.dayNumber}</legend>
              {day.sessions.map(({ session, registration }) => (
                <label key={session.id} className="flex items-center gap-2 text-sm text-slate-800">
                  <input
                    type="checkbox"
                    className="size-4 rounded border-slate-300"
                    checked={Boolean(checked[session.id])}
                    disabled={data.attendee.status !== 'active' && !original[session.id]}
                    onChange={(e) => setChecked({ ...checked, [session.id]: e.target.checked })}
                  />
                  <span className="flex-1">
                    {session.label}
                    {session.countsForMajor && <span className="ml-1 text-xs text-brand-700">(counts for Major)</span>}
                  </span>
                  {registration?.status === 'removed' && <span className="text-xs text-slate-500">removed {formatDateTime(registration.removedAt)}</span>}
                </label>
              ))}
            </fieldset>
          ))}
          {changes.length > 0 && (
            <label className="block text-xs font-medium text-slate-600">
              Reason {removing ? '(required to remove)' : '(optional)'}
              <input
                value={reason}
                maxLength={200}
                onChange={(e) => setReason(e.target.value)}
                className="mt-1 h-9 w-full rounded-lg border-0 px-2.5 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-brand-600"
              />
              {reasonError && <span className="mt-1 block text-xs text-red-700">{reasonError}</span>}
            </label>
          )}
          <div className="flex justify-end gap-2">
            {changes.length > 0 && (
              <Button type="button" variant="ghost" size="sm" onClick={() => apply(data)} icon={<RotateCcw className="size-4" aria-hidden />}>Undo</Button>
            )}
            <Button type="button" size="sm" loading={saving} disabled={changes.length === 0 || (removing && reason.trim() === '')} onClick={() => void save()}>
              Save registration eligibility
            </Button>
          </div>
          <p className="text-xs text-slate-500">Saving never changes the attendee, the attendee code or the QR code. Removed registrations stay in the history.</p>
        </div>
      ) : null}

      <div>
        <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Registration history</p>
        {rows.length === 0 ? (
          <p className="text-sm text-slate-500">Not registered in any session yet.</p>
        ) : (
          <table className="w-full text-left text-sm" data-testid="registration-history">
            <thead className="text-xs text-slate-500">
              <tr><th className="py-1 font-medium">Session</th><th className="py-1 font-medium">Registered</th><th className="py-1 font-medium">Status</th></tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {rows.map((row) => (
                <tr key={row.session.id}>
                  <td className="py-1.5 pr-2">{row.session.label}<span className="block text-xs text-slate-500">Day {row.day.day.dayNumber}</span></td>
                  <td className="py-1.5 pr-2 text-slate-700">
                    {formatDateTime(row.registration.registeredAt)}
                    <span className="block text-xs text-slate-500">
                      {row.registration.source === 'scan' ? 'QR scan' : 'Manual (admin)'}{row.registration.registeredBy ? ` · ${row.registration.registeredBy}` : ''}
                    </span>
                  </td>
                  <td className="py-1.5">
                    {row.registration.status === 'active' ? (
                      <Badge tone="success">Registered</Badge>
                    ) : (
                      <span title={row.registration.removalReason ?? undefined}>
                        <Badge tone="muted">Removed</Badge>
                        <span className="block text-xs text-slate-500">{row.registration.removedBy ?? ''} {formatDateTime(row.registration.removedAt)}</span>
                      </span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {data.history.length > 0 && (
        <details className="text-sm">
          <summary className="cursor-pointer text-xs font-semibold uppercase tracking-wide text-slate-500">Change log ({data.history.length})</summary>
          <ul className="mt-1 space-y-1" data-testid="registration-changes">
            {data.history.map((item, index) => (
              <li key={index} className="text-xs text-slate-600">
                <span className="tabular-nums">{formatDateTime(item.at)}</span> · {ACTIONS[item.action]} · {item.sessionLabel}
                {item.by && ` · ${item.by}`}{item.reason && ` · “${item.reason}”`}
              </li>
            ))}
          </ul>
        </details>
      )}
    </section>
  )
}
