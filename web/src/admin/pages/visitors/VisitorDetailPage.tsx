import { useState, type ReactNode } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router'
import type { VisitorDetail } from '../../../shared/api-types'
import { SOURCE_LABELS } from '../../../shared/domain'
import { useConvertVisitor, useDeleteVisitor, useUnconvertVisitor, useVisitor } from '../../api/visitors'
import { useCan } from '../../auth/context'
import { Button } from '../../components/Button'
import { Card } from '../../components/Card'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Icon } from '../../components/Icon'
import { PageHeader } from '../../components/PageHeader'
import { ErrorState, LoadingState } from '../../components/States'
import { StatusBadge } from '../../components/StatusBadge'
import { useToast } from '../../components/toast/context'
import { linkClass } from '../../components/styles'
import { describeAnswers, ordinal } from '../../lib/answers'
import { errorMessage, isApiError } from '../../lib/errors'
import { formatDate, formatDateTime } from '../../lib/format'
import { readBackState, type BackState } from '../../lib/paths'
import { formatPhone, whatsappLink } from '../../lib/phone'
import { parsePositiveInt } from '../../lib/visitorFilters'
import { NotFoundPage } from '../NotFoundPage'
import { NotesSection } from './NotesSection'
import { VisitorEditDialog } from './VisitorEditDialog'

const DEFAULT_BACK: BackState = { from: '/admin/visiteurs', label: 'la liste des visiteurs' }

function Detail({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-xs font-bold uppercase tracking-wide text-gray-700">{label}</dt>
      <dd className="break-words text-sm text-gray-900">{children}</dd>
    </div>
  )
}

function BackLink({ back }: { back: BackState }) {
  return (
    <Link to={back.from} className={`${linkClass} inline-flex w-fit items-center gap-1 text-sm print:hidden`}>
      <Icon name="left" className="size-4" />
      Retour à {back.label}
    </Link>
  )
}

type PendingAction = 'convert' | 'unconvert' | 'delete' | null

