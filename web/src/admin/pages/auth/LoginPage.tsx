import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router'
import { z } from 'zod'
import { ApiError } from '../../../shared/http'
import { useAuth } from '../../auth/context'
import { Button } from '../../components/Button'
import { Field, FormAlert } from '../../components/Field'
import { inputClass, linkClass } from '../../components/styles'
import { formatDelay } from '../../lib/format'
import { emailField } from '../../lib/schemas'
import { AuthLayout } from './AuthLayout'

const credentialsSchema = z.object({
  email: emailField,
  password: z.string().min(1, 'Saisissez votre mot de passe.'),
})
type Credentials = z.infer<typeof credentialsSchema>

const totpSchema = z.object({
  code: z.string().regex(/^\d{6}$/, 'Le code comporte 6 chiffres.'),
})
const recoverySchema = z.object({
  recovery_code: z.string().trim().min(1, 'Saisissez un code de récupération.').max(100, 'Code trop long.'),
})

interface LoginFailure {
  message: string
  retryAfter?: number
  /** L'étape 2FA n'est plus valable : retour à la saisie des identifiants avec ce message. */
  restart?: boolean
}

/** Message d'un 422 sur l'étape 2FA : celui du champ en erreur, sinon le message global du serveur. */
function twoFactorRejection(error: ApiError): string {
  return error.fieldError('code') ?? error.fieldError('recovery_code') ?? error.message
}

/** Messages distincts selon la cause de l'échec (identifiants, verrouillage, limite, réseau/serveur). */
function describeFailure(error: unknown, stage: 'credentials' | 'two_factor'): LoginFailure {
  if (!(error instanceof ApiError)) return { message: 'Une erreur inattendue est survenue. Merci de réessayer.' }
  switch (error.status) {
    case 0:
      return {
        message:
          error.code === 'timeout'
            ? 'Le serveur met trop de temps à répondre. Merci de réessayer dans un instant.'
            : 'Connexion au serveur impossible. Vérifiez votre connexion internet puis réessayez.',
      }
    case 401:
    case 419:
      return { message: 'La vérification a expiré. Merci de recommencer la connexion.', restart: stage === 'two_factor' }
    case 422:
      if (stage === 'credentials') {
        return { message: 'Identifiants incorrects. Vérifiez votre adresse e-mail et votre mot de passe.' }
      }
      // Connexion en attente expirée (> 5 min) : le serveur exige de ressaisir les identifiants.
      if (error.code === 'two_factor_expired') return { message: error.message, restart: true }
      return { message: twoFactorRejection(error) }
    case 423:
      return {
        message:
          'Ce compte est temporairement verrouillé après de trop nombreuses tentatives. Réessayez dans 15 minutes ou réinitialisez votre mot de passe.',
      }
    case 429: {
      const seconds = error.retryAfter && error.retryAfter > 0 ? error.retryAfter : 60
      return { message: `Trop de tentatives. Merci de patienter ${formatDelay(seconds)} avant de réessayer.`, retryAfter: seconds }
    }
    default:
      return error.status >= 500
        ? { message: 'Le serveur rencontre un problème. Merci de réessayer dans quelques instants.' }
        : { message: error.message }
  }
}

/** Désactive l'envoi pendant le délai imposé par un 429 (en-tête Retry-After). */
function useCooldown() {
  const [until, setUntil] = useState<number | null>(null)
  useEffect(() => {
    if (until === null) return
    const timer = setTimeout(() => setUntil(null), Math.max(0, until - Date.now()))
    return () => clearTimeout(timer)
  }, [until])
  return { blocked: until !== null, block: (seconds: number) => setUntil(Date.now() + seconds * 1000) }
}

