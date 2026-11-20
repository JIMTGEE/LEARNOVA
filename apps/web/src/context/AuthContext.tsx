import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import type { Session } from '@supabase/supabase-js'
import { supabase } from '@/lib/supabase'
import { queryClient } from '@/lib/queryClient'
import type { Profile, School } from '@/types/domain'

interface AuthState {
  session: Session | null
  profile: Profile | null
  school: School | null
  loading: boolean
  signIn: (email: string, password: string) => Promise<void>
  signOut: () => Promise<void>
}

const AuthContext = createContext<AuthState | null>(null)

type ProfileRow = Profile & { schools: School | null }

export function AuthProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState<Session | null>(null)
  const [sessionReady, setSessionReady] = useState(false)
  const [profile, setProfile] = useState<Profile | null>(null)
  const [school, setSchool] = useState<School | null>(null)
  const [profileReady, setProfileReady] = useState(false)

  useEffect(() => {
    void supabase.auth.getSession().then(({ data }) => {
      setSession(data.session)
      setSessionReady(true)
    })

    // Keep this callback synchronous: awaiting Supabase calls inside it can deadlock.
    const { data } = supabase.auth.onAuthStateChange((event, next) => {
      if (event === 'SIGNED_OUT') queryClient.clear() // never leak one user's data to the next
      setSession(next)
    })
    return () => data.subscription.unsubscribe()
  }, [])

  const userId = session?.user.id ?? null

  useEffect(() => {
    if (!sessionReady) return
    if (!userId) {
      setProfile(null)
      setSchool(null)
      setProfileReady(true)
      return
    }

    let cancelled = false
    setProfileReady(false)
    void supabase
      .from('profiles')
      .select(
        'id, school_id, role, full_name, email, phone, status, force_password_change, schools(id, name, code)',
      )
      .eq('id', userId)
      .maybeSingle<ProfileRow>()
      .then(({ data }) => {
        if (cancelled) return
        if (data) {
          const { schools, ...rest } = data
          setProfile(rest)
          setSchool(schools)
        } else {
          setProfile(null)
          setSchool(null)
        }
        setProfileReady(true)
      })

    return () => {
      cancelled = true
    }
  }, [sessionReady, userId])

  const signIn = useCallback(async (email: string, password: string) => {
    const { error } = await supabase.auth.signInWithPassword({ email, password })
    if (error) throw error
  }, [])

  const signOut = useCallback(async () => {
    await supabase.auth.signOut()
  }, [])

  const value = useMemo<AuthState>(
    () => ({
      session,
      profile,
      school,
      loading: !sessionReady || !profileReady,
      signIn,
      signOut,
    }),
    [session, profile, school, sessionReady, profileReady, signIn, signOut],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>')
  return ctx
}
