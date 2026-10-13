import { useCallback, useEffect, useRef, useState } from 'react'
import { Ban, ExternalLink, Pencil, Plus, Trash2 } from 'lucide-react'
import { AssignmentModal } from '../components/predrawn/AssignmentModal'
import { PredrawnStage } from '../components/predrawn/PredrawnStage'
import { RandomizerSessionsCard } from '../components/predrawn/RandomizerSessionsCard'
import { Alert } from '../components/ui/Alert'
import { Badge } from '../components/ui/Badge'
import { Button } from '../components/ui/Button'
import { Card, CardHeader } from '../components/ui/Card'
import { Modal } from '../components/ui/Modal'
import { PageHeader } from '../components/ui/PageHeader'
import { Spinner } from '../components/ui/Spinner'
import { useApiQuery } from '../hooks/useApiQuery'
import { useAuth } from '../hooks/useAuth'
import { useRandomizerBackground } from '../hooks/useRandomizerBackground'
import { errorMessage } from '../services/apiClient'
import { EVENT_DAY_CHANGED } from '../services/eventDayService'
import { createOperatorChannel, predrawnService, type OperatorNotice, type PredrawnAssignment } from '../services/predrawnService'
import { cn } from '../utils/cn'
import { formatTime } from '../utils/format'

const fetchOverview = () => predrawnService.overview()

const STATUS: Record<PredrawnAssignment['status'], { label: string; tone: 'muted' | 'success' | 'danger' }> = {
  assigned: { label: 'Not revealed', tone: 'muted' },
  revealed: { label: 'Revealed', tone: 'success' },
  voided: { label: 'VOID', tone: 'danger' },
}

/**
 * Randomizer (Pre-Drawn Winner Reveal): winners chosen BEFORE the event
 * (Spin the Wheel) are revealed one by one on the LED screen. No random
 * selection happens here. The rolling names come from the registration
 * sessions an admin selects for the day; every assigned winner must be in
 * that pool. Admins choose sessions and assign letters; admins and event
 * operators present. Open this page in a second window on the operator
 * laptop to see the operator notices while the first window is fullscreen.
 */
