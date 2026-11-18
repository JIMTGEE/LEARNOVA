import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { Navigate, useLocation } from 'react-router'
import { toast } from 'sonner'
import { z } from 'zod'
import { useAuth } from '@/context/AuthContext'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'

const schema = z.object({
  email: z.email('Enter a valid email address'),
  password: z.string().min(1, 'Enter your password'),
})
type FormValues = z.infer<typeof schema>

export function LoginPage() {
  const { session, loading, signIn } = useAuth()
  const location = useLocation()
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) })

  if (!loading && session) {
    const from = (location.state as { from?: { pathname: string } } | null)?.from?.pathname
    return <Navigate to={from ?? '/app'} replace />
  }

  async function onSubmit(values: FormValues) {
    try {
      await signIn(values.email.trim(), values.password)
    } catch {
      toast.error('That email and password do not match.')
    }
  }

  return (
    <div className="grid min-h-dvh md:grid-cols-[1.1fr_1fr]">
      <section className="hidden flex-col justify-between bg-primary p-10 text-primary-foreground md:flex">
        <div className="flex items-center gap-3">
          <div className="grid size-10 place-items-center rounded-md bg-primary-foreground font-bold text-primary">
            L
          </div>
          <span className="text-xl font-bold">Learnova</span>
        </div>
        <div className="max-w-md">
          <div className="mb-5 h-1 w-14 rounded-full bg-accent" />
          <h2 className="text-4xl leading-tight font-bold">
            Attendance, results and fees in one place.
          </h2>
          <p className="mt-4 text-lg text-primary-foreground/80">
            Teachers, parents and school leaders each see exactly what they should, and nothing more.
          </p>
        </div>
        <p className="text-sm text-primary-foreground/70">Built for schools in Ghana.</p>
      </section>

      <section className="grid place-items-center p-6">
        <form onSubmit={handleSubmit(onSubmit)} className="w-full max-w-sm space-y-5" noValidate>
          <div>
            <h1 className="text-2xl font-bold">Sign in</h1>
            <p className="mt-1 text-sm text-muted-foreground">Use the email your school registered for you.</p>
          </div>

          <div className="space-y-1.5">
            <label htmlFor="email" className="text-sm font-semibold">
              Email
            </label>
            <Input id="email" type="email" autoComplete="username" {...register('email')} />
            {errors.email && <p className="text-sm text-destructive">{errors.email.message}</p>}
          </div>

          <div className="space-y-1.5">
            <label htmlFor="password" className="text-sm font-semibold">
              Password
            </label>
            <Input id="password" type="password" autoComplete="current-password" {...register('password')} />
            {errors.password && <p className="text-sm text-destructive">{errors.password.message}</p>}
          </div>

          <Button type="submit" className="w-full" disabled={isSubmitting}>
            {isSubmitting ? 'Signing in…' : 'Sign in'}
          </Button>
        </form>
      </section>
    </div>
  )
}
