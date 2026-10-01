import { useEffect } from 'react'
import { API_BASE_URL } from '../services/apiClient'

/**
 * Public /major-form route (target of the LED-screen QR). Hands over to the
 * server-side redirect, which sends the visitor to MAJOR_FORM_URL.
 */
export function MajorFormRedirectPage() {
  const target = `${API_BASE_URL}/major-form`

  useEffect(() => {
    window.location.replace(target)
  }, [target])

  return (
    <div className="flex min-h-screen items-center justify-center p-6 text-center">
      <p className="text-slate-600">
        Opening the form… <a className="font-semibold text-brand-700" href={target}>Tap here if nothing happens.</a>
      </p>
    </div>
  )
}
