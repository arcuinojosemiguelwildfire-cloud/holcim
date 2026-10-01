import { cn } from '../../utils/cn'

/** Neutral text wordmark (no client logo artwork is bundled). */
export function BrandMark({ inverted = false }: { inverted?: boolean }) {
  return (
    <div className="flex items-center gap-2.5">
      <div className="flex size-8 items-center justify-center rounded-lg bg-brand-700 text-sm font-bold text-white">H</div>
      <div className="leading-tight">
        <p className={cn('text-sm font-semibold', inverted ? 'text-white' : 'text-slate-900')}>Holcim</p>
        <p className={cn('text-xs', inverted ? 'text-slate-400' : 'text-slate-500')}>Event System</p>
      </div>
    </div>
  )
}
