import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import type { TwoFactorSetup } from '../../../shared/api-types'
import { authApi } from '../../api/auth'
import { useAuth } from '../../auth/context'
import { Button } from '../../components/Button'
import { Card } from '../../components/Card'
import { Field, FormAlert } from '../../components/Field'
import { Icon } from '../../components/Icon'
import { QrCode } from '../../components/QrCode'
import { Badge } from '../../components/StatusBadge'
import { useToast } from '../../components/toast/context'
import { inputClass } from '../../components/styles'
import { useQrMatrix } from '../../hooks/useQrMatrix'
import { errorMessage, isApiError } from '../../lib/errors'
import { applyServerErrors } from '../../lib/forms'
import { downloadBlob } from '../../lib/poster'

const passwordSchema = z.object({ password: z.string().min(1, 'Saisissez votre mot de passe.') })
const codeSchema = z.object({ code: z.string().regex(/^\d{6}$/, 'Le code comporte 6 chiffres.') })

/** Confirmation du mot de passe avant une opération sensible (activation / désactivation). */
function PasswordStep({
  submitLabel,
  variant = 'primary',
  onSubmit,
}: {
  submitLabel: string
  variant?: 'primary' | 'danger'
  onSubmit: (password: string) => Promise<void>
}) {
  const { user } = useAuth()
  const [failure, setFailure] = useState<string | null>(null)
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<z.infer<typeof passwordSchema>>({ resolver: zodResolver(passwordSchema), defaultValues: { password: '' } })
  return (
    <form
      noValidate
      className="flex max-w-md flex-col gap-4"
      onSubmit={handleSubmit(async ({ password }) => {
        setFailure(null)
        try {
          await onSubmit(password)
        } catch (error) {
          setFailure(applyServerErrors(error, setError, ['password']))
        }
      })}
    >
      <FormAlert message={failure} />
      <input type="email" name="username" autoComplete="username" value={user?.email ?? ''} readOnly hidden />
      <Field label="Mot de passe actuel" hint="Par sécurité, confirmez votre mot de passe." error={errors.password?.message}>
        {(control) => <input {...control} {...register('password')} type="password" autoComplete="current-password" className={inputClass} />}
      </Field>
      <div>
        <Button type="submit" variant={variant} pending={isSubmitting} pendingLabel="Vérification…">
          {submitLabel}
        </Button>
      </div>
    </form>
  )
}

function RecoveryCodes({ codes }: { codes: string[] }) {
  const toast = useToast()
  const text = `Codes de récupération — Église La Maison de la Destinée (administration)\n\n${codes.join('\n')}\n`
  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-gray-800">
        Conservez ces codes en lieu sûr : chacun permet <strong>une seule</strong> connexion si vous perdez votre téléphone.
        Ils ne seront plus affichés.
      </p>
      <ol className="grid grid-cols-2 gap-2 rounded-xl bg-gray-50 p-4 font-mono text-sm text-gray-900" aria-label="Codes de récupération">
        {codes.map((code) => (
          <li key={code}>{code}</li>
        ))}
      </ol>
      <div className="flex flex-wrap gap-2">
        <Button
          variant="secondary"
          size="sm"
          onClick={async () => {
            try {
              await navigator.clipboard.writeText(codes.join('\n'))
              toast.success('Codes copiés dans le presse-papiers.')
            } catch {
              toast.error('Copie impossible : sélectionnez les codes manuellement.')
            }
          }}
        >
          Copier les codes
        </Button>
        <Button
          variant="secondary"
          size="sm"
          onClick={() => downloadBlob(new Blob([text], { type: 'text/plain;charset=utf-8' }), 'codes-recuperation.txt')}
        >
          <Icon name="download" className="size-4" />
          Télécharger (.txt)
        </Button>
      </div>
    </div>
  )
}

