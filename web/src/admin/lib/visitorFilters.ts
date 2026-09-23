// Filtres de la liste des visiteurs : lecture/écriture dans l'URL (useSearchParams),
// requête API et liens d'export partagent la même représentation.

import type { VisitorFilters, VisitorSort } from '../../shared/api-types'
import { buildQuery } from '../../shared/http'
import { STATUS_LABELS, VISITOR_STATUSES, type VisitorStatus } from '../../shared/domain'

export type StatusFilter = VisitorStatus | 'non_membre'

export const STATUS_FILTER_OPTIONS: { value: StatusFilter; label: string }[] = [
  { value: 'non_membre', label: 'Tous sauf membres' },
  ...VISITOR_STATUSES.map((s) => ({ value: s, label: STATUS_LABELS[s] })),
]

export const SORT_OPTIONS: { value: VisitorSort; label: string }[] = [
  { value: '-created_at', label: 'Plus récents d’abord' },
  { value: 'created_at', label: 'Plus anciens d’abord' },
  { value: 'full_name', label: 'Nom (A → Z)' },
  { value: '-last_visit_date', label: 'Dernière visite' },
]

export const DEFAULT_SORT: VisitorSort = '-created_at'

const DATE = /^\d{4}-\d{2}-\d{2}$/

export function parsePositiveInt(value: string | null): number | undefined {
  if (!value || !/^\d{1,9}$/.test(value)) return undefined
  const n = Number(value)
  return n >= 1 ? n : undefined
}

export function parseSearch(value: string | null): string | undefined {
  const trimmed = value?.trim().slice(0, 100)
  return trimmed ? trimmed : undefined
}

/** Lit les filtres depuis l'URL en ignorant les valeurs invalides. */
export function parseVisitorFilters(params: URLSearchParams): VisitorFilters {
  const filters: VisitorFilters = {}
  const search = parseSearch(params.get('search'))
  if (search) filters.search = search
  const status = params.get('status')
  if (status && STATUS_FILTER_OPTIONS.some((o) => o.value === status)) filters.status = status as StatusFilter
  const family = parsePositiveInt(params.get('family_id'))
  if (family) filters.family_id = family
  const from = params.get('from')
  if (from && DATE.test(from)) filters.from = from
  const to = params.get('to')
  if (to && DATE.test(to)) filters.to = to
  const sort = params.get('sort')
  if (sort && sort !== DEFAULT_SORT && SORT_OPTIONS.some((o) => o.value === sort)) filters.sort = sort as VisitorSort
  const page = parsePositiveInt(params.get('page'))
  if (page && page > 1) filters.page = page
  return filters
}

type FilterKey = Exclude<keyof VisitorFilters, 'page' | 'per_page'>

/** Applique une modification de filtres à l'URL courante ; revient à la page 1. */
export function withFilters(params: URLSearchParams, changes: Partial<Record<FilterKey, string | undefined>>): URLSearchParams {
  const next = new URLSearchParams(params)
  for (const [key, value] of Object.entries(changes)) {
    if (value === undefined || value === '') next.delete(key)
    else next.set(key, value)
  }
  if (next.get('sort') === DEFAULT_SORT) next.delete('sort')
  next.delete('page')
  return next
}

export function hasActiveFilters(filters: VisitorFilters): boolean {
  return Boolean(filters.search || filters.status || filters.family_id || filters.from || filters.to)
}

export function isInvalidPeriod(filters: VisitorFilters): boolean {
  return Boolean(filters.from && filters.to && filters.from > filters.to)
}

/** Paramètres envoyés à l'API (pagination comprise). */
export function toApiQuery(filters: VisitorFilters): Record<string, string | number | undefined> {
  return {
    search: filters.search,
    status: filters.status,
    family_id: filters.family_id,
    from: filters.from,
    to: filters.to,
    sort: filters.sort ?? DEFAULT_SORT,
    page: filters.page ?? 1,
  }
}

export type ExportFormat = 'csv' | 'xlsx' | 'pdf'

/** Au-delà, le serveur refuse l'export PDF (422 `too_many_rows`, limite de dompdf) : CSV ou Excel uniquement. */
export const PDF_EXPORT_MAX_ROWS = 1000

/** Lien de téléchargement direct (cookie de session) avec les filtres courants, sans pagination. */
export function exportUrl(format: ExportFormat, filters: VisitorFilters): string {
  return `/api/admin/exports/visitors.${format}${buildQuery({
    search: filters.search,
    status: filters.status,
    family_id: filters.family_id,
    from: filters.from,
    to: filters.to,
    sort: filters.sort,
  })}`
}
