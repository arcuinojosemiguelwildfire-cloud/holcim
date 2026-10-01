import {
  CalendarDays,
  ClipboardCheck,
  ChartColumn,
  Dices,
  LayoutDashboard,
  QrCode,
  Presentation,
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
  { label: 'Attendees', path: '/attendees', icon: Users },
  { label: 'QR / ID Generator', path: '/qr-codes', icon: QrCode, roles: ['admin', 'registration_staff'] },
  { label: 'Registration', path: '/registration', icon: ScanLine, roles: ['admin', 'registration_staff'] },
  { label: 'Minor Randomizer', path: '/minor-randomizer', icon: Dices, roles: ['admin', 'event_operator'] },
  { label: 'Major Eligibility', path: '/major-eligibility', icon: ClipboardCheck, roles: ['admin', 'event_operator'] },
  { label: 'Major QR', path: '/major-qr', icon: Presentation, roles: ['admin', 'event_operator'] },
  { label: 'Major Randomizer', path: '/major-randomizer', icon: Trophy, roles: ['admin', 'event_operator'] },
  { label: 'Reports', path: '/reports', icon: ChartColumn, roles: ['admin'] },
  { label: 'Settings', path: '/settings', icon: Settings, comingSoon: true },
]
