import { Construction } from 'lucide-react'
import { Card } from '../components/ui/Card'
import { PageHeader } from '../components/ui/PageHeader'

interface ComingSoonPageProps {
  title: string
  /** What the module will do once it is built. */
  plannedFeatures: string[]
}

/**
 * Honest placeholder for modules that are not built yet. Deliberately has no
 * fake data or non-working controls.
 */
export function ComingSoonPage({ title, plannedFeatures }: ComingSoonPageProps) {
  return (
    <>
      <PageHeader title={title} />
      <Card className="p-8">
        <div className="flex flex-col items-center text-center">
          <span className="flex size-12 items-center justify-center rounded-full bg-amber-50 text-amber-600 ring-1 ring-amber-200">
            <Construction className="size-6" aria-hidden />
          </span>
          <p className="mt-4 inline-flex rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-amber-800 ring-1 ring-amber-200">
            Coming Soon
          </p>
          <h2 className="mt-3 text-lg font-semibold text-slate-900">{title} is not available yet</h2>
          <p className="mt-1 max-w-md text-sm text-slate-500">
            This module is planned for a later development phase. Nothing on this page is functional yet.
          </p>
        </div>
        <div className="mx-auto mt-8 max-w-md">
          <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Planned</h3>
          <ul className="mt-3 space-y-2 text-sm text-slate-600">
            {plannedFeatures.map((feature) => (
              <li key={feature} className="flex gap-2">
                <span className="mt-2 size-1.5 shrink-0 rounded-full bg-slate-300" aria-hidden />
                {feature}
              </li>
            ))}
          </ul>
        </div>
      </Card>
    </>
  )
}
