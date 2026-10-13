import { useState } from 'react'
import { Users } from 'lucide-react'
import { Alert } from '../ui/Alert'
import { Badge } from '../ui/Badge'
import { Button } from '../ui/Button'
import { Card, CardHeader } from '../ui/Card'
import { ApiError, errorMessage } from '../../services/apiClient'
import { predrawnService, type PoolConflict, type RandomizerPool } from '../../services/predrawnService'
import { formatNumber, formatShortDate } from '../../utils/format'

interface RandomizerSessionsCardProps {
  dayName: string
  pool: RandomizerPool
  isAdmin: boolean
  /** Called after the selection was saved (refresh the overview). */
  onSaved: (message: string) => void
}

/** "13:00" -> "1:00 PM" */
function timeLabel(value: string | null): string {
  if (!value) return 'no start time'
  const [h = 0, m = 0] = value.split(':').map(Number)
  return `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`
}

/**
 * Which registration sessions of the current event day feed the Randomizer
 * (the rolling names and who can be assigned a letter). Admins choose; event
 * operators see the selection. Saving only changes the selection; it never
 * touches registrations or scan history. If an assigned (not yet revealed)
 * winner would fall outside the new pool, the server refuses until the admin
 * confirms; such assignments are kept, flagged and cannot be revealed until
 * they are fixed.
 */
export function RandomizerSessionsCard({ dayName, pool, isAdmin, onSaved }: RandomizerSessionsCardProps) {
  const [checked, setChecked] = useState<number[]>(pool.selectedSessionIds)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [conflicts, setConflicts] = useState<PoolConflict[] | null>(null)
  const saved = pool.selectedSessionIds.join(',')
  // When the saved selection changes (save here, or another admin), reset the checkboxes.
  const [lastSaved, setLastSaved] = useState(saved)
  if (lastSaved !== saved) {
    setLastSaved(saved)
    setChecked(pool.selectedSessionIds)
    setConflicts(null)
  }

  const dirty = [...checked].sort((a, b) => a - b).join(',') !== saved
  const toggle = (id: number) => {
    setConflicts(null)
    setChecked((current) => (current.includes(id) ? current.filter((x) => x !== id) : [...current, id]))
  }

  const save = async (confirm: boolean) => {
    setSaving(true)
    setError(null)
    try {
      const result = await predrawnService.savePool(checked, confirm)
      setConflicts(null)
      const flagged = result.outOfPool.filter((c) => c.status === 'assigned')
      onSaved(result.pool.configured
        ? `Randomizer sessions saved: ${formatNumber(result.pool.size)} attendee(s) in the pool.${flagged.length > 0
          ? ` Letter(s) ${flagged.map((c) => c.letter).join(', ')} are outside the pool and cannot be revealed until fixed.` : ''}`
        : 'Randomizer sessions cleared. Select at least one session before presenting.')
    } catch (e) {
      if (e instanceof ApiError && e.code === 'POOL_CONFLICTS') {
        setConflicts(Array.isArray(e.details.conflicts) ? (e.details.conflicts as PoolConflict[]) : [])
      } else {
        setError(errorMessage(e))
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card className="mt-6" data-testid="randomizer-sessions">
      <CardHeader
        title={`Name pool — ${dayName}`}
        description="Attendees registered in at least one selected registration session (each person once). Archived attendees and removed registrations are left out."
        actions={
          <span className="inline-flex items-center gap-2 text-sm text-slate-600" data-testid="pool-size">
            <Users className="size-4" aria-hidden />
            {pool.configured ? `${formatNumber(pool.size)} in pool` : 'Not set'}
          </span>
        }
      />
      <div className="space-y-4 p-5">
        {!pool.configured && (
          <Alert tone="error" title="No registration sessions selected">
            {isAdmin
              ? 'Select at least one registration session below. The presentation cannot start until you do.'
              : 'An admin must select the registration sessions for the Randomizer before the presentation can start.'}
          </Alert>
        )}
        {error && <Alert tone="error" onDismiss={() => setError(null)}>{error}</Alert>}
        {conflicts && (
          <Alert tone="error" title="Assigned winners are not in the new selection">
            <p>
              These winners are not registered in any of the sessions you selected. If you save anyway, their assignments are kept but
              <b> cannot be revealed</b> until you edit them or change the selection again:
            </p>
            <ul className="mt-2 list-disc pl-5" data-testid="pool-conflicts">
              {conflicts.map((c) => <li key={c.letter}><b className="font-mono">{c.letter}</b> — {c.fullName} ({c.attendeeCode})</li>)}
            </ul>
            <div className="mt-3 flex flex-wrap gap-2">
              <Button size="sm" variant="danger" loading={saving} onClick={() => void save(true)}>Save anyway</Button>
              <Button size="sm" variant="secondary" disabled={saving} onClick={() => setConflicts(null)}>Keep editing</Button>
            </div>
          </Alert>
        )}
        {pool.sessions.length === 0 ? (
          <p className="text-sm text-slate-500">This event day has no registration sessions yet (Events page).</p>
        ) : (
          <ul className="divide-y divide-slate-100 rounded-lg ring-1 ring-slate-200">
            {pool.sessions.map((s) => (
              <li key={s.id}>
                <label className={`flex items-center gap-3 px-4 py-3 text-sm ${isAdmin ? 'cursor-pointer hover:bg-slate-50' : ''}`}>
                  <input
                    type="checkbox"
                    className="size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600"
                    checked={checked.includes(s.id)}
                    disabled={!isAdmin || saving}
                    onChange={() => toggle(s.id)}
                    data-testid={`pool-session-${s.id}`}
                  />
                  <span className="min-w-0 flex-1">
                    <span className="block font-medium text-slate-900">{s.label}</span>
                    <span className="block text-xs text-slate-500">
                      {formatShortDate(s.sessionDate)} · {timeLabel(s.startTime)} · {formatNumber(s.registered)} registered
                    </span>
                  </span>
                  {s.current && <Badge tone="brand">Current</Badge>}
                  {!s.enabled && <Badge tone="muted">Disabled</Badge>}
                </label>
              </li>
            ))}
          </ul>
        )}
        {isAdmin && (
          <div className="flex justify-end">
            <Button disabled={!dirty || conflicts !== null} loading={saving && conflicts === null} onClick={() => void save(false)} data-testid="pool-save">
              Save sessions
            </Button>
          </div>
        )}
      </div>
    </Card>
  )
}
