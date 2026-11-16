import { ArrowRight } from 'lucide-react'
import { Link } from 'react-router'
import { useAuth } from '@/context/AuthContext'
import { navItems } from '@/routes/navigation'
import { roleLabels } from '@/utils/roles'

function greeting(date = new Date()) {
  const h = date.getHours()
  if (h < 12) return 'Good morning'
  if (h < 17) return 'Good afternoon'
  return 'Good evening'
}

export function DashboardPage() {
  const { profile, school } = useAuth()
  if (!profile) return null

  const shortcuts = navItems.filter(
    (item) => item.to !== '/app' && (!item.roles || item.roles.includes(profile.role)),
  )
  const firstName = profile.full_name.split(' ')[0]

  return (
    <div className="max-w-3xl space-y-8">
      <header>
        <h1 className="text-3xl font-bold">
          {greeting()}, {firstName}
        </h1>
        <p className="mt-1 text-muted-foreground">
          {roleLabels[profile.role]}
          {school ? ` at ${school.name}` : ''}
        </p>
      </header>

      {shortcuts.length > 0 && (
        <div className="grid gap-3 sm:grid-cols-2">
          {shortcuts.map(({ to, label, icon: Icon }) => (
            <Link
              key={to}
              to={to}
              className="group flex items-center justify-between rounded-lg border border-border bg-card p-4 transition-colors hover:border-primary"
            >
              <span className="flex items-center gap-3 font-semibold">
                <Icon className="size-5 text-primary" aria-hidden />
                {label}
              </span>
              <ArrowRight className="size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
            </Link>
          ))}
        </div>
      )}
    </div>
  )
}
