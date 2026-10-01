import {
  CalendarDays,
  ChartColumn,
  Dices,
  LayoutDashboard,
  ScanLine,
  Settings,
  Trophy,
  Users,
  type LucideIcon,
} from 'lucide-react'
import type { UserRole } from '../types/auth'

export interface NavItem {
  label: string
  path: string
  icon: LucideIcon
  /** Roles that can see the item. Omit = every signed-in role. */
  roles?: UserRole[]
  /** Shown as a "Soon" tag until the module is built. */
  comingSoon?: boolean
}

/**
 * Sidebar navigation. As modules are delivered, remove `comingSoon` and
 * restrict `roles` where needed (e.g. Registration -> registration_staff).
 */
export const NAV_ITEMS: NavItem[] = [
  { label: 'Dashboard', path: '/', icon: LayoutDashboard },
  { label: 'Events', path: '/events', icon: CalendarDays },
  { label: 'Attendees', path: '/attendees', icon: Users, comingSoon: true },
  { label: 'Registration', path: '/registration', icon: ScanLine, comingSoon: true },
  { label: 'Minor Randomizer', path: '/minor-randomizer', icon: Dices, comingSoon: true },
  { label: 'Major Randomizer', path: '/major-randomizer', icon: Trophy, comingSoon: true },
  { label: 'Reports', path: '/reports', icon: ChartColumn, comingSoon: true },
  { label: 'Settings', path: '/settings', icon: Settings, comingSoon: true },
]
