import { LoaderCircle } from 'lucide-react'
import { cn } from '../../utils/cn'

interface SpinnerProps {
  label?: string
  className?: string
}

export function Spinner({ label = 'Loading…', className }: SpinnerProps) {
  return (
    <div role="status" className={cn('flex items-center justify-center gap-2 py-10 text-sm text-slate-500', className)}>
      <LoaderCircle className="size-5 animate-spin text-brand-600" aria-hidden />
      <span>{label}</span>
    </div>
  )
}
