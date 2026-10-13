import { useCallback, useEffect, useRef, useState } from 'react'
import { Maximize, Minimize } from 'lucide-react'
import { useFullscreen } from '../../hooks/useFullscreen'
import { ApiError, errorMessage } from '../../services/apiClient'
import { predrawnService, type OperatorNotice, type PredrawnWinnerDisplay } from '../../services/predrawnService'
import { stageBackgroundStyle } from '../../services/randomizerBackgroundService'
import { cn } from '../../utils/cn'

type Phase = 'ready' | 'rolling' | 'landing' | 'winner'

interface PredrawnStageProps {
  eventName: string | null
  backgroundUrl: string | null
  /** Operator-only notices (shown in the admin view / a second window, never on the LED screen). */
  notify: (notice: Omit<OperatorNotice, 'id' | 'at'>) => void
  /** Called after a successful reveal (refresh the assignment list). */
  onRevealed: () => void
}

const ROLL_TICK_MS = 70
const LAND_STEPS = [90, 120, 160, 210, 270, 340, 420]

/** Keys typed into form fields or while a dialog is open are never shortcuts. */
function isTypingTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false
  return target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) || target.closest('dialog[open]') !== null
}

function scramble(name: string): string {
  const letters = name.replace(/\s+/g, '').split('')
  return name.replace(/\S/g, () => letters[Math.floor(Math.random() * letters.length)] ?? '•')
}

/**
 * Pre-Drawn Winner Reveal screen. The winners were chosen before the event
 * (outside this system); this screen only reveals them.
 *
 * Fullscreen = the LED presentation: only the background image until
 * Space starts the rolling names (no server call, nothing recorded). While
 * the names roll, the assigned letter (A-Z) asks the server to record the
 * reveal; only when that succeeds does the roll slow down and stop on the
 * winner (name + prize label). Unassigned letters, letters pressed before
 * Space, repeats and errors never show anything on the LED screen: they go
 * to the operator notices. One reveal request at a time; held-down keys
 * (auto-repeat) and keys typed in form fields are ignored.
 * Backspace / Delete: stop the roll (nothing recorded) or hide the winner.
 */
