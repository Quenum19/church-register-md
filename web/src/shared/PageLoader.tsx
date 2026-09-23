export function PageLoader() {
  return (
    <div role="status" aria-live="polite" className="flex min-h-dvh items-center justify-center bg-church-purple-dk text-white">
      <span className="size-8 animate-spin rounded-full border-4 border-white/30 border-t-church-gold motion-reduce:animate-none" aria-hidden="true" />
      <span className="sr-only">Chargement…</span>
    </div>
  )
}
