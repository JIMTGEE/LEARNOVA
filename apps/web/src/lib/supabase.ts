import { createClient } from '@supabase/supabase-js'

const url = import.meta.env.VITE_SUPABASE_URL as string | undefined
const key = import.meta.env.VITE_SUPABASE_ANON_KEY as string | undefined

export const isSupabaseConfigured = Boolean(url && key)

// The browser only ever holds the public anon/publishable key.
// Every read and write is checked by Row Level Security in the database.
export const supabase = createClient(url ?? 'http://localhost:54321', key ?? 'not-configured')