function VisitorActions({ visitor, back }: { visitor: VisitorDetail; back: BackState }) {
  const toast = useToast()
  const navigate = useNavigate()
  const canUpdate = useCan('visitors.update')
  const canConvert = useCan('visitors.convert')
  const canUnconvert = useCan('visitors.unconvert')
  const canDelete = useCan('visitors.delete')
  const [editing, setEditing] = useState(false)
  const [action, setAction] = useState<PendingAction>(null)
  const convert = useConvertVisitor(visitor.id)
  const unconvert = useUnconvertVisitor(visitor.id)
  const remove = useDeleteVisitor(visitor.id)
  const eligible = visitor.status === 'membre_potentiel'
  const isMember = visitor.status === 'membre'

  const close = () => {
    convert.reset()
    unconvert.reset()
    remove.reset()
    setAction(null)
  }

  if (!canUpdate && !canConvert && !canUnconvert && !canDelete) return null

  return (
    <>
      <div className="flex flex-wrap gap-2">
        {canUpdate && (
          <Button variant="secondary" onClick={() => setEditing(true)}>
            <Icon name="edit" className="size-4" />
            Modifier
          </Button>
        )}
        {isMember
          ? canUnconvert && (
              <Button variant="secondary" onClick={() => setAction('unconvert')}>
                Annuler la conversion
              </Button>
            )
          : canConvert && (
              <Button
                variant="gold"
                disabled={!eligible}
                aria-describedby={eligible ? undefined : 'conversion-hint'}
                onClick={() => setAction('convert')}
              >
                <Icon name="check" className="size-4" />
                Convertir en membre
              </Button>
            )}
        {canDelete && (
          <Button variant="danger" onClick={() => setAction('delete')}>
            <Icon name="trash" className="size-4" />
            Supprimer
          </Button>
        )}
      </div>
      {!isMember && canConvert && !eligible && (
        <p id="conversion-hint" className="text-sm text-gray-700 sm:basis-full sm:text-right">
          Conversion possible après la 3e visite (statut « Membre potentiel »).
        </p>
      )}

      {editing && <VisitorEditDialog visitor={visitor} onClose={() => setEditing(false)} />}

      {action === 'convert' && (
        <ConfirmDialog
          title="Convertir en membre ?"
          confirmLabel="Convertir en membre"
          pendingLabel="Conversion…"
          pending={convert.isPending}
          error={convert.isError ? errorMessage(convert.error) : null}
          onCancel={close}
          onConfirm={() =>
            convert.mutate(undefined, {
              onSuccess: () => {
                close()
                toast.success(`${visitor.full_name} est désormais membre.`)
              },
            })
          }
        >
          <p>
            <strong>{visitor.full_name}</strong> sera enregistré(e) comme membre de l’église. Cette action est journalisée
            et peut être annulée.
          </p>
        </ConfirmDialog>
      )}

      {action === 'unconvert' && (
        <ConfirmDialog
          title="Annuler la conversion ?"
          confirmLabel="Annuler la conversion"
          pendingLabel="Annulation…"
          pending={unconvert.isPending}
          error={unconvert.isError ? errorMessage(unconvert.error) : null}
          onCancel={close}
          onConfirm={() =>
            unconvert.mutate(undefined, {
              onSuccess: () => {
                close()
                toast.success(`La conversion de ${visitor.full_name} a été annulée.`)
              },
            })
          }
        >
          <p>
            <strong>{visitor.full_name}</strong> ne sera plus membre ; son statut sera recalculé d’après ses visites.
          </p>
        </ConfirmDialog>
      )}

      {action === 'delete' && (
        <ConfirmDialog
          title="Supprimer ce visiteur ?"
          confirmLabel="Supprimer définitivement"
          pendingLabel="Suppression…"
          tone="danger"
          pending={remove.isPending}
          error={remove.isError ? errorMessage(remove.error) : null}
          onCancel={close}
          onConfirm={() =>
            remove.mutate(undefined, {
              onSuccess: () => {
                toast.success(`${visitor.full_name} a été supprimé(e).`)
                navigate(back.from, { replace: true })
              },
            })
          }
        >
          <p>
            <strong>{visitor.full_name}</strong> sera supprimé(e) avec ses visites, ses notes et son éventuel statut de
            membre.
          </p>
          <p className="font-bold text-red-800">Cette action est irréversible.</p>
        </ConfirmDialog>
      )}
    </>
  )
}

function Visits({ visitor }: { visitor: VisitorDetail }) {
  const visits = [...visitor.visits].sort((a, b) => a.visit_number - b.visit_number)
  return (
    <Card title="Parcours des visites" description={`${visitor.visit_count} visite${visitor.visit_count > 1 ? 's' : ''} sur 3`}>
      {visits.length === 0 ? (
        <p className="text-sm text-gray-700">Aucune visite enregistrée.</p>
      ) : (
        <ol className="relative flex flex-col gap-5 border-l-2 border-church-purple/30 pl-6">
          {visits.map((visit) => {
            const answers = describeAnswers(visit.answers)
            return (
              <li key={visit.id} className="relative">
                <span
                  aria-hidden="true"
                  className="absolute -left-[33px] top-0.5 grid size-5 place-items-center rounded-full bg-church-purple text-[0.65rem] font-bold text-white"
                >
                  {visit.visit_number}
                </span>
                <h3 className="font-bold text-gray-900">
                  {ordinal(visit.visit_number)} visite · <time dateTime={visit.visit_date}>{formatDate(visit.visit_date)}</time>
                </h3>
                <p className="text-sm text-gray-700">
                  Famille d’accueil : <span className="font-bold text-gray-900">{visit.family?.name ?? 'non définie'}</span>
                </p>
                {answers.length > 0 && (
                  <dl className="mt-2 flex flex-col gap-1 text-sm">
                    {answers.map((a) => (
                      <div key={a.label}>
                        <dt className="inline font-bold text-gray-800">{a.label} : </dt>
                        <dd className="inline text-gray-900">{a.value}</dd>
                      </div>
                    ))}
                  </dl>
                )}
              </li>
            )
          })}
        </ol>
      )}
    </Card>
  )
}

