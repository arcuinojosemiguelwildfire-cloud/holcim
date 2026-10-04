import { useCallback, useState, type FormEvent } from 'react'
import { History, RotateCcw } from 'lucide-react'
import { Alert } from '../ui/Alert'
import { Button } from '../ui/Button'
import { Card, CardHeader } from '../ui/Card'
import { SelectField, TextField } from '../ui/FormField'
import { Modal } from '../ui/Modal'
import { Spinner } from '../ui/Spinner'
import { useApiQuery } from '../../hooks/useApiQuery'
import { ApiError, errorMessage } from '../../services/apiClient'
import { eventDayService } from '../../services/eventDayService'
import { eventService } from '../../services/eventService'
import {
  randomizerResetService,
  type RandomizerResetPreview,
  type RandomizerResetScope,
  type RandomizerResetWinner,
} from '../../services/settingsService'
import { cn } from '../../utils/cn'

const SCOPE_INFO: Record<RandomizerResetScope, { label: string; button: string }> = {
  minor: { label: 'Minor', button: 'Reset Minor Draw' },
  major: { label: 'Major', button: 'Reset Major Draw' },
  both: { label: 'Minor + Major', button: 'Reset Raffle Draws' },
}
const SCOPES = (Object.keys(SCOPE_INFO) as RandomizerResetScope[]).map((value) => ({ value, ...SCOPE_INFO[value] }))

const fetchEvents = () => eventService.list()

/**
 * Settings > Randomizer Reset (admin only; the API enforces it).
 * Lets previous winners of one event day be drawn again in Minor, Major or
 * both. Draw history, registrations, attendees, QR codes and manual
 * participants are kept.
 */
export function RandomizerResetCard({ onDone }: { onDone: (message: string) => void }) {
  const { data: events } = useApiQuery(fetchEvents)
  // Chosen values; until the admin picks, default to the active event / current day.
  const [chosenEventId, setEventId] = useState<number | null>(null)
  const [chosenDayId, setDayId] = useState<number | null>(null)
  const [scope, setScope] = useState<RandomizerResetScope>('minor')
  const [confirming, setConfirming] = useState(false)

  const eventId = chosenEventId ?? (events?.find((e) => e.status === 'active') ?? events?.[0])?.id ?? null
  const daysFetcher = useCallback(() => (eventId ? eventDayService.list(eventId) : Promise.resolve([])), [eventId])
  const { data: days } = useApiQuery(daysFetcher)
  const dayId = days?.some((d) => d.id === chosenDayId)
    ? chosenDayId
    : (days?.find((d) => d.status === 'active') ?? days?.[0])?.id ?? null

  const previewFetcher = useCallback(
    () => (eventId && dayId ? randomizerResetService.preview(eventId, dayId) : Promise.resolve(null)),
    [eventId, dayId],
  )
  const { data: preview, error: previewError, loading: previewLoading, reload } = useApiQuery(previewFetcher)

  const scopeInfo = SCOPE_INFO[scope]
  const affected = preview ? (scope === 'minor' ? preview.minor.count : scope === 'major' ? preview.major.count : preview.minor.count + preview.major.count) : 0

  return (
    <Card className="mt-6 overflow-hidden" data-testid="randomizer-reset">
      <CardHeader
        title="Randomizer Reset"
        description="Let previous winners of one event day be drawn again. Draw history is kept; registrations and attendees are not changed."
      />
      <div className="space-y-5 px-5 py-4 text-sm">
        <div className="grid gap-4 sm:grid-cols-2">
          <SelectField
            label="Event"
            name="reset-event"
            value={eventId ? String(eventId) : ''}
            onChange={(e) => { setEventId(Number(e.target.value)); setDayId(null) }}
            options={(events ?? []).map((e) => ({ value: String(e.id), label: `${e.name}${e.status === 'active' ? ' (active)' : ''}` }))}
          />
          <SelectField
            label="Event day"
            name="reset-day"
            value={dayId ? String(dayId) : ''}
            onChange={(e) => setDayId(Number(e.target.value))}
            options={(days ?? []).map((d) => ({ value: String(d.id), label: `${d.displayName}${d.status === 'active' ? ' (current)' : ''}` }))}
          />
        </div>

        <fieldset>
          <legend className="text-sm font-medium text-slate-700">Randomizer</legend>
          <div className="mt-2 flex flex-wrap gap-2" role="radiogroup">
            {SCOPES.map((option) => (
              <label
                key={option.value}
                className={cn(
                  'flex cursor-pointer items-center gap-2 rounded-lg px-3 py-2 ring-1 ring-inset',
                  scope === option.value ? 'bg-brand-50 text-brand-800 ring-brand-300' : 'bg-white text-slate-700 ring-slate-300 hover:bg-slate-50',
                )}
              >
                <input
                  type="radio"
                  name="reset-scope"
                  value={option.value}
                  checked={scope === option.value}
                  onChange={() => setScope(option.value)}
                  className="text-brand-700 focus:ring-brand-600"
                />
                {option.label}
              </label>
            ))}
          </div>
        </fieldset>

        {previewError && <Alert tone="error">{previewError}</Alert>}
        {previewLoading && !preview ? (
          <Spinner />
        ) : preview ? (
          <div className="rounded-lg p-4 ring-1 ring-slate-200" data-testid="randomizer-reset-preview">
            <p className="font-semibold text-slate-900">
              {scopeInfo.button} — Day {preview.eventDay.dayNumber}
            </p>
            <p className="mt-1 text-slate-600">
              {scope === 'both'
                ? `Attendees who already won Minor or Major on Day ${preview.eventDay.dayNumber} become eligible for that randomizer again.`
                : `Attendees who already won ${scopeInfo.label} on Day ${preview.eventDay.dayNumber} become eligible for ${scopeInfo.label} again.`}{' '}
              Other days{scope !== 'both' ? ` and ${scope === 'minor' ? 'Major' : 'Minor'}` : ''} are not affected.
            </p>
            <div className="mt-3 grid gap-3 md:grid-cols-2">
              {(scope === 'minor' || scope === 'both') && <WinnerList title="Current Minor winners" winners={preview.minor.winners} />}
              {(scope === 'major' || scope === 'both') && <WinnerList title="Current Major winners" winners={preview.major.winners} />}
            </div>
            <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
              <span className="text-slate-600">
                Current chosen winners affected: <strong className="text-slate-900" data-testid="reset-affected">{affected}</strong>
              </span>
              <Button variant="danger" disabled={affected === 0} onClick={() => setConfirming(true)} icon={<RotateCcw className="size-4" aria-hidden />}>
                {scopeInfo.button}
              </Button>
            </div>
          </div>
        ) : (
          <p className="text-slate-500">Choose an event and an event day.</p>
        )}
      </div>

      {confirming && preview && (
        <ConfirmModal
          preview={preview}
          scope={scope}
          affected={affected}
          onClose={() => setConfirming(false)}
          onDone={(message) => {
            setConfirming(false)
            reload()
            onDone(message)
          }}
        />
      )}
    </Card>
  )
}

