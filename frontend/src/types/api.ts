/** Shapes shared with the PHP API (see backend/core/Response.php). */

export interface ApiSuccess<T> {
  success: true
  data: T
  message?: string
}

export interface ApiErrorPayload {
  code: string
  message: string
  details?: {
    fields?: Record<string, string[]>
    [key: string]: unknown
  }
}

export interface ApiFailure {
  success: false
  error: ApiErrorPayload
}

export type ApiEnvelope<T> = ApiSuccess<T> | ApiFailure
