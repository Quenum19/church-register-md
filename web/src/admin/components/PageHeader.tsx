import type { ReactNode } from 'react'
import { usePageTitle } from '../hooks/usePageTitle'

interface PageHeaderProps {
  title: string
  /** Titre de l'onglet du navigateur, si différent du titre affiché. */
  documentTitle?: string
  description?: ReactNode
  actions?: ReactNode
  before?: ReactNode
}

/** En-tête de page : met à jour le titre du document ; le h1 reçoit le focus à chaque navigation. */
export function PageHeader({ title, documentTitle, description, actions, before }: PageHeaderProps) {
  usePageTitle(documentTitle ?? title)
  return (
    <header className="mb-6 flex flex-col gap-3">
      {before}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="min-w-0">
          <h1 tabIndex={-1} className="font-display text-2xl font-bold text-church-purple-dk outline-none sm:text-3xl">
            {title}
          </h1>
          {description && <div className="mt-1 text-sm text-gray-700">{description}</div>}
        </div>
        {actions && <div className="flex flex-wrap gap-2 print:hidden">{actions}</div>}
      </div>
    </header>
  )
}
