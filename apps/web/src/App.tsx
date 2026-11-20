import { QueryClientProvider } from '@tanstack/react-query'
import { RouterProvider } from 'react-router'
import { Toaster } from 'sonner'
import { SetupNotice } from '@/components/SetupNotice'
import { AuthProvider } from '@/context/AuthContext'
import { isSupabaseConfigured } from '@/lib/supabase'
import { queryClient } from '@/lib/queryClient'
import { router } from '@/routes'

export default function App() {
  if (!isSupabaseConfigured) return <SetupNotice />

  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <RouterProvider router={router} />
      </AuthProvider>
      <Toaster position="top-center" richColors />
    </QueryClientProvider>
  )
}
