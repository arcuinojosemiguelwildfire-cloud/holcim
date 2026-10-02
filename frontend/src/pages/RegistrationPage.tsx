import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { CircleAlert, CircleCheck, CircleX, ScanBarcode, Volume2, VolumeX } from 'lucide-react'
import { Alert } from '../components/ui/Alert'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { AttendeeStatusSearch } from '../components/registration/AttendeeStatusSearch'
import { RecentScansCard } from '../components/registration/RecentScansCard'
import { ApiError, errorMessage } from '../services/apiClient'
import {
  registrationService,
  type RegistrationCounts,
  type RegistrationSummary,
  type ScanCounts,
  type ScanSuccess,
} from '../services/registrationService'
import { beep } from '../utils/beep'
import { cn } from '../utils/cn'
import { formatNumber, formatTime } from '../utils/format'
import { cleanText } from '../utils/qr'

/**
 * Registration with a physical QR scanner (Phase 9.1).
 *
 * USB / Bluetooth scanners in keyboard (HID) mode "type" the decoded QR
 * value into the focused input and press Enter. No camera is used. The
 * input keeps focus between scans, so the operator never needs the mouse:
 * scan -> result -> input cleared and focused -> next scan.
 */

/** How long a result stays large on screen before the panel returns to "Ready". */
const RESULT_MS = 3500

type ScanResult =
  | { kind: 'registered' | 'already_registered'; data: ScanSuccess }
  | { kind: 'error'; code: string; title: string; message: string }

const ERROR_TITLES: Record<string, string> = {
  INVALID_QR: 'Invalid QR Code',
  WRONG_EVENT: 'Invalid Event',
  ATTENDEE_INACTIVE: 'Attendee Inactive',
  NO_ACTIVE_EVENT: 'No active event',
  NO_ACTIVE_DAY: 'No active event day',
}

/** True when keystrokes already go to a control that needs them. */
function isTypingTarget(element: Element | null): boolean {
  if (!element) return false
  const tag = element.tagName
  return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (element as HTMLElement).isContentEditable
}

