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

/** Every role except scanner_operator (who only has the scanner). */
export const STAFF_ROLES: UserRole[] = ['admin', 'registration_staff', 'event_operator']
/** Roles that can use the registration scanner. */
export const SCANNER_ROLES: UserRole[] = ['admin', 'registration_staff', 'scanner_operator']
export const RANDOMIZER_ROLES: UserRole[] = ['admin', 'event_operator']

/** Landing page per role (scanner operators go straight to the scanner). */
export function homePathFor(role: UserRole | undefined): string {
  return role === 'scanner_operator' ? '/registration' : '/'
}

/**
 * Sidebar navigation. `roles` mirrors the server-side permission matrix
 * (the API enforces it; hiding items is only for convenience).
 */
export const NAV_ITEMS: NavItem[] = [
  { label: 'Dashboard', path: '/', icon: LayoutDashboard, roles: STAFF_ROLES },
  { label: 'Events', path: '/events', icon: CalendarDays, roles: STAFF_ROLES },
  { label: 'Attendees', path: '/attendees', icon: Users, roles: STAFF_ROLES },
  { label: 'QR / ID Generator', path: '/qr-codes', icon: QrCode, roles: ['admin', 'registration_staff'] },
  { label: 'Registration', path: '/registration', icon: ScanLine, roles: SCANNER_ROLES },
  { label: 'Minor Randomizer', path: '/minor-randomizer', icon: Dices, roles: RANDOMIZER_ROLES },
  { label: 'Major Eligibility', path: '/major-eligibility', icon: ClipboardCheck, roles: RANDOMIZER_ROLES },
  { label: 'Major QR', path: '/major-qr', icon: Presentation, roles: RANDOMIZER_ROLES },
  { label: 'Major Randomizer', path: '/major-randomizer', icon: Trophy, roles: RANDOMIZER_ROLES },
  { label: 'Reports', path: '/reports', icon: ChartColumn, roles: ['admin'] },
  { label: 'Settings', path: '/settings', icon: Settings, roles: ['admin'] },
]
