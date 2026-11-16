import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { listStudents, type StudentsQuery } from '@/services/students.service'

export function useStudents(query: StudentsQuery) {
  return useQuery({
    queryKey: ['students', query],
    queryFn: () => listStudents(query),
    placeholderData: keepPreviousData,
  })
}
