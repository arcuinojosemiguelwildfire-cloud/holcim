import { useEffect, useState } from 'react'
import { Search } from 'lucide-react'
import { Alert } from '../ui/Alert'
import { Button } from '../ui/Button'
import { Modal } from '../ui/Modal'
import { ApiError, errorMessage } from '../../services/apiClient'
import { predrawnService, type PoolCandidate, type PredrawnAssignment } from '../../services/predrawnService'
import { cn } from '../../utils/cn'

const LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('')

interface AssignmentModalProps {
  /** null = new assignment */
  editing: PredrawnAssignment | null
  /** Letters already used by OTHER live assignments. */
  takenLetters: string[]
  onClose: () => void
  onSaved: (assignment: PredrawnAssignment) => void
}

type Picked = Pick<PoolCandidate, 'id' | 'attendeeCode' | 'fullName' | 'company' | 'department'>

/**
 * Admin: assign a pre-drawn winner to a letter A-Z, with an optional prize
 * label. Any ACTIVE attendee of the active event can be picked - registered,
 * scanned or not (the winners were chosen outside the system); the list
 * marks who is registered in the selected sessions. The server checks
 * everything again (letter / attendee unique per event day, attendee active
 * and in this event).
 */
export function AssignmentModal({ editing, takenLetters, onClose, onSaved }: AssignmentModalProps) {
  const freeLetters = LETTERS.filter((l) => !takenLetters.includes(l) || l === editing?.letter)
  const [letter, setLetter] = useState(editing?.letter ?? freeLetters[0] ?? '')
  const [prize, setPrize] = useState(editing?.prizeLabel ?? '')
  const [selected, setSelected] = useState<Picked | null>(editing ? editing.attendee : null)
  const [query, setQuery] = useState('')
  const [found, setFound] = useState<{ term: string; items: PoolCandidate[] } | null>(null)
  const [error, setError] = useState<ApiError | string | null>(null)
  const [saving, setSaving] = useState(false)
  const term = query.trim()
  const results = term !== '' && found?.term === term ? found.items : null

  useEffect(() => {
    if (term === '') return
    let cancelled = false
    const timer = window.setTimeout(() => {
      predrawnService.poolCandidates(term).then(
        (items) => {
          if (!cancelled) setFound({ term, items })
        },
        (e: unknown) => {
          if (!cancelled) setError(errorMessage(e))
        },
      )
    }, 250)
    return () => {
      cancelled = true
      window.clearTimeout(timer)
    }
  }, [term])

  const save = async () => {
    if (!selected || !letter) return
    setSaving(true)
    setError(null)
    const input = { letter, attendee_id: selected.id, prize_label: prize.trim() === '' ? null : prize.trim() }
    try {
      onSaved(editing ? await predrawnService.update(editing.id, input) : await predrawnService.assign(input))
    } catch (e) {
      setError(e instanceof ApiError ? e : errorMessage(e))
    } finally {
      setSaving(false)
    }
  }

  const apiError = error instanceof ApiError ? error : null
  const message = typeof error === 'string' ? error : apiError?.message

  return (
    <Modal
      open
      title={editing ? `Edit letter ${editing.letter}` : 'Assign a pre-drawn winner'}
      description="The winner was already selected outside this system (Spin the Wheel). This only sets which letter reveals them."
      onClose={onClose}
      footer={
        <>
          <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="button" disabled={!selected || !letter} loading={saving} onClick={() => void save()}>
            {editing ? 'Save changes' : 'Assign'}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {message && <Alert tone="error">{message}</Alert>}
        <div className="grid gap-4 sm:grid-cols-[8rem_1fr]">
          <label className="block text-sm font-medium text-slate-700">
            Letter
            <select
              value={letter}
              onChange={(e) => setLetter(e.target.value)}
              className={cn('mt-1.5 h-10 w-full rounded-lg border-0 text-sm ring-1 ring-inset focus:ring-2 focus:ring-brand-600',
                apiError?.fieldError('letter') ? 'ring-red-400' : 'ring-slate-300')}
              data-testid="assignment-letter"
            >
              {freeLetters.map((l) => <option key={l} value={l}>{l}</option>)}
            </select>
          </label>
          <label className="block text-sm font-medium text-slate-700">
            Prize label <span className="font-normal text-slate-400">(optional, shown on the LED screen)</span>
            <input
              value={prize}
              maxLength={150}
              onChange={(e) => setPrize(e.target.value)}
              placeholder="e.g. Grand Prize"
              className="mt-1.5 h-10 w-full rounded-lg border-0 px-3 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-brand-600"
              data-testid="assignment-prize"
            />
          </label>
        </div>

        <div>
          <p className="text-sm font-medium text-slate-700">Winner <span className="font-normal text-slate-400">(any active attendee of this event)</span></p>
          {selected && (
            <p className="mt-1.5 rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-900 ring-1 ring-inset ring-brand-200" data-testid="assignment-selected">
              <b>{selected.fullName}</b> · <span className="font-mono">{selected.attendeeCode}</span>
              {selected.company && ` · ${selected.company}`}{selected.department && ` · ${selected.department}`}
            </p>
          )}
          {apiError?.fieldError('attendee_id') && <p className="mt-1.5 text-sm text-red-600">{apiError.fieldError('attendee_id')}</p>}
          <label className="relative mt-2 block">
            <span className="sr-only">Search attendees</span>
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" aria-hidden />
            <input
              type="search"
              autoFocus={!editing}
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Search name, code, company or Guest No."
              autoComplete="off"
              className="h-10 w-full rounded-lg border-0 pl-9 pr-3 text-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-brand-600"
              data-testid="assignment-search"
            />
          </label>
          {results && results.length === 0 && <p className="mt-2 text-sm text-slate-500">No active attendee of this event matches “{term}”.</p>}
          {results && results.length > 0 && (
            <ul className="mt-2 max-h-56 divide-y divide-slate-100 overflow-y-auto rounded-lg ring-1 ring-slate-200">
              {results.map((a) => {
                const taken = a.assignedLetter !== null && a.id !== editing?.attendee.id
                return (
                <li key={a.id}>
                  <button
                    type="button"
                    disabled={taken}
                    onClick={() => setSelected(a)}
                    className={cn('flex w-full flex-col px-3 py-2 text-left text-sm hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60',
                      selected?.id === a.id && 'bg-brand-50')}
                  >
                    <span className="truncate font-medium text-slate-900">
                      {a.fullName}{taken && <span className="ml-2 text-xs font-normal text-slate-500">already letter {a.assignedLetter}</span>}
                      <span className={cn('ml-2 rounded px-1.5 py-0.5 text-[11px] font-medium', a.inPool ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500')}
                        data-testid={`candidate-pool-${a.id}`}>
                        {a.inPool ? 'Registered in selected sessions' : 'Not registered in selected sessions'}
                      </span>
                    </span>
                    <span className="truncate text-xs text-slate-500">
                      <span className="font-mono">{a.attendeeCode}</span>{a.company && ` · ${a.company}`}{a.department && ` · ${a.department}`}
                    </span>
                  </button>
                </li>
                )
              })}
            </ul>
          )}
        </div>
      </div>
    </Modal>
  )
}
