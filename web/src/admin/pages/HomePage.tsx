import { clsx } from 'clsx'
import { Link } from 'react-router'
import type { Stats } from '../../shared/api-types'
import { MONTH_LABELS, STATUS_LABELS, VISITOR_STATUSES, type VisitorStatus } from '../../shared/domain'
import { useStats } from '../api/stats'
import { useAuth, useCan } from '../auth/context'
import { Card } from '../components/Card'
import { PageHeader } from '../components/PageHeader'
import { ErrorState, LoadingState } from '../components/States'
import { linkClass } from '../components/styles'
import { capitalize, formatMonth, formatNumber, todayParts } from '../lib/format'

const STATUS_BAR: Record<VisitorStatus, string> = {
  prospect: 'bg-blue-600',
  recurrent: 'bg-amber-600',
  membre_potentiel: 'bg-church-purple',
  membre: 'bg-green-600',
}

const VISIT_SERIES = [
  { key: 'v1', label: '1re visite', color: 'bg-church-purple' },
  { key: 'v2', label: '2e visite', color: 'bg-church-gold' },
  { key: 'v3', label: '3e visite', color: 'bg-green-600' },
] as const

const MONTH_SHORT = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.']
// Sur mobile, douze colonnes n'offrent qu'une vingtaine de pixels chacune : l'abréviation y serait
// coupée (« ja… »). On affiche alors l'initiale du mois ; le libellé complet reste dans le texte
// lu par les lecteurs d'écran (« Janvier 2026 : 12 visites »).
const MONTH_INITIAL = MONTH_LABELS.map((label) => label[0].toUpperCase())

function percent(value: number, total: number): number {
  return total > 0 ? Math.round((value / total) * 100) : 0
}

function KeyFigures({ stats }: { stats: Stats }) {
  const figures = [
    { label: 'Total visiteurs', value: stats.total_visitors, hint: 'depuis le début' },
    { label: 'Nouveaux aujourd’hui', value: stats.new_today, hint: '1re visite ce jour' },
    { label: 'Visites ce mois-ci', value: stats.visits_this_month, hint: 'toutes visites confondues' },
    { label: 'Membres', value: stats.by_status.membre ?? 0, hint: 'visiteurs convertis' },
  ]
  return (
    <dl className="grid grid-cols-2 gap-4 lg:grid-cols-4">
      {figures.map((f) => (
        <div key={f.label} className="flex flex-col rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
          <dt className="text-sm font-bold text-gray-700">{f.label}</dt>
          <dd className="order-first font-display text-3xl font-bold text-church-purple-dk">{formatNumber(f.value)}</dd>
          <dd className="mt-1 text-xs text-gray-600">{f.hint}</dd>
        </div>
      ))}
    </dl>
  )
}

function StatusBreakdown({ stats }: { stats: Stats }) {
  const total = VISITOR_STATUSES.reduce((sum, s) => sum + (stats.by_status[s] ?? 0), 0)
  return (
    <Card
      title="Répartition par statut"
      className="lg:col-span-2"
      actions={
        <Link to="/admin/visiteurs" className={linkClass}>
          Voir les visiteurs
        </Link>
      }
    >
      <ul className="flex flex-col gap-4">
        {VISITOR_STATUSES.map((status) => {
          const count = stats.by_status[status] ?? 0
          const pct = percent(count, total)
          return (
            <li key={status}>
              <div className="mb-1 flex items-baseline justify-between gap-3 text-sm">
                <span className="font-bold text-gray-900">{STATUS_LABELS[status]}</span>
                <span className="text-gray-800">
                  {formatNumber(count)} <span className="text-gray-600">({pct} %)</span>
                </span>
              </div>
              <div className="h-2.5 overflow-hidden rounded-full bg-gray-100" aria-hidden="true">
                <div className={clsx('h-full rounded-full', STATUS_BAR[status])} style={{ width: `${pct}%` }} />
              </div>
            </li>
          )
        })}
      </ul>
    </Card>
  )
}

function FamilyCard({ stats }: { stats: Stats }) {
  const today = todayParts()
  return (
    <section
      aria-labelledby="famille-service"
      className="flex flex-col justify-between gap-4 rounded-2xl bg-church-purple-dk p-6 text-white shadow-sm"
    >
      <div>
        <h2 id="famille-service" className="text-xs font-bold uppercase tracking-widest text-church-gold-lt">
          Famille de service
        </h2>
        <p className="mt-3 font-display text-3xl font-bold">{stats.current_family?.name ?? 'Non définie'}</p>
        <p className="mt-1 text-sm text-purple-100">{capitalize(formatMonth(today.year, today.month))}</p>
      </div>
      <p className="border-t border-white/20 pt-3 text-sm text-purple-100">
        Mois prochain : <strong className="text-white">{stats.next_family?.name ?? 'non définie'}</strong>
      </p>
    </section>
  )
}

