import { NavLink } from 'react-router'
import { X } from 'lucide-react'
import { useAuth } from '../../hooks/useAuth'
import { NAV_ITEMS } from '../../layouts/navigation'
import { cn } from '../../utils/cn'
import { BrandMark } from './BrandMark'

interface SidebarProps {
  mobileOpen: boolean
  onClose: () => void
}

export function Sidebar({ mobileOpen, onClose }: SidebarProps) {
  const { hasRole } = useAuth()
  const items = NAV_ITEMS.filter((item) => !item.roles || hasRole(...item.roles))

  return (
    <>
      {/* Mobile backdrop */}
      <div
        className={cn(
          'fixed inset-0 z-30 bg-slate-900/40 transition-opacity lg:hidden',
          mobileOpen ? 'opacity-100' : 'pointer-events-none opacity-0',
        )}
        onClick={onClose}
        aria-hidden
      />

      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-slate-900 text-slate-300 transition-transform lg:translate-x-0',
          mobileOpen ? 'translate-x-0' : '-translate-x-full',
        )}
        aria-label="Main navigation"
      >
        <div className="flex h-16 items-center justify-between px-5">
          <BrandMark inverted />
          <button
            type="button"
            onClick={onClose}
            className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-800 hover:text-white lg:hidden"
            aria-label="Close navigation"
          >
            <X className="size-5" aria-hidden />
          </button>
        </div>

        <nav className="flex-1 overflow-y-auto px-3 py-4">
          <ul className="space-y-1">
            {items.map(({ label, path, icon: Icon, comingSoon }) => (
              <li key={path}>
                <NavLink
                  to={path}
                  end={path === '/'}
                  onClick={onClose}
                  className={({ isActive }) =>
                    cn(
                      'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                      isActive ? 'bg-brand-700 text-white' : 'hover:bg-slate-800 hover:text-white',
                    )
                  }
                >
                  {({ isActive }) => (
                    <>
                      <Icon className="size-5 shrink-0" aria-hidden />
                      <span className="flex-1">{label}</span>
                      {comingSoon && (
                        <span
                          className={cn(
                            'rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                            isActive ? 'bg-brand-800 text-brand-100' : 'bg-slate-800 text-slate-400',
                          )}
                        >
                          Soon
                        </span>
                      )}
                    </>
                  )}
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>

        <div className="border-t border-slate-800 px-5 py-4 text-xs text-slate-500">Phase 2 · Attendees</div>
      </aside>
    </>
  )
}
