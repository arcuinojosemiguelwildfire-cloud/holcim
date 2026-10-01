import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import QrScanner from 'qr-scanner'
import { CircleAlert, CircleCheck, CircleX, Keyboard, RefreshCw, Video, Volume2, VolumeX } from 'lucide-react'
import { Alert } from '../components/ui/Alert'
import { Button } from '../components/ui/Button'
import { Card, CardHeader } from '../components/ui/Card'
import { ApiError, errorMessage } from '../services/apiClient'
import { registrationService, type RegistrationCounts, type RegistrationSummary, type ScanSuccess } from '../services/registrationService'
import { beep } from '../utils/beep'
import { cn } from '../utils/cn'
import { formatNumber } from '../utils/format'

/** How long a result stays on screen before the scanner is ready again. */
const RESULT_MS = 2500
/** The same QR is ignored for this long after its result closes (still in front of the camera). */
const SAME_CODE_COOLDOWN_MS = 4000

type CameraState = 'starting' | 'running' | 'error'

type ScanResult =
  | { kind: 'registered' | 'already_registered'; data: ScanSuccess }
  | { kind: 'error'; code: string; title: string; message: string }

const ERROR_TITLES: Record<string, string> = {
  INVALID_QR: 'Invalid QR',
  WRONG_EVENT: 'Invalid for this event',
  ATTENDEE_INACTIVE: 'Attendee inactive',
  NO_ACTIVE_EVENT: 'No active event',
}

function formatTime(value: string | null): string {
  if (!value) return ''
  const date = new Date(value.replace(' ', 'T'))
  return Number.isNaN(date.getTime()) ? value : date.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', second: '2-digit' })
}

function cameraErrorMessage(error: unknown): string {
  if (!window.isSecureContext) {
    return 'The camera only works over HTTPS (or on localhost). Open the system through its https:// address.'
  }
  const text = String(error instanceof Error ? `${error.name} ${error.message}` : error)
  if (/NotAllowed|Permission|denied/i.test(text)) {
    return 'Camera access is required for QR scanning. Allow camera access for this site in the browser settings, then try again.'
  }
  if (/not ?found|NotFound|Overconstrained|no camera/i.test(text)) {
    return 'No camera was found on this device. Connect a camera, or use a handheld scanner with the input below.'
  }
  if (/NotSupported/i.test(text)) {
    return 'This browser cannot use the camera on this page. Use an up-to-date Chrome, Edge or Safari over HTTPS, or a handheld scanner with the input below.'
  }
  if (/NotReadable|in use|TrackStart/i.test(text)) {
    return 'The camera is being used by another app or tab. Close it, then try again.'
  }
  return 'The camera could not be started. Try again, or use a handheld scanner with the input below.'
}

