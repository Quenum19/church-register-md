import type { Paginated } from '../../shared/api-types'
import { formatNumber } from '../lib/format'
import { Button } from './Button'
import { Icon } from './Icon'

interface PaginationProps {
  meta: Paginated<unknown>['meta']
  onPageChange: (page: number) => void
  /** Libellé des éléments comptés (« visiteur », « membre »…), accordé au pluriel par un « s ». */
  noun: string
  label?: string
  disabled?: boolean
}

/** Pagination bornée : jamais « page 1 sur 0 », boutons désactivés aux extrémités. */
export function Pagination({ meta, onPageChange, noun, label = 'Pagination', disabled = false }: PaginationProps) {
  const last = Math.max(1, meta.last_page)
  const current = Math.min(Math.max(1, meta.current_page), last)
  const total = Math.max(0, meta.total)
  return (
    <nav aria-label={label} className="flex flex-col items-center justify-between gap-3 sm:flex-row">
      <p className="text-sm text-gray-700">
        Page <strong>{current}</strong> sur <strong>{last}</strong>
        <span aria-hidden="true"> · </span>
        <span>
          {formatNumber(total)} {noun}
          {total > 1 ? 's' : ''}
        </span>
      </p>
      <div className="flex gap-2">
        <Button variant="secondary" size="sm" disabled={disabled || current <= 1} onClick={() => onPageChange(current - 1)}>
          <Icon name="left" className="size-4" />
          Page précédente
        </Button>
        <Button variant="secondary" size="sm" disabled={disabled || current >= last} onClick={() => onPageChange(current + 1)}>
          Page suivante
          <Icon name="right" className="size-4" />
        </Button>
      </div>
    </nav>
  )
}
