import { clsx } from 'clsx'
import { useState } from 'react'
import type { ChurchEvent } from '../../../shared/api-types'
import { useDeleteEvent, useEvents, useUpdateEvent } from '../../api/events'
import { useCan } from '../../auth/context'
import { Button } from '../../components/Button'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Icon } from '../../components/Icon'
import { PageHeader } from '../../components/PageHeader'
import { EmptyState, ErrorState, LoadingState } from '../../components/States'
import { Badge } from '../../components/StatusBadge'
import { useToast } from '../../components/toast/context'
import { buttonClass, tableCellClass, tableHeadClass } from '../../components/styles'
import { copyText } from '../../lib/clipboard'
import { errorMessage } from '../../lib/errors'
import { formatDate, formatNumber } from '../../lib/format'
import { EventDialog } from './EventDialog'
import { EventPosterPanel } from './EventPosterPanel'

function registrationsLabel(event: ChurchEvent): string {
  return `${formatNumber(event.visitors_count)} inscrit${event.visitors_count > 1 ? 's' : ''}`
}

/** Fiche d'inscription papier (PDF) d'un événement ; le cookie de session suffit. */
function paperFormUrl(event: ChurchEvent, perPage: 1 | 2): string {
  return `/api/admin/events/${event.id}/formulaire.pdf?par_page=${perPage}`
}

/**
 * Deuxième voie d'enregistrement, à côté du QR code : une fiche à remplir à la main, pour les
 * personnes sans téléphone ou quand la file d'attente s'allonge. Deux fiches par page par défaut.
 */
function PaperFormLinks({ event }: { event: ChurchEvent }) {
  return (
    <span role="group" aria-label={`Fiche d’inscription papier de ${event.name}`} className="flex gap-1">
      <a href={paperFormUrl(event, 2)} download className={buttonClass('ghost', 'sm')}>
        <Icon name="printer" className="size-4" />
        Fiche papier (PDF) <span className="sr-only">, 2 fiches par page</span>
      </a>
      <a href={paperFormUrl(event, 1)} download className={buttonClass('ghost', 'sm')}>
        1 par page
      </a>
    </span>
  )
}

interface RowActionsProps {
  event: ChurchEvent
  posterOpen: boolean
  onPoster: () => void
  onEdit: () => void
  onDelete: () => void
}

function RowActions({ event, posterOpen, onPoster, onEdit, onDelete }: RowActionsProps) {
  const toast = useToast()
  const canManage = useCan('events.manage')
  const update = useUpdateEvent()

  const copy = async () => {
    if (await copyText(event.url)) toast.success('Lien copié dans le presse-papiers.')
    else toast.error('Copie impossible : sélectionnez le lien affiché puis copiez-le.')
  }

  const toggle = () =>
    update.mutate(
      { id: event.id, active: !event.active },
      {
        onSuccess: () =>
          toast.success(
            event.active
              ? `Le lien de « ${event.name} » est désactivé.`
              : `Le lien de « ${event.name} » est de nouveau actif.`,
          ),
        onError: (error) => toast.error(errorMessage(error)),
      },
    )

  return (
    <div className="flex flex-wrap gap-1">
      <Button variant="ghost" size="sm" onClick={copy}>
        <Icon name="copy" className="size-4" />
        Copier le lien <span className="sr-only">de {event.name}</span>
      </Button>
      <Button variant="ghost" size="sm" aria-expanded={posterOpen} onClick={onPoster}>
        <Icon name="qr" className="size-4" />
        QR code <span className="sr-only">de {event.name}</span>
      </Button>
      <PaperFormLinks event={event} />
      {canManage && (
        <>
          <Button variant="ghost" size="sm" onClick={onEdit}>
            <Icon name="edit" className="size-4" />
            Modifier <span className="sr-only">{event.name}</span>
          </Button>
          <Button variant="ghost" size="sm" pending={update.isPending} pendingLabel="Enregistrement…" onClick={toggle}>
            <Icon name={event.active ? 'close' : 'check'} className="size-4" />
            {event.active ? 'Désactiver' : 'Activer'} <span className="sr-only">{event.name}</span>
          </Button>
          <Button variant="ghost" size="sm" onClick={onDelete} className="text-red-800 hover:bg-red-50">
            <Icon name="trash" className="size-4" />
            Supprimer <span className="sr-only">{event.name}</span>
          </Button>
        </>
      )}
    </div>
  )
}

function ActiveBadge({ event }: { event: ChurchEvent }) {
  return event.active ? <Badge tone="green">Lien actif</Badge> : <Badge tone="red">Lien désactivé</Badge>
}

function EventLink({ event }: { event: ChurchEvent }) {
  return (
    <a
      href={event.url}
      target="_blank"
      rel="noopener noreferrer"
      className="break-all font-bold text-church-purple underline decoration-church-purple/40 underline-offset-2 hover:decoration-church-purple focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-church-purple"
    >
      {event.url}
      {' '}<span className="sr-only">(nouvel onglet)</span>
    </a>
  )
}