export function RegistrationPage() {
  const inputRef = useRef<HTMLInputElement>(null)
  const queueRef = useRef<string[]>([])
  const processingRef = useRef(false)
  const resultTimer = useRef<number | undefined>(undefined)

  const [result, setResult] = useState<ScanResult | null>(null)
  const [processing, setProcessing] = useState(false)
  const [focused, setFocused] = useState(false)
  const [summary, setSummary] = useState<RegistrationSummary | null>(null)
  const [summaryError, setSummaryError] = useState<string | null>(null)
  const [sound, setSound] = useState(true)
  const soundRef = useRef(sound)
  const [value, setValue] = useState('')
  const [scansVersion, setScansVersion] = useState(0)

  useEffect(() => {
    soundRef.current = sound
  }, [sound])

  const focusInput = useCallback(() => {
    inputRef.current?.focus({ preventScroll: true })
  }, [])

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

  // Keep the scanner input ready: when a scanner (or keyboard) types while
  // focus is on a button or the page itself, move focus back to the input
  // before the character arrives. Other text fields (attendee lookup) keep
  // their focus.
  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.ctrlKey || event.metaKey || event.altKey) return
      if (isTypingTarget(document.activeElement)) return
      if (event.key.length === 1 || event.key === 'Enter') {
        if (event.key === 'Enter') event.preventDefault() // never "click" a focused button
        focusInput()
      }
    }
    window.addEventListener('keydown', onKeyDown, true)
    return () => window.removeEventListener('keydown', onKeyDown, true)
  }, [focusInput])

  const showResult = useCallback((next: ScanResult) => {
    setResult(next)
    window.clearTimeout(resultTimer.current)
    resultTimer.current = window.setTimeout(() => setResult(null), RESULT_MS)
  }, [])

  useEffect(() => () => window.clearTimeout(resultTimer.current), [])

  const updateCounts = useCallback((counts: RegistrationCounts, scanCounts: ScanCounts) => {
    setSummary((current) => (current ? { ...current, counts, scanCounts } : current))
  }, [])

  /** One scan through the existing registration API (server validates everything). */
  const submitScan = useCallback(
    async (scanned: string) => {
      try {
        const data = await registrationService.scan(scanned)
        if (soundRef.current) beep(data.status === 'registered' ? 'success' : 'warning')
        updateCounts(data.counts, data.scanCounts)
        showResult({ kind: data.status, data })
      } catch (error) {
        if (soundRef.current) beep('error')
        const code = error instanceof ApiError ? error.code : 'ERROR'
        showResult({
          kind: 'error',
          code,
          title: ERROR_TITLES[code] ?? 'Scan failed',
          message: error instanceof ApiError && ERROR_TITLES[code] ? error.message : `${errorMessage(error)} Please scan again.`,
        })
      }
    },
    [showResult, updateCounts],
  )

  /**
   * Scans are processed one at a time in arrival order, so a fast second
   * scan while the first request is in flight is queued, not lost.
   */
  const drainQueue = useCallback(async () => {
    if (processingRef.current) return
    processingRef.current = true
    setProcessing(true)
    try {
      while (queueRef.current.length > 0) {
        const next = queueRef.current.shift() as string
        await submitScan(next)
      }
    } finally {
      processingRef.current = false
      setProcessing(false)
      setScansVersion((n) => n + 1)
      loadSummary()
    }
  }, [loadSummary, submitScan])

  const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const scanned = value.trim()
    setValue('')
    focusInput()
    if (!scanned) return
    queueRef.current.push(scanned)
    void drainQueue()
  }

  const counts = summary?.counts
  const scanCounts = summary?.scanCounts

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-slate-900">Registration</h1>
          <p className="mt-1 text-sm text-slate-500">
            {summary ? (
              <>
                {summary.event.name} — <span className="font-medium text-slate-700" data-testid="scanner-day">{summary.eventDay.displayName}</span>
              </>
            ) : (
              'Scan attendee QR codes with the QR scanner to check them in.'
            )}
          </p>
        </div>
        <Button
          variant="ghost"
          size="sm"
          onClick={() => {
            setSound((on) => !on)
            focusInput()
          }}
          icon={sound ? <Volume2 className="size-4" aria-hidden /> : <VolumeX className="size-4" aria-hidden />}
        >
          Sound {sound ? 'on' : 'off'}
        </Button>
      </div>

      {summaryError && <Alert tone="error">{summaryError}</Alert>}

      <div className="grid grid-cols-3 gap-3 lg:grid-cols-5">
        <Counter label="Total attendees" value={counts?.total} />
        <Counter label="Registered today" value={counts?.registered} tone="good" />
        <Counter label="Remaining" value={counts?.remaining} />
        <Counter label="My scans today" value={scanCounts?.mine} testId="personal-scans" />
        <Counter label="All scans today" value={scanCounts?.all} testId="general-scans" />
      </div>

      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px]">
        <Card className="flex flex-col overflow-hidden">
          <form onSubmit={handleSubmit} className="border-b border-slate-200 px-5 py-4" autoComplete="off">
            <label htmlFor="scan-input" className="flex items-center gap-2 text-base font-semibold text-slate-900">
              <ScanBarcode className="size-5 text-brand-700" aria-hidden />
              Scan Attendee QR
            </label>
            <input
              ref={inputRef}
              id="scan-input"
              name="scan"
              value={value}
              onChange={(e) => setValue(e.target.value)}
              onFocus={() => setFocused(true)}
              onBlur={() => setFocused(false)}
              placeholder="Scan QR here…"
              autoFocus
              autoComplete="off"
              autoCorrect="off"
              autoCapitalize="none"
              spellCheck={false}
              enterKeyHint="go"
              className={cn(
                'mt-2 h-14 w-full rounded-lg border-0 px-4 font-mono text-lg ring-2 ring-inset placeholder:font-sans placeholder:text-slate-400 focus:outline-none',
                focused ? 'ring-brand-600' : 'ring-amber-400',
              )}
            />
            <p
              role="status"
              aria-live="polite"
              className={cn('mt-2 text-sm font-medium', processing ? 'text-slate-500' : focused ? 'text-emerald-700' : 'text-amber-700')}
              data-testid="scanner-status"
            >
              {processing ? 'Checking…' : focused ? 'Ready to scan' : 'Scanner input is not selected. Click the box above (or press any key) before scanning.'}
            </p>
          </form>

          <div className="relative min-h-[300px] flex-1" onClick={focusInput}>
            {result ? (
              <ResultPanel result={result} />
            ) : (
              <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-slate-50 p-6 text-center text-slate-500">
                <ScanBarcode className="size-12 text-slate-300" aria-hidden />
                <p className="text-lg font-medium text-slate-700">Ready for the next attendee</p>
                <p className="max-w-sm text-sm">Point the QR scanner at the attendee&apos;s QR code. The result appears here.</p>
              </div>
            )}
          </div>
        </Card>

        <RecentScansCard refreshKey={scansVersion} />
      </div>

      <AttendeeStatusSearch />
    </div>
  )
}

