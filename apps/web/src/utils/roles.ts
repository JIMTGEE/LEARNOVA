import type { UserRole } from '@/types/domain'

export const roleLabels: Record<UserRole, string> = {
  super_admin: 'Platform administrator',
  admin: 'Administrator',
  headmaster: 'Headmaster',
  teacher: 'Teacher',
  accountant: 'Accountant',
  parent: 'Parent',
  student: 'Student',
}

export const STAFF_ROLES: UserRole[] = ['admin', 'headmaster', 'teacher', 'accountant']