function WinnerList({ title, winners }: { title: string; winners: RandomizerResetWinner[] }) {
  return (
    <div>
      <h4 className="text-xs font-semibold uppercase tracking-wide text-slate-500">
        {title} ({winners.length})
      </h4>
      {winners.length === 0 ? (
        <p className="mt-1 text-slate-500">None — nobody is excluded.</p>
      ) : (
        <ul className="mt-1 max-h-48 divide-y divide-slate-100 overflow-y-auto rounded-md ring-1 ring-slate-200">
          {winners.map((w, i) => (
            <li key={`${w.attendeeCode}-${i}`} className="flex items-baseline justify-between gap-3 px-3 py-1.5">
              <span className="min-w-0 truncate font-medium text-slate-800">{w.fullName}</span>
              <span className="shrink-0 text-xs text-slate-500">{[w.company, w.department].filter(Boolean).join(' · ') || w.attendeeCode}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

function ConfirmModal({
  preview,
  scope,
  affected,
  onClose,
  onDone,
}: {
  preview: RandomizerResetPreview
  scope: RandomizerResetScope
  affected: number
  onClose: () => void
  onDone: (message: string) => void
}) {
  const phrase = preview.phrases[scope]
  const info = SCOPE_INFO[scope]
  const [typed, setTyped] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | string | null>(null)
  const ready = typed === phrase && password !== ''

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!ready) return
    setBusy(true)
    setError(null)
    try {
      const result = await randomizerResetService.reset({
        event_id: preview.event.id,
        event_day_id: preview.eventDay.id,
        randomizer: scope,
        confirmation: typed,
        password,
      })
      onDone(result.message)
    } catch (caught) {
      setPassword('')
      setError(caught instanceof ApiError ? caught : errorMessage(caught))
    } finally {
      setBusy(false)
    }
  }

  const apiError = error instanceof ApiError ? error : null
  const general = typeof error === 'string' ? error : apiError && apiError.code !== 'VALIDATION_ERROR' ? apiError.message : null

  return (
    <Modal
      open
      title={`${info.button} — Day ${preview.eventDay.dayNumber}?`}
      description="Previous winners can be selected again. Draw history is kept."
      onClose={() => !busy && onClose()}
      footer={
        <>
          <Button key="cancel" variant="secondary" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button key="reset" type="submit" form="randomizer-reset-form" variant="danger" loading={busy} disabled={!ready}
            icon={<History className="size-4" aria-hidden />}>
            {info.button}
          </Button>
        </>
      }
    >
      <form id="randomizer-reset-form" onSubmit={submit} className="space-y-4" noValidate>
        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 rounded-lg bg-slate-50 p-3 text-sm" data-testid="randomizer-reset-summary">
          <dt className="text-slate-500">Event</dt><dd className="font-medium text-slate-900">{preview.event.name}</dd>
          <dt className="text-slate-500">Day</dt><dd className="font-medium text-slate-900">{preview.eventDay.displayName}</dd>
          <dt className="text-slate-500">Randomizer</dt><dd className="font-medium text-slate-900">{info.label}</dd>
          <dt className="text-slate-500">Current chosen winners</dt><dd className="font-medium text-slate-900">{affected}</dd>
        </dl>
        <p className="text-sm text-slate-600">
          After reset, those attendees may be selected again in {info.label}. Registration and attendee data will NOT be changed.
        </p>
        {general && <Alert tone="error">{general}</Alert>}
        <TextField
          label={`Type ${phrase} to confirm`}
          name="confirmation"
          autoComplete="off"
          spellCheck={false}
          value={typed}
          onChange={(e) => setTyped(e.target.value)}
          error={apiError?.fieldError('confirmation')}
        />
        <TextField
          label="Your current password"
          name="password"
          type="password"
          autoComplete="current-password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          error={apiError?.fieldError('password')}
        />
      </form>
    </Modal>
  )
}
