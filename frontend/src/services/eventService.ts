import type { EventInput, EventRecord, EventStatus } from '../types/event'
import { apiClient } from './apiClient'

export const eventService = {
  async list(): Promise<EventRecord[]> {
    const data = await apiClient.get<{ events: EventRecord[] }>('/events')
    return data.events
  },

  async create(input: EventInput): Promise<EventRecord> {
    const data = await apiClient.post<{ event: EventRecord }>('/events', input)
    return data.event
  },

  async update(id: number, input: EventInput): Promise<EventRecord> {
    const data = await apiClient.put<{ event: EventRecord }>(`/events/${id}`, input)
    return data.event
  },

  async setStatus(id: number, status: EventStatus): Promise<EventRecord> {
    const data = await apiClient.patch<{ event: EventRecord }>(`/events/${id}/status`, { status })
    return data.event
  },
}
