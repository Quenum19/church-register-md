import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { z } from 'zod'
import type { UpdateProfileRequest } from '../../../shared/api-types'
import { ROLE_LABELS } from '../../../shared/domain'
import { authApi } from '../../api/auth'
import { queryKeys } from '../../api/keys'
import { useAuth } from '../../auth/context'
import { Button } from '../../components/Button'
import { Card } from '../../components/Card'
import { Field, FormAlert } from '../../components/Field'
import { useToast } from '../../components/toast/context'
import { inputClass } from '../../components/styles'
import { applyServerErrors } from '../../lib/forms'
import { emailField, requiredText } from '../../lib/schemas'

/** Le serveur enregistre les adresses en minuscules : seule une vraie nouvelle adresse compte comme un changement. */
function normalizeEmail(email: string): string {
  return email.trim().toLowerCase()
}

/** `current_password` est obligatoire quand l'adresse e-mail change (contrat §3). */
function profileSchema(currentEmail: string) {
  return z
    .object({ name: requiredText('Le nom', 100), email: emailField, current_password: z.string() })
    .refine((v) => normalizeEmail(v.email) === normalizeEmail(currentEmail) || v.current_password !== '', {
      path: ['current_password'],
      message: 'Saisissez votre mot de passe actuel pour changer d’adresse e-mail.',
    })
}
type FormValues = z.infer<ReturnType<typeof profileSchema>>

export function ProfileTab() {
  const { user, setUser } = useAuth()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [failure, setFailure] = useState<string | null>(null)
  const currentEmail = user?.email ?? ''
  const schema = useMemo(() => profileSchema(currentEmail), [currentEmail])
  const mutation = useMutation({
    mutationFn: authApi.updateProfile,
    onSuccess: ({ user: updated }) => {
      setUser(updated)
      return queryClient.invalidateQueries({ queryKey: queryKeys.users })
    },
  })
  const {
    register,
    control: formControl,
    handleSubmit,
    setError,
    reset,
    formState: { errors, dirtyFields },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { name: user?.name ?? '', email: currentEmail, current_password: '' },
  })
  const email = useWatch({ control: formControl, name: 'email' })
  const emailChanged = normalizeEmail(email ?? '') !== normalizeEmail(currentEmail)

  return (
    <Card title="Mon profil" description={user ? `Rôle : ${ROLE_LABELS[user.role] ?? user.role}` : undefined} className="max-w-2xl">
      <form
        noValidate
        className="flex flex-col gap-4"
        onSubmit={handleSubmit(async (values) => {
          setFailure(null)
          const body: UpdateProfileRequest = {}
          if (dirtyFields.name) body.name = values.name
          if (normalizeEmail(values.email) !== normalizeEmail(currentEmail)) {
            body.email = values.email
            body.current_password = values.current_password
          }
          if (Object.keys(body).length === 0) {
            toast.info('Aucune modification à enregistrer.')
            return
          }
          try {
            const { user: updated } = await mutation.mutateAsync(body)
            reset({ name: updated.name, email: updated.email, current_password: '' })
            toast.success('Profil mis à jour.')
          } catch (error) {
            setFailure(applyServerErrors(error, setError, ['name', 'email', 'current_password']))
          }
        })}
      >
        <FormAlert message={failure} />
        <Field label="Nom" error={errors.name?.message}>
          {(control) => <input {...control} {...register('name')} autoComplete="name" className={inputClass} />}
        </Field>
        <Field label="Adresse e-mail" hint="Utilisée pour la connexion et les e-mails de réinitialisation." error={errors.email?.message}>
          {(control) => <input {...control} {...register('email')} type="email" autoComplete="email" className={inputClass} />}
        </Field>
        {emailChanged && (
          <Field
            label="Mot de passe actuel"
            hint="Par sécurité, confirmez votre mot de passe pour changer d’adresse e-mail."
            error={errors.current_password?.message}
          >
            {(control) => (
              <input
                {...control}
                {...register('current_password')}
                type="password"
                autoComplete="current-password"
                aria-required="true"
                className={inputClass}
              />
            )}
          </Field>
        )}
        <div>
          <Button type="submit" pending={mutation.isPending} pendingLabel="Enregistrement…">
            Enregistrer
          </Button>
        </div>
      </form>
    </Card>
  )
}
