import { useCallback, useState, type FormEvent, type ReactNode } from 'react'
import { Archive, ArchiveRestore, Pencil } from 'lucide-react'
import { AttendeeQrPanel } from '../qr/AttendeeQrPanel'
import { Alert } from '../ui/Alert'
import { Badge } from '../ui/Badge'
import { Button } from '../ui/Button'
import { TextField } from '../ui/FormField'
import { Modal } from '../ui/Modal'
import { Spinner } from '../ui/Spinner'
import { useApiQuery } from '../../hooks/useApiQuery'
import { useAuth } from '../../hooks/useAuth'
import { ApiError, errorMessage } from '../../services/apiClient'
import { attendeeService } from '../../services/attendeeService'
import type { AttendeeDetail, AttendeeUpdateInput } from '../../types/attendee'

interface AttendeeDetailModalProps {
  attendeeId: number
  onClose: () => void
  onChanged: () => void
}

function toForm(attendee: AttendeeDetail): AttendeeUpdateInput {
  return {
    full_name: attendee.fullName,
    company: attendee.company ?? '',
    department: attendee.department ?? '',
    email: attendee.email ?? '',
    external_identifier: attendee.externalIdentifier ?? '',
  }
}

export function AttendeeDetailModal({ attendeeId, onClose, onChanged }: AttendeeDetailModalProps) {
  const { hasRole } = useAuth()
  const canManage = hasRole('admin')
  const fetcher = useCallback(() => attendeeService.get(attendeeId), [attendeeId])
  const { data: loaded, error: loadError, loading } = useApiQuery(fetcher)

  const [updated, setUpdated] = useState<AttendeeDetail | null>(null)
  const attendee = updated ?? loaded
  const [form, setForm] = useState<AttendeeUpdateInput | null>(null)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<ApiError | string | null>(null)

  const handleSave = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!attendee || !form) return
    setSaving(true)
    setError(null)
    try {
      setUpdated(await attendeeService.update(attendee.id, form))
      setForm(null)
      onChanged()
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : errorMessage(caught))
    } finally {
      setSaving(false)
    }
  }

  const handleStatus = async () => {
    if (!attendee) return
    setSaving(true)
    setError(null)
    try {
      setUpdated(await attendeeService.setStatus(attendee.id, attendee.status === 'active' ? 'archived' : 'active'))
      onChanged()
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setSaving(false)
    }
  }

  const apiError = error instanceof ApiError ? error : null
  const generalMessage = typeof error === 'string' ? error : apiError && apiError.code !== 'VALIDATION_ERROR' ? apiError.message : null
  const editing = form !== null

  const footer = attendee ? (
    editing ? (
      <>
        <Button key="cancel" variant="secondary" onClick={() => { setForm(null); setError(null) }} disabled={saving}>
          Cancel
        </Button>
        <Button key="save" type="submit" form="attendee-form" loading={saving}>
          Save changes
        </Button>
      </>
    ) : canManage ? (
      <>
        <Button
          key="status"
          variant="secondary"
          onClick={handleStatus}
          loading={saving}
          icon={attendee.status === 'active' ? <Archive className="size-4" aria-hidden /> : <ArchiveRestore className="size-4" aria-hidden />}
        >
          {attendee.status === 'active' ? 'Archive' : 'Restore'}
        </Button>
        <Button key="edit" onClick={() => setForm(toForm(attendee))} icon={<Pencil className="size-4" aria-hidden />}>
          Edit
        </Button>
      </>
    ) : (
      <Button variant="secondary" onClick={onClose}>
        Close
      </Button>
    )
  ) : undefined

  return (
    <Modal open title={attendee ? attendee.fullName : 'Attendee'} description={attendee?.attendeeCode} onClose={onClose} footer={footer}>
      {loading && !attendee ? (
        <Spinner />
      ) : loadError ? (
        <Alert tone="error">{loadError}</Alert>
      ) : attendee ? (
        <div className="space-y-4">
          {generalMessage && <Alert tone="error">{generalMessage}</Alert>}
          {editing && form ? (
            <form id="attendee-form" onSubmit={handleSave} className="space-y-4" noValidate>
              <TextField label="Attendee code" value={attendee.attendeeCode} disabled hint="Attendee codes never change." />
              <TextField label="Full name" required autoFocus maxLength={200} value={form.full_name}
                onChange={(e) => setForm({ ...form, full_name: e.target.value })} error={apiError?.fieldError('full_name')} />
              <TextField label="Company" maxLength={200} hint="Optional" value={form.company}
                onChange={(e) => setForm({ ...form, company: e.target.value })} error={apiError?.fieldError('company')} />
              <TextField label="Cluster" maxLength={150} hint="Optional (location / region)" value={form.department}
                onChange={(e) => setForm({ ...form, department: e.target.value })} error={apiError?.fieldError('department')} />
              <TextField label="Email" type="email" maxLength={190} value={form.email}
                onChange={(e) => setForm({ ...form, email: e.target.value })} error={apiError?.fieldError('email')} />
              <TextField label="Employee ID / External ID" maxLength={190} value={form.external_identifier} hint="Optional"
                onChange={(e) => setForm({ ...form, external_identifier: e.target.value })} error={apiError?.fieldError('external_identifier')} />
            </form>
          ) : (
            <dl className="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
              <Detail label="Attendee code"><span className="font-mono">{attendee.attendeeCode}</span></Detail>
              <Detail label="Status">
                <Badge tone={attendee.status === 'active' ? 'success' : 'muted'} dot>
                  {attendee.status === 'active' ? 'Active' : 'Archived'}
                </Badge>
              </Detail>
              <Detail label="Full name">{attendee.fullName}</Detail>
              <Detail label="Company">{attendee.company ?? '—'}</Detail>
              <Detail label="Cluster">{attendee.department ?? '—'}</Detail>
              <Detail label="Email">{attendee.email ?? '—'}</Detail>
              <Detail label="Employee ID / External ID">{attendee.externalIdentifier ?? '—'}</Detail>
              <Detail label="Created">{attendee.createdAt}</Detail>

              {Object.keys(attendee.extraData).length > 0 && (
                <div className="sm:col-span-2">
                  <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Other imported columns</dt>
                  <dd className="mt-1 grid grid-cols-1 gap-1 rounded-lg bg-slate-50 p-3 sm:grid-cols-2">
                    {Object.entries(attendee.extraData).map(([key, value]) => (
                      <div key={key} className="min-w-0">
                        <span className="text-slate-500">{key}:</span> <span className="break-words text-slate-800">{value}</span>
                      </div>
                    ))}
                  </dd>
                </div>
              )}
            </dl>
          )}
          {!editing && <AttendeeQrPanel attendeeId={attendee.id} attendeeStatus={attendee.status} />}
        </div>
      ) : null}
    </Modal>
  )
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</dt>
      <dd className="mt-0.5 break-words text-slate-900">{children}</dd>
    </div>
  )
}
