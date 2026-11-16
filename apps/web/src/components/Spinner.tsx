import { Loader2 } from 'lucide-react'

export function FullPageSpinner() {
  return (
    <div className="grid min-h-dvh place-items-center text-muted-foreground" role="status">
      <Loader2 className="size-6 animate-spin" aria-label="Loading" />
    </div>
  )
}
