import { clsx } from 'clsx'
import { useState } from 'react'
import { Link, useLocation, useParams } from 'react-router'
import type { ReportDetail } from '../../../shared/api-types'
import { useReport, useSendReport } from '../../api/reports'
import { useCan } from '../../auth/context'
import { Button } from '../../components/Button'
import { Card } from '../../components/Card'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Icon } from '../../components/Icon'
import { PageHeader } from '../../components/PageHeader'
import { ErrorState, LoadingState } from '../../components/States'
import { useToast } from '../../components/toast/context'
import { linkClass, tableCellClass, tableHeadClass } from '../../components/styles'
import { ordinal } from '../../lib/answers'
import { errorMessage, isApiError } from '../../lib/errors'
import { capitalize, formatDate, formatDateTime, formatMonth, ofMonth, todayParts } from '../../lib/format'
import { parseMonth, parseYear } from '../../lib/params'
import type { BackState } from '../../lib/paths'
import { formatPhone } from '../../lib/phone'
import { NotFoundPage } from '../NotFoundPage'

type SendStep = 'confirm' | 'force' | null

function SendReport({ report }: { report: ReportDetail }) {
  const toast = useToast()
  const mutation = useSendReport(report.year, report.month)
  const [step, setStep] = useState<SendStep>(null)
  const ofLabel = ofMonth(report.year, report.month)

  const close = () => {
    mutation.reset()
    setStep(null)
  }
  const send = (force: boolean) =>
    mutation.mutate(force, {
      onSuccess: ({ data }) => {
        setStep(null)
        toast.success(`Rapport ${ofLabel} envoyé à ${data.recipients.length} destinataire${data.recipients.length > 1 ? 's' : ''}.`)
      },
      onError: (error) => {
        // Envoyé entre-temps (ex. envoi automatique) : confirmation explicite avant un renvoi forcé.
        if (isApiError(error, 409, 'already_sent')) {
          mutation.reset()
          setStep('force')
        }
      },
    })

  const alreadySent = Boolean(report.dispatch)
  return (
    <>
      <Button variant="gold" onClick={() => setStep(alreadySent ? 'force' : 'confirm')}>
        <Icon name="mail" className="size-4" />
        {alreadySent ? 'Renvoyer le rapport' : 'Envoyer le rapport'}
      </Button>
      {step === 'confirm' && (
        <ConfirmDialog
          title="Envoyer le rapport ?"
          confirmLabel="Envoyer"
          pendingLabel="Envoi…"
          pending={mutation.isPending}
          error={mutation.isError ? errorMessage(mutation.error) : null}
          onCancel={close}
          onConfirm={() => send(false)}
        >
          <p>
            Le rapport <strong>{ofLabel}</strong> (famille {report.family?.name ?? 'non définie'}) sera envoyé par e-mail
            aux destinataires configurés pour cette famille et aux destinataires globaux.
          </p>
        </ConfirmDialog>
      )}
      {step === 'force' && (
        <ConfirmDialog
          title="Ce rapport a déjà été envoyé"
          confirmLabel="Renvoyer quand même"
          pendingLabel="Envoi…"
          pending={mutation.isPending}
          error={mutation.isError ? errorMessage(mutation.error) : null}
          onCancel={close}
          onConfirm={() => send(true)}
        >
          <p>
            {report.dispatch
              ? `Le rapport ${ofLabel} a été envoyé le ${formatDateTime(report.dispatch.sent_at)}.`
              : `Le rapport ${ofLabel} vient d’être envoyé (probablement par l’envoi automatique).`}
          </p>
          <p>Voulez-vous vraiment l’envoyer une nouvelle fois ? Les destinataires le recevront en double.</p>
        </ConfirmDialog>
      )}
    </>
  )
}

function Summary({ report }: { report: ReportDetail }) {
  const items = [
    { label: '1re visite', value: report.counts.v1 },
    { label: '2e visite', value: report.counts.v2 },
    { label: '3e visite', value: report.counts.v3 },
    { label: 'Total des visites', value: report.counts.total },
    { label: 'Conversions', value: report.counts.conversions },
  ]
  return (
    <dl className="grid grid-cols-2 gap-3 sm:grid-cols-5">
      {items.map((item) => (
        <div key={item.label} className="flex flex-col rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
          <dt className="text-sm font-bold text-gray-700">{item.label}</dt>
          <dd className="order-first font-display text-2xl font-bold text-church-purple-dk">{item.value}</dd>
        </div>
      ))}
    </dl>
  )
}

