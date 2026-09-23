import { clsx } from 'clsx'
import { useMemo } from 'react'
import { Link, useLocation, useSearchParams } from 'react-router'
import type { MemberSummary } from '../../shared/api-types'
import type { MemberFilters } from '../api/keys'
import { useMemberList } from '../api/visitors'
import { PageHeader } from '../components/PageHeader'
import { Pagination } from '../components/Pagination'
import { SearchForm } from '../components/SearchForm'
import { EmptyState, ErrorState, LoadingState } from '../components/States'
import { linkClass, tableCellClass, tableHeadClass } from '../components/styles'
import { useClampPage, usePageNavigation } from '../hooks/usePageParam'
import { formatDate, formatNumber } from '../lib/format'
import type { BackState } from '../lib/paths'
import { formatPhone } from '../lib/phone'
import { parsePositiveInt, parseSearch } from '../lib/visitorFilters'

function MemberTable({ members, backState }: { members: MemberSummary[]; backState: BackState }) {
  return (
    <div className="hidden overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm md:block">
      <table className="min-w-full divide-y divide-gray-200">
        <caption className="sr-only">Liste des membres</caption>
        <thead className="bg-gray-50">
          <tr>
            <th scope="col" className={tableHeadClass}>Nom</th>
            <th scope="col" className={tableHeadClass}>Téléphone</th>
            <th scope="col" className={tableHeadClass}>Commune / quartier</th>
            <th scope="col" className={tableHeadClass}>Converti(e) le</th>
            <th scope="col" className={tableHeadClass}>Par</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-gray-100">
          {members.map((m) => (
            <tr key={m.id} className="hover:bg-church-purple-xl/40">
              <th scope="row" className={clsx(tableCellClass, 'text-left font-normal')}>
                <Link to={`/admin/visiteurs/${m.id}`} state={backState} className={linkClass}>
                  {m.full_name}
                </Link>
              </th>
              <td className={clsx(tableCellClass, 'whitespace-nowrap')}>{formatPhone(m.phone)}</td>
              <td className={tableCellClass}>{[m.commune, m.quartier].filter(Boolean).join(' · ')}</td>
              <td className={clsx(tableCellClass, 'whitespace-nowrap')}>{formatDate(m.converted_at)}</td>
              <td className={tableCellClass}>{m.converted_by?.name ?? '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function MemberCards({ members, backState }: { members: MemberSummary[]; backState: BackState }) {
  return (
    <ul className="flex flex-col gap-3 md:hidden" aria-label="Liste des membres">
      {members.map((m) => (
        <li key={m.id} className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
          <Link to={`/admin/visiteurs/${m.id}`} state={backState} className={clsx(linkClass, 'text-base')}>
            {m.full_name}
          </Link>
          <p className="mt-2 text-sm text-gray-900">{formatPhone(m.phone)}</p>
          <p className="text-sm text-gray-800">{[m.commune, m.quartier].filter(Boolean).join(' · ')}</p>
          <p className="mt-2 text-sm text-gray-700">
            Converti(e) le {formatDate(m.converted_at)}
            {m.converted_by ? ` par ${m.converted_by.name}` : ''}
          </p>
        </li>
      ))}
    </ul>
  )
}

export function MembersPage() {
  const [params, setParams] = useSearchParams()
  const location = useLocation()
  const filters = useMemo<MemberFilters>(() => {
    const search = parseSearch(params.get('search'))
    const page = parsePositiveInt(params.get('page'))
    return { ...(search ? { search } : {}), ...(page && page > 1 ? { page } : {}) }
  }, [params])
  const query = useMemberList(filters)
  const goToPage = usePageNavigation(setParams)
  const requestedPage = filters.page ?? 1
  useClampPage(requestedPage, query.data && !query.isPlaceholderData ? Math.max(1, query.data.meta.last_page) : null, goToPage)
  const backState: BackState = { from: `${location.pathname}${location.search}`, label: 'la liste des membres' }
  const data = query.data

  return (
    <>
      <PageHeader title="Membres" description="Visiteurs convertis en membres de l’église." />
      <div className="flex flex-col gap-5">
        <section aria-label="Recherche" className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
          <SearchForm
            value={filters.search ?? ''}
            hint="Nom, commune, quartier ou téléphone"
            onSearch={(search) =>
              setParams(() => {
                const next = new URLSearchParams()
                if (search) next.set('search', search)
                return next
              })
            }
          />
        </section>

        {query.isPending ? (
          <LoadingState label="Chargement des membres…" />
        ) : query.isError ? (
          <ErrorState error={query.error} onRetry={() => query.refetch()} />
        ) : data && data.data.length === 0 && requestedPage <= Math.max(1, data.meta.last_page) ? (
          <EmptyState title="Aucun membre trouvé">
            {filters.search ? 'Aucun membre ne correspond à cette recherche.' : 'Aucun visiteur n’a encore été converti en membre.'}
          </EmptyState>
        ) : data ? (
          <div aria-busy={query.isFetching} className={clsx('flex flex-col gap-4', query.isPlaceholderData && 'opacity-60')}>
            <p role="status" className="text-sm text-gray-800">
              {formatNumber(data.meta.total)} membre{data.meta.total > 1 ? 's' : ''}
              {filters.search ? ' correspondant à la recherche' : ''}.
            </p>
            <MemberTable members={data.data} backState={backState} />
            <MemberCards members={data.data} backState={backState} />
            <Pagination meta={data.meta} noun="membre" onPageChange={(p) => goToPage(p)} disabled={query.isPlaceholderData} />
          </div>
        ) : null}
      </div>
    </>
  )
}
