import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router'
import { z } from 'zod'
import { ensureCsrfCookie } from '../../../shared/http'
import { authApi } from '../../api/auth'
import { Button } from '../../components/Button'
import { Field, FormAlert } from '../../components/Field'
import { inputClass, linkClass } from '../../components/styles'
import { isApiError } from '../../lib/errors'
import { formatDelay } from '../../lib/format'
import { applyServerErrors } from '../../lib/forms'
import { emailField } from '../../lib/schemas'
import { AuthLayout } from './AuthLayout'

const schema = z.object({ email: emailField })
type FormValues = z.infer<typeof schema>

export function ForgotPasswordPage() {
  const [sent, setSent] = useState(false)
  const [failure, setFailure] = useState<string | null>(null)
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { email: '' } })

  const onSubmit = handleSubmit(async ({ email }) => {
    setFailure(null)
    try {
      await ensureCsrfCookie()
      await authApi.forgotPassword(email)
      setSent(true)
    } catch (error) {
      if (isApiError(error, 429)) {
        setFailure(`Trop de demandes. Merci de patienter ${formatDelay(error.retryAfter ?? 60)} avant de réessayer.`)
        return
      }
      setFailure(applyServerErrors(error, setError, ['email']))
    }
  })

  return (
    <AuthLayout
      title="Mot de passe oublié"
      subtitle={sent ? undefined : 'Indiquez l’adresse e-mail de votre compte : nous vous enverrons un lien pour choisir un nouveau mot de passe.'}
    >
      {sent ? (
        <div className="flex flex-col gap-4">
          <FormAlert
            tone="success"
            message="Si un compte correspond à cette adresse, un e-mail contenant un lien de réinitialisation (valable 60 minutes) vient de vous être envoyé. Pensez à vérifier vos courriers indésirables."
          />
          <p className="text-center text-sm">
            <Link to="/admin/connexion" className={linkClass}>
              Retour à la connexion
            </Link>
          </p>
        </div>
      ) : (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
          <FormAlert message={failure} />
          <Field label="Adresse e-mail" error={errors.email?.message}>
            {(control) => (
              <input {...control} {...register('email')} type="email" autoComplete="username" inputMode="email" className={inputClass} />
            )}
          </Field>
          <Button type="submit" pending={isSubmitting} pendingLabel="Envoi…" className="w-full">
            Envoyer le lien
          </Button>
          <p className="text-center text-sm">
            <Link to="/admin/connexion" className={linkClass}>
              Retour à la connexion
            </Link>
          </p>
        </form>
      )}
    </AuthLayout>
  )
}
