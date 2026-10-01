import { ButtonLink } from '../components/ui/Button'

export function NotFoundPage() {
  return (
    <div className="flex flex-col items-center py-20 text-center">
      <p className="text-sm font-semibold text-brand-700">404</p>
      <h1 className="mt-2 text-2xl font-semibold text-slate-900">Page not found</h1>
      <p className="mt-2 text-sm text-slate-500">The page you are looking for does not exist.</p>
      <ButtonLink to="/" variant="secondary" className="mt-6">
        Back to dashboard
      </ButtonLink>
    </div>
  )
}
