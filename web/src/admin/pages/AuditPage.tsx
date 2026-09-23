import { clsx } from 'clsx'
import { useMemo } from 'react'
import { useSearchParams } from 'react-router'
import type { AuditLog, AuditSubjectType } from '../../shared/api-types'
import { useAuditLogs } from '../api/audit'
import type { AuditFilters } from '../api/keys'
import { useUsers } from '../api/users'
import { useCan } from '../auth/context'
import { Button } from '../components/Button'
import { Field } from '../components/Field'
import { Icon } from '../components/Icon'
import { PageHeader } from '../components/PageHeader'
import { Pagination } from '../components/Pagination'
import { EmptyState, ErrorState, LoadingState } from '../components/States'
import { inputClass, tableCellClass, tableHeadClass } from '../components/styles'
import { useClampPage, usePageNavigation } from '../hooks/usePageParam'
import { formatDateTime } from '../lib/format'
import { parsePositiveInt } from '../lib/visitorFilters'

const AUDIT_ACTIONS: Record<string, string> = {
  'auth.login': 'Connexion',
  'auth.logout': 'Déconnexion',
  'auth.password_changed': 'Mot de passe modifié',
  'auth.two_factor_enabled': 'Double authentification activée',
  'auth.two_factor_disabled': 'Double authentification désactivée',
  'visitor.updated': 'Visiteur modifié',
  'visitor.deleted': 'Visiteur supprimé',
  'visitor.converted': 'Conversion en membre',
  'visitor.unconverted': 'Conversion annulée',
  'note.created': 'Note ajoutée',
  'note.deleted': 'Note supprimée',
  'user.created': 'Administrateur invité',
  'user.updated': 'Administrateur modifié',
  'user.deleted': 'Administrateur supprimé',
  'settings.updated': 'Paramètres modifiés',
  'rotation.updated': 'Rotation modifiée',
  'recipient.created': 'Destinataire ajouté',
  'recipient.updated': 'Destinataire modifié',
  'recipient.deleted': 'Destinataire supprimé',
  'report.sent': 'Rapport envoyé',
  'export.csv': 'Export CSV',
  'export.xlsx': 'Export Excel',
  'export.pdf': 'Export PDF',
}

/** Libellés des alias `subject_type` (morph map imposée côté serveur, contrat §4). */
const SUBJECT_LABELS: Record<AuditSubjectType, string> = {
  visitor: 'Visiteur',
  visit: 'Visite',
  note: 'Note',
  member: 'Membre',
  user: 'Administrateur',
  family: 'Famille',
  rotation: 'Rotation',
  recipient: 'Destinataire',
  report: 'Rapport',
}

function subjectLabel(log: AuditLog): string {
  // Sans sujet (ex. settings.updated) : les champs modifiés figurent dans les détails.
  if (!log.subject_type) return '—'
  const label = SUBJECT_LABELS[log.subject_type] ?? log.subject_type
  return log.subject_id ? `${label} n° ${log.subject_id}` : label
}

/** Détails (meta) affichés en texte brut, jamais interprétés comme HTML. */
function MetaDetails({ meta }: { meta: AuditLog['meta'] }) {
  const entries = meta ? Object.entries(meta) : []
  if (entries.length === 0) return <span className="text-gray-600">—</span>
  return (
    <dl className="flex flex-col gap-0.5 text-xs">
      {entries.slice(0, 8).map(([key, value]) => (
        <div key={key} className="break-all">
          <dt className="inline font-bold text-gray-800">{key} : </dt>
          <dd className="inline text-gray-900">{typeof value === 'string' ? value : JSON.stringify(value)}</dd>
        </div>
      ))}
    </dl>
  )
}

