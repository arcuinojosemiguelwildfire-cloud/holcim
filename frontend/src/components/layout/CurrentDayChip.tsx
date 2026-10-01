import { useEffect, useState } from 'react'
import { CalendarCheck } from 'lucide-react'
import { EVENT_DAY_CHANGED, eventDayService } from '../../services/eventDayService'
import type { CurrentEventDay } from '../../types/eventDay'

/** "Holcim Event 2026 — Day 2 — October 11, 2026" in the top bar (every role). */
export function CurrentDayChip() {
  const [current, setCurrent] = useState<CurrentEventDay | null>(null)

  useEffect(() => {
    let cancelled = false
    const load = () => {
      eventDayService.current().then(
        (data) => {
          if (!cancelled) setCurrent(data)
        },
        () => undefined,
      )
    }
    load()
    const interval = window.setInterval(load, 60000)
    window.addEventListener(EVENT_DAY_CHANGED, load)
    return () => {
      cancelled = true
      window.clearInterval(interval)
      window.removeEventListener(EVENT_DAY_CHANGED, load)
    }
  }, [])

  if (!current) return null
  if (!current.event || !current.eventDay) {
    return (
      <span className="hidden rounded-full bg-amber-50 px-3 py-1 text-xs font-medium text-amber-800 ring-1 ring-inset ring-amber-200 md:inline-flex">
        {current.event ? 'No current event day' : 'No active event'}
      </span>
    )
  }

  return (
    <span
      className="hidden min-w-0 items-center gap-1.5 truncate rounded-full bg-brand-50 px-3 py-1 text-xs font-medium text-brand-800 ring-1 ring-inset ring-brand-200 md:inline-flex"
      title={`${current.event.name} — ${current.eventDay.displayName}`}
      data-testid="current-day"
    >
      <CalendarCheck className="size-3.5 shrink-0" aria-hidden />
      <span className="truncate">
        Current Event Day: <b>{current.eventDay.displayName}</b>
      </span>
    </span>
  )
}
