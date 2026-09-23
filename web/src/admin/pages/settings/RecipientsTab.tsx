import { zodResolver } from '@hookform/resolvers/zod'
import { clsx } from 'clsx'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import type { Family, Recipient } from '../../../shared/api-types'
import { useDeleteRecipient, useFamilies, useRecipients, useSaveRecipient, useSendTestEmail } from '../../api/settings'
import { useAuth } from '../../auth/context'
import { Button } from '../../components/Button'
import { Card } from '../../components/Card'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Dialog, DialogActions } from '../../components/Dialog'
import { CheckboxField, Field, FormAlert } from '../../components/Field'
import { Icon } from '../../components/Icon'
import { EmptyState, ErrorState, LoadingState } from '../../components/States'
import { Badge } from '../../components/StatusBadge'
import { useToast } from '../../components/toast/context'
import { checkboxClass, inputClass, tableCellClass, tableHeadClass } from '../../components/styles'
import { errorMessage } from '../../lib/errors'
import { applyServerErrors } from '../../lib/forms'
import { emailField, requiredText } from '../../lib/schemas'

const recipientSchema = z.object({
  name: requiredText('Le nom', 100),
  email: emailField,
  family_id: z.string(),
  active: z.boolean(),
})
type RecipientValues = z.infer<typeof recipientSchema>

function RecipientDialog({ recipient, families, onClose }: { recipient: Recipient | null; families: Family[]; onClose: () => void }) {
  const toast = useToast()
  const mutation = useSaveRecipient()
  const [failure, setFailure] = useState<string | null>(null)
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<RecipientValues>({
    resolver: zodResolver(recipientSchema),
    defaultValues: {
      name: recipient?.name ?? '',
      email: recipient?.email ?? '',
      family_id: recipient?.family ? String(recipient.family.id) : '',
      active: recipient?.active ?? true,
    },
  })

  return (
    <Dialog title={recipient ? 'Modifier le destinataire' : 'Ajouter un destinataire'} onClose={onClose} busy={mutation.isPending}>
      <form
        noValidate
        className="flex flex-col gap-4"
        onSubmit={handleSubmit(async (values) => {
          setFailure(null)
          try {
            await mutation.mutateAsync({
              ...(recipient ? { id: recipient.id } : {}),
              name: values.name,
              email: values.email,
              family_id: values.family_id ? Number(values.family_id) : null,
              active: values.active,
            })
            toast.success(recipient ? 'Destinataire modifié.' : 'Destinataire ajouté.')
            onClose()
          } catch (error) {
            setFailure(applyServerErrors(error, setError, ['name', 'email', 'family_id', 'active']))
          }
        })}
      >
        <FormAlert message={failure} />
        <Field label="Nom" error={errors.name?.message}>
          {(control) => <input {...control} {...register('name')} autoComplete="off" className={inputClass} />}
        </Field>
        <Field label="Adresse e-mail" error={errors.email?.message}>
          {(control) => <input {...control} {...register('email')} type="email" autoComplete="off" className={inputClass} />}
        </Field>
        <Field label="Rapports reçus" hint="Un destinataire global reçoit les rapports de toutes les familles." error={errors.family_id?.message}>
          {(control) => (
            <select {...control} {...register('family_id')} className={inputClass}>
              <option value="">Tous les rapports (destinataire global)</option>
              {families.map((f) => (
                <option key={f.id} value={f.id}>
                  Famille {f.name} uniquement
                </option>
              ))}
            </select>
          )}
        </Field>
        <CheckboxField label="Actif" hint="Un destinataire inactif ne reçoit plus les rapports.">
          {(control) => <input {...control} {...register('active')} type="checkbox" className={checkboxClass} />}
        </CheckboxField>
        <DialogActions>
          <Button variant="secondary" onClick={onClose} disabled={mutation.isPending}>
            Annuler
          </Button>
          <Button type="submit" pending={mutation.isPending} pendingLabel="Enregistrement…">
            Enregistrer
          </Button>
        </DialogActions>
      </form>
    </Dialog>
  )
}

const testSchema = z.object({
  email: z.union([z.literal(''), emailField]),
})

function TestEmailForm() {
  const { user } = useAuth()
  const toast = useToast()
  const mutation = useSendTestEmail()
  const [failure, setFailure] = useState<string | null>(null)
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<z.infer<typeof testSchema>>({ resolver: zodResolver(testSchema), defaultValues: { email: '' } })

  return (
    <form
      noValidate
      className="flex flex-col gap-3"
      onSubmit={handleSubmit(async ({ email }) => {
        setFailure(null)
        try {
          await mutation.mutateAsync(email || null)
          toast.success(`E-mail de test envoyé à ${email || user?.email || 'votre adresse'}.`)
        } catch (error) {
          setFailure(applyServerErrors(error, setError, ['email']))
        }
      })}
    >
      <FormAlert message={failure} />
      <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
        <Field label="Adresse de test" optional hint={`Par défaut : ${user?.email ?? 'votre adresse'}`} error={errors.email?.message} className="flex-1">
          {(control) => <input {...control} {...register('email')} type="email" autoComplete="off" className={inputClass} />}
        </Field>
        <Button type="submit" variant="secondary" pending={mutation.isPending} pendingLabel="Envoi…">
          <Icon name="mail" className="size-4" />
          Envoyer un e-mail de test
        </Button>
      </div>
    </form>
  )
}

