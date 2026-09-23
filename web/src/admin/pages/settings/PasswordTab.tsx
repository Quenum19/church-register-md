import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { authApi } from '../../api/auth'
import { useAuth } from '../../auth/context'
import { Button } from '../../components/Button'
import { Card } from '../../components/Card'
import { Field, FormAlert } from '../../components/Field'
import { useToast } from '../../components/toast/context'
import { inputClass } from '../../components/styles'
import { applyServerErrors } from '../../lib/forms'
import { PASSWORD_HINT, passwordPolicy } from '../../lib/schemas'

const schema = z
  .object({
    current_password: z.string().min(1, 'Saisissez votre mot de passe actuel.'),
    password: passwordPolicy,
    password_confirmation: z.string(),
  })
  .refine((v) => v.password === v.password_confirmation, {
    path: ['password_confirmation'],
    message: 'Les deux mots de passe ne correspondent pas.',
  })
type FormValues = z.infer<typeof schema>

export function PasswordTab() {
  const { user } = useAuth()
  const toast = useToast()
  const [failure, setFailure] = useState<string | null>(null)
  const mutation = useMutation({ mutationFn: authApi.updatePassword })
  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { current_password: '', password: '', password_confirmation: '' },
  })

  return (
    <Card title="Changer de mot de passe" description="Vos autres sessions ouvertes seront déconnectées." className="max-w-2xl">
      <form
        noValidate
        className="flex flex-col gap-4"
        onSubmit={handleSubmit(async (values) => {
          setFailure(null)
          try {
            await mutation.mutateAsync(values)
            reset()
            toast.success('Mot de passe modifié. Vos autres sessions ont été déconnectées.')
          } catch (error) {
            setFailure(applyServerErrors(error, setError, ['current_password', 'password', 'password_confirmation']))
          }
        })}
      >
        <FormAlert message={failure} />
        {/* Identifiant caché pour les gestionnaires de mots de passe. */}
        <input type="email" name="username" autoComplete="username" value={user?.email ?? ''} readOnly hidden />
        <Field label="Mot de passe actuel" error={errors.current_password?.message}>
          {(control) => (
            <input {...control} {...register('current_password')} type="password" autoComplete="current-password" className={inputClass} />
          )}
        </Field>
        <Field label="Nouveau mot de passe" hint={PASSWORD_HINT} error={errors.password?.message}>
          {(control) => <input {...control} {...register('password')} type="password" autoComplete="new-password" className={inputClass} />}
        </Field>
        <Field label="Confirmation du nouveau mot de passe" error={errors.password_confirmation?.message}>
          {(control) => (
            <input {...control} {...register('password_confirmation')} type="password" autoComplete="new-password" className={inputClass} />
          )}
        </Field>
        <div>
          <Button type="submit" pending={mutation.isPending} pendingLabel="Enregistrement…">
            Changer le mot de passe
          </Button>
        </div>
      </form>
    </Card>
  )
}
