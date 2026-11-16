import { render, screen, waitFor } from '@testing-library/react'
import { expect, it, vi } from 'vitest'

vi.mock('@/lib/supabase', () => {
  const builder = (result: unknown): any =>
    new Proxy({}, {
      get: (_t, prop) =>
        prop === 'then' ? (res: (v: unknown) => unknown) => Promise.resolve(result).then(res) : () => builder(result),
    })
  return {
    isSupabaseConfigured: true,
    supabase: {
      auth: {
        getSession: () => Promise.resolve({ data: { session: { user: { id: 'p1' } } } }),
        onAuthStateChange: () => ({ data: { subscription: { unsubscribe() {} } } }),
        signOut: () => Promise.resolve({}),
      },
      from: () =>
        builder({
          data: {
            id: 'p1', school_id: 's1', role: 'parent', full_name: 'Efua Asante', email: null, phone: null,
            status: 'active', force_password_change: false, schools: { id: 's1', name: 'Demo Academy', code: 'DEMO' },
          },
        }),
    },
  }
})

it('a parent is kept out of the learners list and has no Learners menu', async () => {
  window.history.pushState({}, '', '/app/students')
  const { default: App } = await import('../App')
  render(<App />)
  await waitFor(() => expect(screen.getByText(/Efua/)).toBeTruthy())
  expect(window.location.pathname).toBe('/app')
  expect(screen.queryByRole('link', { name: /Learners/ })).toBeNull()
})
