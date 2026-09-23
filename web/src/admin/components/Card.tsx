import { clsx } from 'clsx'
import { useId, type ReactNode } from 'react'

interface CardProps {
  title?: ReactNode
  description?: ReactNode
  actions?: ReactNode
  className?: string
  children: ReactNode
}

/** Bloc de contenu titré (section étiquetée par son h2). */
export function Card({ title, description, actions, className, children }: CardProps) {
  const titleId = useId()
  return (
    <section
      aria-labelledby={title ? titleId : undefined}
      className={clsx('rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6', className)}
    >
      {(title || actions) && (
        <div className="mb-4 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
          <div>
            {title && (
              <h2 id={titleId} className="font-display text-lg font-bold text-church-purple-dk">
                {title}
              </h2>
            )}
            {description && <p className="mt-1 text-sm text-gray-700">{description}</p>}
          </div>
          {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
        </div>
      )}
      {children}
    </section>
  )
}
