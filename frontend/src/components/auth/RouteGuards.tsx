import { Navigate, Outlet, useLocation } from 'react-router'
import { useAuth } from '../../hooks/useAuth'
import { homePathFor } from '../../layouts/navigation'
import type { UserRole } from '../../types/auth'
import { Spinner } from '../ui/Spinner'

function FullPageSpinner() {
  return (
    <div className="flex min-h-screen items-center justify-center">
      <Spinner label="Checking your session…" />
    </div>
  )
}

/** Renders child routes only for signed-in users; otherwise redirects to /login. */
export function RequireAuth() {
  const { status } = useAuth()
  const location = useLocation()

  if (status === 'loading') return <FullPageSpinner />
  if (status === 'unauthenticated') {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />
  }
  return <Outlet />
}

/**
 * Keeps signed-in users away from the login page. After a successful login
 * this sends the user to the page they originally requested (if any).
 */
export function GuestOnly() {
  const { status } = useAuth()
  const location = useLocation()

  if (status === 'loading') return <FullPageSpinner />
  if (status === 'authenticated') {
    const from = (location.state as { from?: string } | null)?.from
    return <Navigate to={from && from.startsWith('/') ? from : '/'} replace />
  }
  return <Outlet />
}

/**
 * Renders child routes only for the given roles; other roles are sent to
 * their home page (scanner operators -> /registration). The API enforces the
 * same rules server-side; this only avoids showing pages that would fail.
 */
export function RequireRole({ roles }: { roles: UserRole[] }) {
  const { user } = useAuth()
  if (!user) return null
  if (!roles.includes(user.role)) {
    const home = homePathFor(user.role)
    return <Navigate to={home} replace />
  }
  return <Outlet />
}
