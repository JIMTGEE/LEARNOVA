import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router'
import { useAuth } from '@/context/AuthContext'
import { Button } from '@/components/ui/button'
import { FullPageSpinner } from '@/components/Spinner'
import type { UserRole } from '@/types/domain'

function AccountNotice({ title, children }: { title: string; children: ReactNode }) {
  const { signOut } = useAuth()
  return (
    <main className="mx-auto grid min-h-dvh max-w-md place-content-center gap-3 p-6">
      <h1 className="text-2xl font-bold">{title}</h1>
      <p className="text-muted-foreground">{children}</p>
      <div>
        <Button variant="outline" onClick={() => void signOut()}>
          Sign out
        </Button>
      </div>
    </main>
  )
}

export function RequireAuth({ children }: { children: ReactNode }) {
  const { session, profile, school, loading } = useAuth()
  const location = useLocation()

  if (loading) return <FullPageSpinner />
  if (!session) return <Navigate to="/login" state={{ from: location }} replace />

  if (!profile) {
    return (
      <AccountNotice title="Your account is not linked to a school">
        Ask your school administrator to set up your access.
      </AccountNotice>
    )
  }
  if (profile.status !== 'active') {
    return (
      <AccountNotice title="Your account is not active">
        Contact your school administrator to restore access.
      </AccountNotice>
    )
  }
  if (profile.role !== 'super_admin' && !school) {
    return (
      <AccountNotice title="Your school's access is paused">
        Please contact your school administrator.
      </AccountNotice>
    )
  }
  return children
}

export function RequireRole({ roles, children }: { roles: UserRole[]; children: ReactNode }) {
  const { profile } = useAuth()
  if (!profile || !roles.includes(profile.role)) return <Navigate to="/app" replace />
  return children
}