function VisitsByFamily({ stats }: { stats: Stats }) {
  const rows = stats.visits_by_family.map((row) => ({ ...row, total: row.v1 + row.v2 + row.v3 }))
  const max = Math.max(1, ...rows.map((r) => r.total))
  // L'API renvoie toujours les 7 familles, même à 0 : l'état vide dépend des totaux.
  const empty = rows.every((r) => r.total === 0)
  return (
    <Card title="Visites par famille d’accueil" description={`Année ${todayParts().year}`}>
      {empty ? (
        <p className="text-sm text-gray-700">Aucune visite enregistrée cette année.</p>
      ) : (
        <>
          <ul className="mb-4 flex flex-wrap gap-4 text-xs text-gray-800" aria-label="Légende">
            {VISIT_SERIES.map((s) => (
              <li key={s.key} className="flex items-center gap-1.5">
                <span aria-hidden="true" className={clsx('size-3 rounded-sm', s.color)} />
                {s.label}
              </li>
            ))}
          </ul>
          <ul className="flex flex-col gap-4">
            {rows.map((row) => (
              <li key={row.family.id}>
                <div className="mb-1 flex flex-wrap items-baseline justify-between gap-x-3 text-sm">
                  <span className="font-bold text-gray-900">{row.family.name}</span>
                  <span className="text-gray-800">
                    {formatNumber(row.total)} visite{row.total > 1 ? 's' : ''}{' '}
                    <span className="text-gray-600">
                      (1re : {row.v1} · 2e : {row.v2} · 3e : {row.v3})
                    </span>
                  </span>
                </div>
                <div className="flex h-3 overflow-hidden rounded-full bg-gray-100" aria-hidden="true">
                  {VISIT_SERIES.map((s) => (
                    <div key={s.key} className={s.color} style={{ width: `${(row[s.key] / max) * 100}%` }} />
                  ))}
                </div>
              </li>
            ))}
          </ul>
        </>
      )}
    </Card>
  )
}

function MonthlyVisits({ stats }: { stats: Stats }) {
  const months = stats.monthly_visits
  const max = Math.max(1, ...months.map((m) => m.count))
  return (
    <Card title="Visites des 12 derniers mois">
      {months.length === 0 ? (
        <p className="text-sm text-gray-700">Aucune donnée pour le moment.</p>
      ) : (
        <ol className="flex h-56 items-end gap-1.5 sm:gap-2">
          {months.map((m) => {
            const height = (m.count / max) * 100
            return (
              <li key={`${m.year}-${m.month}`} className="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1">
                <span className="sr-only">
                  {capitalize(`${MONTH_LABELS[m.month - 1] ?? ''} ${m.year}`)} : {formatNumber(m.count)} visite
                  {m.count > 1 ? 's' : ''}
                </span>
                <span aria-hidden="true" className="text-xs font-bold text-gray-900">
                  {formatNumber(m.count)}
                </span>
                <div
                  aria-hidden="true"
                  className="w-full max-w-10 rounded-t-md bg-church-purple"
                  style={{ height: `${Math.max(height, m.count > 0 ? 2 : 0)}%` }}
                />
                <span aria-hidden="true" className="w-full text-center text-[0.7rem] leading-tight text-gray-700">
                  <span className="sm:hidden">{MONTH_INITIAL[m.month - 1]}</span>
                  <span className="hidden sm:inline">{MONTH_SHORT[m.month - 1]}</span>
                  {m.month === 1 && (
                    <span className="block">
                      <span className="sm:hidden">{`’${String(m.year).slice(-2)}`}</span>
                      <span className="hidden sm:inline">{m.year}</span>
                    </span>
                  )}
                </span>
              </li>
            )
          })}
        </ol>
      )}
    </Card>
  )
}

export function HomePage() {
  const { user } = useAuth()
  const canView = useCan('visitors.view')
  const stats = useStats(canView)
  const firstName = user?.name.split(/\s+/)[0] ?? ''

  return (
    <>
      <PageHeader
        title="Accueil"
        description={`Bonjour ${firstName}, voici un aperçu de l’activité de la Maison de la Destinée.`}
      />
      {!canView ? (
        <p className="text-gray-800">Utilisez le menu pour accéder aux sections autorisées pour votre compte.</p>
      ) : stats.isPending ? (
        <LoadingState label="Chargement des statistiques…" />
      ) : stats.isError ? (
        <ErrorState error={stats.error} onRetry={() => stats.refetch()} />
      ) : (
        <div className="flex flex-col gap-6">
          <KeyFigures stats={stats.data} />
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <StatusBreakdown stats={stats.data} />
            <FamilyCard stats={stats.data} />
          </div>
          <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <VisitsByFamily stats={stats.data} />
            <MonthlyVisits stats={stats.data} />
          </div>
        </div>
      )}
    </>
  )
}
