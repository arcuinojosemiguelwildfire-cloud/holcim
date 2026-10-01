import type { LucideIcon } from 'lucide-react'
import { Card } from '../ui/Card'
import { formatNumber } from '../../utils/format'
import type { DashboardMetric } from '../../types/dashboard'

interface StatCardProps {
  label: string
  metric: DashboardMetric
  icon: LucideIcon
  /** Shown under the number while the module behind the metric is not built. */
  pendingNote?: string
  hint?: string
}

export function StatCard({ label, metric, icon: Icon, pendingNote, hint }: StatCardProps) {
  return (
    <Card className="p-5">
      <div className="flex items-start justify-between gap-3">
        <p className="text-sm font-medium text-slate-500">{label}</p>
        <span className="flex size-9 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
          <Icon className="size-5" aria-hidden />
        </span>
      </div>
      <p className="mt-2 text-3xl font-semibold tabular-nums tracking-tight text-slate-900">{formatNumber(metric.value)}</p>
      <p className="mt-1 text-xs text-slate-500">{metric.available ? hint : pendingNote}</p>
    </Card>
  )
}