export function RegistrationPage() {
  const videoHostRef = useRef<HTMLDivElement>(null)
  const scannerRef = useRef<QrScanner | null>(null)
  const busyRef = useRef(false)
  const lastRef = useRef<{ value: string; until: number }>({ value: '', until: 0 })
  const resultTimer = useRef<number | undefined>(undefined)

  const [camera, setCamera] = useState<CameraState>('starting')
  const [cameraError, setCameraError] = useState<string | null>(null)
  const [cameraAttempt, setCameraAttempt] = useState(0)
  const [result, setResult] = useState<ScanResult | null>(null)
  const [processing, setProcessing] = useState(false)
  const [summary, setSummary] = useState<RegistrationSummary | null>(null)
  const [summaryError, setSummaryError] = useState<string | null>(null)
  const [sound, setSound] = useState(true)
  const soundRef = useRef(sound)
  const [manualValue, setManualValue] = useState('')

  useEffect(() => {
    soundRef.current = sound
  }, [sound])

  const loadSummary = useCallback(() => {
    registrationService.summary().then(
      (data) => {
        setSummary(data)
        setSummaryError(null)
      },
      (error: unknown) => setSummaryError(errorMessage(error)),
    )
  }, [])

  // Initial load + light polling so counts include other scanners' check-ins.
  useEffect(() => {
    loadSummary()
    const interval = window.setInterval(loadSummary, 15000)
    return () => window.clearInterval(interval)
  }, [loadSummary])

  const showResult = useCallback((next: ScanResult, value: string) => {
    setResult(next)
    window.clearTimeout(resultTimer.current)
    resultTimer.current = window.setTimeout(() => {
      setResult(null)
      lastRef.current = { value, until: Date.now() + SAME_CODE_COOLDOWN_MS }
      busyRef.current = false
    }, RESULT_MS)
  }, [])

  const updateCounts = useCallback((counts: RegistrationCounts) => {
    setSummary((current) => (current ? { ...current, counts } : current))
  }, [])

  /** Scan lock: one request at a time, results shown, then resume. */
  const handleScan = useCallback(
    async (value: string) => {
      const trimmed = value.trim()
      if (!trimmed || busyRef.current) return
      if (trimmed === lastRef.current.value && Date.now() < lastRef.current.until) return
      busyRef.current = true
      setProcessing(true)

      try {
        const data = await registrationService.scan(trimmed)
        if (soundRef.current) beep(data.status === 'registered' ? 'success' : 'warning')
        updateCounts(data.counts)
        if (data.status === 'registered') loadSummary()
        showResult({ kind: data.status, data }, trimmed)
      } catch (error) {
        if (soundRef.current) beep('error')
        const code = error instanceof ApiError ? error.code : 'ERROR'
        showResult(
          {
            kind: 'error',
            code,
            title: ERROR_TITLES[code] ?? 'Scan failed',
            message: error instanceof ApiError && ERROR_TITLES[code] ? error.message : `${errorMessage(error)} Please scan again.`,
          },
          trimmed,
        )
      } finally {
        setProcessing(false)
      }
    },
    [loadSummary, showResult, updateCounts],
  )

  const handleScanRef = useRef(handleScan)
  useEffect(() => {
    handleScanRef.current = handleScan
  }, [handleScan])

  // Camera lifecycle. Each start gets its own <video> element so a previous
  // scanner instance being torn down can never stop the new stream.
  useEffect(() => {
    const host = videoHostRef.current
    if (!host) return
    let disposed = false
    const video = document.createElement('video')
    video.className = 'absolute inset-0 h-full w-full object-cover'
    video.muted = true
    video.playsInline = true
    host.appendChild(video)

    const scanner = new QrScanner(video, (decoded) => void handleScanRef.current(decoded.data), {
      preferredCamera: 'environment',
      highlightScanRegion: false,
      highlightCodeOutline: false,
      maxScansPerSecond: 8,
      returnDetailedScanResult: true,
      // Scan the whole frame (the default only scans a small centre square,
      // which misses QR codes held close to the camera).
      calculateScanRegion: (v) => {
        const scale = Math.min(1, 800 / Math.max(v.videoWidth, v.videoHeight, 1))
        return {
          x: 0,
          y: 0,
          width: v.videoWidth,
          height: v.videoHeight,
          downScaledWidth: Math.round(v.videoWidth * scale),
          downScaledHeight: Math.round(v.videoHeight * scale),
        }
      },
    })
    scannerRef.current = scanner

    scanner.start().then(
      () => {
        if (!disposed) setCamera('running')
      },
      async (error: unknown) => {
        // qr-scanner reports most failures as "Camera not found"; ask the
        // browser directly once to get the real reason (e.g. permission denied).
        let reason: unknown = error
        try {
          const stream = await navigator.mediaDevices.getUserMedia({ video: true })
          stream.getTracks().forEach((track) => track.stop())
        } catch (probeError) {
          reason = probeError
        }
        if (disposed) return
        setCamera('error')
        setCameraError(cameraErrorMessage(reason))
      },
    )

    return () => {
      disposed = true
      scanner.destroy()
      video.remove()
      scannerRef.current = null
    }
  }, [cameraAttempt])

  useEffect(() => () => window.clearTimeout(resultTimer.current), [])

  const retryCamera = () => {
    setCamera('starting')
    setCameraError(null)
    setCameraAttempt((n) => n + 1)
  }

  const submitManual = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const value = manualValue
    setManualValue('')
    void handleScan(value)
  }

  const counts = summary?.counts

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-slate-900">Registration</h1>
          <p className="mt-1 text-sm text-slate-500">{summary ? summary.event.name : 'Scan attendee QR codes to check them in.'}</p>
        </div>
        <Button
          variant="ghost"
          size="sm"
          onClick={() => setSound((value) => !value)}
          icon={sound ? <Volume2 className="size-4" aria-hidden /> : <VolumeX className="size-4" aria-hidden />}
        >
          Sound {sound ? 'on' : 'off'}
        </Button>
      </div>

      {summaryError && <Alert tone="error">{summaryError}</Alert>}

      <div className="grid grid-cols-3 gap-3">
        <Counter label="Total attendees" value={counts?.total} />
        <Counter label="Registered" value={counts?.registered} tone="good" />
        <Counter label="Remaining" value={counts?.remaining} />
      </div>

      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px]">
        <Card className="overflow-hidden">
          <div className="relative aspect-[4/3] w-full bg-slate-900 sm:aspect-video lg:aspect-[4/3]">
            <div ref={videoHostRef} className="absolute inset-0" />

            {camera === 'running' && !result && (
              <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
                <div className="relative aspect-square w-[min(60%,320px)] rounded-2xl shadow-[0_0_0_9999px_rgba(15,23,42,0.45)]">
                  <span className="absolute -left-0.5 -top-0.5 size-10 rounded-tl-2xl border-l-4 border-t-4 border-white" />
                  <span className="absolute -right-0.5 -top-0.5 size-10 rounded-tr-2xl border-r-4 border-t-4 border-white" />
                  <span className="absolute -bottom-0.5 -left-0.5 size-10 rounded-bl-2xl border-b-4 border-l-4 border-white" />
                  <span className="absolute -bottom-0.5 -right-0.5 size-10 rounded-br-2xl border-b-4 border-r-4 border-white" />
                </div>
              </div>
            )}

            {camera === 'starting' && (
              <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 text-slate-300">
                <Video className="size-8" aria-hidden />
                <p className="text-sm">Starting camera… allow camera access if your browser asks.</p>
              </div>
            )}

            {camera === 'error' && (
              <div className="absolute inset-0 flex flex-col items-center justify-center gap-4 bg-slate-900 p-6 text-center">
                <CircleAlert className="size-10 text-amber-400" aria-hidden />
                <p className="max-w-md text-base text-white">{cameraError}</p>
                <Button variant="secondary" onClick={retryCamera} icon={<RefreshCw className="size-4" aria-hidden />}>
                  Try again
                </Button>
              </div>
            )}

            {result && <ResultOverlay result={result} />}
          </div>

          <div className="flex flex-wrap items-center gap-3 border-t border-slate-200 px-4 py-3">
            <p
              role="status"
              aria-live="polite"
              className={cn(
                'flex-1 text-sm font-medium',
                processing ? 'text-slate-500' : camera === 'running' && !result ? 'text-emerald-700' : 'text-slate-500',
              )}
            >
              {processing ? 'Checking…' : result ? 'Showing result…' : camera === 'running' ? 'Ready to scan' : camera === 'starting' ? 'Starting camera…' : 'Camera unavailable'}
            </p>
            <form onSubmit={submitManual} className="flex min-w-0 flex-1 basis-64 items-center gap-2">
              <label htmlFor="manual-scan" className="sr-only">Handheld scanner or manual input</label>
              <Keyboard className="size-4 shrink-0 text-slate-400" aria-hidden />
              <input
                id="manual-scan"
                value={manualValue}
                onChange={(e) => setManualValue(e.target.value)}
                placeholder="Handheld scanner / paste QR value"
                autoComplete="off"
                className="h-9 min-w-0 flex-1 rounded-lg border-0 px-3 text-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-brand-600"
              />
            </form>
          </div>
        </Card>

        <Card className="flex max-h-[640px] flex-col overflow-hidden">
          <CardHeader title="Recent registrations" description="Latest 20 check-ins" />
          {summary && summary.recent.length === 0 ? (
            <p className="px-5 py-8 text-center text-sm text-slate-500">No one has been registered yet.</p>
          ) : (
            <ul className="divide-y divide-slate-100 overflow-y-auto">
              {summary?.recent.map((row) => (
                <li key={`${row.attendeeCode}-${row.registeredAt}`} className="flex items-baseline gap-3 px-5 py-2.5 text-sm">
                  <span className="w-20 shrink-0 tabular-nums text-slate-500">{formatTime(row.registeredAt)}</span>
                  <span className="min-w-0 flex-1">
                    <span className="block truncate font-medium text-slate-900">{row.fullName}</span>
                    <span className="block truncate text-xs text-slate-500">
                      <span className="font-mono">{row.attendeeCode}</span>
                      {row.department && ` · ${row.department}`}
                    </span>
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </div>
  )
}

function Counter({ label, value, tone }: { label: string; value: number | undefined; tone?: 'good' }) {
  return (
    <Card className="px-4 py-3 sm:px-5 sm:py-4">
      <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500 sm:text-xs">{label}</p>
      <p className={cn('mt-1 text-2xl font-bold tabular-nums sm:text-4xl', tone === 'good' ? 'text-emerald-700' : 'text-slate-900')}>
        {value === undefined ? '—' : formatNumber(value)}
      </p>
    </Card>
  )
}

function ResultOverlay({ result }: { result: ScanResult }) {
  if (result.kind === 'error') {
    return (
      <div role="alert" className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-red-600 p-6 text-center text-white">
        <CircleX className="size-10 sm:size-16" aria-hidden />
        <p className="text-2xl font-bold uppercase tracking-wide sm:text-3xl">{result.title}</p>
        <p className="max-w-md text-base text-red-50 sm:text-lg">{result.message}</p>
      </div>
    )
  }

  const { data } = result
  const registered = result.kind === 'registered'

  return (
    <div
      role="alert"
      className={cn(
        'absolute inset-0 flex flex-col items-center justify-center gap-1 p-4 text-center text-white sm:gap-2 sm:p-6',
        registered ? 'bg-emerald-600' : 'bg-amber-500',
      )}
    >
      {registered ? <CircleCheck className="hidden size-14 sm:block" aria-hidden /> : <CircleAlert className="hidden size-14 sm:block" aria-hidden />}
      <p className="text-lg font-bold uppercase tracking-wide sm:text-xl">{registered ? 'Registration successful' : 'Already registered'}</p>
      <p className="mt-2 text-3xl font-extrabold uppercase leading-tight sm:text-5xl">{data.attendee.fullName}</p>
      {data.attendee.department && <p className="text-xl font-medium sm:text-2xl">{data.attendee.department}</p>}
      <p className="font-mono text-lg">{data.attendee.code}</p>
      <p className="mt-2 text-base">
        {registered ? 'Registered' : 'First registered'} {formatTime(data.registeredAt)}
      </p>
      {registered ? (
        <p className="mt-1 rounded-full bg-white/20 px-4 py-1 text-sm font-bold uppercase tracking-wide">Minor draw: eligible</p>
      ) : (
        <p className="text-base">This attendee is already registered.</p>
      )}
    </div>
  )
}
