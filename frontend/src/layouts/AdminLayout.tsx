import { useState } from 'react'
import { Outlet, useLocation } from 'react-router'
import { Sidebar } from '../components/layout/Sidebar'
import { Topbar } from '../components/layout/Topbar'
import { NAV_ITEMS } from './navigation'

function titleForPath(pathname: string): string {
  const match = NAV_ITEMS.find((item) => (item.path === '/' ? pathname === '/' : pathname.startsWith(item.path)))
  return match?.label ?? 'Holcim Event System'
}

/** Sidebar + top navigation + main content area for every signed-in page. */
export function AdminLayout() {
  const [mobileNavOpen, setMobileNavOpen] = useState(false)
  const { pathname } = useLocation()

  return (
    <div className="min-h-screen">
      <Sidebar mobileOpen={mobileNavOpen} onClose={() => setMobileNavOpen(false)} />
      <div className="lg:pl-64">
        <Topbar title={titleForPath(pathname)} onOpenNavigation={() => setMobileNavOpen(true)} />
        <main className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
