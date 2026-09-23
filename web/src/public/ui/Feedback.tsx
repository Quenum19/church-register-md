import { clsx } from 'clsx'
import type { ReactNode } from 'react'

export function Spinner() {
  return (
    <span
      aria-hidden="true"
      className="size-5 shrink-0 animate-spin rounded-full border-2 border-current border-t-transparent motion-reduce:animate-none"
    />
  )
}

interface AlertProps {
  tone?: 'error' | 'info'
  title?: string
  children: ReactNode
  action?: ReactNode
}

/** Message d'erreur annoncé immédiatement (role="alert") ou information. */
export function Alert({ tone = 'error', title, children, action }: AlertProps) {
  return (
    <div
      role={tone === 'error' ? 'alert' : 'status'}
      className={clsx(
        'rounded-xl border-2 p-4',
        tone === 'error' ? 'border-red-700 bg-red-50 text-red-900' : 'border-church-purple/40 bg-church-purple-xl text-gray-900',
      )}
    >
      {title && <p className="font-bold">{title}</p>}
      <div className={clsx(title && 'mt-1')}>{children}</div>
      {action && <div className="mt-3">{action}</div>}
    </div>
  )
}

/** Région aria-live discrète pour annoncer les changements d'état (envoi, réessai…). */
export function LiveStatus({ message }: { message: string }) {
  return (
    <p role="status" aria-live="polite" className="sr-only">
      {message}
    </p>
  )
}
