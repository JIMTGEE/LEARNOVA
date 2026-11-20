import { LogOut } from 'lucide-react'
import { NavLink, Outlet } from 'react-router'
import { useAuth } from '@/context/AuthContext'
import { Button } from '@/components/ui/button'
import { navItems } from '@/routes/navigation'
import { roleLabels } from '@/utils/roles'
import { cn } from '@/lib/utils'

export function DashboardLayout() {
  const { profile, school, signOut } = useAuth()
  if (!profile) return null

  const items = navItems.filter((item) => !item.roles || item.roles.includes(profile.role))

  return (
    <div className="min-h-dvh md:grid md:grid-cols-[15rem_1fr]">
      <aside className="border-b border-border bg-card md:flex md:flex-col md:border-r md:border-b-0">
        <div className="flex items-center gap-3 px-4 py-4">
          <div className="grid size-9 shrink-0 place-items-center rounded-md bg-primary font-bold text-primary-foreground">
            E
          </div>
          <div className="min-w-0">
            <p className="truncate font-semibold leading-tight">{school?.name ?? 'EduSync'}</p>
            <p className="text-xs text-muted-foreground">EduSync</p>
          </div>
        </div>

        <nav className="flex gap-1 overflow-x-auto px-3 pb-3 md:flex-1 md:flex-col md:overflow-visible">
          {items.map(({ to, label, icon: Icon }) => (
            <NavLink
              key={to}
              to={to}
              end={to === '/app'}
              className={({ isActive }) =>
                cn(
                  'flex shrink-0 items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                  isActive
                    ? 'bg-primary/10 text-primary'
                    : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                )
              }
            >
              <Icon className="size-4" aria-hidden />
              {label}
            </NavLink>
          ))}
        </nav>

        <div className="hidden items-center justify-between gap-2 border-t border-border p-3 md:flex">
          <div className="min-w-0">
            <p className="truncate text-sm font-semibold">{profile.full_name}</p>
            <p className="text-xs text-muted-foreground">{roleLabels[profile.role]}</p>
          </div>
          <Button variant="ghost" size="icon" onClick={() => void signOut()} aria-label="Sign out">
            <LogOut className="size-4" />
          </Button>
        </div>
      </aside>

      <main className="min-w-0 p-4 md:p-8">
        <Outlet />
      </main>
    </div>
  )
}
