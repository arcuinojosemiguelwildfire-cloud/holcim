import { useCallback, useEffect, useState } from 'react'
import { errorMessage } from '../services/apiClient'

interface QueryState<T> {
  data: T | null
  error: string | null
  loading: boolean
}

/**
 * Loads data from an API call and exposes { data, error, loading, reload }.
 * Stale responses (from an earlier reload or after unmount) are ignored.
 *
 * `fetcher` should be a stable reference (module-level function or useCallback).
 */
export function useApiQuery<T>(fetcher: () => Promise<T>) {
  const [state, setState] = useState<QueryState<T>>({ data: null, error: null, loading: true })
  const [version, setVersion] = useState(0)

  useEffect(() => {
    let cancelled = false

    fetcher().then(
      (data) => {
        if (!cancelled) setState({ data, error: null, loading: false })
      },
      (error: unknown) => {
        if (!cancelled) setState((previous) => ({ ...previous, error: errorMessage(error), loading: false }))
      },
    )

    return () => {
      cancelled = true
    }
  }, [fetcher, version])

  const reload = useCallback(() => {
    setState((previous) => ({ ...previous, loading: true }))
    setVersion((current) => current + 1)
  }, [])

  return { ...state, reload }
}
