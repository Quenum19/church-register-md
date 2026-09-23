import { clsx } from 'clsx'
import { useEffect } from 'react'
import { Link, useSearchParams } from 'react-router'
import type { ReportSummary } from '../../../shared/api-types'
import { useReports } from '../../api/reports'
import { Field } from '../../components/Field'
import { PageHeader } from '../../components/PageHeader'
import { EmptyState, ErrorState, LoadingState } from '../../components/States'
import { Badge } from '../../components/StatusBadge'
import { inputClass, linkClass, tableCellClass, tableHeadClass } from '../../components/styles'
import { isApiError } from '../../lib/errors'
import { capitalize, formatMonth, formatShortDate, todayParts } from '../../lib/format'
import { parseYear } from '../../lib/params'

function DispatchBadge({ report }: { report: ReportSummary }) {
  const today = todayParts()
  if (report.dispatch) return <Badge tone="green">{`Envoyé le ${formatShortDate(report.dispatch.sent_at)}`}</Badge>
  if (report.year === today.year && report.month === today.month) return <Badge tone="purple">Mois en cours</Badge>
  return <Badge tone="gray">Non envoyé</Badge>
}

function reportPath(r: ReportSummary) {
  return `/admin/rapports/${r.year}/${r.month}`
}

function ReportTable({ reports }: { reports: ReportSummary[] }) {
  return (
    <div className="hidden overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm md:block">
      <table className="min-w-full divide-y divide-gray-200">
        <caption className="sr-only">Rapports mensuels</caption>
        <thead className="bg-gray-50">
          <tr>
            <th scope="col" className={tableHeadClass}>Mois</th>
            <th scope="col" className={tableHeadClass}>Famille de service</th>
            <th scope="col" className={clsx(tableHeadClass, 'text-right')}>1re visite</th>
            <th scope="col" className={clsx(tableHeadClass, 'text-right')}>2e visite</th>
            <th scope="col" className={clsx(tableHeadClass, 'text-right')}>3e visite</th>
            <th scope="col" className={clsx(tableHeadClass, 'text-right')}>Total</th>
            <th scope="col" className={clsx(tableHeadClass, 'text-right')}>Conversions</th>
            <th scope="col" className={tableHeadClass}>Envoi</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-gray-100">
          {reports.map((r) => (
            <tr key={`${r.year}-${r.month}`} className="hover:bg-church-purple-xl/40">
              <th scope="row" className={clsx(tableCellClass, 'text-left font-normal')}>
                <Link to={reportPath(r)} className={linkClass}>
                  {capitalize(formatMonth(r.year, r.month))}
                </Link>
              </th>
              <td className={tableCellClass}>{r.family?.name ?? 'Non définie'}</td>
              <td className={clsx(tableCellClass, 'text-right tabular-nums')}>{r.counts.v1}</td>
              <td className={clsx(tableCellClass, 'text-right tabular-nums')}>{r.counts.v2}</td>
              <td className={clsx(tableCellClass, 'text-right tabular-nums')}>{r.counts.v3}</td>
              <td className={clsx(tableCellClass, 'text-right font-bold tabular-nums')}>{r.counts.total}</td>
              <td className={clsx(tableCellClass, 'text-right tabular-nums')}>{r.counts.conversions}</td>
              <td className={tableCellClass}>
                <DispatchBadge report={r} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function ReportCards({ reports }: { reports: ReportSummary[] }) {
  return (
    <ul className="flex flex-col gap-3 md:hidden" aria-label="Rapports mensuels">
      {reports.map((r) => (
        <li key={`${r.year}-${r.month}`} className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
          <div className="flex items-start justify-between gap-3">
            <Link to={reportPath(r)} className={clsx(linkClass, 'text-base')}>
              {capitalize(formatMonth(r.year, r.month))}
            </Link>
            <DispatchBadge report={r} />
          </div>
          <p className="mt-1 text-sm text-gray-800">Famille : {r.family?.name ?? 'non définie'}</p>
          <p className="mt-2 text-sm text-gray-900">
            {r.counts.total} visite{r.counts.total > 1 ? 's' : ''} (1re : {r.counts.v1} · 2e : {r.counts.v2} · 3e : {r.counts.v3})
            · {r.counts.conversions} conversion{r.counts.conversions > 1 ? 's' : ''}
          </p>
        </li>
      ))}
    </ul>
  )
}

/**
 * Année demandée hors des années disponibles (lien ancien, saisie manuelle) : l'API répond 422
 * sur `year`, ou la liste reçue ne la mentionne pas. On revient alors à l'année courante.
 */
function isUnavailableYear(year: number, currentYear: number, query: ReturnType<typeof useReports>): boolean {
  if (year === currentYear) return false
  if (query.isError) return isApiError(query.error, 422)
  return query.data !== undefined && !query.data.meta.available_years.includes(year)
}

export function ReportsPage() {
  const [params, setParams] = useSearchParams()
  const currentYear = todayParts().year
  const rawYear = params.get('annee')
  const year = parseYear(rawYear) ?? currentYear
  const query = useReports(year)
  const unavailable = isUnavailableYear(year, currentYear, query)
  // Paramètre illisible, redondant (année courante) ou année indisponible : l'URL est corrigée sans écran d'erreur.
  const fixUrl = rawYear !== null && (unavailable || year === currentYear)

  useEffect(() => {
    if (!fixUrl) return
    setParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        next.delete('annee')
        return next
      },
      { replace: true },
    )
  }, [fixUrl, setParams])

  const available = query.data?.meta.available_years ?? [currentYear]
  const years = Array.from(new Set([...available, unavailable ? currentYear : year])).sort((a, b) => b - a)

  return (
    <>
      <PageHeader title="Rapports mensuels" description="Chaque rapport concerne les visiteurs accueillis par la famille de service du mois." />
      <div className="flex flex-col gap-5">
        <div className="max-w-xs">
          <Field label="Année">
            {(control) => (
              <select
                {...control}
                value={year}
                onChange={(e) => setParams(e.target.value === String(currentYear) ? {} : { annee: e.target.value }, { replace: true })}
                className={inputClass}
              >
                {years.map((y) => (
                  <option key={y} value={y}>
                    {y}
                  </option>
                ))}
              </select>
            )}
          </Field>
        </div>

        {query.isPending || unavailable ? (
          <LoadingState label="Chargement des rapports…" />
        ) : query.isError ? (
          <ErrorState error={query.error} onRetry={() => query.refetch()} />
        ) : query.data.data.length === 0 ? (
          <EmptyState title="Aucun rapport pour cette année" />
        ) : (
          <>
            <ReportTable reports={query.data.data} />
            <ReportCards reports={query.data.data} />
          </>
        )}
      </div>
    </>
  )
}
