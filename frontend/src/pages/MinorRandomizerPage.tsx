import { useCallback, useEffect, useState } from 'react'
import { RandomizerStage, type DrawOutcome } from '../components/randomizer/RandomizerStage'
import { Alert } from '../components/ui/Alert'
import { Card, CardHeader } from '../components/ui/Card'
import { errorMessage } from '../services/apiClient'
import { randomizerService, type MinorSummary, type RecentWinner } from '../services/randomizerService'

function formatTime(value: string): string {
  const date = new Date(value.replace(' ', 'T'))
  return Number.isNaN(date.getTime()) ? value : date.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', second: '2-digit' })
}

export function MinorRandomizerPage() {
  const [summary, setSummary] = useState<MinorSummary | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [pendingWinners, setPendingWinners] = useState<RecentWinner[] | null>(null)

  const load = useCallback(() => {
    randomizerService.minorSummary().then(
      (data) => {
        setSummary(data)
        setError(null)
      },
      (caught: unknown) => setError(errorMessage(caught)),
    )
  }, [])

  // Initial load + refresh the eligible count as registrations come in.
  useEffect(() => {
    load()
    const interval = window.setInterval(load, 20000)
    return () => window.clearInterval(interval)
  }, [load])

  const draw = useCallback(async (): Promise<DrawOutcome> => {
    const result = await randomizerService.minorDraw()
    setSummary((current) => (current ? { ...current, eligibleCount: result.eligibleCount } : current))
    // Reveal the new history row only after the animation has landed.
    setPendingWinners(result.recentWinners)
    return { winner: result.winner, rollNames: result.rollNames }
  }, [])

  useEffect(() => {
    if (!pendingWinners) return
    const timer = window.setTimeout(() => {
      setSummary((current) => (current ? { ...current, recentWinners: pendingWinners } : current))
      setPendingWinners(null)
    }, 3800)
    return () => window.clearTimeout(timer)
  }, [pendingWinners])

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight text-slate-900">Minor Randomizer</h1>
        <p className="mt-1 text-sm text-slate-500">
          Draws from attendees who have checked in at registration. Press “Enter fullscreen” before showing it on the LED screen or projector.
        </p>
      </div>

      {error && <Alert tone="error">{error}</Alert>}

      <RandomizerStage
        title="Minor Randomizer"
        eventName={summary?.event.name ?? null}
        eligibleCount={summary ? summary.eligibleCount : null}
        onDraw={draw}
      />

      <Card className="overflow-hidden">
        <CardHeader title="Recent winners" description="Latest 10 Minor draws for this event. Winners stay eligible for later draws." />
        {summary && summary.recentWinners.length === 0 ? (
          <p className="px-5 py-8 text-center text-sm text-slate-500">No draws yet.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                <tr>
                  <th scope="col" className="px-5 py-3">Time</th>
                  <th scope="col" className="px-5 py-3">Code</th>
                  <th scope="col" className="px-5 py-3">Name</th>
                  <th scope="col" className="px-5 py-3">Department</th>
                  <th scope="col" className="px-5 py-3">Drawn by</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {summary?.recentWinners.map((row) => (
                  <tr key={row.drawId}>
                    <td className="whitespace-nowrap px-5 py-3 tabular-nums text-slate-500">{formatTime(row.selectedAt)}</td>
                    <td className="whitespace-nowrap px-5 py-3 font-mono text-xs text-slate-700">{row.attendeeCode}</td>
                    <td className="px-5 py-3 font-medium text-slate-900">{row.fullName}</td>
                    <td className="px-5 py-3 text-slate-600">{row.department ?? '—'}</td>
                    <td className="px-5 py-3 text-slate-500">{row.drawnBy ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  )
}
