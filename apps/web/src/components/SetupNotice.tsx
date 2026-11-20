export function SetupNotice() {
  return (
    <main className="mx-auto grid min-h-dvh max-w-xl place-content-center gap-3 p-6">
      <h1 className="text-2xl font-bold">EduSync is not connected to a database yet</h1>
      <p className="text-muted-foreground">
        Copy <code className="rounded bg-muted px-1">apps/web/.env.example</code> to{' '}
        <code className="rounded bg-muted px-1">apps/web/.env.local</code>, add your Supabase project
        URL and anon key, then restart the dev server.
      </p>
    </main>
  )
}