function Counter({ label, value, tone, testId }: { label: string; value: number | undefined; tone?: 'good'; testId?: string }) {
  return (
    <Card className="px-4 py-3 sm:px-5 sm:py-4" data-testid={testId}>
      <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500 sm:text-xs">{label}</p>
      <p className={cn('mt-1 text-2xl font-bold tabular-nums sm:text-4xl', tone === 'good' ? 'text-emerald-700' : 'text-slate-900')}>
        {value === undefined ? '—' : formatNumber(value)}
      </p>
    </Card>
  )
}

function ResultPanel({ result }: { result: ScanResult }) {
  if (result.kind === 'error') {
    return (
      <div role="alert" data-testid="scan-result" className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-red-600 p-6 text-center text-white">
        <CircleX className="size-12 sm:size-16" aria-hidden />
        <p className="text-2xl font-bold uppercase tracking-wide sm:text-3xl">{result.title}</p>
        <p className="max-w-md text-base text-red-50 sm:text-lg">{result.message}</p>
      </div>
    )
  }

  const { data } = result
  const registered = result.kind === 'registered'
  const name = cleanText(data.attendee.fullName)
  const company = cleanText(data.attendee.company)
  const department = cleanText(data.attendee.department)

  return (
    <div
      role="alert"
      data-testid="scan-result"
      className={cn(
        'absolute inset-0 flex flex-col items-center justify-center gap-1 p-4 text-center text-white sm:gap-2 sm:p-6',
        registered ? 'bg-emerald-600' : 'bg-amber-500',
      )}
    >
      {registered ? <CircleCheck className="size-12" aria-hidden /> : <CircleAlert className="size-12" aria-hidden />}
      <p className="text-xl font-bold uppercase tracking-wide">{registered ? 'Registration Successful' : 'Already Registered'}</p>
      {name && <p className="mt-2 text-3xl font-extrabold uppercase leading-tight sm:text-5xl">{name}</p>}
      {registered && company && <p className="text-xl font-semibold sm:text-2xl">{company}</p>}
      {registered && department && <p className="text-lg font-medium text-white/90 sm:text-xl">{department}</p>}
      {registered ? (
        <p className="mt-2 rounded-full bg-white/20 px-4 py-1 text-sm font-bold uppercase tracking-wide">Minor draw: eligible</p>
      ) : (
        <p className="mt-2 text-base">
          Already registered for Day {data.eventDay.dayNumber} at {formatTime(data.registeredAt)}
          {data.registeredBy ? ` by ${data.registeredBy}` : ''}.
        </p>
      )}
    </div>
  )
}
