import type { ReactNode } from 'react'
import { CircleAlert, CircleCheck, Info, X } from 'lucide-react'
import { cn } from '../../utils/cn'

type Tone = 'error' | 'success' | 'info'

const TONES: Record<Tone, { container: string; icon: ReactNode }> = {
  error: {
    container: 'bg-red-50 text-red-800 ring-red-200',
    icon: <CircleAlert className="size-5 shrink-0 text-red-500" aria-hidden />,
  },
  success: {
    container: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    icon: <CircleCheck className="size-5 shrink-0 text-emerald-500" aria-hidden />,
  },
  info: {
    container: 'bg-sky-50 text-sky-800 ring-sky-200',
    icon: <Info className="size-5 shrink-0 text-sky-500" aria-hidden />,
  },
}

interface AlertProps {
  tone?: Tone
  title?: string
  children?: ReactNode
  onDismiss?: () => void
  action?: ReactNode
  className?: string
}

export function Alert({ tone = 'info', title, children, onDismiss, action, className }: AlertProps) {
  const style = TONES[tone]
  return (
    <div
      role={tone === 'error' ? 'alert' : 'status'}
      className={cn('flex gap-3 rounded-lg p-4 text-sm ring-1 ring-inset', style.container, className)}
    >
      {style.icon}
      <div className="min-w-0 flex-1">
        {title && <p className="font-medium">{title}</p>}
        {children && <div className={cn(title && 'mt-1')}>{children}</div>}
        {action && <div className="mt-3">{action}</div>}
      </div>
      {onDismiss && (
        <button type="button" onClick={onDismiss} className="-m-1 rounded p-1 opacity-70 hover:opacity-100" aria-label="Dismiss">
          <X className="size-4" aria-hidden />
        </button>
      )}
    </div>
  )
}
