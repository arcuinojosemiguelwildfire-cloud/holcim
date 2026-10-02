import { useCallback, useEffect, useRef, useState } from 'react'
import { Maximize, Minimize, Trophy, Users } from 'lucide-react'
import { useFullscreen } from '../../hooks/useFullscreen'
import { errorMessage } from '../../services/apiClient'
import { cn } from '../../utils/cn'
import { formatNumber } from '../../utils/format'

export interface DrawWinner {
  attendeeCode: string
  fullName: string
  company: string | null
  department: string | null
}

export interface DrawOutcome {
  /** Server ID of the recorded draw (used for voiding). */
  drawId: number
  winner: DrawWinner
  /** Other eligible names shown while rolling (a sample, not the full pool). */
  rollNames: string[]
}

interface RandomizerStageProps {
  /** e.g. "Minor Randomizer" */
  title: string
  eventName: string | null
  eligibleCount: number | null
  /** Performs the server-side draw. */
  onDraw: () => Promise<DrawOutcome>
  /** Marks a draw VOID (record is kept). Omit to hide the control. */
  onVoid?: (drawId: number, reason: string) => Promise<void>
}

type Phase = 'ready' | 'rolling' | 'winner'

const ROLL_MS = 3200

function shuffle<T>(items: T[]): T[] {
  const copy = [...items]
  for (let i = copy.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1))
    ;[copy[i], copy[j]] = [copy[j] as T, copy[i] as T]
  }
  return copy
}

/** Placeholder names for very small pools so the roll still looks alive. */
function scramble(name: string): string {
  const letters = name.replace(/\s+/g, '').split('')
  return name.replace(/\S/g, () => letters[Math.floor(Math.random() * letters.length)] ?? '•')
}

/**
 * Stage-style draw screen (reusable for the Major Randomizer).
 * The winner is decided by the server; this component only animates it.
 * The stage element itself goes fullscreen, so the admin chrome disappears.
 */
