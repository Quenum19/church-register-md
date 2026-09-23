import { clsx } from 'clsx'
import { useCallback, useId, useMemo } from 'react'
import { Link, useLocation, useSearchParams } from 'react-router'
import type { VisitorFilters, VisitorSummary } from '../../../shared/api-types'
import { useVisitorList } from '../../api/visitors'
import { useCan } from '../../auth/context'
import { Icon } from '../../components/Icon'
import { PageHeader } from '../../components/PageHeader'
import { Pagination } from '../../components/Pagination'
import { EmptyState, ErrorState, LoadingState } from '../../components/States'
import { StatusBadge } from '../../components/StatusBadge'
import { buttonClass, linkClass, tableCellClass, tableHeadClass } from '../../components/styles'
import { useClampPage, usePageNavigation } from '../../hooks/usePageParam'
import { formatNumber, formatShortDate } from '../../lib/format'
import type { BackState } from '../../lib/paths'
import { formatPhone } from '../../lib/phone'
import {
  exportUrl,
  hasActiveFilters,
  isInvalidPeriod,
  parseVisitorFilters,
  PDF_EXPORT_MAX_ROWS,
  withFilters,
} from '../../lib/visitorFilters'
import { VisitorFiltersBar, type FilterChange } from './VisitorFiltersBar'

function ExportLinks({ filters, total }: { filters: VisitorFilters; total: number | undefined }) {
  const pdfTooLarge = total !== undefined && total > PDF_EXPORT_MAX_ROWS
  const pdfHintId = useId()
  return (
    <div role="group" aria-label="Exporter la liste filtrée" className="flex flex-wrap gap-2">
      <a href={exportUrl('csv', filters)} download className={buttonClass('secondary', 'sm')}>
        <Icon name="download" className="size-4" />
        <span>
          <span className="sr-only">Exporter en</span> CSV
        </span>
      </a>
      <a href={exportUrl('xlsx', filters)} download className={buttonClass('secondary', 'sm')}>
        <Icon name="download" className="size-4" />
        <span>
          <span className="sr-only">Exporter en</span> Excel
        </span>
      </a>
      {pdfTooLarge ? (
        <>
          {/* Lien désactivé mais focalisable : l'explication reste découvrable au clavier et au lecteur d'écran. */}
          <span role="link" aria-disabled="true" tabIndex={0} aria-describedby={pdfHintId} className={buttonClass('secondary', 'sm')}>
            <Icon name="download" className="size-4" />
            <span>
              <span className="sr-only">Exporter en</span> PDF
            </span>
          </span>
          <p id={pdfHintId} className="max-w-xs basis-full text-sm text-gray-700">
            Plus de {formatNumber(PDF_EXPORT_MAX_ROWS)} lignes : utilisez CSV ou Excel, ou affinez les filtres pour obtenir un PDF.
          </p>
        </>
      ) : (
        <a href={exportUrl('pdf', filters)} download className={buttonClass('secondary', 'sm')}>
          <Icon name="download" className="size-4" />
          <span>
            <span className="sr-only">Exporter en</span> PDF
          </span>
        </a>
      )}
    </div>
  )
}

