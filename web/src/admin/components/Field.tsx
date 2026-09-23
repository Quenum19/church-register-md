import { clsx } from 'clsx'
import { useId, type ReactNode } from 'react'

export interface FieldControlProps {
  id: string
  'aria-invalid': boolean
  'aria-describedby': string | undefined
}

interface FieldProps {
  label: ReactNode
  error?: string
  hint?: ReactNode
  /** Mention « facultatif » après le libellé. */
  optional?: boolean
  className?: string
  children: (control: FieldControlProps) => ReactNode
}

/** Libellé lié, aide et message d'erreur reliés au contrôle via aria-describedby. */
export function Field({ label, error, hint, optional, className, children }: FieldProps) {
  const id = useId()
  const hintId = hint ? `${id}-hint` : undefined
  const errorId = error ? `${id}-error` : undefined
  const describedBy = [hintId, errorId].filter(Boolean).join(' ') || undefined
  return (
    <div className={clsx('flex flex-col gap-1.5', className)}>
      <label htmlFor={id} className="text-sm font-bold text-gray-800">
        {label}
        {optional && <span className="font-normal text-gray-600"> (facultatif)</span>}
      </label>
      {hint && (
        <p id={hintId} className="text-sm text-gray-600">
          {hint}
        </p>
      )}
      {children({ id, 'aria-invalid': Boolean(error), 'aria-describedby': describedBy })}
      {error && (
        <p id={errorId} className="text-sm font-bold text-red-700">
          {error}
        </p>
      )}
    </div>
  )
}

interface CheckboxFieldProps {
  label: ReactNode
  hint?: ReactNode
  children: (control: { id: string; 'aria-describedby': string | undefined }) => ReactNode
}

export function CheckboxField({ label, hint, children }: CheckboxFieldProps) {
  const id = useId()
  const hintId = hint ? `${id}-hint` : undefined
  return (
    <div className="flex items-start gap-3">
      <div className="flex min-h-11 items-center sm:min-h-6">{children({ id, 'aria-describedby': hintId })}</div>
      <div className="flex flex-col gap-0.5 pt-2.5 sm:pt-0">
        <label htmlFor={id} className="text-sm font-bold text-gray-800">
          {label}
        </label>
        {hint && (
          <p id={hintId} className="text-sm text-gray-600">
            {hint}
          </p>
        )}
      </div>
    </div>
  )
}

/** Erreur globale d'un formulaire (annoncée immédiatement). */
export function FormAlert({ message, tone = 'error' }: { message: ReactNode | null | undefined; tone?: 'error' | 'success' | 'info' }) {
  if (!message) return null
  return (
    <div
      role={tone === 'error' ? 'alert' : 'status'}
      className={clsx(
        'rounded-lg border px-4 py-3 text-sm',
        tone === 'error' && 'border-red-300 bg-red-50 text-red-800',
        tone === 'success' && 'border-green-300 bg-green-50 text-green-900',
        tone === 'info' && 'border-church-purple/30 bg-church-purple-xl text-church-purple-dk',
      )}
    >
      {message}
    </div>
  )
}