export function RandomizerStage({ title, eventName, eligibleCount, onDraw, onVoid }: RandomizerStageProps) {
  const stageRef = useRef<HTMLDivElement>(null)
  const { isFullscreen, supported, enter, exit } = useFullscreen(stageRef)
  const [phase, setPhase] = useState<Phase>('ready')
  const [rollingName, setRollingName] = useState('')
  const [winner, setWinner] = useState<DrawWinner | null>(null)
  const [drawId, setDrawId] = useState<number | null>(null)
  const [voided, setVoided] = useState<string | null>(null)
  const [voidOpen, setVoidOpen] = useState(false)
  const [voidReason, setVoidReason] = useState('Winner not present')
  const [voiding, setVoiding] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const timers = useRef<number[]>([])

  useEffect(() => () => timers.current.forEach((t) => window.clearTimeout(t)), [])

  const empty = eligibleCount === 0

  const start = useCallback(async () => {
    if (phase === 'rolling' || empty || voidOpen) return
    setError(null)
    setWinner(null)
    setDrawId(null)
    setVoided(null)
    setPhase('rolling')
    setRollingName('• • •')

    let outcome: DrawOutcome
    try {
      outcome = await onDraw()
    } catch (caught) {
      setError(errorMessage(caught))
      setPhase('ready')
      return
    }

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    const names = outcome.rollNames.length >= 3
      ? shuffle([...outcome.rollNames, outcome.winner.fullName])
      : Array.from({ length: 12 }, () => scramble(outcome.winner.fullName))

    // Fast at first, then slowing down before landing on the winner.
    const total = reduceMotion ? 600 : ROLL_MS
    let elapsed = 0
    let index = 0
    const tick = () => {
      const progress = elapsed / total
      const delay = 50 + Math.pow(progress, 3) * 350
      setRollingName(names[index % names.length] ?? '')
      index++
      elapsed += delay
      if (elapsed < total) {
        timers.current.push(window.setTimeout(tick, delay))
      } else {
        timers.current.push(
          window.setTimeout(() => {
            setWinner(outcome.winner)
            setDrawId(outcome.drawId)
            setPhase('winner')
          }, delay),
        )
      }
    }
    tick()
  }, [empty, onDraw, phase, voidOpen])

  const confirmVoid = async () => {
    if (!onVoid || drawId === null) return
    setVoiding(true)
    setError(null)
    try {
      const reason = voidReason.trim()
      await onVoid(drawId, reason)
      setVoided(reason || 'Voided')
      setVoidOpen(false)
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setVoiding(false)
    }
  }

  // Presenter remote / keyboard: Space or Enter draws while fullscreen.
  useEffect(() => {
    if (!isFullscreen) return
    const onKey = (event: KeyboardEvent) => {
      if (voidOpen) return
      if (event.key === ' ' || event.key === 'Enter') {
        event.preventDefault()
        void start()
      }
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [isFullscreen, start, voidOpen])

  return (
    <div
      ref={stageRef}
      className={cn(
        'relative flex flex-col overflow-hidden bg-slate-950 text-white',
        'bg-[radial-gradient(ellipse_at_top,rgba(20,184,166,0.28),transparent_60%),radial-gradient(ellipse_at_bottom,rgba(15,118,110,0.25),transparent_55%)]',
        isFullscreen ? 'h-screen w-screen' : 'min-h-[560px] rounded-2xl shadow-lg ring-1 ring-slate-900/10',
      )}
    >
      {/* Header */}
      <div className={cn('flex items-start justify-between gap-4', isFullscreen ? 'px-[3vw] pt-[3vh]' : 'px-6 pt-6 sm:px-8')}>
        <div className="min-w-0">
          <p className={cn('font-semibold uppercase tracking-[0.3em] text-brand-200', isFullscreen ? 'text-[1.6vw]' : 'text-xs sm:text-sm')}>
            {title}
          </p>
          <p className={cn('mt-1 truncate font-medium text-slate-300', isFullscreen ? 'text-[1.3vw]' : 'text-sm')}>{eventName ?? '—'}</p>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <span className={cn('inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 font-medium text-slate-200', isFullscreen ? 'text-[1.1vw]' : 'text-sm')}>
            <Users className="size-4" aria-hidden />
            {eligibleCount === null ? '…' : `${formatNumber(eligibleCount)} eligible`}
          </span>
          {supported && (
            <button
              type="button"
              onClick={() => void (isFullscreen ? exit() : enter())}
              className={cn(
                'inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-medium transition-colors',
                isFullscreen ? 'text-white/40 hover:bg-white/10 hover:text-white' : 'bg-white/10 text-slate-200 hover:bg-white/20',
              )}
              aria-label={isFullscreen ? 'Exit fullscreen' : 'Enter fullscreen'}
            >
              {isFullscreen ? <Minimize className="size-4" aria-hidden /> : <Maximize className="size-4" aria-hidden />}
              {!isFullscreen && 'Enter fullscreen'}
            </button>
          )}
        </div>
      </div>

      {/* Centre */}
      <div className="flex flex-1 flex-col items-center justify-center px-6 py-10 text-center" aria-live="polite">
        {empty ? (
          <p className={cn('font-semibold text-slate-300', isFullscreen ? 'text-[3vw]' : 'text-2xl sm:text-3xl')}>No eligible attendees yet.</p>
        ) : phase === 'rolling' ? (
          <p
            className={cn(
              'max-w-full truncate font-extrabold uppercase tracking-tight text-white/90',
              isFullscreen ? 'text-[6vw]' : 'text-4xl sm:text-6xl',
            )}
          >
            {rollingName}
          </p>
        ) : phase === 'winner' && winner ? (
          <div className="flex max-w-full flex-col items-center">
            {voided ? (
              <span
                className={cn(
                  'inline-flex items-center gap-3 rounded-full bg-red-500 px-6 py-2 font-black uppercase tracking-[0.25em] text-white',
                  isFullscreen ? 'text-[2.2vw]' : 'text-lg sm:text-xl',
                )}
              >
                Void
              </span>
            ) : (
              <span
                className={cn(
                  'inline-flex items-center gap-3 rounded-full bg-amber-400 px-6 py-2 font-black uppercase tracking-[0.25em] text-slate-950 shadow-lg shadow-amber-500/30',
                  isFullscreen ? 'text-[2.2vw]' : 'text-lg sm:text-xl',
                )}
              >
                <Trophy className={isFullscreen ? 'size-[2.4vw]' : 'size-6'} aria-hidden /> Winner
              </span>
            )}
            <p
              className={cn(
                'mt-[3vh] max-w-full break-words font-black uppercase leading-[1.05] tracking-tight',
                voided && 'text-white/40 line-through decoration-red-500',
                isFullscreen ? 'text-[7.5vw]' : 'text-5xl sm:text-7xl',
              )}
            >
              {winner.fullName}
            </p>
            {winner.company && (
              <p className={cn('mt-[1.5vh] font-semibold text-brand-200', isFullscreen ? 'text-[3vw]' : 'text-2xl sm:text-3xl')}>
                {winner.company}
              </p>
            )}
            {winner.department && (
              <p className={cn('mt-[0.8vh] font-medium text-slate-200', isFullscreen ? 'text-[2.2vw]' : 'text-lg sm:text-xl')}>
                {winner.department}
              </p>
            )}
            <p className={cn('mt-[1.5vh] font-mono font-bold text-slate-300', isFullscreen ? 'text-[2.6vw]' : 'text-xl sm:text-2xl')}>
              {winner.attendeeCode}
            </p>
            {voided && <p className={cn('mt-[1.5vh] text-red-200', isFullscreen ? 'text-[1.6vw]' : 'text-base')}>Draw voided: {voided}</p>}
          </div>
        ) : (
          <p className={cn('font-bold uppercase tracking-[0.2em] text-slate-400', isFullscreen ? 'text-[3vw]' : 'text-2xl sm:text-4xl')}>
            Ready to draw
          </p>
        )}
        {error && <p className="mt-6 rounded-lg bg-red-500/20 px-4 py-2 text-base text-red-100">{error}</p>}
      </div>

      {/* Void (operator control, kept small) */}
      {onVoid && phase === 'winner' && drawId !== null && !voided && (
        <div className={cn('absolute left-4 z-10', isFullscreen ? 'bottom-[3vh]' : 'bottom-6 sm:left-6')}>
          {voidOpen ? (
            <div className="w-72 rounded-xl bg-slate-900/95 p-4 text-left shadow-xl ring-1 ring-white/10">
              <p className="text-sm font-semibold text-white">Void this draw?</p>
              <p className="mt-1 text-xs text-slate-400">The draw stays in history marked VOID. Eligibility is not changed.</p>
              <label htmlFor="void-reason" className="mt-3 block text-xs font-medium text-slate-300">Reason (optional)</label>
              <input
                id="void-reason"
                value={voidReason}
                maxLength={200}
                onChange={(e) => setVoidReason(e.target.value)}
                onKeyDown={(e) => e.stopPropagation()}
                className="mt-1 h-9 w-full rounded-md border-0 bg-white/10 px-2 text-sm text-white ring-1 ring-inset ring-white/20 focus:ring-2 focus:ring-brand-500"
              />
              <div className="mt-3 flex justify-end gap-2">
                <button key="void-cancel" type="button" onClick={() => setVoidOpen(false)} disabled={voiding}
                  className="rounded-md px-3 py-1.5 text-sm text-slate-300 hover:bg-white/10">Cancel</button>
                <button key="void-confirm" type="button" onClick={() => void confirmVoid()} disabled={voiding}
                  className="rounded-md bg-red-500 px-3 py-1.5 text-sm font-semibold text-white hover:bg-red-600 disabled:opacity-60">
                  {voiding ? 'Voiding…' : 'Void draw'}
                </button>
              </div>
            </div>
          ) : (
            <button
              type="button"
              onClick={() => setVoidOpen(true)}
              className="rounded-full px-3 py-1.5 text-sm font-medium text-white/40 ring-1 ring-white/15 hover:bg-white/10 hover:text-white"
            >
              Void draw
            </button>
          )}
        </div>
      )}

      {/* Controls */}
      <div className={cn('flex justify-center', isFullscreen ? 'pb-[5vh]' : 'pb-8')}>
        <button
          type="button"
          onClick={() => void start()}
          disabled={phase === 'rolling' || empty || eligibleCount === null}
          className={cn(
            'rounded-full bg-brand-500 font-black uppercase tracking-[0.2em] text-slate-950 shadow-lg shadow-brand-500/30 transition',
            'hover:bg-brand-200 disabled:cursor-not-allowed disabled:opacity-40',
            isFullscreen ? 'px-[4vw] py-[2vh] text-[1.8vw]' : 'px-10 py-4 text-lg',
          )}
        >
          {phase === 'rolling' ? 'Drawing…' : phase === 'winner' ? 'Next draw' : 'Start draw'}
        </button>
      </div>
      {isFullscreen && (
        <p className="pointer-events-none absolute bottom-2 left-0 right-0 text-center text-[0.8vw] text-white/25">
          Space / Enter to draw · Esc to exit fullscreen
        </p>
      )}
    </div>
  )
}