export function AuditPage() {
  const [params, setParams] = useSearchParams()
  const canListUsers = useCan('users.manage')
  const users = useUsers(canListUsers)
  const filters = useMemo<AuditFilters>(() => {
    const action = params.get('action')
    const userId = parsePositiveInt(params.get('user_id'))
    const page = parsePositiveInt(params.get('page'))
    return {
      ...(action && action in AUDIT_ACTIONS ? { action } : {}),
      ...(userId ? { user_id: userId } : {}),
      ...(page && page > 1 ? { page } : {}),
    }
  }, [params])
  const query = useAuditLogs(filters)
  const goToPage = usePageNavigation(setParams)
  const requestedPage = filters.page ?? 1
  useClampPage(requestedPage, query.data && !query.isPlaceholderData ? Math.max(1, query.data.meta.last_page) : null, goToPage)

  const setFilter = (key: 'action' | 'user_id', value: string) =>
    setParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (value) next.set(key, value)
        else next.delete(key)
        next.delete('page')
        return next
      },
      { replace: true },
    )

  const data = query.data
  return (
    <>
      <PageHeader title="Journal d’audit" description="Actions sensibles effectuées dans le tableau de bord." />
      <div className="flex flex-col gap-5">
        <section aria-label="Filtres" className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Field label="Action">
              {(control) => (
                <select {...control} value={filters.action ?? ''} onChange={(e) => setFilter('action', e.target.value)} className={inputClass}>
                  <option value="">Toutes les actions</option>
                  {Object.entries(AUDIT_ACTIONS).map(([value, label]) => (
                    <option key={value} value={value}>
                      {label}
                    </option>
                  ))}
                </select>
              )}
            </Field>
            {canListUsers && (
              <Field label="Utilisateur">
                {(control) => (
                  <select
                    {...control}
                    value={filters.user_id ? String(filters.user_id) : ''}
                    onChange={(e) => setFilter('user_id', e.target.value)}
                    className={inputClass}
                  >
                    <option value="">Tous les utilisateurs</option>
                    {users.data?.map((u) => (
                      <option key={u.id} value={u.id}>
                        {u.name}
                      </option>
                    ))}
                    {filters.user_id && !users.data?.some((u) => u.id === filters.user_id) && (
                      <option value={filters.user_id}>Utilisateur n° {filters.user_id}</option>
                    )}
                  </select>
                )}
              </Field>
            )}
          </div>
          {(filters.action || filters.user_id) && (
            <Button variant="ghost" size="sm" className="mt-4" onClick={() => setParams(new URLSearchParams(), { replace: true })}>
              <Icon name="close" className="size-4" />
              Réinitialiser les filtres
            </Button>
          )}
        </section>

        {query.isPending ? (
          <LoadingState label="Chargement du journal…" />
        ) : query.isError ? (
          <ErrorState error={query.error} onRetry={() => query.refetch()} />
        ) : data && data.data.length === 0 && requestedPage <= Math.max(1, data.meta.last_page) ? (
          <EmptyState title="Aucune entrée">Aucune action ne correspond à ces filtres.</EmptyState>
        ) : data ? (
          <div aria-busy={query.isFetching} className={clsx('flex flex-col gap-4', query.isPlaceholderData && 'opacity-60')}>
            <div className="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm">
              <table className="min-w-full divide-y divide-gray-200">
                <caption className="sr-only">Journal d’audit</caption>
                <thead className="bg-gray-50">
                  <tr>
                    <th scope="col" className={tableHeadClass}>Date</th>
                    <th scope="col" className={tableHeadClass}>Utilisateur</th>
                    <th scope="col" className={tableHeadClass}>Action</th>
                    <th scope="col" className={tableHeadClass}>Objet</th>
                    <th scope="col" className={tableHeadClass}>Adresse IP</th>
                    <th scope="col" className={tableHeadClass}>Détails</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {data.data.map((log) => (
                    <tr key={log.id}>
                      <td className={clsx(tableCellClass, 'whitespace-nowrap')}>
                        <time dateTime={log.created_at}>{formatDateTime(log.created_at)}</time>
                      </td>
                      <td className={tableCellClass}>{log.user?.name ?? 'Système'}</td>
                      <th scope="row" className={clsx(tableCellClass, 'text-left font-bold')}>
                        {AUDIT_ACTIONS[log.action] ?? log.action}
                      </th>
                      <td className={clsx(tableCellClass, 'whitespace-nowrap')}>{subjectLabel(log)}</td>
                      <td className={clsx(tableCellClass, 'whitespace-nowrap font-mono text-xs')}>{log.ip ?? '—'}</td>
                      <td className={clsx(tableCellClass, 'min-w-48')}>
                        <MetaDetails meta={log.meta} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination meta={data.meta} noun="entrée" onPageChange={(p) => goToPage(p)} disabled={query.isPlaceholderData} />
          </div>
        ) : null}
      </div>
    </>
  )
}
