import { clsx } from 'clsx'
import { useState } from 'react'
import type { User } from '../../../shared/api-types'
import { ROLE_LABELS } from '../../../shared/domain'
import { useDeleteUser, useResendInvitation, useUsers } from '../../api/users'
import { useAuth } from '../../auth/context'
import { Button } from '../../components/Button'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Icon } from '../../components/Icon'
import { PageHeader } from '../../components/PageHeader'
import { EmptyState, ErrorState, LoadingState } from '../../components/States'
import { Badge } from '../../components/StatusBadge'
import { useToast } from '../../components/toast/context'
import { tableCellClass, tableHeadClass } from '../../components/styles'
import { errorMessage } from '../../lib/errors'
import { formatDateTime } from '../../lib/format'
import { EditUserDialog, InviteUserDialog } from './UserDialogs'

function StateBadges({ user }: { user: User }) {
  return (
    <div className="flex flex-wrap gap-1.5">
      {user.invitation_pending ? (
        <Badge tone="gold">Invitation en attente</Badge>
      ) : user.is_active ? (
        <Badge tone="green">Actif</Badge>
      ) : (
        <Badge tone="red">Désactivé</Badge>
      )}
      {user.invitation_pending && !user.is_active && <Badge tone="red">Désactivé</Badge>}
    </div>
  )
}

interface RowActionsProps {
  user: User
  isSelf: boolean
  onEdit: () => void
  onDelete: () => void
}

function RowActions({ user, isSelf, onEdit, onDelete }: RowActionsProps) {
  const toast = useToast()
  const resend = useResendInvitation()
  return (
    <div className="flex flex-wrap gap-1">
      <Button variant="ghost" size="sm" onClick={onEdit}>
        <Icon name="edit" className="size-4" />
        Modifier <span className="sr-only">{user.name}</span>
      </Button>
      {user.invitation_pending && (
        <Button
          variant="ghost"
          size="sm"
          pending={resend.isPending}
          pendingLabel="Envoi…"
          onClick={() =>
            resend.mutate(user.id, {
              onSuccess: () => toast.success(`Invitation renvoyée à ${user.email}.`),
              onError: (error) => toast.error(errorMessage(error)),
            })
          }
        >
          <Icon name="mail" className="size-4" />
          Renvoyer l’invitation <span className="sr-only">à {user.name}</span>
        </Button>
      )}
      {!isSelf && (
        <Button variant="ghost" size="sm" onClick={onDelete} className="text-red-800 hover:bg-red-50">
          <Icon name="trash" className="size-4" />
          Supprimer <span className="sr-only">{user.name}</span>
        </Button>
      )}
    </div>
  )
}

export function UsersPage() {
  const { user: me } = useAuth()
  const toast = useToast()
  const users = useUsers()
  const remove = useDeleteUser()
  const [inviting, setInviting] = useState(false)
  const [editing, setEditing] = useState<User | null>(null)
  const [deleting, setDeleting] = useState<User | null>(null)

  const closeDelete = () => {
    remove.reset()
    setDeleting(null)
  }

  return (
    <>
      <PageHeader
        title="Administrateurs"
        description="Comptes ayant accès au tableau de bord et leurs rôles."
        actions={
          <Button onClick={() => setInviting(true)}>
            <Icon name="plus" className="size-4" />
            Inviter un administrateur
          </Button>
        }
      />

      {users.isPending ? (
        <LoadingState label="Chargement des administrateurs…" />
      ) : users.isError ? (
        <ErrorState error={users.error} onRetry={() => users.refetch()} />
      ) : users.data.length === 0 ? (
        <EmptyState title="Aucun administrateur" />
      ) : (
        <>
          <div className="hidden overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm lg:block">
            <table className="min-w-full divide-y divide-gray-200">
              <caption className="sr-only">Administrateurs</caption>
              <thead className="bg-gray-50">
                <tr>
                  <th scope="col" className={tableHeadClass}>Nom</th>
                  <th scope="col" className={tableHeadClass}>Rôle</th>
                  <th scope="col" className={tableHeadClass}>État</th>
                  <th scope="col" className={tableHeadClass}>Double authentification</th>
                  <th scope="col" className={tableHeadClass}>Dernière connexion</th>
                  <th scope="col" className={tableHeadClass}>
                    <span className="sr-only">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {users.data.map((u) => {
                  const isSelf = u.id === me?.id
                  return (
                    <tr key={u.id}>
                      <th scope="row" className={clsx(tableCellClass, 'text-left font-normal')}>
                        <span className="font-bold">{u.name}</span>
                        {isSelf && <> <span className="text-gray-700">(vous)</span></>}
                        <span className="block text-gray-700">{u.email}</span>
                      </th>
                      <td className={tableCellClass}>{ROLE_LABELS[u.role] ?? u.role}</td>
                      <td className={tableCellClass}>
                        <StateBadges user={u} />
                      </td>
                      <td className={tableCellClass}>{u.two_factor_enabled ? 'Activée' : 'Non activée'}</td>
                      <td className={clsx(tableCellClass, 'whitespace-nowrap')}>{formatDateTime(u.last_login_at, 'Jamais')}</td>
                      <td className={tableCellClass}>
                        <RowActions user={u} isSelf={isSelf} onEdit={() => setEditing(u)} onDelete={() => setDeleting(u)} />
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          <ul className="flex flex-col gap-3 lg:hidden" aria-label="Administrateurs">
            {users.data.map((u) => {
              const isSelf = u.id === me?.id
              return (
                <li key={u.id} className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                  <p className="font-bold text-gray-900">
                    {u.name}
                    {isSelf && <> <span className="font-normal text-gray-700">(vous)</span></>}
                  </p>
                  <p className="break-all text-sm text-gray-700">{u.email}</p>
                  <div className="mt-2 flex flex-wrap items-center gap-2 text-sm">
                    <span className="font-bold text-church-purple">{ROLE_LABELS[u.role] ?? u.role}</span>
                    <StateBadges user={u} />
                  </div>
                  <p className="mt-2 text-sm text-gray-800">
                    Double authentification : {u.two_factor_enabled ? 'activée' : 'non activée'}
                  </p>
                  <p className="text-sm text-gray-800">Dernière connexion : {formatDateTime(u.last_login_at, 'jamais')}</p>
                  <div className="mt-3">
                    <RowActions user={u} isSelf={isSelf} onEdit={() => setEditing(u)} onDelete={() => setDeleting(u)} />
                  </div>
                </li>
              )
            })}
          </ul>
        </>
      )}

      {inviting && <InviteUserDialog onClose={() => setInviting(false)} />}
      {editing && <EditUserDialog user={editing} isSelf={editing.id === me?.id} onClose={() => setEditing(null)} />}
      {deleting && (
        <ConfirmDialog
          title="Supprimer cet administrateur ?"
          confirmLabel="Supprimer le compte"
          pendingLabel="Suppression…"
          tone="danger"
          pending={remove.isPending}
          error={remove.isError ? errorMessage(remove.error) : null}
          onCancel={closeDelete}
          onConfirm={() =>
            remove.mutate(deleting.id, {
              onSuccess: () => {
                toast.success(`Le compte de ${deleting.name} a été supprimé.`)
                setDeleting(null)
              },
            })
          }
        >
          <p>
            Le compte de <strong>{deleting.name}</strong> ({deleting.email}) sera supprimé et ses sessions fermées. Cette
            action est irréversible.
          </p>
        </ConfirmDialog>
      )}
    </>
  )
}