function ReportView({ report }: { report: ReportDetail }) {
  const location = useLocation()
  const canSend = useCan('reports.send')
  const label = capitalize(formatMonth(report.year, report.month))
  const today = todayParts()
  const isCurrent = report.year === today.year && report.month === today.month
  const backState: BackState = { from: `${location.pathname}${location.search}`, label: `le rapport ${ofMonth(report.year, report.month)}` }
  const yearQuery = report.year === today.year ? '' : `?annee=${report.year}`

  return (
    <>
      <PageHeader
        title={`Rapport ${ofMonth(report.year, report.month)}`}
        documentTitle={`Rapport ${label}`}
        before={
          <Link to={`/admin/rapports${yearQuery}`} className={`${linkClass} inline-flex w-fit items-center gap-1 text-sm`}>
            <Icon name="left" className="size-4" />
            Retour aux rapports
          </Link>
        }
        description={
          <>
            Famille de service : <strong className="text-gray-900">{report.family?.name ?? 'non définie'}</strong>
            {isCurrent && <span className="block">Mois en cours : le rapport sera complet à la fin du mois.</span>}
          </>
        }
        actions={canSend ? <SendReport report={report} /> : undefined}
      />

      <div className="flex flex-col gap-6">
        <Summary report={report} />

        <Card title="Envoi par e-mail">
          {report.dispatch ? (
            <div className="text-sm text-gray-900">
              <p>
                Envoyé le <strong>{formatDateTime(report.dispatch.sent_at)}</strong> à {report.dispatch.recipients.length}{' '}
                destinataire{report.dispatch.recipients.length > 1 ? 's' : ''} :
              </p>
              <ul className="mt-2 flex flex-wrap gap-2">
                {report.dispatch.recipients.map((email) => (
                  <li key={email} className="rounded-full bg-gray-100 px-3 py-1 text-gray-800">
                    {email}
                  </li>
                ))}
              </ul>
            </div>
          ) : (
            <p className="text-sm text-gray-800">
              Pas encore envoyé. L’envoi automatique a lieu chaque jour à 8 h pour le mois précédent.
            </p>
          )}
        </Card>

        <Card title="Visiteurs accueillis" description={`${report.visitors.length} visite${report.visitors.length > 1 ? 's' : ''}`}>
          {report.visitors.length === 0 ? (
            <p className="text-sm text-gray-700">Aucun visiteur accueilli par cette famille ce mois-ci.</p>
          ) : (
            <div className="-mx-5 overflow-x-auto sm:-mx-6">
              <table className="min-w-full divide-y divide-gray-200">
                <caption className="sr-only">Visiteurs accueillis en {formatMonth(report.year, report.month)}</caption>
                <thead className="bg-gray-50">
                  <tr>
                    <th scope="col" className={tableHeadClass}>Nom</th>
                    <th scope="col" className={tableHeadClass}>Téléphone</th>
                    <th scope="col" className={tableHeadClass}>Visite</th>
                    <th scope="col" className={tableHeadClass}>Date</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {report.visitors.map((v) => (
                    <tr key={`${v.id}-${v.visit_number}`}>
                      <th scope="row" className={clsx(tableCellClass, 'text-left font-normal')}>
                        <Link to={`/admin/visiteurs/${v.id}`} state={backState} className={linkClass}>
                          {v.full_name}
                        </Link>
                      </th>
                      <td className={clsx(tableCellClass, 'whitespace-nowrap')}>{formatPhone(v.phone)}</td>
                      <td className={tableCellClass}>{ordinal(v.visit_number)}</td>
                      <td className={clsx(tableCellClass, 'whitespace-nowrap')}>{formatDate(v.visit_date)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>

        <Card title="Conversions" description="Membres convertis ce mois-ci dont la 1re visite a été accueillie par cette famille.">
          {report.conversions.length === 0 ? (
            <p className="text-sm text-gray-700">Aucune conversion ce mois-ci.</p>
          ) : (
            <ul className="flex flex-col divide-y divide-gray-100">
              {report.conversions.map((c) => (
                <li key={c.id} className="flex flex-wrap justify-between gap-2 py-2 text-sm">
                  <Link to={`/admin/visiteurs/${c.id}`} state={backState} className={linkClass}>
                    {c.full_name}
                  </Link>
                  <span className="text-gray-800">{formatDate(c.converted_at)}</span>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </>
  )
}

export function ReportDetailPage() {
  const params = useParams()
  const year = parseYear(params.year)
  const month = parseMonth(params.month)
  const valid = year !== undefined && month !== undefined
  const query = useReport(year ?? 0, month ?? 0, valid)

  if (!valid) return <NotFoundPage message="Ce rapport n’existe pas." />
  if (query.isPending) return <LoadingState label="Chargement du rapport…" />
  if (query.isError) {
    if (isApiError(query.error, 404)) {
      return <NotFoundPage message="Ce mois est dans le futur ou antérieur aux données disponibles." />
    }
    return (
      <>
        <PageHeader title={`Rapport ${ofMonth(year, month)}`} />
        <ErrorState error={query.error} onRetry={() => query.refetch()} />
      </>
    )
  }
  return <ReportView report={query.data} />
}
