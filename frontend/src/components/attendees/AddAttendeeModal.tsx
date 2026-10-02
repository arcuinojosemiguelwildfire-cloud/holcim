import { useState, type FormEvent } from 'react'
import { Download, Eye, Printer, UserPlus } from 'lucide-react'
import { QrCard } from '../qr/QrCard'
import { Alert } from '../ui/Alert'
import { Button } from '../ui/Button'
import { TextField } from '../ui/FormField'
import { Modal } from '../ui/Modal'
import { ApiError, errorMessage } from '../../services/apiClient'
import { attendeeService } from '../../services/attendeeService'
import type { AttendeeCreateInput, AttendeeDetail } from '../../types/attendee'
import type { AttendeeQr } from '../../types/qr'
import { downloadQrPng, openQrPrintSheet } from '../../utils/qr'

const EMPTY: AttendeeCreateInput = { full_name: '', company: '', department: '', external_identifier: '', email: '' }

interface AddAttendeeModalProps {
  onClose: () => void
  /** Called after an attendee was created (list should reload). */
  onCreated: () => void
  /** Opens the normal attendee detail view. */
  onView: (attendeeId: number) => void
}

/**
 * Manual "Add Attendee" (admin / event operator). Creates ONE attendee in the
 * master list with a server-generated code and a normal QR. This is NOT the
 * randomizer "+ Add Participant": it does not register the attendee or make
 * them raffle eligible - scanning their QR on an event day does that.
 */
export function AddAttendeeModal({ onClose, onCreated, onView }: AddAttendeeModalProps) {
  const [form, setForm] = useState<AttendeeCreateInput>(EMPTY)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<ApiError | string | null>(null)
  const [created, setCreated] = useState<{ attendee: AttendeeDetail; qr: AttendeeQr } | null>(null)

  const set = (field: keyof AttendeeCreateInput) => (event: { target: { value: string } }) => setForm({ ...form, [field]: event.target.value })

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (form.full_name.trim() === '') {
      setError(new ApiError(422, { code: 'VALIDATION_ERROR', message: 'Full name is required.', details: { fields: { full_name: ['Full name is required.'] } } }))
      return
    }
    setSaving(true)
    setError(null)
    try {
      setCreated(await attendeeService.create(form))
      onCreated()
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : errorMessage(caught))
    } finally {
      setSaving(false)
    }
  }

  const apiError = error instanceof ApiError ? error : null
  const generalMessage = typeof error === 'string' ? error : apiError && apiError.code !== 'VALIDATION_ERROR' ? apiError.message : null

  if (created) {
    const { attendee, qr } = created
    return (
      <Modal
        open
        title="Attendee added"
        description={`${attendee.fullName} was added to the attendee list.`}
        onClose={onClose}
        footer={
          <>
            <Button key="another" variant="secondary" icon={<UserPlus className="size-4" aria-hidden />}
              onClick={() => { setCreated(null); setForm(EMPTY) }}>
              Add another
            </Button>
            <Button key="done" onClick={onClose}>Done</Button>
          </>
        }
      >
        <div className="space-y-4" data-testid="add-attendee-success">
          <Alert tone="success">
            Attendee added with a QR code. They are not registered yet: scan this QR at the entrance on each event day to register them
            (that also makes them eligible for the Minor and Major draws for that day).
          </Alert>
          {qr.qrPayload ? (
            <div className="flex flex-col items-center gap-4 sm:flex-row sm:items-start">
              <QrCard
                payload={qr.qrPayload}
                attendeeCode={qr.attendeeCode}
                fullName={qr.fullName}
                company={qr.company}
                department={qr.department}
                className="w-52 shrink-0"
              />
              <div className="flex flex-wrap gap-2 sm:flex-col">
                <Button size="sm" variant="secondary" icon={<Eye className="size-4" aria-hidden />} onClick={() => onView(attendee.id)}>
                  View QR
                </Button>
                <Button size="sm" variant="secondary" icon={<Download className="size-4" aria-hidden />}
                  onClick={() => qr.qrPayload && downloadQrPng(qr.qrPayload, qr).catch((e) => setError(errorMessage(e)))}>
                  Download QR
                </Button>
                <Button size="sm" variant="secondary" icon={<Printer className="size-4" aria-hidden />} onClick={() => openQrPrintSheet([attendee.id])}>
                  Print QR
                </Button>
              </div>
            </div>
          ) : (
            <Alert tone="info">The attendee was added but no QR is available. Generate it from the attendee details.</Alert>
          )}
          {generalMessage && <Alert tone="error">{generalMessage}</Alert>}
        </div>
      </Modal>
    )
  }

  return (
    <Modal
      open
      title="Add attendee"
      description="Adds one person to the attendee list and creates their QR. The attendee code is generated automatically."
      onClose={onClose}
      footer={
        <>
          <Button key="cancel" variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button key="save" type="submit" form="add-attendee-form" loading={saving} icon={<UserPlus className="size-4" aria-hidden />}>
            Add attendee
          </Button>
        </>
      }
    >
      <form id="add-attendee-form" onSubmit={handleSubmit} className="space-y-4" noValidate>
        {generalMessage && <Alert tone="error">{generalMessage}</Alert>}
        <TextField label="Full name" name="full_name" required autoFocus maxLength={200} value={form.full_name}
          onChange={set('full_name')} error={apiError?.fieldError('full_name')} />
        <TextField label="Company" name="company" maxLength={200} hint="Optional" value={form.company}
          onChange={set('company')} error={apiError?.fieldError('company')} />
        <TextField label="Cluster" name="department" maxLength={150} hint="Optional (location / region)" value={form.department}
          onChange={set('department')} error={apiError?.fieldError('department')} />
        <TextField label="Employee ID" name="external_identifier" maxLength={190} hint="Optional" value={form.external_identifier}
          onChange={set('external_identifier')} error={apiError?.fieldError('external_identifier')} />
        <TextField label="Email" name="email" type="email" maxLength={190} hint="Optional" value={form.email}
          onChange={set('email')} error={apiError?.fieldError('email')} />
      </form>
    </Modal>
  )
}
