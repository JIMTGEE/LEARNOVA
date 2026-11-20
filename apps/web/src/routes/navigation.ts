import { GraduationCap, LayoutDashboard, type LucideIcon } from 'lucide-react'
import type { UserRole } from '@/types/domain'
import { STAFF_ROLES } from '@/utils/roles'

export interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  roles?: UserRole[] // omit = everyone who is signed in
}

// Add an entry here when a module ships. Roles are a convenience for the UI only;
// the database enforces access regardless of what the menu shows.
export const navItems: NavItem[] = [
  { to: '/app', label: 'Dashboard', icon: LayoutDashboard },
  { to: '/app/students', label: 'Learners', icon: GraduationCap, roles: STAFF_ROLES },
]