export function RandomizerPage() {
  const { hasRole } = useAuth()
  const isAdmin = hasRole('admin')
  const { data, error, loading, reload } = useApiQuery(fetchOverview)
  const backgroundUrl = useRandomizerBackground()
  const [notices, setNotices] = useState<OperatorNotice[]>([])
  const [editing, setEditing] = useState<PredrawnAssignment | 'new' | null>(null)
  const [removing, setRemoving] = useState<PredrawnAssignment | null>(null)
  const [voiding, setVoiding] = useState<PredrawnAssignment | null>(null)
  const [voidReason, setVoidReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)

  // Created in an effect (not during render) so React's dev double-mount never leaves a closed channel.
  const channelRef = useRef<ReturnType<typeof createOperatorChannel> | null>(null)
  useEffect(() => {
    const channel = createOperatorChannel((notice) => setNotices((current) => [notice, ...current].slice(0, 30)))
    channelRef.current = channel
    return () => {
      channel.close()
      if (channelRef.current === channel) channelRef.current = null
    }
  }, [])
  // Pick up changes made elsewhere (another admin's session selection or assignments, a day
  // switch): re-read the overview every 15 s while the page is visible - the same polling
  // interval as Recent Scans - and when the current event day changes. Read-only GET.
  useEffect(() => {
    const refresh = () => {
      if (document.visibilityState === 'visible') reload()
    }
    const interval = window.setInterval(refresh, 15000)
    window.addEventListener(EVENT_DAY_CHANGED, reload)
    return () => {
      window.clearInterval(interval)
      window.removeEventListener(EVENT_DAY_CHANGED, reload)
    }
  }, [reload])
  // A reveal in another window refreshes this window's list too.
  useEffect(() => {
    if (notices[0]?.tone === 'success') reload()
  }, [notices, reload])
  const notify = useCallback((notice: Omit<OperatorNotice, 'id' | 'at'>) => {
    try {
      channelRef.current?.post(notice)
    } catch {
      /* a notice must never block the presentation */
    }
  }, [])

  const assignments = data?.assignments ?? []
  const takenLetters = assignments.map((a) => a.letter)
  const revealed = assignments.filter((a) => a.status === 'revealed').length
  const poolReady = data?.pool.configured ?? false
  const outOfPool = assignments.filter((a) => a.status === 'assigned' && a.inPool === false)

  const act = async (action: () => Promise<unknown>, success: string) => {
    setBusy(true)
    setMessage(null)
    try {
      await action()
      setMessage({ tone: 'success', text: success })
      reload()
      return true
    } catch (e) {
      setMessage({ tone: 'error', text: errorMessage(e) })
      return false
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <PageHeader
        title="Randomizer"
        description="Reveal winners who were already selected before the event (Spin the Wheel). The rolling names are for display only; this is not a random draw."
        actions={
          <Button
            variant="secondary"
            icon={<ExternalLink className="size-4" aria-hidden />}
            onClick={() => window.open(window.location.href, 'holcim-predrawn-operator', 'popup,width=1100,height=800')}
          >
            Open operator window
          </Button>
        }
      />

      {error && <Alert tone="error" className="mb-4">{error}</Alert>}
      {message && <Alert tone={message.tone} className="mb-4" onDismiss={() => setMessage(null)}>{message.text}</Alert>}
      {outOfPool.length > 0 && (
        <Alert tone="error" className="mb-4" title="Assigned winners outside the name pool">
          {outOfPool.map((a) => `${a.letter} (${a.attendee.fullName})`).join(', ')} {outOfPool.length === 1 ? 'is' : 'are'} not registered in the selected
          sessions and cannot be revealed. {isAdmin ? 'Edit or remove the assignment, or change the session selection.' : 'Ask an admin to fix the assignment or the session selection.'}
        </Alert>
      )}

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div className="space-y-6">
          <PredrawnStage
            eventName={data ? `${data.event.name} — ${data.eventDay.displayName}` : null}
            backgroundUrl={backgroundUrl}
            poolReady={poolReady}
            poolKey={data?.pool.selectedSessionIds.join(',') ?? ''}
            notify={notify}
            onRevealed={reload}
          />
          <p className="text-sm text-slate-500" data-testid="predrawn-help">
            <b className="font-medium text-slate-700">Enter fullscreen</b> on the LED screen: only the background shows.
            <b className="font-medium text-slate-700"> Space</b> starts the names; while they roll, press the winner&apos;s
            <b className="font-medium text-slate-700"> letter</b> to reveal them (recorded first, then shown).
            <b className="font-medium text-slate-700"> Backspace</b> stops the names or hides the winner; <b className="font-medium text-slate-700">Esc</b> leaves fullscreen.
            Unassigned letters and errors appear only in the operator notices.
          </p>
        </div>

        <Card className="h-fit">
          <CardHeader title="Operator notices" description="Never shown on the LED screen. Use “Open operator window” on the laptop while this window is fullscreen." />
          <ul className="max-h-80 divide-y divide-slate-100 overflow-y-auto text-sm" data-testid="operator-notices" aria-live="polite">
            {notices.length === 0 && <li className="px-5 py-4 text-slate-500">No notices yet.</li>}
            {notices.map((n) => (
              <li key={n.id} className={cn('px-5 py-2.5', n.tone === 'error' ? 'text-red-700' : n.tone === 'success' ? 'text-emerald-700' : 'text-slate-600')}>
                <span className="mr-2 tabular-nums text-xs text-slate-400">{formatTime(n.at)}</span>{n.text}
              </li>
            ))}
          </ul>
        </Card>
      </div>

      {data && (
        <RandomizerSessionsCard
          dayName={data.eventDay.displayName}
          pool={data.pool}
          isAdmin={isAdmin}
          onSaved={(text) => {
            setMessage({ tone: 'success', text })
            reload()
          }}
        />
      )}

      <Card className="mt-6">
        <CardHeader
          title={`Assignments${data ? ` — ${data.eventDay.displayName}` : ''}`}
          description={`Review before presenting. ${revealed} of ${assignments.length} revealed. Category on every record: Pre-Drawn Winner.`}
          actions={isAdmin && (
            <Button icon={<Plus className="size-4" aria-hidden />} onClick={() => setEditing('new')} disabled={!data || !poolReady || assignments.length >= 26}
              title={!poolReady ? 'Select registration sessions first' : undefined}>
              Assign letter
            </Button>
          )}
        />
        {loading && !data ? (
          <div className="flex justify-center p-8"><Spinner /></div>
        ) : assignments.length === 0 ? (
          <p className="px-5 py-8 text-center text-sm text-slate-500">No letters assigned for this event day yet.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-sm" data-testid="assignments-table">
              <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                <tr>
                  <th className="px-5 py-3">Letter</th><th className="px-5 py-3">Winner</th><th className="px-5 py-3">Prize</th>
                  <th className="px-5 py-3">Status</th>{isAdmin && <th className="px-5 py-3 text-right">Actions</th>}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {assignments.map((a) => (
                  <tr key={a.id}>
                    <td className="px-5 py-3"><span className="inline-flex size-8 items-center justify-center rounded-lg bg-slate-900 font-mono text-base font-bold text-white">{a.letter}</span></td>
                    <td className="px-5 py-3">
                      <span className="block font-medium text-slate-900">{a.attendee.fullName}</span>
                      <span className="block text-xs text-slate-500">
                        <span className="font-mono">{a.attendee.attendeeCode}</span>{a.attendee.company && ` · ${a.attendee.company}`}{a.attendee.department && ` · ${a.attendee.department}`}
                        {!a.attendee.active && <span className="ml-1 font-medium text-red-600">· archived (cannot be revealed)</span>}
                        {a.inPool === false && a.status === 'assigned' && (
                          <span className="ml-1 font-medium text-red-600" data-testid={`not-in-pool-${a.letter}`}>· not in the selected sessions (cannot be revealed)</span>
                        )}
                        {a.inPool === false && a.status === 'revealed' && <span className="ml-1 text-slate-500">· not in the current selection</span>}
                      </span>
                    </td>
                    <td className="px-5 py-3 text-slate-700">{a.prizeLabel ?? '—'}</td>
                    <td className="px-5 py-3">
                      <Badge tone={STATUS[a.status].tone}>{STATUS[a.status].label}</Badge>
                      {a.inPool === false && a.status === 'assigned' && <Badge tone="danger" className="ml-2">Not in pool</Badge>}
                      {a.revealedAt && <span className="ml-2 text-xs text-slate-500">{formatTime(a.revealedAt)}{a.revealedBy && ` by ${a.revealedBy}`}</span>}
                    </td>
                    {isAdmin && (
                      <td className="px-5 py-3 text-right">
                        {a.status === 'assigned' ? (
                          <span className="inline-flex gap-1">
                            <Button size="sm" variant="ghost" icon={<Pencil className="size-4" aria-hidden />} onClick={() => setEditing(a)}>Edit</Button>
                            <Button size="sm" variant="ghost" icon={<Trash2 className="size-4" aria-hidden />} onClick={() => setRemoving(a)}>Remove</Button>
                          </span>
                        ) : (
                          <Button size="sm" variant="ghost" icon={<Ban className="size-4" aria-hidden />} onClick={() => { setVoidReason(''); setVoiding(a) }}>Void reveal</Button>
                        )}
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {(data?.voided.length ?? 0) > 0 && (
          <div className="border-t border-slate-200 px-5 py-4">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Voided reveals (kept for the record)</p>
            <ul className="mt-2 space-y-1 text-sm text-slate-600" data-testid="voided-reveals">
              {data?.voided.map((v) => (
                <li key={v.id}>
                  <span className="font-mono font-bold">{v.letter}</span> · {v.attendee.fullName}{v.prizeLabel && ` · ${v.prizeLabel}`} · revealed {v.revealedAt && formatTime(v.revealedAt)},
                  voided {v.voidedAt && formatTime(v.voidedAt)}{v.voidedBy && ` by ${v.voidedBy}`}{v.voidReason && ` — ${v.voidReason}`}
                </li>
              ))}
            </ul>
          </div>
        )}
      </Card>

      {editing && (
        <AssignmentModal
          editing={editing === 'new' ? null : editing}
          takenLetters={takenLetters}
          onClose={() => setEditing(null)}
          onSaved={(a) => {
            setEditing(null)
            setMessage({ tone: 'success', text: `Letter ${a.letter} = ${a.attendee.fullName}${a.prizeLabel ? ` (${a.prizeLabel})` : ''}.` })
            reload()
          }}
        />
      )}

      <Modal
        open={removing !== null}
        title={removing ? `Remove letter ${removing.letter}?` : ''}
        description="The assignment has not been revealed, so it is removed. The removal is written to the audit log."
        onClose={() => !busy && setRemoving(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setRemoving(null)} disabled={busy}>Cancel</Button>
            <Button variant="danger" loading={busy} onClick={() => {
              const target = removing
              if (target) void act(() => predrawnService.remove(target.id), `Letter ${target.letter} removed.`).then(() => setRemoving(null))
            }}>Remove</Button>
          </>
        }
      >
        {removing && <p className="text-sm text-slate-600">{removing.letter} — {removing.attendee.fullName}</p>}
      </Modal>

      <Modal
        open={voiding !== null}
        title={voiding ? `Void the reveal of letter ${voiding.letter}?` : ''}
        description="The reveal stays in the history and reports marked VOID. The letter can then be assigned again (for example to reveal again after a wrong key)."
        onClose={() => !busy && setVoiding(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setVoiding(null)} disabled={busy}>Cancel</Button>
            <Button variant="danger" loading={busy} onClick={() => {
              const target = voiding
              if (target) void act(() => predrawnService.voidReveal(target.id, voidReason.trim()), `Reveal of letter ${target.letter} voided.`).then(() => setVoiding(null))
            }}>Void reveal</Button>
          </>
        }
      >
        {voiding && (
          <label className="block text-sm font-medium text-slate-700">
            Reason (optional)
            <input value={voidReason} maxLength={200} onChange={(e) => setVoidReason(e.target.value)}
              className="mt-1.5 h-10 w-full rounded-lg border-0 px-3 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-brand-600" />
          </label>
        )}
      </Modal>
    </>
  )
}
