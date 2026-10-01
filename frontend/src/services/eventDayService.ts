import type { CurrentEventDay, EventDay } from '../types/eventDay'
import { apiClient } from './apiClient'

/** Fired after an admin changes the current day so the top bar refreshes. */
export const EVENT_DAY_CHANGED = 'holcim:event-day-changed'

export const eventDayService = {
  current: () => apiClient.get<CurrentEventDay>('/event-days/current'),
  async list(eventId: number): Promise<EventDay[]> {
    return (await apiClient.get<{ days: EventDay[] }>(`/events/${eventId}/days`)).days
  },
  async create(eventId: number, input: { event_date: string; label: string }): Promise<EventDay> {
    return (await apiClient.post<{ day: EventDay }>(`/events/${eventId}/days`, input)).day
  },
  async update(dayId: number, input: { event_date: string; label: string }): Promise<EventDay> {
    return (await apiClient.put<{ day: EventDay }>(`/event-days/${dayId}`, input)).day
  },
  async activate(dayId: number): Promise<EventDay> {
    const day = (await apiClient.patch<{ day: EventDay }>(`/event-days/${dayId}/activate`, {})).day
    window.dispatchEvent(new Event(EVENT_DAY_CHANGED))
    return day
  },
}
