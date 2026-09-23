import { clsx } from 'clsx'
import { STATUS_LABELS, type VisitorStatus } from '../../shared/domain'

const COLORS: Record<VisitorStatus, string> = {
  prospect: 'border-blue-200 bg-blue-50 text-blue-800',
  recurrent: 'border-amber-300 bg-amber-50 text-amber-900',
  membre_potentiel: 'border-purple-300 bg-church-purple-xl text-church-purple-dk',
  membre: 'border-green-300 bg-green-50 text-green-800',
}

export function StatusBadge({ status }: { status: VisitorStatus }) {
  return (
    <span className={clsx('inline-flex items-center whitespace-nowrap rounded-full border px-2.5 py-0.5 text-xs font-bold', COLORS[status])}>
      {STATUS_LABELS[status] ?? status}
    </span>
  )
}

export function Badge({ children, tone = 'gray' }: { children: string; tone?: 'gray' | 'green' | 'gold' | 'red' | 'purple' }) {
  return (
    <span
      className={clsx(
        'inline-flex items-center whitespace-nowrap rounded-full border px-2.5 py-0.5 text-xs font-bold',
        tone === 'gray' && 'border-gray-300 bg-gray-50 text-gray-800',
        tone === 'green' && 'border-green-300 bg-green-50 text-green-800',
        tone === 'gold' && 'border-church-gold/50 bg-church-gold-pale text-church-gold-dk',
        tone === 'red' && 'border-red-300 bg-red-50 text-red-800',
        tone === 'purple' && 'border-purple-300 bg-church-purple-xl text-church-purple-dk',
      )}
    >
      {children}
    </span>
  )
}
