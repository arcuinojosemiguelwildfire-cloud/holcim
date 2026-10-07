import type { CurrentEventDay, EventDay } from '../types/eventDay'
import type { RegistrationSession, RegistrationSessionInput } from '../types/registrationSession'
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

  // Registration sessions (Phase 10, admin only).
  async sessions(dayId: number): Promise<RegistrationSession[]> {
    return (await apiClient.get<{ sessions: RegistrationSession[] }>(`/event-days/${dayId}/sessions`)).sessions
  },
  async createSession(dayId: number, input: RegistrationSessionInput): Promise<RegistrationSession> {
    return (await apiClient.post<{ session: RegistrationSession }>(`/event-days/${dayId}/sessions`, input)).session
  },
  async updateSession(sessionId: number, input: RegistrationSessionInput): Promise<RegistrationSession> {
    return (await apiClient.put<{ session: RegistrationSession }>(`/registration-sessions/${sessionId}`, input)).session
  },
  /** Makes the session the current one of its day (scans go there). */
  async activateSession(sessionId: number): Promise<RegistrationSession> {
    const session = (await apiClient.patch<{ session: RegistrationSession }>(`/registration-sessions/${sessionId}/activate`, {})).session
    window.dispatchEvent(new Event(EVENT_DAY_CHANGED))
    return session
  },
}
