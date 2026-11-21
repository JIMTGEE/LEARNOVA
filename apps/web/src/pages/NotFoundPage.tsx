import { Link } from 'react-router'

export function NotFoundPage() {
  return (
    <main className="mx-auto grid min-h-dvh max-w-md place-content-center gap-3 p-6">
      <h1 className="text-2xl font-bold">Page not found</h1>
      <p className="text-muted-foreground">That address does not exist in Learnova.</p>
      <Link to="/app" className="font-semibold text-primary underline-offset-4 hover:underline">
        Back to the dashboard
      </Link>
    </main>
  )
}
