import { supabase } from '@/lib/supabase'
import type { Student } from '@/types/domain'

export const STUDENTS_PAGE_SIZE = 25

export interface StudentsQuery {
  search: string
  page: number // zero-based
}

export interface StudentsResult {
  rows: Student[]
  total: number
}

// No school_id filter here on purpose: Row Level Security already limits every query
// to the signed-in user's school (and, for parents and students, to their own records).
export async function listStudents({ search, page }: StudentsQuery): Promise<StudentsResult> {
  const from = page * STUDENTS_PAGE_SIZE

  let query = supabase
    .from('students')
    .select('id, admission_number, full_name, gender, date_of_birth, status', { count: 'exact' })
    .order('full_name', { ascending: true })
    .range(from, from + STUDENTS_PAGE_SIZE - 1)

  // Strip characters that have meaning inside a PostgREST filter string.
  const term = search.replace(/[,()%*\\]/g, ' ').trim()
  if (term) {
    query = query.or(`full_name.ilike.%${term}%,admission_number.ilike.%${term}%`)
  }

  const { data, error, count } = await query
  if (error) throw error
  return { rows: (data ?? []) as Student[], total: count ?? 0 }
}