export function PredrawnStage({ eventName, backgroundUrl, notify, onRevealed }: PredrawnStageProps) {
  const stageRef = useRef<HTMLDivElement>(null)
  const { isFullscreen, supported, enter, exit } = useFullscreen(stageRef)
  const [phase, setPhaseState] = useState<Phase>('ready')
  const [rollingName, setRollingName] = useState('')
  const [winner, setWinner] = useState<PredrawnWinnerDisplay | null>(null)
  const phaseRef = useRef<Phase>('ready')
  const busyRef = useRef(false)
  const namesRef = useRef<string[]>([])
  const timers = useRef<number[]>([])
  const rollTimer = useRef<number | null>(null)

  const setPhase = useCallback((next: Phase) => {
    phaseRef.current = next
    setPhaseState(next)
  }, [])

  const clearTimers = useCallback(() => {
    if (rollTimer.current !== null) window.clearInterval(rollTimer.current)
    rollTimer.current = null
    timers.current.forEach((t) => window.clearTimeout(t))
    timers.current = []
  }, [])

  const refreshNames = useCallback(() => {
    predrawnService.rollNames().then(
      (names) => {
        namesRef.current = names
      },
      () => {
        /* keep the previous sample; the roll falls back to scrambled text */
      },
    )
  }, [])

  useEffect(() => {
    refreshNames()
    return clearTimers
  }, [refreshNames, clearTimers])

  const nextName = useCallback((index: number, fallback = 'Holcim'): string => {
    const names = namesRef.current
    return names.length > 0 ? (names[index % names.length] ?? fallback) : scramble(fallback)
  }, [])

  const startRoll = useCallback(() => {
    clearTimers()
    setWinner(null)
    setPhase('rolling')
    let index = Math.floor(Math.random() * 1000)
    setRollingName(nextName(index++))
    rollTimer.current = window.setInterval(() => setRollingName(nextName(index++)), ROLL_TICK_MS)
    refreshNames() // fresh sample for the next roll
  }, [clearTimers, nextName, refreshNames, setPhase])

  const stopRoll = useCallback(() => {
    clearTimers()
    setWinner(null)
    setPhase('ready')
  }, [clearTimers, setPhase])

  /** Slow down over a few names, then stop on the recorded winner. */
  const land = useCallback((display: PredrawnWinnerDisplay) => {
    clearTimers()
    setPhase('landing')
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    const steps = reduceMotion ? [] : LAND_STEPS
    let elapsed = 0
    let index = Math.floor(Math.random() * 1000)
    for (const step of steps) {
      elapsed += step
      const name = nextName(index++, display.fullName)
      timers.current.push(window.setTimeout(() => setRollingName(name), elapsed))
    }
    timers.current.push(window.setTimeout(() => {
      setWinner(display)
      setPhase('winner')
    }, elapsed + 450))
  }, [clearTimers, nextName, setPhase])

  const reveal = useCallback(async (letter: string) => {
    busyRef.current = true
    notify({ tone: 'info', text: `Letter ${letter}: revealing…` })
    try {
      const result = await predrawnService.reveal(letter)
      // The reveal is recorded; only now is the winner shown.
      if (phaseRef.current === 'rolling') {
        land(result.winner)
      } else {
        // The roll was stopped meanwhile (e.g. fullscreen left): still show what was recorded in the admin view.
        setWinner(result.winner)
        setPhase('winner')
      }
      notify({ tone: 'success', text: `Letter ${letter} revealed: ${result.winner.fullName}${result.winner.prizeLabel ? ` (${result.winner.prizeLabel})` : ''}.` })
      onRevealed()
    } catch (caught) {
      // Nothing was recorded: keep rolling; tell the operator only.
      const code = caught instanceof ApiError ? caught.code : null
      notify({
        tone: 'error',
        text: code === 'NOT_ASSIGNED' ? `Letter ${letter} has no pre-drawn winner. Still rolling.`
          : `Letter ${letter} not revealed: ${errorMessage(caught)} Still rolling.`,
      })
    } finally {
      busyRef.current = false
    }
  }, [land, notify, onRevealed, setPhase])

  // Keyboard (fullscreen presentation only).
  useEffect(() => {
    if (!isFullscreen) return
    const onKey = (event: KeyboardEvent) => {
      if (event.ctrlKey || event.metaKey || event.altKey || isTypingTarget(event.target)) return
      const key = event.key
      const isLetter = /^[a-z]$/i.test(key)
      if (key !== ' ' && key !== 'Backspace' && key !== 'Delete' && !isLetter) return
      event.preventDefault()
      if (event.repeat) return // a held-down key never acts twice
      const phaseNow = phaseRef.current
      if (key === ' ') {
        if (busyRef.current || phaseNow === 'rolling' || phaseNow === 'landing') return
        startRoll()
        return
      }
      if (key === 'Backspace' || key === 'Delete') {
        if (busyRef.current || phaseNow === 'landing') return
        stopRoll()
        return
      }
      const letter = key.toUpperCase()
      if (phaseNow !== 'rolling') {
        notify({ tone: 'info', text: `Letter ${letter} ignored: press Space to start the names first.` })
        return
      }
      if (busyRef.current) return // one reveal request at a time
      void reveal(letter)
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [isFullscreen, notify, reveal, startRoll, stopRoll])

  // Leaving fullscreen during a roll stops it (nothing recorded unless a reveal is in flight).
  useEffect(() => {
    if (!isFullscreen && phaseRef.current === 'rolling' && !busyRef.current) stopRoll()
  }, [isFullscreen, stopRoll])

  const presenting = isFullscreen

  return (
    <div
      ref={stageRef}
      className={cn(
        'relative flex flex-col overflow-hidden bg-slate-950 text-white',
        'bg-[radial-gradient(ellipse_at_top,rgba(20,184,166,0.28),transparent_60%),radial-gradient(ellipse_at_bottom,rgba(15,118,110,0.25),transparent_55%)]',
        presenting ? 'h-screen w-screen cursor-none' : 'min-h-[420px] rounded-2xl shadow-lg ring-1 ring-slate-900/10',
      )}
      style={stageBackgroundStyle(backgroundUrl, !presenting)}
      data-presenting={presenting ? 'true' : 'false'}
      data-phase={phase}
    >
      {!presenting && (
        <div className="flex items-start justify-between gap-4 px-6 pt-6 sm:px-8">
          <div className="min-w-0">
            <p className="text-xs font-semibold uppercase tracking-[0.3em] text-brand-200 sm:text-sm">Pre-Drawn Winner Reveal</p>
            <p className="mt-1 truncate text-sm font-medium text-slate-300">{eventName ?? '—'}</p>
          </div>
          {supported && (
            <button
              type="button"
              onClick={() => void (isFullscreen ? exit() : enter())}
              className="inline-flex shrink-0 items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-sm font-medium text-slate-200 hover:bg-white/20"
            >
              {isFullscreen ? <Minimize className="size-4" aria-hidden /> : <Maximize className="size-4" aria-hidden />}
              Enter fullscreen
            </button>
          )}
        </div>
      )}

      <div className="flex flex-1 flex-col items-center justify-center px-[4vw] py-8 text-center" aria-live="polite">
        {phase === 'rolling' || phase === 'landing' ? (
          <p
            className={cn('max-w-full truncate font-extrabold uppercase tracking-tight text-white [text-shadow:0_0.15em_0.6em_rgba(0,0,0,0.9)]',
              presenting ? 'text-[6vw]' : 'text-4xl sm:text-5xl')}
            data-testid="predrawn-rolling"
          >
            {rollingName}
          </p>
        ) : phase === 'winner' && winner ? (
          <div className="flex max-w-full flex-col items-center [text-shadow:0_0.15em_0.6em_rgba(0,0,0,0.9),0_0_0.15em_rgba(0,0,0,0.8)]" data-testid="predrawn-winner">
            <p className={cn('max-w-full break-words font-black uppercase leading-[1.05] tracking-tight text-white', presenting ? 'text-[7.5vw]' : 'text-5xl sm:text-6xl')}>
              {winner.fullName}
            </p>
            {winner.prizeLabel && (
              <p className={cn('mt-[2vh] font-semibold text-white', presenting ? 'text-[3.2vw]' : 'text-2xl sm:text-3xl')}>{winner.prizeLabel}</p>
            )}
          </div>
        ) : !presenting ? (
          <p className="max-w-md text-sm text-slate-300">
            Fullscreen shows only the background. <b>Space</b> starts the names, then the assigned <b>letter</b> reveals that winner.
          </p>
        ) : null}
      </div>
    </div>
  )
}
