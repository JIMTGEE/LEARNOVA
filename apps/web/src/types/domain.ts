// Hand-written for the first slice. Once the schema settles, generate the full
// types with: npx supabase gen types typescript --project-id <ref> > src/types/database.ts

export type UserRole =
  | 'super_admin'
  | 'admin'
  | 'headmaster'
  | 'teacher'
  | 'accountant'
  | 'parent'
  | 'student'

export type AccountStatus = 'active' | 'inactive' | 'suspended' | 'graduated'

export interface School {
  id: string
  name: string
  code: string
}

export interface Profile {
  id: string
  school_id: string | null
  role: UserRole
  full_name: string
  email: string | null
  phone: string | null
  status: AccountStatus
  force_password_change: boolean
}

export interface Student {
  id: string
  admission_number: string
  full_name: string
  gender: 'male' | 'female' | 'other' | null
  date_of_birth: string | null
  status: AccountStatus
}
