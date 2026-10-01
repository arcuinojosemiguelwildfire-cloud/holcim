import { useRef } from 'react'
import { Maximize, Minimize } from 'lucide-react'
import { QrImage } from '../components/qr/QrImage'
import { Alert } from '../components/ui/Alert'
import { Spinner } from '../components/ui/Spinner'
import { useApiQuery } from '../hooks/useApiQuery'
import { useFullscreen } from '../hooks/useFullscreen'
import { apiClient } from '../services/apiClient'
import { cn } from '../utils/cn'

interface MajorFormInfo {
  configured: boolean
  formUrl: string | null
  qrUrl: string | null
}

const fetchInfo = () => apiClient.get<MajorFormInfo>('/major-form/info')

function fallbackQrUrl(): string {
  return `${window.location.origin}${import.meta.env.BASE_URL.replace(/\/+$/, '')}/major-form`
}

/** LED/projector screen with the Major QR. The QR encodes the stable /major-form route. */
export function MajorQrPage() {
  const { data, error, loading } = useApiQuery(fetchInfo)
  const stageRef = useRef<HTMLDivElement>(null)
  const { isFullscreen, supported, enter, exit } = useFullscreen(stageRef)
  const qrUrl = data?.qrUrl ?? fallbackQrUrl()
  const isLocal = /localhost|127\.0\.0\.1|\/\/192\.168\.|\/\/10\./i.test(qrUrl)

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight text-slate-900">Major QR</h1>
        <p className="mt-1 text-sm text-slate-500">
          Show this on the LED screen. It opens the client's form; eligibility still comes from importing the exported responses.
        </p>
      </div>

      {error && <Alert tone="error">{error}</Alert>}
      {data && !data.configured && (
        <Alert tone="error" title="Major form link is not configured">
          Set MAJOR_FORM_URL in the server's backend/.env. Until then, people who scan see a “form not available yet” page.
        </Alert>
      )}
      {data && isLocal && (
        <Alert tone="error" title="This QR points to a local address">
          {qrUrl} only works on this computer/network. Set APP_URL to the public HTTPS address before showing it at the event.
        </Alert>
      )}
      {data?.configured && (
        <p className="text-sm text-slate-500">
          Redirects to: <span className="break-all font-mono text-slate-700">{data.formUrl}</span>
        </p>
      )}

      {loading && !data ? (
        <Spinner />
      ) : (
        <div
          ref={stageRef}
          className={cn(
            'relative flex flex-col items-center justify-center bg-white text-center text-slate-950',
            isFullscreen ? 'h-screen w-screen gap-[3vh] p-[4vh]' : 'gap-6 rounded-2xl p-8 shadow-sm ring-1 ring-slate-200 sm:p-12',
          )}
        >
          {supported && (
            <button
              type="button"
              onClick={() => void (isFullscreen ? exit() : enter())}
              aria-label={isFullscreen ? 'Exit fullscreen' : 'Enter fullscreen'}
              className={cn(
                'absolute right-4 top-4 inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-sm font-medium',
                isFullscreen ? 'text-slate-300 hover:bg-slate-100 hover:text-slate-700' : 'bg-slate-100 text-slate-700 hover:bg-slate-200',
              )}
            >
              {isFullscreen ? <Minimize className="size-4" aria-hidden /> : <Maximize className="size-4" aria-hidden />}
              {!isFullscreen && 'Enter fullscreen'}
            </button>
          )}
          <p className={cn('font-black uppercase tracking-[0.15em]', isFullscreen ? 'text-[4.5vh]' : 'text-2xl sm:text-4xl')}>
            Scan to join the Major Draw
          </p>
          <QrImage
            payload={qrUrl}
            label="Major draw form QR code"
            className={isFullscreen ? 'w-[min(62vh,80vw)]' : 'w-full max-w-[420px]'}
          />
          <p className={cn('max-w-3xl font-medium text-slate-600', isFullscreen ? 'text-[2.6vh]' : 'text-base sm:text-lg')}>
            Complete the form to become eligible for the Major Randomizer.
          </p>
          <p className={cn('font-mono text-slate-400', isFullscreen ? 'text-[1.8vh]' : 'text-xs')}>{qrUrl}</p>
        </div>
      )}
    </div>
  )
}