function VisitorView({ visitor, back }: { visitor: VisitorDetail; back: BackState }) {
  const source = SOURCE_LABELS[visitor.source] ?? visitor.source
  return (
    <>
      <PageHeader
        title={visitor.full_name}
        documentTitle={`${visitor.full_name} · Visiteurs`}
        before={<BackLink back={back} />}
        description={
          <div className="flex flex-wrap items-center gap-2">
            <StatusBadge status={visitor.status} />
            <span>Enregistré(e) le {formatDate(visitor.created_at)}</span>
          </div>
        }
      />
      <div className="mb-6 flex flex-col gap-2 sm:items-end">
        <VisitorActions visitor={visitor} back={back} />
      </div>

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div className="flex flex-col gap-6 xl:col-span-1">
          <Card title="Coordonnées">
            <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-1">
              <Detail label="Téléphone">
                <a href={`tel:${visitor.phone}`} className={linkClass}>
                  {formatPhone(visitor.phone)}
                </a>
              </Detail>
              <Detail label="WhatsApp">
                {visitor.whatsapp ? (
                  <a href={whatsappLink(visitor.whatsapp)} target="_blank" rel="noopener noreferrer" className={linkClass}>
                    {formatPhone(visitor.whatsapp)}
                    {' '}<span className="sr-only">(ouvre WhatsApp dans un nouvel onglet)</span>
                  </a>
                ) : (
                  'Non renseigné'
                )}
              </Detail>
              <Detail label="Commune">{visitor.commune || '—'}</Detail>
              <Detail label="Quartier">{visitor.quartier || '—'}</Detail>
              <Detail label="Groupe WhatsApp">{visitor.wants_whatsapp_group ? 'Souhaite le rejoindre' : 'Non souhaité'}</Detail>
            </dl>
          </Card>

          <Card title="Provenance">
            <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-1">
              <Detail label="Comment nous a-t-il/elle connus ?">
                {source}
                {visitor.source_other && <span className="block text-gray-700">« {visitor.source_other} »</span>}
              </Detail>
              {visitor.invited_by && <Detail label="Invité(e) par">{visitor.invited_by}</Detail>}
              {visitor.inviter_family && <Detail label="Famille de l’invitant">{visitor.inviter_family.name}</Detail>}
              <Detail label="Consentement">
                {visitor.consent_at ? `Donné le ${formatDateTime(visitor.consent_at)}` : 'Non enregistré'}
              </Detail>
            </dl>
          </Card>

          {visitor.member && (
            <Card title="Membre">
              <dl className="grid grid-cols-1 gap-4">
                <Detail label="Converti(e) le">{formatDateTime(visitor.member.converted_at)}</Detail>
                <Detail label="Par">{visitor.member.converted_by?.name ?? 'Compte supprimé'}</Detail>
              </dl>
            </Card>
          )}
        </div>

        <div className="flex flex-col gap-6 xl:col-span-2">
          <Visits visitor={visitor} />
          <NotesSection visitorId={visitor.id} notes={visitor.notes} />
        </div>
      </div>
    </>
  )
}

export function VisitorDetailPage() {
  const params = useParams()
  const location = useLocation()
  const back = readBackState(location.state, DEFAULT_BACK)
  const id = parsePositiveInt(params.id ?? null)
  const query = useVisitor(id ?? 0, id !== undefined)

  if (!id) return <NotFoundPage message="Ce visiteur n’existe pas." />
  if (query.isPending) return <LoadingState label="Chargement de la fiche…" />
  if (query.isError) {
    if (isApiError(query.error, 404)) return <NotFoundPage message="Ce visiteur n’existe pas ou a été supprimé." />
    return (
      <>
        <PageHeader title="Fiche visiteur" before={<BackLink back={back} />} />
        <ErrorState error={query.error} onRetry={() => query.refetch()} />
      </>
    )
  }
  return <VisitorView visitor={query.data} back={back} />
}
