import { useCallback, useState } from 'react'
import { Download, Printer, QrCode, RefreshCw } from 'lucide-react'
import { Alert } from '../ui/Alert'
import { Badge } from '../ui/Badge'
import { Button } from '../ui/Button'
import { useApiQuery } from '../../hooks/useApiQuery'
import { useAuth } from '../../hooks/useAuth'
import { errorMessage } from '../../services/apiClient'
import { qrService } from '../../services/qrService'
import type { AttendeeQr } from '../../types/qr'
import { downloadQrPng, openQrPrintSheet } from '../../utils/qr'
import { QrCard } from './QrCard'

function formatIssued(value: string): string {
  const date = new Date(value.replace(' ', 'T'))
  return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' })
}

/** QR section of the attendee detail view: show, download, print, generate, regenerate. */
export function AttendeeQrPanel({ attendeeId, attendeeStatus }: { attendeeId: number; attendeeStatus: string }) {
  const { hasRole } = useAuth()
  const canView = hasRole('admin', 'registration_staff')
  const canManage = hasRole('admin')
  const fetcher = useCallback(() => qrService.forAttendee(attendeeId), [attendeeId])
  const { data, error: loadError } = useApiQuery(canView ? fetcher : noop)

  const [updated, setUpdated] = useState<AttendeeQr | null>(null)
  const [confirming, setConfirming] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const qr = updated ?? data

  if (!canView) return null

  const act = async (action: () => Promise<AttendeeQr>, message: string) => {
    setBusy(true)
    setError(null)
    setNotice(null)
    try {
      setUpdated(await action())
      setNotice(message)
      setConfirming(false)
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="rounded-lg border border-slate-200 p-4" aria-label="Attendee QR">
      <div className="flex items-center justify-between gap-2">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Attendee QR</h3>
        {qr && (
          <Badge tone={qr.status === 'generated' ? 'success' : 'warning'} dot>
            {qr.status === 'generated' ? 'Generated' : 'Not generated'}
          </Badge>
        )}
      </div>

      {(loadError || error) && <Alert tone="error" className="mt-3">{error ?? loadError}</Alert>}
      {notice && <Alert tone="success" className="mt-3" onDismiss={() => setNotice(null)}>{notice}</Alert>}

      {qr?.status === 'generated' && qr.qrPayload ? (
        <div className="mt-3 flex flex-col gap-4 sm:flex-row sm:items-start">
          <QrCard
            payload={qr.qrPayload}
            attendeeCode={qr.attendeeCode}
            fullName={qr.fullName}
            company={qr.company}
            department={qr.department}
            className="w-48 shrink-0 self-center sm:self-start"
          />
          <div className="min-w-0 flex-1 space-y-3 text-sm">
            <div>
              <p className="font-mono font-medium text-slate-900">{qr.attendeeCode}</p>
              {qr.generatedAt && <p className="text-slate-500">Generated {formatIssued(qr.generatedAt)}</p>}
            </div>
            <div className="flex flex-wrap gap-2">
              <Button size="sm" variant="secondary" icon={<Download className="size-4" aria-hidden />}
                onClick={() => qr.qrPayload && downloadQrPng(qr.qrPayload, qr).catch((e) => setError(errorMessage(e)))}>
                Download QR
              </Button>
              <Button size="sm" variant="secondary" icon={<Printer className="size-4" aria-hidden />} onClick={() => openQrPrintSheet([qr.attendeeId])}>
                Print
              </Button>
              {canManage && attendeeStatus === 'active' && !confirming && (
                <Button size="sm" variant="ghost" icon={<RefreshCw className="size-4" aria-hidden />} onClick={() => setConfirming(true)}>
                  Regenerate
                </Button>
              )}
            </div>
            {confirming && (
              <div className="rounded-lg bg-amber-50 p-3 ring-1 ring-inset ring-amber-200">
                <p className="font-medium text-amber-900">Regenerate QR?</p>
                <p className="mt-1 text-amber-800">
                  The current QR will stop being valid and a new QR will be created. Any printed copy of the old QR must be replaced.
                </p>
                <div className="mt-3 flex gap-2">
                  <Button key="cancel-regen" size="sm" variant="secondary" onClick={() => setConfirming(false)} disabled={busy}>Cancel</Button>
                  <Button key="confirm-regen" size="sm" variant="danger" loading={busy}
                    onClick={() => act(() => qrService.regenerate(attendeeId), 'New QR created. The previous QR is no longer valid.')}>
                    Regenerate
                  </Button>
                </div>
              </div>
            )}
          </div>
        </div>
      ) : qr ? (
        <div className="mt-3 flex flex-wrap items-center gap-3 text-sm text-slate-500">
          <QrCode className="size-5" aria-hidden />
          <span className="flex-1">No QR has been generated for this attendee.</span>
          {canManage && attendeeStatus === 'active' && (
            <Button size="sm" loading={busy} onClick={() => act(() => qrService.generate(attendeeId), 'QR generated.')}>
              Generate QR
            </Button>
          )}
        </div>
      ) : null}
    </section>
  )
}

const noop = (): Promise<AttendeeQr> => new Promise(() => undefined)