interface RecipientActionsProps {
  recipient: Recipient
  canEdit: boolean
  onEdit: () => void
  onDelete: () => void
}

function RecipientActions({ recipient, canEdit, onEdit, onDelete }: RecipientActionsProps) {
  return (
    <div className="flex flex-wrap gap-1">
      <Button variant="ghost" size="sm" onClick={onEdit} disabled={!canEdit}>
        <Icon name="edit" className="size-4" />
        Modifier <span className="sr-only">{recipient.name}</span>
      </Button>
      <Button variant="ghost" size="sm" onClick={onDelete} className="text-red-800 hover:bg-red-50">
        <Icon name="trash" className="size-4" />
        Supprimer <span className="sr-only">{recipient.name}</span>
      </Button>
    </div>
  )
}

export function RecipientsTab() {
  const toast = useToast()
  const recipients = useRecipients()
  const families = useFamilies()
  const remove = useDeleteRecipient()
  const [editing, setEditing] = useState<Recipient | 'new' | null>(null)
  const [deleting, setDeleting] = useState<Recipient | null>(null)

  return (
    <div className="flex max-w-4xl flex-col gap-6">
      <Card
        title="Destinataires des rapports"
        description="Personnes qui reçoivent par e-mail le rapport mensuel de la famille de service."
        actions={
          <Button onClick={() => setEditing('new')} disabled={!families.data}>
            <Icon name="plus" className="size-4" />
            Ajouter un destinataire
          </Button>
        }
      >
        {recipients.isPending ? (
          <LoadingState label="Chargement des destinataires…" />
        ) : recipients.isError ? (
          <ErrorState error={recipients.error} onRetry={() => recipients.refetch()} />
        ) : recipients.data.length === 0 ? (
          <EmptyState title="Aucun destinataire">Ajoutez au moins un destinataire pour que les rapports puissent être envoyés.</EmptyState>
        ) : (
          <div className="-mx-5 overflow-x-auto sm:-mx-6">
            <table className="min-w-full divide-y divide-gray-200">
              <caption className="sr-only">Destinataires des rapports</caption>
              <thead className="bg-gray-50">
                <tr>
                  <th scope="col" className={tableHeadClass}>Destinataire</th>
                  <th scope="col" className={tableHeadClass}>Rapports reçus</th>
                  <th scope="col" className={tableHeadClass}>État</th>
                  <th scope="col" className={tableHeadClass}>
                    <span className="sr-only">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {recipients.data.map((r) => (
                  <tr key={r.id}>
                    <th scope="row" className={clsx(tableCellClass, 'text-left font-normal')}>
                      <span className="font-bold">{r.name}</span>
                      <span className="block break-all text-gray-700">{r.email}</span>
                    </th>
                    <td className={tableCellClass}>{r.family ? `Famille ${r.family.name}` : 'Tous les rapports'}</td>
                    <td className={tableCellClass}>
                      <Badge tone={r.active ? 'green' : 'gray'}>{r.active ? 'Actif' : 'Inactif'}</Badge>
                    </td>
                    <td className={tableCellClass}>
                      <RecipientActions
                        recipient={r}
                        canEdit={Boolean(families.data)}
                        onEdit={() => setEditing(r)}
                        onDelete={() => setDeleting(r)}
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Card title="Tester l’envoi" description="Vérifie la configuration de l’envoi d’e-mails (Brevo).">
        <TestEmailForm />
      </Card>

      {editing && families.data && (
        <RecipientDialog recipient={editing === 'new' ? null : editing} families={families.data} onClose={() => setEditing(null)} />
      )}
      {deleting && (
        <ConfirmDialog
          title="Supprimer ce destinataire ?"
          confirmLabel="Supprimer"
          pendingLabel="Suppression…"
          tone="danger"
          pending={remove.isPending}
          error={remove.isError ? errorMessage(remove.error) : null}
          onCancel={() => {
            remove.reset()
            setDeleting(null)
          }}
          onConfirm={() =>
            remove.mutate(deleting.id, {
              onSuccess: () => {
                toast.success('Destinataire supprimé.')
                setDeleting(null)
              },
            })
          }
        >
          <p>
            <strong>{deleting.name}</strong> ({deleting.email}) ne recevra plus les rapports.
          </p>
        </ConfirmDialog>
      )}
    </div>
  )
}
