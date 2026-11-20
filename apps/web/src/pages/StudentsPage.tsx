import { ChevronLeft, ChevronRight, Search } from 'lucide-react'
import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { useStudents } from '@/hooks/useStudents'
import { STUDENTS_PAGE_SIZE } from '@/services/students.service'
import { cn } from '@/lib/utils'

function formatDate(value: string | null) {
  return value ? new Date(value).toLocaleDateString('en-GB') : '–'
}

export function StudentsPage() {
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(0)
  const debounced = useDebouncedValue(search)
  const { data, isPending, isError, isFetching } = useStudents({ search: debounced, page })

  const total = data?.total ?? 0
  const lastPage = Math.max(0, Math.ceil(total / STUDENTS_PAGE_SIZE) - 1)
  const first = total === 0 ? 0 : page * STUDENTS_PAGE_SIZE + 1
  const last = Math.min(total, (page + 1) * STUDENTS_PAGE_SIZE)

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">Learners</h1>
          <p className="mt-1 text-muted-foreground">
            {isPending ? 'Loading…' : `${total} ${total === 1 ? 'learner' : 'learners'}`}
          </p>
        </div>
        <div className="relative w-full sm:w-72">
          <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            className="pl-9"
            placeholder="Search name or admission number"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(0)
            }}
            aria-label="Search learners"
          />
        </div>
      </header>

      <div className={cn('overflow-x-auto rounded-lg border border-border bg-card', isFetching && 'opacity-70')}>
        <table className="w-full text-left text-sm">
          <thead className="border-b border-border bg-muted/60 text-muted-foreground">
            <tr>
              <th className="px-4 py-3 font-semibold">Admission no.</th>
              <th className="px-4 py-3 font-semibold">Name</th>
              <th className="px-4 py-3 font-semibold">Gender</th>
              <th className="px-4 py-3 font-semibold">Date of birth</th>
              <th className="px-4 py-3 font-semibold">Status</th>
            </tr>
          </thead>
          <tbody>
            {isError && (
              <tr>
                <td colSpan={5} className="px-4 py-10 text-center text-destructive">
                  Could not load learners. Check your connection and try again.
                </td>
              </tr>
            )}
            {!isError && !isPending && data?.rows.length === 0 && (
              <tr>
                <td colSpan={5} className="px-4 py-10 text-center text-muted-foreground">
                  {debounced ? 'No learner matches that search.' : 'No learners have been added yet.'}
                </td>
              </tr>
            )}
            {data?.rows.map((s) => (
              <tr key={s.id} className="border-b border-border last:border-0">
                <td className="px-4 py-3 tabular-nums">{s.admission_number}</td>
                <td className="px-4 py-3 font-semibold">{s.full_name}</td>
                <td className="px-4 py-3 capitalize">{s.gender ?? '–'}</td>
                <td className="px-4 py-3 tabular-nums">{formatDate(s.date_of_birth)}</td>
                <td className="px-4 py-3">
                  <span
                    className={cn(
                      'rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize',
                      s.status === 'active' ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground',
                    )}
                  >
                    {s.status}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {total > STUDENTS_PAGE_SIZE && (
        <div className="flex items-center justify-between text-sm text-muted-foreground">
          <span className="tabular-nums">
            {first}–{last} of {total}
          </span>
          <div className="flex gap-2">
            <Button variant="outline" size="sm" disabled={page === 0} onClick={() => setPage(page - 1)}>
              <ChevronLeft className="size-4" /> Previous
            </Button>
            <Button variant="outline" size="sm" disabled={page >= lastPage} onClick={() => setPage(page + 1)}>
              Next <ChevronRight className="size-4" />
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
