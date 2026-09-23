import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { z } from 'zod'
import type { UpdateUserRequest, User } from '../../../shared/api-types'
import { ROLE_LABELS, ROLES } from '../../../shared/domain'
import { useInviteUser, useUpdateUser } from '../../api/users'
import { Button } from '../../components/Button'
import { Dialog, DialogActions } from '../../components/Dialog'
import { CheckboxField, Field, FormAlert } from '../../components/Field'
import { useToast } from '../../components/toast/context'
import { checkboxClass, inputClass } from '../../components/styles'
import { applyServerErrors } from '../../lib/forms'
import { emailField, requiredText } from '../../lib/schemas'

const ROLE_DESCRIPTIONS: Record<(typeof ROLES)[number], string> = {
  lecteur: 'Consulte les visiteurs, membres, statistiques et rapports.',
  moderateur: 'Comme le lecteur, et modifie les fiches et ajoute des notes.',
  super_admin: 'Tous les droits : conversions, exports, envois, paramètres et administrateurs.',
}

function RoleOptions() {
  return ROLES.map((role) => (
    <option key={role} value={role}>
      {ROLE_LABELS[role]}
    </option>
  ))
}

const inviteSchema = z.object({
  name: requiredText('Le nom', 100),
  email: emailField,
  role: z.enum(ROLES),
})
type InviteValues = z.infer<typeof inviteSchema>

export function InviteUserDialog({ onClose }: { onClose: () => void }) {
  const toast = useToast()
  const mutation = useInviteUser()
  const [failure, setFailure] = useState<string | null>(null)
  const {
    register,
    handleSubmit,
    setError,
    control: formControl,
    formState: { errors },
  } = useForm<InviteValues>({ resolver: zodResolver(inviteSchema), defaultValues: { name: '', email: '', role: 'lecteur' } })
  const role = useWatch({ control: formControl, name: 'role' })

  return (
    <Dialog
      title="Inviter un administrateur"
      description="Un e-mail d’invitation valable 48 heures lui permettra de choisir son mot de passe."
      onClose={onClose}
      busy={mutation.isPending}
    >
      <form
        noValidate
        className="flex flex-col gap-4"
        onSubmit={handleSubmit(async (values) => {
          setFailure(null)
          try {
            await mutation.mutateAsync(values)
            toast.success(`Invitation envoyée à ${values.email}.`)
            onClose()
          } catch (error) {
            setFailure(applyServerErrors(error, setError, ['name', 'email', 'role']))
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
        <Field label="Rôle" hint={ROLE_DESCRIPTIONS[role]} error={errors.role?.message}>
          {(control) => (
            <select {...control} {...register('role')} className={inputClass}>
              <RoleOptions />
            </select>
          )}
        </Field>
        <DialogActions>
          <Button variant="secondary" onClick={onClose} disabled={mutation.isPending}>
            Annuler
          </Button>
          <Button type="submit" pending={mutation.isPending} pendingLabel="Envoi…">
            Envoyer l’invitation
          </Button>
        </DialogActions>
      </form>
    </Dialog>
  )
}

const editSchema = z.object({
  name: requiredText('Le nom', 100),
  email: emailField,
  role: z.enum(ROLES),
  is_active: z.boolean(),
})
type EditValues = z.infer<typeof editSchema>

export function EditUserDialog({ user, isSelf, onClose }: { user: User; isSelf: boolean; onClose: () => void }) {
  const toast = useToast()
  const mutation = useUpdateUser()
  const [failure, setFailure] = useState<string | null>(null)
  const {
    register,
    handleSubmit,
    setError,
    control: formControl,
    formState: { errors, dirtyFields },
  } = useForm<EditValues>({
    resolver: zodResolver(editSchema),
    defaultValues: { name: user.name, email: user.email, role: user.role, is_active: user.is_active },
  })
  const role = useWatch({ control: formControl, name: 'role' })

  return (
    <Dialog title="Modifier l’administrateur" description={user.email} onClose={onClose} busy={mutation.isPending}>
      <form
        noValidate
        className="flex flex-col gap-4"
        onSubmit={handleSubmit(async (values) => {
          setFailure(null)
          const body: UpdateUserRequest = {}
          if (dirtyFields.name) body.name = values.name
          if (dirtyFields.email) body.email = values.email
          if (dirtyFields.role) body.role = values.role
          if (dirtyFields.is_active) body.is_active = values.is_active
          if (Object.keys(body).length === 0) {
            onClose()
            return
          }
          try {
            await mutation.mutateAsync({ id: user.id, ...body })
            toast.success(`Compte de ${values.name} mis à jour.`)
            onClose()
          } catch (error) {
            setFailure(applyServerErrors(error, setError, ['name', 'email', 'role', 'is_active']))
          }
        })}
      >
        <FormAlert message={failure} />
        {isSelf && (
          <FormAlert
            tone="info"
            message="Il s’agit de votre propre compte : vous ne pouvez ni changer votre rôle ni vous désactiver."
          />
        )}
        <Field label="Nom" error={errors.name?.message}>
          {(control) => <input {...control} {...register('name')} autoComplete="off" className={inputClass} />}
        </Field>
        <Field label="Adresse e-mail" error={errors.email?.message}>
          {(control) => <input {...control} {...register('email')} type="email" autoComplete="off" className={inputClass} />}
        </Field>
        {isSelf ? (
          // Pas de contrôle désactivé enregistré dans le formulaire : le rôle et l'état sont seulement affichés.
          <dl className="grid grid-cols-2 gap-3 rounded-xl bg-gray-50 p-4 text-sm">
            <div>
              <dt className="font-bold text-gray-800">Rôle</dt>
              <dd>{ROLE_LABELS[user.role]}</dd>
            </div>
            <div>
              <dt className="font-bold text-gray-800">État</dt>
              <dd>{user.is_active ? 'Actif' : 'Inactif'}</dd>
            </div>
          </dl>
        ) : (
          <>
            <Field label="Rôle" hint={ROLE_DESCRIPTIONS[role]} error={errors.role?.message}>
              {(control) => (
                <select {...control} {...register('role')} className={inputClass}>
                  <RoleOptions />
                </select>
              )}
            </Field>
            <CheckboxField label="Compte actif" hint="Un compte désactivé ne peut plus se connecter ; ses sessions sont fermées.">
              {(control) => <input {...control} {...register('is_active')} type="checkbox" className={checkboxClass} />}
            </CheckboxField>
          </>
        )}
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
