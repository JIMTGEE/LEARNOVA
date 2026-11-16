import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'

let role = 'teacher'

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
        getSession: () => Promise.resolve({ data: { session: { user: { id: 'u1' } } } }),
        onAuthStateChange: () => ({ data: { subscription: { unsubscribe() {} } } }),
        signOut: () => Promise.resolve({}),
      },
      from: (table: string) =>
        table === 'profiles'
          ? builder({
              data: {
                id: 'u1', school_id: 's1', role, full_name: 'Kofi Boateng', email: 'k@x.test', phone: null,
                status: 'active', force_password_change: false, schools: { id: 's1', name: 'Demo Academy', code: 'DEMO' },
              },
            })
          : builder({
              data: [
                { id: 'a', admission_number: 'A-001', full_name: 'Ama Mensah', gender: 'female', date_of_birth: '2015-03-02', status: 'active' },
                { id: 'b', admission_number: 'A-002', full_name: 'Kwame Owusu', gender: 'male', date_of_birth: null, status: 'active' },
              ],
              count: 2,
              error: null,
            }),
    },
  }
})

beforeEach(() => window.history.pushState({}, '', '/app/students'))
afterEach(cleanup)

it('teacher sees the learners list with the school name and menu', async () => {
  role = 'teacher'
  const { default: App } = await import('../App')
  render(<App />)
  await waitFor(() => expect(screen.getByText('Ama Mensah')).toBeTruthy())
  expect(screen.getByText('Kwame Owusu')).toBeTruthy()
  expect(screen.getByText('2 learners')).toBeTruthy()
  expect(screen.getByText('Demo Academy')).toBeTruthy()
  expect(screen.getByRole('link', { name: /Learners/ })).toBeTruthy()
})
