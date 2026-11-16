import { render, screen, waitFor } from '@testing-library/react'
import { expect, it } from 'vitest'
import App from '../App'

it('sends a signed-out visitor to the sign-in page', async () => {
  window.history.pushState({}, '', '/app/students')
  render(<App />)
  await waitFor(() => expect(screen.getByRole('heading', { name: 'Sign in' })).toBeTruthy())
  expect(screen.getByLabelText('Email')).toBeTruthy()
  expect(screen.getByLabelText('Password')).toBeTruthy()
  expect(window.location.pathname).toBe('/login')
})
