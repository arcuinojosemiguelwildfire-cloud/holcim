import { useState, type FormEvent } from 'react'
import { AlertTriangle, RotateCcw, ShieldCheck } from 'lucide-react'
import { Alert } from '../ui/Alert'
import { Button } from '../ui/Button'
import { Card, CardHeader } from '../ui/Card'
import { TextField } from '../ui/FormField'
import { Modal } from '../ui/Modal'
import { Spinner } from '../ui/Spinner'
import { useApiQuery } from '../../hooks/useApiQuery'
import { ApiError, errorMessage } from '../../services/apiClient'
import { EVENT_DAY_CHANGED } from '../../services/eventDayService'
import { systemResetService, type SystemResetCounts } from '../../services/settingsService'
import { formatNumber } from '../../utils/format'

export const RESET_PHRASE = 'RESET EVENT DATA'
export const RESET_SUCCESS = 'System reset completed. All event/test data was removed. Admin account was preserved.'

const fetchSummary = () => systemResetService.summary()

const REMOVED: Array<[keyof SystemResetCounts, string]> = [
  ['events', 'Events'],
  ['event_days', 'Event days'],
  ['attendees', 'Attendees and their QR codes'],
  ['registration_scans', 'Registrations (check-ins)'],
  ['randomizer_draws', 'Randomizer draws / winners'],
  ['manual_participants', 'Manual raffle participants and Major eligibility'],
  ['import_batches', 'Import batches'],
  ['non_admin_users', 'Registration Staff, Event Operator and Scanner Operator accounts'],
]

/** Settings > System Reset (admin only; the API enforces it). */
export function SystemResetCard({ onReset }: { onReset: () => void }) {
  const { data, error, loading, reload } = useApiQuery(fetchSummary)
  const [confirming, setConfirming] = useState(false)

  return (
    <Card className="mt-6 overflow-hidden ring-red-200" data-testid="system-reset">
      <CardHeader
        title="System Reset"
        description="Clean the system after testing so the real event starts with no test data."
        actions={
          <Button variant="danger" onClick={() => setConfirming(true)} icon={<RotateCcw className="size-4" aria-hidden />}>
            Reset event data
          </Button>
        }
      />
      <div className="space-y-4 px-5 py-4 text-sm">
        <Alert tone="error" title="Reset Event Data">
          This permanently removes event, attendee, registration, raffle, scanner, import, and other event-related test data. Your Admin
          account will be preserved.
        </Alert>
        {error && <Alert tone="error">{error}</Alert>}
        {loading && !data ? (
          <Spinner />
        ) : data ? (
          <div className="grid gap-4 md:grid-cols-2">
            <div>
              <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Will be deleted</h3>
              <ul className="mt-2 divide-y divide-slate-100 rounded-lg ring-1 ring-slate-200">
                {REMOVED.map(([key, label]) => (
                  <li key={key} className="flex items-center justify-between gap-3 px-3 py-2">
                    <span className="text-slate-700">{label}</span>
                    <span className="tabular-nums font-medium text-slate-900">{formatNumber(data.counts[key])}</span>
                  </li>
                ))}
                <li className="px-3 py-2 text-slate-500">Also: scan logs, login attempts and audit log entries.</li>
              </ul>
            </div>
            <div>
              <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Kept</h3>
              <ul className="mt-2 space-y-2 rounded-lg p-3 text-slate-700 ring-1 ring-slate-200">
                <li className="flex gap-2">
                  <ShieldCheck className="size-4 shrink-0 text-emerald-600" aria-hidden />
                  {formatNumber(data.counts.admin_users)} Admin account(s): name, email, password and role unchanged
                </li>
                <li className="flex gap-2">
                  <ShieldCheck className="size-4 shrink-0 text-emerald-600" aria-hidden />
                  Database structure, migrations and configuration
                </li>
              </ul>
            </div>
          </div>
        ) : null}
      </div>

      {confirming && (
        <ConfirmResetModal
          onClose={() => setConfirming(false)}
          onDone={() => {
            setConfirming(false)
            reload()
            window.dispatchEvent(new Event(EVENT_DAY_CHANGED))
            onReset()
          }}
        />
      )}
    </Card>
  )
}

function ConfirmResetModal({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const [phrase, setPhrase] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | string | null>(null)
  const ready = phrase === RESET_PHRASE && password !== ''

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!ready) return
    setBusy(true)
    setError(null)
    try {
      await systemResetService.reset(phrase, password)
      setPassword('')
      onDone()
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
      title="Reset all event data?"
      description="This cannot be undone."
      onClose={() => !busy && onClose()}
      footer={
        <>
          <Button key="cancel" variant="secondary" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button key="reset" type="submit" form="system-reset-form" variant="danger" loading={busy} disabled={!ready}
            icon={<AlertTriangle className="size-4" aria-hidden />}>
            Permanently reset
          </Button>
        </>
      }
    >
      <form id="system-reset-form" onSubmit={submit} className="space-y-4" noValidate>
        <Alert tone="error">
          Every event, event day, attendee, QR code, registration, winner, import and non-admin account will be permanently deleted.
          Download any reports you need first. Your Admin account will be preserved.
        </Alert>
        {general && <Alert tone="error">{general}</Alert>}
        {apiError?.code === 'VALIDATION_ERROR' && !apiError.fieldError('confirmation') && !apiError.fieldError('password') && (
          <Alert tone="error">{apiError.message}</Alert>
        )}
        <TextField
          label={`Type ${RESET_PHRASE} to confirm`}
          name="confirmation"
          autoComplete="off"
          spellCheck={false}
          value={phrase}
          onChange={(e) => setPhrase(e.target.value)}
          error={apiError?.fieldError('confirmation')}
          hint="Exactly as shown, in capital letters."
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
