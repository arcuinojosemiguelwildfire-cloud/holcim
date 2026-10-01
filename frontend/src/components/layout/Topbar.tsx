import { Menu } from 'lucide-react'
import { UserMenu } from './UserMenu'

interface TopbarProps {
  title: string
  onOpenNavigation: () => void
}

export function Topbar({ title, onOpenNavigation }: TopbarProps) {
  return (
    <header className="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6">
      <button
        type="button"
        onClick={onOpenNavigation}
        className="-ml-1 rounded-lg p-2 text-slate-500 hover:bg-slate-100 lg:hidden"
        aria-label="Open navigation"
      >
        <Menu className="size-5" aria-hidden />
      </button>
      <p className="flex-1 truncate text-sm font-medium text-slate-500">{title}</p>
      <UserMenu />
    </header>
  )
}