function VisitorTable({ visitors, backState }: { visitors: VisitorSummary[]; backState: BackState }) {
  return (
    <div className="hidden overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm md:block">
      <table className="min-w-full divide-y divide-gray-200">
        <caption className="sr-only">Liste des visiteurs</caption>
        <thead className="bg-gray-50">
          <tr>
            <th scope="col" className={tableHeadClass}>Nom</th>
            <th scope="col" className={tableHeadClass}>Téléphone</th>
            <th scope="col" className={tableHeadClass}>Commune / quartier</th>
            <th scope="col" className={tableHeadClass}>Statut</th>
            <th scope="col" className={tableHeadClass}>Visites</th>
            <th scope="col" className={tableHeadClass}>1re visite</th>
            <th scope="col" className={tableHeadClass}>Dernière visite</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-gray-100">
          {visitors.map((v) => (
            <tr key={v.id} className="hover:bg-church-purple-xl/40">
              <th scope="row" className={clsx(tableCellClass, 'text-left font-normal')}>
                <Link to={`/admin/visiteurs/${v.id}`} state={backState} className={linkClass}>
                  {v.full_name}
                </Link>
              </th>
              <td className={clsx(tableCellClass, 'whitespace-nowrap')}>{formatPhone(v.phone)}</td>
              <td className={tableCellClass}>
                {v.commune}
                {v.quartier && <span className="block text-gray-700">{v.quartier}</span>}
              </td>
              <td className={tableCellClass}>
                <StatusBadge status={v.status} />
              </td>
              <td className={tableCellClass}>{v.visit_count} / 3</td>
              <td className={clsx(tableCellClass, 'whitespace-nowrap')}>{formatShortDate(v.first_visit_date)}</td>
              <td className={clsx(tableCellClass, 'whitespace-nowrap')}>{formatShortDate(v.last_visit_date)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function VisitorCards({ visitors, backState }: { visitors: VisitorSummary[]; backState: BackState }) {
  return (
    <ul className="flex flex-col gap-3 md:hidden" aria-label="Liste des visiteurs">
      {visitors.map((v) => (
        <li key={v.id} className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
          <div className="flex items-start justify-between gap-3">
            <Link to={`/admin/visiteurs/${v.id}`} state={backState} className={clsx(linkClass, 'text-base')}>
              {v.full_name}
            </Link>
            <StatusBadge status={v.status} />
          </div>
          <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            <div className="col-span-2">
              <dt className="sr-only">Téléphone</dt>
              <dd className="text-gray-900">{formatPhone(v.phone)}</dd>
            </div>
            <div className="col-span-2">
              <dt className="sr-only">Commune et quartier</dt>
              <dd className="text-gray-800">{[v.commune, v.quartier].filter(Boolean).join(' · ')}</dd>
            </div>
            <div>
              <dt className="text-xs font-bold text-gray-700">Visites</dt>
              <dd>{v.visit_count} / 3</dd>
            </div>
            <div>
              <dt className="text-xs font-bold text-gray-700">Dernière visite</dt>
              <dd>{formatShortDate(v.last_visit_date)}</dd>
            </div>
          </dl>
        </li>
      ))}
    </ul>
  )
}

export function VisitorsPage() {
  const [params, setParams] = useSearchParams()
  const location = useLocation()
  const filters = useMemo(() => parseVisitorFilters(params), [params])
  const invalidPeriod = isInvalidPeriod(filters)
  const canExport = useCan('visitors.export')
  const query = useVisitorList(filters, !invalidPeriod)
  const backState: BackState = { from: `${location.pathname}${location.search}`, label: 'la liste des visiteurs' }
  const requestedPage = filters.page ?? 1

  const changeFilters = useCallback(
    (changes: FilterChange, options?: { push?: boolean }) => {
      setParams((prev) => withFilters(prev, changes), { replace: !options?.push })
    },
    [setParams],
  )

  const goToPage = usePageNavigation(setParams)
  // Page demandée au-delà de la dernière (ex. après une suppression) : on revient sur la dernière page.
  useClampPage(requestedPage, query.data && !query.isPlaceholderData ? Math.max(1, query.data.meta.last_page) : null, goToPage)

  const data = query.data
  return (
    <>
      <PageHeader
        title="Visiteurs"
        description="Personnes ayant enregistré au moins une visite."
        actions={canExport ? <ExportLinks filters={filters} total={data?.meta.total} /> : undefined}
      />

      <div className="flex flex-col gap-5">
        <VisitorFiltersBar
          filters={filters}
          onChange={changeFilters}
          onReset={() => setParams(new URLSearchParams(), { replace: true })}
          invalidPeriod={invalidPeriod}
        />

        {invalidPeriod ? null : query.isPending ? (
          <LoadingState label="Chargement des visiteurs…" />
        ) : query.isError ? (
          <ErrorState error={query.error} onRetry={() => query.refetch()} />
        ) : data && data.data.length === 0 && requestedPage <= Math.max(1, data.meta.last_page) ? (
          <EmptyState title="Aucun visiteur trouvé">
            {hasActiveFilters(filters) ? 'Aucun visiteur ne correspond à ces filtres.' : 'Aucune visite n’a encore été enregistrée.'}
          </EmptyState>
        ) : data ? (
          <div aria-busy={query.isFetching} className={clsx('flex flex-col gap-4', query.isPlaceholderData && 'opacity-60')}>
            <p role="status" className="text-sm text-gray-800">
              {formatNumber(data.meta.total)} visiteur{data.meta.total > 1 ? 's' : ''}
              {hasActiveFilters(filters) ? ' correspondant aux filtres' : ''}.
            </p>
            <VisitorTable visitors={data.data} backState={backState} />
            <VisitorCards visitors={data.data} backState={backState} />
            <Pagination meta={data.meta} noun="visiteur" onPageChange={(p) => goToPage(p)} disabled={query.isPlaceholderData} />
          </div>
        ) : null}
      </div>
    </>
  )
}