function SetupStep({ setup, onDone, onCancel }: { setup: TwoFactorSetup; onDone: () => Promise<void>; onCancel: () => void }) {
  const qr = useQrMatrix(setup.otpauth_url)
  const [failure, setFailure] = useState<string | null>(null)
  const confirm = useMutation({ mutationFn: authApi.confirmTwoFactor })
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<z.infer<typeof codeSchema>>({ resolver: zodResolver(codeSchema), defaultValues: { code: '' } })
  const groupedSecret = setup.secret.match(/.{1,4}/g)?.join(' ') ?? setup.secret

  return (
    <div className="flex flex-col gap-6">
      <section aria-labelledby="etape-1" className="flex flex-col gap-3">
        <h3 id="etape-1" className="font-bold text-gray-900">
          1. Scannez ce QR code avec votre application d’authentification
        </h3>
        <p className="text-sm text-gray-700">Par exemple Google Authenticator, Microsoft Authenticator, Aegis ou 2FAS.</p>
        <div className="w-52 rounded-xl border border-gray-300 bg-white p-2">
          {qr.matrix ? (
            <QrCode matrix={qr.matrix} label="QR code de configuration de la double authentification" />
          ) : (
            <div className="grid aspect-square place-items-center text-sm text-gray-700">{qr.failed ? 'QR code indisponible' : 'Génération…'}</div>
          )}
        </div>
        <p className="text-sm text-gray-800">
          Impossible de scanner ? Saisissez cette clé dans l’application :{' '}
          <code className="break-all rounded bg-gray-100 px-2 py-1 font-mono text-gray-900">{groupedSecret}</code>
        </p>
      </section>

      <section aria-labelledby="etape-2" className="flex flex-col gap-3">
        <h3 id="etape-2" className="font-bold text-gray-900">
          2. Enregistrez vos codes de récupération
        </h3>
        <RecoveryCodes codes={setup.recovery_codes} />
      </section>

      <section aria-labelledby="etape-3" className="flex flex-col gap-3">
        <h3 id="etape-3" className="font-bold text-gray-900">
          3. Confirmez avec le code affiché par l’application
        </h3>
        <form
          noValidate
          className="flex max-w-md flex-col gap-4"
          onSubmit={handleSubmit(async ({ code }) => {
            setFailure(null)
            try {
              await confirm.mutateAsync(code)
              await onDone()
            } catch (error) {
              setFailure(applyServerErrors(error, setError, ['code']))
            }
          })}
        >
          <FormAlert message={failure} />
          <Field label="Code à 6 chiffres" error={errors.code?.message}>
            {(control) => (
              <input
                {...control}
                {...register('code', { setValueAs: (v: unknown) => String(v ?? '').replace(/\s+/g, '') })}
                inputMode="numeric"
                autoComplete="one-time-code"
                maxLength={7}
                className={`${inputClass} font-mono tracking-widest`}
              />
            )}
          </Field>
          <div className="flex flex-wrap gap-2">
            <Button type="submit" pending={confirm.isPending} pendingLabel="Vérification…">
              Activer la double authentification
            </Button>
            <Button variant="secondary" onClick={onCancel} disabled={confirm.isPending}>
              Annuler
            </Button>
          </div>
        </form>
      </section>
    </div>
  )
}

export function TwoFactorTab() {
  const { user, refresh } = useAuth()
  const toast = useToast()
  const [setup, setSetup] = useState<TwoFactorSetup | null>(null)
  const enabled = Boolean(user?.two_factor_enabled)

  return (
    <Card
      title="Double authentification"
      description="Un code temporaire, généré par une application sur votre téléphone, est demandé à chaque connexion."
      actions={<Badge tone={enabled ? 'green' : 'gray'}>{enabled ? 'Activée' : 'Désactivée'}</Badge>}
      className="max-w-3xl"
    >
      {enabled ? (
        <div className="flex flex-col gap-4">
          <p className="text-sm text-gray-800">
            Votre compte est protégé. Pour désactiver la double authentification, confirmez votre mot de passe.
          </p>
          <PasswordStep
            submitLabel="Désactiver la double authentification"
            variant="danger"
            onSubmit={async (password) => {
              await authApi.disableTwoFactor(password)
              await refresh()
              toast.success('Double authentification désactivée.')
            }}
          />
        </div>
      ) : setup ? (
        <SetupStep
          setup={setup}
          onCancel={() => setSetup(null)}
          onDone={async () => {
            setSetup(null)
            await refresh()
            toast.success('Double authentification activée.')
          }}
        />
      ) : (
        <PasswordStep
          submitLabel="Configurer la double authentification"
          onSubmit={async (password) => {
            try {
              setSetup(await authApi.enableTwoFactor(password))
            } catch (error) {
              // Activée entre-temps (autre onglet) : on recharge le compte pour afficher l'état réel.
              if (!isApiError(error, 409, 'two_factor_already_enabled')) throw error
              await refresh()
              toast.info(errorMessage(error))
            }
          }}
        />
      )}
    </Card>
  )
}
