import { useEffect, useMemo, useRef, useState, type ChangeEvent } from 'react'
import { ImageUp, RotateCcw, Trophy } from 'lucide-react'
import { Alert } from '../ui/Alert'
import { Button } from '../ui/Button'
import { Card, CardHeader } from '../ui/Card'
import { Modal } from '../ui/Modal'
import { Spinner } from '../ui/Spinner'
import { useApiQuery } from '../../hooks/useApiQuery'
import { errorMessage } from '../../services/apiClient'
import {
  BACKGROUND_ACCEPT,
  BACKGROUND_MAX_BYTES,
  BACKGROUND_TYPES,
  backgroundImageUrl,
  randomizerBackgroundService,
  stageBackgroundStyle,
} from '../../services/randomizerBackgroundService'
import { cn } from '../../utils/cn'
import { formatDateTime } from '../../utils/format'

const fetchBackground = () => randomizerBackgroundService.current()

/**
 * Settings > Randomizer Background (admin only; the API enforces it).
 * Upload a JPG / PNG / WebP (≤ 10 MB) for the Minor and Major Randomizer
 * screens, or restore the built-in default. A failed upload keeps the
 * current background.
 */
export function RandomizerBackgroundCard() {
  const { data: current, error: loadError, loading, reload } = useApiQuery(fetchBackground)
  const [file, setFile] = useState<File | null>(null)
  const [busy, setBusy] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [message, setMessage] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const input = useRef<HTMLInputElement>(null)

  // The selected file is previewed from a temporary object URL (never saved anywhere).
  const previewUrl = useMemo(() => (file ? URL.createObjectURL(file) : null), [file])
  useEffect(() => () => {
    if (previewUrl) URL.revokeObjectURL(previewUrl)
  }, [previewUrl])

  const clearSelection = () => {
    setFile(null)
    if (input.current) input.current.value = ''
  }

  const choose = (event: ChangeEvent<HTMLInputElement>) => {
    const chosen = event.target.files?.[0] ?? null
    setMessage(null)
    if (!chosen) return clearSelection()
    // Quick checks here; the server validates the real content again.
    if (!BACKGROUND_TYPES.includes(chosen.type) && !/\.(jpe?g|png|webp)$/i.test(chosen.name)) {
      setMessage({ tone: 'error', text: 'Only JPG, JPEG, PNG and WebP images are allowed.' })
      return clearSelection()
    }
    if (chosen.size > BACKGROUND_MAX_BYTES) {
      setMessage({ tone: 'error', text: 'The image must be 10 MB or smaller.' })
      return clearSelection()
    }
    setFile(chosen)
  }

  const upload = async () => {
    if (!file) return
    setBusy(true)
    setMessage(null)
    try {
      await randomizerBackgroundService.upload(file)
      clearSelection()
      reload()
      setMessage({ tone: 'success', text: 'Randomizer background updated. Open the Minor or Major Randomizer to see it.' })
    } catch (caught) {
      setMessage({ tone: 'error', text: `${errorMessage(caught)} The current background was not changed.` })
    } finally {
      setBusy(false)
    }
  }

  const restore = async () => {
    setBusy(true)
    setMessage(null)
    try {
      await randomizerBackgroundService.restoreDefault()
      setConfirming(false)
      clearSelection()
      reload()
      setMessage({ tone: 'success', text: 'The default Randomizer background was restored.' })
    } catch (caught) {
      setConfirming(false)
      setMessage({ tone: 'error', text: errorMessage(caught) })
    } finally {
      setBusy(false)
    }
  }

  const activeUrl = backgroundImageUrl(current)

  return (
    <Card>
      <CardHeader
        title="Randomizer Background"
        description="Background image of the Minor and Major Randomizer screens, including fullscreen. JPG, PNG or WebP, up to 10 MB. A landscape image (e.g. 1920 × 1080) looks best; a dark overlay keeps names readable."
      />
      <div className="grid gap-6 p-5 lg:grid-cols-2">
        <div>
          <p className="mb-2 text-sm font-medium text-slate-700">Current background</p>
          {loading && !current ? (
            <div className="flex aspect-video items-center justify-center rounded-xl bg-slate-100"><Spinner /></div>
          ) : (
            <StagePreview url={activeUrl} label={current?.custom ? 'Custom image' : 'Default background'} />
          )}
          {loadError && <p className="mt-2 text-sm text-red-600">{loadError}</p>}
          {current?.custom && (
            <p className="mt-2 text-xs text-slate-500">
              {current.originalName} · {current.width} × {current.height}px
              {current.updatedAt && <> · changed {formatDateTime(current.updatedAt)}</>}
            </p>
          )}
        </div>

        <div>
          <p className="mb-2 text-sm font-medium text-slate-700">{file ? 'New background (not saved yet)' : 'Upload a new background'}</p>
          {previewUrl ? (
            <StagePreview url={previewUrl} label="Preview" />
          ) : (
            <label
              htmlFor="randomizer-background-file"
              className={cn(
                'flex aspect-video cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-300 text-sm text-slate-500 hover:border-brand-400 hover:text-brand-700',
                busy && 'pointer-events-none opacity-60',
              )}
            >
              <ImageUp className="size-6" aria-hidden />
              Choose an image…
            </label>
          )}
          <input
            ref={input}
            id="randomizer-background-file"
            type="file"
            accept={BACKGROUND_ACCEPT}
            className="sr-only"
            onChange={choose}
            disabled={busy}
          />
          {file && (
            <p className="mt-2 truncate text-xs text-slate-500" data-testid="background-filename">
              {file.name} · {(file.size / (1024 * 1024)).toFixed(1)} MB
            </p>
          )}
          <div className="mt-4 flex flex-wrap gap-2">
            {file ? (
              <>
                <Button onClick={() => void upload()} loading={busy} icon={<ImageUp className="size-4" aria-hidden />}>
                  Upload and save
                </Button>
                <Button variant="ghost" onClick={clearSelection} disabled={busy}>Cancel</Button>
                <Button variant="secondary" onClick={() => input.current?.click()} disabled={busy}>Choose another</Button>
              </>
            ) : (
              <Button variant="secondary" onClick={() => input.current?.click()} disabled={busy} icon={<ImageUp className="size-4" aria-hidden />}>
                Choose image
              </Button>
            )}
            {current?.custom && !file && (
              <Button variant="danger" onClick={() => setConfirming(true)} disabled={busy} icon={<RotateCcw className="size-4" aria-hidden />}>
                Restore Default Background
              </Button>
            )}
          </div>
        </div>
      </div>
      {message && (
        <div className="px-5 pb-5">
          <Alert tone={message.tone} onDismiss={() => setMessage(null)}>{message.text}</Alert>
        </div>
      )}

      <Modal
        open={confirming}
        title="Restore the default background?"
        description="The Minor and Major Randomizer screens go back to the built-in background. The uploaded image is removed. Nothing else changes."
        onClose={() => !busy && setConfirming(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setConfirming(false)} disabled={busy}>Cancel</Button>
            <Button variant="danger" onClick={() => void restore()} loading={busy}>Restore Default Background</Button>
          </>
        }
      >
        <p className="text-sm text-slate-600">Draws, winners, eligibility and every other setting stay as they are.</p>
      </Modal>
    </Card>
  )
}

/** Small 16:9 replica of the Randomizer stage background, with sample text for legibility. */
function StagePreview({ url, label }: { url: string | null; label: string }) {
  return (
    <div
      className={cn(
        'relative flex aspect-video flex-col items-center justify-center overflow-hidden rounded-xl bg-slate-950 text-white ring-1 ring-slate-900/10',
        !url && 'bg-[radial-gradient(ellipse_at_top,rgba(20,184,166,0.28),transparent_60%),radial-gradient(ellipse_at_bottom,rgba(15,118,110,0.25),transparent_55%)]',
      )}
      style={stageBackgroundStyle(url)}
      data-testid="background-preview"
    >
      <span className="absolute left-3 top-3 rounded-full bg-black/40 px-2 py-0.5 text-[11px] font-medium text-white/80">{label}</span>
      <span className="inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-[0.2em] text-amber-300">
        <Trophy className="size-3.5" aria-hidden /> Winner
      </span>
      <span className="mt-1 text-2xl font-extrabold tracking-tight">Juan Dela Cruz</span>
      <span className="mt-0.5 text-xs font-semibold text-brand-200">Sample Company</span>
    </div>
  )
}