function CredentialsForm({ onTwoFactor }: { onTwoFactor: () => void }) {
  const { login } = useAuth()
  const [failure, setFailure] = useState<string | null>(null)
  const [showPassword, setShowPassword] = useState(false)
  const cooldown = useCooldown()
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<Credentials>({ resolver: zodResolver(credentialsSchema), defaultValues: { email: '', password: '' } })

  const onSubmit = handleSubmit(async (values) => {
    setFailure(null)
    try {
      if ((await login(values)) === 'two_factor') onTwoFactor()
    } catch (error) {
      const result = describeFailure(error, 'credentials')
      setFailure(result.message)
      if (result.retryAfter) cooldown.block(result.retryAfter)
    }
  })

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
      <FormAlert message={failure} />
      <Field label="Adresse e-mail" error={errors.email?.message}>
        {(control) => (
          <input {...control} {...register('email')} type="email" autoComplete="username" inputMode="email" className={inputClass} />
        )}
      </Field>
      <Field label="Mot de passe" error={errors.password?.message}>
        {(control) => (
          <div className="relative">
            <input
              {...control}
              {...register('password')}
              type={showPassword ? 'text' : 'password'}
              autoComplete="current-password"
              className={`${inputClass} pr-28`}
            />
            <button
              type="button"
              onClick={() => setShowPassword((v) => !v)}
              aria-pressed={showPassword}
              className="absolute right-1 top-1/2 min-h-9 -translate-y-1/2 rounded-md px-3 text-sm font-bold text-church-purple hover:bg-church-purple-xl focus-visible:outline-2 focus-visible:outline-church-purple"
            >
              {showPassword ? 'Masquer' : 'Afficher'}
              {' '}<span className="sr-only">le mot de passe</span>
            </button>
          </div>
        )}
      </Field>
      <Button type="submit" pending={isSubmitting} pendingLabel="Connexion…" disabled={cooldown.blocked} className="mt-1 w-full">
        Se connecter
      </Button>
      <p className="text-center text-sm">
        <Link to="/admin/mot-de-passe-oublie" className={linkClass}>
          Mot de passe oublié ?
        </Link>
      </p>
    </form>
  )
}

function TwoFactorForm({ onRestart }: { onRestart: (message?: string) => void }) {
  const { completeTwoFactor } = useAuth()
  const [mode, setMode] = useState<'code' | 'recovery'>('code')
  const [failure, setFailure] = useState<string | null>(null)
  const cooldown = useCooldown()

  const handleFailure = (error: unknown) => {
    const result = describeFailure(error, 'two_factor')
    if (result.restart) {
      onRestart(result.message)
      return
    }
    setFailure(result.message)
    if (result.retryAfter) cooldown.block(result.retryAfter)
  }

  return (
    <div className="flex flex-col gap-4">
      <FormAlert message={failure} />
      {mode === 'code' ? (
        <TotpForm
          blocked={cooldown.blocked}
          onSubmit={async (code) => {
            setFailure(null)
            try {
              await completeTwoFactor({ code })
            } catch (error) {
              handleFailure(error)
            }
          }}
        />
      ) : (
        <RecoveryForm
          blocked={cooldown.blocked}
          onSubmit={async (recovery_code) => {
            setFailure(null)
            try {
              await completeTwoFactor({ recovery_code })
            } catch (error) {
              handleFailure(error)
            }
          }}
        />
      )}
      <div className="flex flex-col items-center gap-2 text-sm">
        <button
          type="button"
          className={linkClass}
          onClick={() => {
            setFailure(null)
            setMode((m) => (m === 'code' ? 'recovery' : 'code'))
          }}
        >
          {mode === 'code' ? 'Utiliser un code de récupération' : 'Utiliser le code de l’application'}
        </button>
        <button type="button" className={linkClass} onClick={() => onRestart()}>
          Revenir à la saisie des identifiants
        </button>
      </div>
    </div>
  )
}

