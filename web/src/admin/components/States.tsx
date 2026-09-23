import { clsx } from 'clsx'
import type { ReactNode } from 'react'
import { errorMessage } from '../lib/errors'
import { Button } from './Button'
import { Spinner } from './Spinner'

export function LoadingState({ label = 'Chargement…', className }: { label?: string; className?: string }) {
  return (
    <div role="status" className={clsx('flex items-center justify-center gap-3 py-12 text-church-purple', className)}>
      <Spinner className="size-6" />
      <span className="text-sm font-bold text-gray-700">{label}</span>
    </div>
  )
}

export function ErrorState({
  error,
  onRetry,
  title = 'Impossible de charger ces données.',
}: {
  error: unknown
  onRetry?: () => void
  title?: string
}) {
  return (
    <div role="alert" className="flex flex-col items-start gap-3 rounded-2xl border border-red-300 bg-red-50 p-5 text-red-900">
      <div>
        <p className="font-bold">{title}</p>
        <p className="mt-1 text-sm">{errorMessage(error)}</p>
      </div>
      {onRetry && (
        <Button variant="secondary" size="sm" onClick={onRetry}>
          Réessayer
        </Button>
      )}
    </div>
  )
}

export function EmptyState({ title, children }: { title: string; children?: ReactNode }) {
  return (
    <div className="rounded-2xl border border-dashed border-gray-400 bg-white px-6 py-10 text-center">
      <p className="font-display text-lg font-bold text-church-purple-dk">{title}</p>
      {children && <div className="mt-2 text-sm text-gray-700">{children}</div>}
    </div>
  )
}
