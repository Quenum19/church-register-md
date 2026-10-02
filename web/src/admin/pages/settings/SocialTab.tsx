// Paramètres → Réseaux sociaux : adresses des réseaux, QR code et affichette à poser sur
// les tables. Le QR mène à la page publique « Suivez-nous » (/reseaux), qui n'affiche que
// les réseaux renseignés ici.

import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import type { Settings, SocialNetwork, UpdateSettingsRequest } from '../../../shared/api-types'
import { useSettings, useUpdateSettings } from '../../api/settings'
import { useCan } from '../../auth/context'
import { Button } from '../../components/Button'
import { Card } from '../../components/Card'
import { Field, FormAlert } from '../../components/Field'
import { QrCode } from '../../components/QrCode'
import { ErrorState, LoadingState } from '../../components/States'
import { useToast } from '../../components/toast/context'
import { buttonClass, inputClass } from '../../components/styles'
import { copyText } from '../../lib/clipboard'
import { useQrMatrix } from '../../hooks/useQrMatrix'
import { applyServerErrors } from '../../lib/forms'
import { socialPageUrl } from '../../lib/social'

type NetworkKey = SocialNetwork['key']

const KEYS: NetworkKey[] = ['facebook', 'youtube', 'instagram', 'tiktok']

const url = z
  .string()
  .trim()
  .refine((value) => value === '' || /^https:\/\/\S+\.\S+/.test(value), {
    error: 'Saisissez une adresse complète commençant par https, ou laissez vide.',
  })

const schema = z.object({ facebook: url, youtube: url, instagram: url, tiktok: url })
type FormValues = z.infer<typeof schema>

const PLACEHOLDERS: Record<NetworkKey, string> = {
  facebook: 'https://facebook.com/votre-page',
  youtube: 'https://youtube.com/@votre-chaine',
  instagram: 'https://instagram.com/votre-compte',
  tiktok: 'https://tiktok.com/@votre-compte',
}

function SocialForm({ settings }: { settings: Settings }) {
  const toast = useToast()
  const mutation = useUpdateSettings()
  const [failure, setFailure] = useState<string | null>(null)
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    values: {
      facebook: settings.social_links.facebook ?? '',
      youtube: settings.social_links.youtube ?? '',
      instagram: settings.social_links.instagram ?? '',
      tiktok: settings.social_links.tiktok ?? '',
    },
  })

  return (
    <form
      noValidate
      className="flex flex-col gap-5"
      onSubmit={handleSubmit(async (values) => {
        setFailure(null)
        const body: UpdateSettingsRequest = { social_links: values }
        try {
          await mutation.mutateAsync(body)
          toast.success('Réseaux sociaux enregistrés.')
        } catch (error) {
          setFailure(
            applyServerErrors(error, setError, KEYS, {
              'social_links.facebook': 'facebook',
              'social_links.youtube': 'youtube',
              'social_links.instagram': 'instagram',
              'social_links.tiktok': 'tiktok',
            }),
          )
        }
      })}
    >
      <FormAlert message={failure} />
      <p className="text-sm text-gray-800">
        Laissez un champ vide pour retirer le réseau de la page publique.
      </p>
      {KEYS.map((key) => (
        <Field key={key} label={settings.social_networks[key]} error={errors[key]?.message}>
          {(control) => (
            <input
              {...control}
              {...register(key)}
              type="url"
              inputMode="url"
              placeholder={PLACEHOLDERS[key]}
              className={inputClass}
            />
          )}
        </Field>
      ))}
      <div>
        <Button type="submit" pending={mutation.isPending} pendingLabel="Enregistrement…">
          Enregistrer
        </Button>
      </div>
    </form>
  )
}

function SocialPoster({ settings }: { settings: Settings }) {
  const toast = useToast()
  const pageUrl = socialPageUrl(settings)
  const qr = useQrMatrix(pageUrl)
  const configured = KEYS.filter((key) => settings.social_links[key])

  return (
    <div className="flex flex-col items-start gap-4">
      {qr.matrix ? (
        <QrCode matrix={qr.matrix} label="QR code de la page des réseaux sociaux" className="w-44" />
      ) : (
        <p className="text-sm text-gray-700">{qr.failed ? "QR code indisponible : vérifiez l’adresse publique." : "Génération du QR code…"}</p>
      )}
      <p className="break-all text-sm text-gray-800">{pageUrl}</p>
      {configured.length === 0 && (
        <p className="text-sm font-bold text-amber-800">
          Aucun réseau n’est renseigné : la page ne montrera encore aucun lien.
        </p>
      )}
      <div className="flex flex-wrap gap-2">
        <button
          type="button"
          className={buttonClass('secondary', 'sm')}
          onClick={async () => {
            const done = await copyText(pageUrl)
            if (done) toast.success('Lien copié.')
            else toast.error('Copie impossible : sélectionnez le lien à la main.')
          }}
        >
          Copier le lien
        </button>
        <a href="/api/admin/reseaux-sociaux/affiche.pdf" download className={buttonClass('secondary', 'sm')}>
          Affichette A5 (PDF)
        </a>
        <a href={pageUrl} target="_blank" rel="noopener noreferrer" className={buttonClass('ghost', 'sm')}>
          Voir la page
        </a>
      </div>
      <p className="text-sm text-gray-700">
        L’affichette est au format A5, celui des porte-affiches posés sur les tables.
      </p>
    </div>
  )
}

export function SocialTab() {
  const { data, isPending, isError, refetch } = useSettings()
  const canUpdate = useCan('settings.update')

  if (isPending) return <LoadingState label="Chargement des réseaux sociaux…" />
  if (isError || !data) return <ErrorState error={null} onRetry={() => void refetch()} />

  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <Card
        title="Réseaux de l’église"
        description="Adresses affichées sur la page publique « Suivez-nous »."
      >
        {canUpdate ? (
          <SocialForm settings={data} />
        ) : (
          <dl className="flex flex-col gap-3 text-sm">
            {KEYS.map((key) => (
              <div key={key}>
                <dt className="font-bold text-gray-800">{data.social_networks[key]}</dt>
                <dd className="break-all text-gray-900">{data.social_links[key] ?? '—'}</dd>
              </div>
            ))}
          </dl>
        )}
      </Card>
      <Card title="QR code à poser sur les tables" description="Il mène à la page « Suivez-nous ».">
        <SocialPoster settings={data} />
      </Card>
    </div>
  )
}