function TotpForm({ onSubmit, blocked }: { onSubmit: (code: string) => Promise<void>; blocked: boolean }) {
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<z.infer<typeof totpSchema>>({ resolver: zodResolver(totpSchema), defaultValues: { code: '' } })
  return (
    <form onSubmit={handleSubmit(({ code }) => onSubmit(code))} noValidate className="flex flex-col gap-4">
      <Field
        label="Code de vérification"
        hint="Saisissez le code à 6 chiffres affiché par votre application d’authentification."
        error={errors.code?.message}
      >
        {(control) => (
          <input
            {...control}
            {...register('code', { setValueAs: (v: unknown) => String(v ?? '').replace(/\s+/g, '') })}
            type="text"
            inputMode="numeric"
            autoComplete="one-time-code"
            maxLength={7}
            // oxlint-disable-next-line jsx-a11y/no-autofocus -- écran dédié à la seule saisie du code
            autoFocus
            className={`${inputClass} text-center font-mono text-xl tracking-[0.4em]`}
          />
        )}
      </Field>
      <Button type="submit" pending={isSubmitting} pendingLabel="Vérification…" disabled={blocked} className="w-full">
        Valider
      </Button>
    </form>
  )
}

function RecoveryForm({ onSubmit, blocked }: { onSubmit: (code: string) => Promise<void>; blocked: boolean }) {
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<z.infer<typeof recoverySchema>>({ resolver: zodResolver(recoverySchema), defaultValues: { recovery_code: '' } })
  return (
    <form onSubmit={handleSubmit(({ recovery_code }) => onSubmit(recovery_code))} noValidate className="flex flex-col gap-4">
      <Field
        label="Code de récupération"
        hint="Un des codes conservés lors de l’activation de la double authentification. Chaque code ne sert qu’une fois."
        error={errors.recovery_code?.message}
      >
        {(control) => (
          <input
            {...control}
            {...register('recovery_code')}
            type="text"
            autoComplete="off"
            autoCapitalize="none"
            spellCheck={false}
            // oxlint-disable-next-line jsx-a11y/no-autofocus -- écran dédié à la seule saisie du code
            autoFocus
            className={`${inputClass} font-mono`}
          />
        )}
      </Field>
      <Button type="submit" pending={isSubmitting} pendingLabel="Vérification…" disabled={blocked} className="w-full">
        Valider
      </Button>
    </form>
  )
}

export function LoginPage() {
  const { sessionEnd } = useAuth()
  const [step, setStep] = useState<'credentials' | 'two_factor'>('credentials')
  /** Message affiché après un retour de l'étape 2FA à la saisie des identifiants (alerte si imposé par le serveur). */
  const [restart, setRestart] = useState<{ message: string; tone: 'info' | 'error' } | null>(null)
  const restartMessage = restart?.message ?? null

  return (
    <AuthLayout
      title={step === 'credentials' ? 'Connexion' : 'Vérification en deux étapes'}
      subtitle={step === 'two_factor' ? 'Votre compte est protégé par la double authentification.' : undefined}
    >
      <div className="flex flex-col gap-4">
        {step === 'credentials' && sessionEnd === 'expired' && !restartMessage && (
          <FormAlert tone="info" message="Votre session a expiré. Merci de vous reconnecter." />
        )}
        {step === 'credentials' && sessionEnd === 'manual' && !restartMessage && (
          <FormAlert tone="success" message="Vous avez été déconnecté." />
        )}
        {step === 'credentials' && restart && <FormAlert tone={restart.tone} message={restart.message} />}
        {step === 'credentials' ? (
          <CredentialsForm
            onTwoFactor={() => {
              setRestart(null)
              setStep('two_factor')
            }}
          />
        ) : (
          <TwoFactorForm
            onRestart={(message) => {
              setRestart(
                message
                  ? { message, tone: 'error' }
                  : { message: 'Merci de saisir à nouveau vos identifiants.', tone: 'info' },
              )
              setStep('credentials')
            }}
          />
        )}
      </div>
    </AuthLayout>
  )
}
