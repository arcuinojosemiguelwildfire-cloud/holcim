import { useState, type FormEvent } from 'react'
import { Alert } from '../ui/Alert'
import { Button } from '../ui/Button'
import { SelectField, TextAreaField, TextField } from '../ui/FormField'
import { Modal } from '../ui/Modal'
import { ApiError, errorMessage } from '../../services/apiClient'
import { eventService } from '../../services/eventService'
import { EVENT_STATUSES, type EventInput, type EventRecord } from '../../types/event'
import { EVENT_STATUS_LABELS } from '../../utils/labels'

const STATUS_OPTIONS = EVENT_STATUSES.map((status) => ({ value: status, label: EVENT_STATUS_LABELS[status] }))

const EMPTY_FORM: EventInput = { name: '', description: '', event_date: '', status: 'draft' }

function toForm(event: EventRecord | null): EventInput {
  if (!event) return EMPTY_FORM
  return {
    name: event.name,
    description: event.description ?? '',
    event_date: event.eventDate,
    status: event.status,
  }
}

interface EventFormModalProps {
  open: boolean
  /** null = create a new event */
  event: EventRecord | null
  onClose: () => void
  onSaved: (event: EventRecord, created: boolean) => void
}

/**
 * Create / edit form. Mount with a `key` that changes per event so the form
 * state resets when switching between events.
 */
export function EventFormModal({ open, event, onClose, onSaved }: EventFormModalProps) {
  const [form, setForm] = useState<EventInput>(() => toForm(event))
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<ApiError | string | null>(null)
  const isEdit = event !== null

  const update = <K extends keyof EventInput>(field: K, value: EventInput[K]) =>
    setForm((current) => ({ ...current, [field]: value }))

  const handleSubmit = async (submitEvent: FormEvent<HTMLFormElement>) => {
    submitEvent.preventDefault()
    setSaving(true)
    setError(null)
    try {
      const saved = isEdit ? await eventService.update(event.id, form) : await eventService.create(form)
      onSaved(saved, !isEdit)
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : errorMessage(caught))
    } finally {
      setSaving(false)
    }
  }

  const apiError = error instanceof ApiError ? error : null
  const generalMessage =
    typeof error === 'string' ? error : apiError && apiError.code !== 'VALIDATION_ERROR' ? apiError.message : null

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={isEdit ? 'Edit event' : 'New event'}
      description={isEdit ? undefined : 'Only one event can be active at a time.'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button type="submit" form="event-form" loading={saving}>
            {isEdit ? 'Save changes' : 'Create event'}
          </Button>
        </>
      }
    >
      <form id="event-form" onSubmit={handleSubmit} className="space-y-4" noValidate>
        {generalMessage && <Alert tone="error">{generalMessage}</Alert>}
        <TextField
          label="Event name"
          required
          maxLength={200}
          value={form.name}
          onChange={(e) => update('name', e.target.value)}
          error={apiError?.fieldError('name')}
          autoFocus
        />
        <TextAreaField
          label="Description"
          rows={3}
          maxLength={5000}
          value={form.description}
          onChange={(e) => update('description', e.target.value)}
          error={apiError?.fieldError('description')}
        />
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField
            label="Event date"
            type="date"
            required
            value={form.event_date}
            onChange={(e) => update('event_date', e.target.value)}
            error={apiError?.fieldError('event_date')}
          />
          <SelectField
            label="Status"
            required
            options={STATUS_OPTIONS}
            value={form.status}
            onChange={(e) => update('status', e.target.value as EventInput['status'])}
            error={apiError?.fieldError('status')}
          />
        </div>
      </form>
    </Modal>
  )
}
