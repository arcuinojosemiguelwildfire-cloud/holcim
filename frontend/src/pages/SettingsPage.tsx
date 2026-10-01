import { useState, type FormEvent } from 'react'
import { KeyRound, ScanLine, UserPlus } from 'lucide-react'
import { Alert } from '../components/ui/Alert'
import { Badge } from '../components/ui/Badge'
import { Button } from '../components/ui/Button'
import { Card, CardHeader } from '../components/ui/Card'
import { EmptyState } from '../components/ui/EmptyState'
import { SelectField, TextField } from '../components/ui/FormField'
import { Modal } from '../components/ui/Modal'
import { PageHeader } from '../components/ui/PageHeader'
import { Spinner } from '../components/ui/Spinner'
import { useApiQuery } from '../hooks/useApiQuery'
import { ApiError, errorMessage } from '../services/apiClient'
import { settingsService, type ScannerOperator, type ScannerOperatorInput } from '../services/settingsService'
import { formatDateTime, formatNumber } from '../utils/format'

const fetchOperators = () => settingsService.scannerOperators()

const EMPTY: ScannerOperatorInput = { name: '', username: '', password: '', password_confirmation: '', status: 'active' }

/** Settings (admin). Phase 8: Scanner Operators. */
export function SettingsPage() {
  const { data, error, loading, reload } = useApiQuery(fetchOperators)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const [creating, setCreating] = useState(false)
  const [resetFor, setResetFor] = useState<ScannerOperator | null>(null)
  const [saving, setSaving] = useState<number | null>(null)

  const toggleStatus = async (operator: ScannerOperator) => {
    const next = operator.status === 'active' ? 'inactive' : 'active'
    setSaving(operator.id)
    setNotice(null)
    try {
      await settingsService.setScannerOperatorStatus(operator.id, next)
      setNotice({ tone: 'success', text: `${operator.name} is now ${next === 'active' ? 'enabled' : 'disabled'}.` })
      reload()
    } catch (e) {
      setNotice({ tone: 'error', text: errorMessage(e) })
    } finally {
      setSaving(null)
    }
  }

  return (
    <>
      <PageHeader title="Settings" description="System configuration for administrators." />

      {notice && (
        <Alert tone={notice.tone} className="mb-4" onDismiss={() => setNotice(null)}>
          {notice.text}
        </Alert>
      )}

      <Card className="overflow-hidden">
        <CardHeader
          title="Scanner Operators"
          description="Accounts that can only use the registration scanner. They sign in with a username."
          actions={
            <Button onClick={() => setCreating(true)} icon={<UserPlus className="size-4" aria-hidden />}>
              Add scanner operator
            </Button>
          }
        />
        {error && <Alert tone="error" className="m-4">{error}</Alert>}
        {loading && !data ? (
          <Spinner />
        ) : data && data.operators.length === 0 ? (
          <EmptyState
            icon={<ScanLine className="size-6" aria-hidden />}
            title="No scanner operators yet"
            description="Add one account per scanning device or person at the entrance."
          />
        ) : data ? (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-sm" data-testid="scanner-operators">
              <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                <tr>
                  <th scope="col" className="px-5 py-3">Name</th>
                  <th scope="col" className="px-5 py-3">Username</th>
                  <th scope="col" className="px-5 py-3">Status</th>
                  <th scope="col" className="px-5 py-3 text-right">{data.eventDay ? `Scans (Day ${data.eventDay.dayNumber})` : 'Scans today'}</th>
                  <th scope="col" className="px-5 py-3">Last sign-in</th>
                  <th scope="col" className="px-5 py-3 text-right"><span className="sr-only">Actions</span></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {data.operators.map((operator) => (
                  <tr key={operator.id}>
                    <td className="px-5 py-3 font-medium text-slate-900">{operator.name}</td>
                    <td className="px-5 py-3 font-mono text-slate-600">{operator.username}</td>
                    <td className="px-5 py-3">
                      {operator.status === 'active' ? <Badge tone="success" dot>Enabled</Badge> : <Badge tone="muted">Disabled</Badge>}
                    </td>
                    <td className="px-5 py-3 text-right tabular-nums">{formatNumber(operator.scansToday)}</td>
                    <td className="px-5 py-3 text-slate-500">{operator.lastLoginAt ? formatDateTime(operator.lastLoginAt) : 'Never'}</td>
                    <td className="whitespace-nowrap px-5 py-3 text-right">
                      <Button key={`pw-${operator.id}`} variant="ghost" size="sm" onClick={() => setResetFor(operator)} icon={<KeyRound className="size-4" aria-hidden />}>
                        Reset password
                      </Button>
                      <Button
                        key={`st-${operator.id}`}
                        variant={operator.status === 'active' ? 'ghost' : 'secondary'}
                        size="sm"
                        loading={saving === operator.id}
                        onClick={() => void toggleStatus(operator)}
                      >
                        {operator.status === 'active' ? 'Disable' : 'Enable'}
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
      </Card>

      {creating && (
        <CreateOperatorModal
          onClose={() => setCreating(false)}
          onCreated={(operator) => {
            setCreating(false)
            setNotice({ tone: 'success', text: `Created scanner operator ${operator.name} (${operator.username}).` })
            reload()
          }}
        />
      )}
      {resetFor && (
        <ResetPasswordModal
          operator={resetFor}
          onClose={() => setResetFor(null)}
          onDone={() => {
            setNotice({ tone: 'success', text: `Password reset for ${resetFor.name}.` })
            setResetFor(null)
          }}
        />
      )}
    </>
  )
}

function CreateOperatorModal({ onClose, onCreated }: { onClose: () => void; onCreated: (operator: ScannerOperator) => void }) {
  const [form, setForm] = useState<ScannerOperatorInput>(EMPTY)
  const [apiError, setApiError] = useState<ApiError | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    setSaving(true)
    setApiError(null)
    setError(null)
    try {
      onCreated(await settingsService.createScannerOperator({ ...form, username: form.username.trim().toLowerCase() }))
    } catch (caught) {
      if (caught instanceof ApiError && Object.keys(caught.fieldErrors).length > 0) setApiError(caught)
      else setError(errorMessage(caught))
    } finally {
      setSaving(false)
    }
  }

  const set = (field: keyof ScannerOperatorInput) => (e: { target: { value: string } }) => setForm({ ...form, [field]: e.target.value })

  return (
    <Modal
      open
      title="Add scanner operator"
      description="This account can only scan attendee QR codes and look up registration status."
      onClose={onClose}
      footer={
        <>
          <Button key="cancel" type="button" variant="secondary" onClick={onClose}>Cancel</Button>
          <Button key="submit" type="submit" form="scanner-operator-form" loading={saving}>Create</Button>
        </>
      }
    >
      <form id="scanner-operator-form" onSubmit={submit} className="space-y-4" noValidate>
        {error && <Alert tone="error">{error}</Alert>}
        <TextField label="Display name" name="name" required maxLength={150} value={form.name} onChange={set('name')} error={apiError?.fieldError('name')} />
        <TextField
          label="Username"
          name="username"
          required
          maxLength={60}
          autoCapitalize="none"
          autoComplete="off"
          spellCheck={false}
          hint="3–60 lower-case letters, numbers, dots, underscores or hyphens."
          value={form.username}
          onChange={set('username')}
          error={apiError?.fieldError('username')}
        />
        <TextField label="Password" name="password" type="password" required autoComplete="new-password" hint="At least 8 characters." value={form.password} onChange={set('password')} error={apiError?.fieldError('password')} />
        <TextField label="Confirm password" name="password_confirmation" type="password" required autoComplete="new-password" value={form.password_confirmation} onChange={set('password_confirmation')} error={apiError?.fieldError('password_confirmation')} />
        <SelectField
          label="Status"
          name="status"
          value={form.status}
          onChange={set('status')}
          options={[
            { value: 'active', label: 'Enabled' },
            { value: 'inactive', label: 'Disabled' },
          ]}
          error={apiError?.fieldError('status')}
        />
      </form>
    </Modal>
  )
}

function ResetPasswordModal({ operator, onClose, onDone }: { operator: ScannerOperator; onClose: () => void; onDone: () => void }) {
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [apiError, setApiError] = useState<ApiError | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    setSaving(true)
    setApiError(null)
    setError(null)
    try {
      await settingsService.resetScannerOperatorPassword(operator.id, password, confirmation)
      onDone()
    } catch (caught) {
      if (caught instanceof ApiError && Object.keys(caught.fieldErrors).length > 0) setApiError(caught)
      else setError(errorMessage(caught))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      title={`Reset password — ${operator.name}`}
      description={`Username: ${operator.username}`}
      onClose={onClose}
      footer={
        <>
          <Button key="cancel" type="button" variant="secondary" onClick={onClose}>Cancel</Button>
          <Button key="submit" type="submit" form="reset-password-form" loading={saving}>Reset password</Button>
        </>
      }
    >
      <form id="reset-password-form" onSubmit={submit} className="space-y-4" noValidate>
        {error && <Alert tone="error">{error}</Alert>}
        <TextField label="New password" name="password" type="password" required autoComplete="new-password" hint="At least 8 characters." value={password} onChange={(e) => setPassword(e.target.value)} error={apiError?.fieldError('password')} />
        <TextField label="Confirm new password" name="password_confirmation" type="password" required autoComplete="new-password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} error={apiError?.fieldError('password_confirmation')} />
      </form>
    </Modal>
  )
}
