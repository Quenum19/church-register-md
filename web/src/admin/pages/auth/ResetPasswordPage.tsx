import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { z } from 'zod'
import { ApiError, ensureCsrfCookie } from '../../../shared/http'
import { authApi } from '../../api/auth'
import { Button } from '../../components/Button'
import { Field, FormAlert } from '../../components/Field'
import { buttonClass, inputClass, linkClass } from '../../components/styles'
import { applyServerErrors } from '../../lib/forms'
import { PASSWORD_HINT, passwordPolicy } from '../../lib/schemas'
import { AuthLayout } from './AuthLayout'

const schema = z
  .object({
    password: passwordPolicy,
    password_confirmation: z.string(),
  })
  .refine((v) => v.password === v.password_confirmation, {
    path: ['password_confirmation'],
    message: 'Les deux mots de passe ne correspondent pas.',
  })
type FormValues = z.infer<typeof schema>

/** Définition du mot de passe : réinitialisation (60 min) ou acceptation d'une invitation (48 h). */
export function ResetPasswordPage() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  // Les paramètres du lien ne sont lus qu'une fois, à l'arrivée : l'URL est ensuite nettoyée
  // (voir l'effet ci-dessous), mais la page continue de fonctionner avec ces valeurs.
  const [link] = useState(() => ({
    token: params.get('token') ?? '',
    email: params.get('email') ?? '',
    // Lien d'invitation : `&invitation=1` (contrat §3).
    invitation: params.get('invitation') === '1',
  }))
  const { token, email, invitation } = link
  const [mustCleanUrl] = useState(() => params.has('token') || params.has('email'))

  // Le jeton de réinitialisation ne doit pas rester dans l'URL : il finirait dans l'historique
  // du navigateur, dans une capture d'écran, dans un lien partagé ou dans un en-tête Referer.
  // « replace » remplace l'entrée courante (history.replaceState) : aucune entrée ajoutée, donc
  // aucun retour arrière ne peut ramener le jeton.
  useEffect(() => {
    if (mustCleanUrl) navigate({ search: '' }, { replace: true })
  }, [mustCleanUrl, navigate])

  const [done, setDone] = useState(false)
  const [failure, setFailure] = useState<string | null>(null)
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { password: '', password_confirmation: '' },
  })

  if (!token || !email) {
    return (
      <AuthLayout title="Lien invalide">
        <div className="flex flex-col gap-4">
          <FormAlert message="Ce lien est incomplet. Utilisez le lien reçu par e-mail, ou demandez-en un nouveau." />
          <Link to="/admin/mot-de-passe-oublie" className={buttonClass('primary', 'md', 'w-full')}>
            Demander un nouveau lien
          </Link>
        </div>
      </AuthLayout>
    )
  }

  const onSubmit = handleSubmit(async (values) => {
    setFailure(null)
    try {
      await ensureCsrfCookie()
      await authApi.resetPassword({ token, email, ...values })
      setDone(true)
    } catch (error) {
      const message = applyServerErrors(error, setError, ['password', 'password_confirmation'])
      if (message) {
        setFailure(
          isTokenProblem(error)
            ? invitation
              ? 'Ce lien d’invitation n’est plus valide : il a expiré (48 h) ou a déjà été utilisé. Demandez à un administrateur de vous renvoyer l’invitation.'
              : 'Ce lien n’est plus valide : il a expiré ou a déjà été utilisé. Demandez un nouveau lien.'
            : message,
        )
      }
    }
  })

  return (
    <AuthLayout
      title={invitation ? 'Bienvenue' : 'Choisir un mot de passe'}
      subtitle={
        done
          ? undefined
          : invitation
            ? 'Choisissez votre mot de passe pour activer votre compte.'
            : 'Pour activer votre accès ou remplacer votre mot de passe.'
      }
    >
      {done ? (
        <div className="flex flex-col gap-4">
          <FormAlert
            tone="success"
            message={
              invitation
                ? 'Votre compte est activé. Vous pouvez maintenant vous connecter.'
                : 'Votre mot de passe est enregistré. Vous pouvez maintenant vous connecter.'
            }
          />
          <Link to="/admin/connexion" className={buttonClass('primary', 'md', 'w-full')}>
            Se connecter
          </Link>
        </div>
      ) : (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
          <FormAlert message={failure} />
          <Field label="Adresse e-mail">
            {(control) => <input {...control} type="email" value={email} readOnly autoComplete="username" className={inputClass} />}
          </Field>
          <Field label="Nouveau mot de passe" hint={PASSWORD_HINT} error={errors.password?.message}>
            {(control) => <input {...control} {...register('password')} type="password" autoComplete="new-password" className={inputClass} />}
          </Field>
          <Field label="Confirmation du mot de passe" error={errors.password_confirmation?.message}>
            {(control) => (
              <input {...control} {...register('password_confirmation')} type="password" autoComplete="new-password" className={inputClass} />
            )}
          </Field>
          <Button type="submit" pending={isSubmitting} pendingLabel="Enregistrement…" className="w-full">
            Enregistrer le mot de passe
          </Button>
          <p className="text-center text-sm">
            <Link to="/admin/mot-de-passe-oublie" className={linkClass}>
              Demander un nouveau lien
            </Link>
          </p>
        </form>
      )}
    </AuthLayout>
  )
}

function isTokenProblem(error: unknown): boolean {
  return error instanceof ApiError && ('token' in error.errors || 'email' in error.errors)
}
