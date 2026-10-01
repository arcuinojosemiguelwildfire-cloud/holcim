import type { ApiEnvelope, ApiErrorPayload } from '../types/api'

/**
 * Single entry point for talking to the PHP API.
 *
 * - Sends the session cookie (credentials: 'include')
 * - Attaches the CSRF token to every state-changing request
 * - Normalises every failure (HTTP error, invalid JSON, network down) into ApiError
 * - Retries once with a fresh CSRF token if the server says it expired
 */

const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL || '/api').replace(/\/+$/, '')

const CSRF_HEADER = 'X-CSRF-Token'
const STATE_CHANGING_METHODS = new Set(['POST', 'PUT', 'PATCH', 'DELETE'])

type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

export class ApiError extends Error {
  readonly status: number
  readonly code: string
  readonly fieldErrors: Record<string, string[]>

  constructor(status: number, payload: ApiErrorPayload) {
    super(payload.message)
    this.name = 'ApiError'
    this.status = status
    this.code = payload.code
    this.fieldErrors = payload.details?.fields ?? {}
  }

  /** First validation message for a field, if any. */
  fieldError(field: string): string | undefined {
    return this.fieldErrors[field]?.[0]
  }
}

let csrfToken: string | null = null
let unauthorizedHandler: (() => void) | null = null

export function setCsrfToken(token: string | null): void {
  csrfToken = token
}

/** Called when an authenticated request returns 401 (session expired). */
export function onUnauthorized(handler: (() => void) | null): void {
  unauthorizedHandler = handler
}

async function send<T>(method: HttpMethod, path: string, body?: unknown): Promise<T> {
  const isForm = body instanceof FormData
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (body !== undefined && !isForm) headers['Content-Type'] = 'application/json'
  if (STATE_CHANGING_METHODS.has(method) && csrfToken) headers[CSRF_HEADER] = csrfToken

  let response: Response
  try {
    response = await fetch(`${API_BASE_URL}${path}`, {
      method,
      headers,
      credentials: 'include',
      body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
    })
  } catch {
    throw new ApiError(0, {
      code: 'NETWORK_ERROR',
      message: 'Cannot reach the server. Check your connection and that the API is running.',
    })
  }

  let envelope: ApiEnvelope<T> | null
  try {
    envelope = (await response.json()) as ApiEnvelope<T>
  } catch {
    envelope = null
  }

  if (!envelope || typeof envelope !== 'object' || !('success' in envelope)) {
    throw new ApiError(response.status, {
      code: 'INVALID_RESPONSE',
      message: `The server returned an unexpected response (HTTP ${response.status}).`,
    })
  }

  if (!envelope.success) {
    throw new ApiError(response.status, envelope.error)
  }

  return envelope.data
}

async function refreshCsrfToken(): Promise<void> {
  const session = await send<{ csrfToken: string }>('GET', '/auth/session')
  csrfToken = session.csrfToken
}

async function request<T>(method: HttpMethod, path: string, body?: unknown): Promise<T> {
  try {
    return await send<T>(method, path, body)
  } catch (error) {
    if (error instanceof ApiError) {
      if (error.code === 'CSRF_TOKEN_MISMATCH' && STATE_CHANGING_METHODS.has(method)) {
        await refreshCsrfToken()
        return send<T>(method, path, body)
      }
      if (error.status === 401 && path !== '/auth/login') {
        unauthorizedHandler?.()
      }
    }
    throw error
  }
}

export const apiClient = {
  get: <T>(path: string) => request<T>('GET', path),
  post: <T>(path: string, body?: unknown) => request<T>('POST', path, body ?? {}),
  put: <T>(path: string, body: unknown) => request<T>('PUT', path, body),
  patch: <T>(path: string, body: unknown) => request<T>('PATCH', path, body),
  delete: <T>(path: string) => request<T>('DELETE', path),
  /** multipart/form-data upload (the browser sets the boundary header). */
  upload: <T>(path: string, form: FormData) => request<T>('POST', path, form),
}

/** Human-readable message for any thrown value. */
export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) return error.message
  if (error instanceof Error) return error.message
  return 'Something went wrong. Please try again.'
}
