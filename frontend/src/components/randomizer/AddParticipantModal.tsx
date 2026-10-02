import { useEffect, useState } from 'react'
import { Search } from 'lucide-react'
import { Alert } from '../ui/Alert'
import { Badge } from '../ui/Badge'
import { Button } from '../ui/Button'
import { Modal } from '../ui/Modal'
import { errorMessage } from '../../services/apiClient'
import {
  randomizerService,
  type AddParticipantResult,
  type ParticipantCandidate,
  type RandomizerType,
} from '../../services/randomizerService'
import type { EventDay } from '../../types/eventDay'
import { cn } from '../../utils/cn'

interface AddParticipantModalProps {
  type: RandomizerType
  eventDay: EventDay | null
  onClose: () => void
  onAdded: (result: AddParticipantResult) => void
}

/**
 * Manually adds an attendee to TODAY's Minor or Major pool. Creates a
 * day-specific record (source: manual) only: the attendee, QR code and
 * registration are not changed, and no check-in is created.
 */
export function AddParticipantModal({ type, eventDay, onClose, onAdded }: AddParticipantModalProps) {
  const [query, setQuery] = useState('')
  const [found, setFound] = useState<{ term: string; items: ParticipantCandidate[] } | null>(null)
  const term = query.trim()
  const results = term !== '' && found?.term === term ? found.items : null
  const [selected, setSelected] = useState<ParticipantCandidate | null>(null)
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const label = type === 'major' ? 'Major' : 'Minor'

  useEffect(() => {
    if (term === '') return
    let cancelled = false
    const timer = window.setTimeout(() => {
      randomizerService.candidates(type, term).then(
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
  }, [term, type])

  const submit = async () => {
    if (!selected) return
    setSaving(true)
    setError(null)
    try {
      onAdded(await randomizerService.addParticipant(type, selected.id, reason.trim()))
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      title={`Add participant — ${label} draw`}
      description={eventDay ? `Adds to the ${label} pool for ${eventDay.displayName} only.` : undefined}
      onClose={onClose}
      footer={
        <>
          <Button key="cancel" type="button" variant="secondary" onClick={onClose}>Cancel</Button>
          <Button key="add" type="button" disabled={!selected || selected.alreadyEligible || selected.alreadyWon} loading={saving} onClick={() => void submit()}>
            Add to today’s pool
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error && <Alert tone="error">{error}</Alert>}
        <label className="relative block">
          <span className="sr-only">Search attendees</span>
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" aria-hidden />
          <input
            type="search"
            autoFocus
            value={query}
            onChange={(e) => {
              setQuery(e.target.value)
              setSelected(null)
            }}
            placeholder="Search code, name, cluster or email"
            autoComplete="off"
            className="h-10 w-full rounded-lg border-0 pl-9 pr-3 text-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-brand-600"
          />
        </label>

        {results && results.length === 0 && <p className="text-sm text-slate-500">No active attendee matches “{query.trim()}”.</p>}
        {results && results.length > 0 && (
          <ul className="max-h-64 divide-y divide-slate-100 overflow-y-auto rounded-lg ring-1 ring-slate-200" data-testid="participant-candidates">
            {results.map((candidate) => (
              <li key={candidate.id}>
                <button
                  type="button"
                  disabled={candidate.alreadyEligible || candidate.alreadyWon}
                  onClick={() => setSelected(candidate)}
                  className={cn(
                    'flex w-full items-center gap-3 px-3 py-2 text-left text-sm',
                    candidate.alreadyEligible || candidate.alreadyWon ? 'cursor-not-allowed opacity-70' : 'hover:bg-slate-50',
                    selected?.id === candidate.id && 'bg-brand-50 ring-1 ring-inset ring-brand-300',
                  )}
                >
                  <span className="min-w-0 flex-1">
                    <span className="block truncate font-medium text-slate-900">{candidate.fullName}</span>
                    <span className="block truncate text-xs text-slate-500">
                      <span className="font-mono">{candidate.attendeeCode}</span>
                      {candidate.company && ` · ${candidate.company}`}{candidate.department && ` · ${candidate.department}`}
                      {candidate.email && ` · ${candidate.email}`}
                    </span>
                  </span>
                  {candidate.alreadyWon ? (
                    <Badge tone="warning">Already won today</Badge>
                  ) : (
                    candidate.alreadyEligible && <Badge tone="success">Already eligible</Badge>
                  )}
                </button>
              </li>
            ))}
          </ul>
        )}

        {selected && (
          <div className="space-y-2 rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200">
            <p className="text-sm text-slate-700">
              Adding <b>{selected.fullName}</b> ({selected.attendeeCode}) to today’s {label} pool.
            </p>
            <label className="block text-sm">
              <span className="mb-1 block font-medium text-slate-700">Reason (optional)</span>
              <input
                value={reason}
                maxLength={200}
                onChange={(e) => setReason(e.target.value)}
                placeholder="e.g. QR code lost, registered at the help desk"
                className="h-9 w-full rounded-lg border-0 px-3 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-brand-600"
              />
            </label>
          </div>
        )}
        <p className="text-xs text-slate-500">
          Use only for an attendee who is present but could not be scanned. This does not register the attendee or change their
          record or QR code. It only affects today’s {label} draw, not the {type === 'major' ? 'Minor' : 'Major'} draw.
        </p>
      </div>
    </Modal>
  )
}