export function EventsPage() {
  const toast = useToast()
  const canManage = useCan('events.manage')
  const events = useEvents()
  const remove = useDeleteEvent()
  const [dialog, setDialog] = useState<{ event: ChurchEvent | null } | null>(null)
  const [deleting, setDeleting] = useState<ChurchEvent | null>(null)
  const [posterId, setPosterId] = useState<number | null>(null)

  const list = events.data ?? []
  const poster = list.find((e) => e.id === posterId) ?? null

  const closeDelete = () => {
    remove.reset()
    setDeleting(null)
  }

  return (
    <>
      <PageHeader
        title="Événements"
        description="Cultes spéciaux disposant de leur propre lien d’inscription et de leur QR code."
        actions={
          canManage ? (
            <Button onClick={() => setDialog({ event: null })}>
              <Icon name="plus" className="size-4" />
              Créer un événement
            </Button>
          ) : undefined
        }
      />

      {poster && <EventPosterPanel key={poster.id} event={poster} onClose={() => setPosterId(null)} />}

      {events.isPending ? (
        <LoadingState label="Chargement des événements…" />
      ) : events.isError ? (
        <ErrorState error={events.error} onRetry={() => events.refetch()} />
      ) : list.length === 0 ? (
        <EmptyState title="Aucun événement">
          {canManage
            ? 'Créez un événement pour obtenir un lien d’inscription et un QR code dédiés à un culte spécial.'
            : 'Aucun culte spécial n’a encore de lien dédié.'}
        </EmptyState>
      ) : (
        <>
          <div className="hidden overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm lg:block">
            <table className="min-w-full divide-y divide-gray-200">
              <caption className="sr-only">Événements</caption>
              <thead className="bg-gray-50">
                <tr>
                  <th scope="col" className={tableHeadClass}>Nom</th>
                  <th scope="col" className={tableHeadClass}>Date</th>
                  <th scope="col" className={tableHeadClass}>Lien d’inscription</th>
                  <th scope="col" className={tableHeadClass}>État</th>
                  <th scope="col" className={tableHeadClass}>Inscrits</th>
                  <th scope="col" className={tableHeadClass}>
                    <span className="sr-only">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {list.map((event) => (
                  <tr key={event.id}>
                    <th scope="row" className={clsx(tableCellClass, 'text-left font-normal')}>
                      <span className="font-bold">{event.name}</span>
                      <span className="block text-gray-700">/e/{event.slug}</span>
                    </th>
                    <td className={clsx(tableCellClass, 'whitespace-nowrap')}>
                      {event.event_date ? formatDate(event.event_date) : 'Non datée'}
                    </td>
                    <td className={clsx(tableCellClass, 'max-w-xs')}>
                      <EventLink event={event} />
                    </td>
                    <td className={tableCellClass}>
                      <ActiveBadge event={event} />
                    </td>
                    <td className={clsx(tableCellClass, 'whitespace-nowrap')}>{registrationsLabel(event)}</td>
                    <td className={tableCellClass}>
                      <RowActions
                        event={event}
                        posterOpen={posterId === event.id}
                        onPoster={() => setPosterId(posterId === event.id ? null : event.id)}
                        onEdit={() => setDialog({ event })}
                        onDelete={() => setDeleting(event)}
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <ul className="flex flex-col gap-3 lg:hidden" aria-label="Événements">
            {list.map((event) => (
              <li key={event.id} className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <p className="font-bold text-gray-900">{event.name}</p>
                  <ActiveBadge event={event} />
                </div>
                <p className="mt-1 text-sm text-gray-800">
                  {event.event_date ? formatDate(event.event_date) : 'Date non fixée'} · {registrationsLabel(event)}
                </p>
                <p className="mt-2 text-sm">
                  <EventLink event={event} />
                </p>
                <div className="mt-3">
                  <RowActions
                    event={event}
                    posterOpen={posterId === event.id}
                    onPoster={() => setPosterId(posterId === event.id ? null : event.id)}
                    onEdit={() => setDialog({ event })}
                    onDelete={() => setDeleting(event)}
                  />
                </div>
              </li>
            ))}
          </ul>
        </>
      )}

      {dialog && <EventDialog event={dialog.event} onClose={() => setDialog(null)} />}

      {deleting && (
        <ConfirmDialog
          title="Supprimer cet événement ?"
          confirmLabel="Supprimer l’événement"
          pendingLabel="Suppression…"
          tone="danger"
          pending={remove.isPending}
          error={remove.isError ? errorMessage(remove.error) : null}
          onCancel={closeDelete}
          onConfirm={() =>
            remove.mutate(deleting.id, {
              onSuccess: () => {
                toast.success(`L’événement « ${deleting.name} » a été supprimé.`)
                if (posterId === deleting.id) setPosterId(null)
                setDeleting(null)
              },
            })
          }
        >
          <p>
            L’événement <strong>{deleting.name}</strong> et son lien <span className="break-all">/e/{deleting.slug}</span>{' '}
            seront supprimés.
          </p>
          {deleting.visits_count > 0 ? (
            <p className="font-bold text-amber-900">
              {formatNumber(deleting.visits_count)} visite{deleting.visits_count > 1 ? 's sont' : ' est'} rattachée
              {deleting.visits_count > 1 ? 's' : ''} à cet événement : la suppression sera refusée. Désactivez plutôt son
              lien pour fermer les inscriptions.
            </p>
          ) : (
            <p className="font-bold text-red-800">Cette action est irréversible.</p>
          )}
        </ConfirmDialog>
      )}
    </>
  )
}
