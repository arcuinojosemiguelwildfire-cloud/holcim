import { Badge, type BadgeTone } from '../ui/Badge'
import { EVENT_STATUS_LABELS } from '../../utils/labels'
import type { EventStatus } from '../../types/event'

const TONES: Record<EventStatus, BadgeTone> = {
  draft: 'neutral',
  active: 'success',
  completed: 'brand',
  archived: 'muted',
}

export function EventStatusBadge({ status }: { status: EventStatus }) {
  return (
    <Badge tone={TONES[status]} dot>
      {EVENT_STATUS_LABELS[status]}
    </Badge>
  )
}
