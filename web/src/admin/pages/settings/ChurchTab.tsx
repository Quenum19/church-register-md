import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { z } from 'zod'
import type { Settings, UpdateSettingsRequest } from '../../../shared/api-types'
import { useSettings, useUpdateSettings } from '../../api/settings'
import { useCan } from '../../auth/context'
import { Button } from '../../components/Button'
import { Card } from '../../components/Card'
import { Field, FormAlert } from '../../components/Field'
import { ErrorState, LoadingState } from '../../components/States'
import { useToast } from '../../components/toast/context'
import { inputClass } from '../../components/styles'
import { applyServerErrors } from '../../lib/forms'
import { requiredText } from '../../lib/schemas'

const schema = z
  .object({
    church_name: requiredText('Le nom de l’église', 120),
    public_url: z
      .string()
      .trim()
      .pipe(z.url({ protocol: /^https?$/, error: 'Saisissez une adresse complète, par exemple https://registre.exemple.org' })),
    verse_mode: z.enum(['0', '1', '2', 'custom']),
    verse_ref: z.string().trim().max(60, 'La référence ne doit pas dépasser 60 caractères.'),
    verse_text: z.string().trim().max(500, 'Le texte ne doit pas dépasser 500 caractères.'),
  })
  .superRefine((v, ctx) => {
    if (v.verse_mode !== 'custom') return
    if (!v.verse_ref) ctx.addIssue({ code: 'custom', path: ['verse_ref'], message: 'La référence est obligatoire.' })
    if (!v.verse_text) ctx.addIssue({ code: 'custom', path: ['verse_text'], message: 'Le texte du verset est obligatoire.' })
  })
type FormValues = z.infer<typeof schema>

function toFormValues(settings: Settings): FormValues {
  const preset = settings.verse.preset
  const isPreset = preset !== null && preset >= 0 && preset <= 2
  return {
    church_name: settings.church_name,
    public_url: settings.public_url,
    verse_mode: isPreset ? (String(preset) as '0' | '1' | '2') : 'custom',
    verse_ref: isPreset ? '' : settings.verse.ref,
    verse_text: isPreset ? '' : settings.verse.text,
  }
}

function ChurchForm({ settings }: { settings: Settings }) {
  const toast = useToast()
  const mutation = useUpdateSettings()
  const [failure, setFailure] = useState<string | null>(null)
  const {
    register,
    handleSubmit,
    setError,
    control: formControl,
    formState: { errors },
  } = useForm<FormValues>({ resolver: zodResolver(schema), values: toFormValues(settings) })
  const mode = useWatch({ control: formControl, name: 'verse_mode' })

  return (
    <form
      noValidate
      className="flex flex-col gap-5"
      onSubmit={handleSubmit(async (values) => {
        setFailure(null)
        const body: UpdateSettingsRequest = {
          church_name: values.church_name,
          public_url: values.public_url,
          verse:
            values.verse_mode === 'custom'
              ? { preset: null, ref: values.verse_ref, text: values.verse_text }
              : { preset: Number(values.verse_mode) },
        }
        try {
          await mutation.mutateAsync(body)
          toast.success('Paramètres de l’église enregistrés.')
        } catch (error) {
          setFailure(
            applyServerErrors(error, setError, ['church_name', 'public_url', 'verse_ref', 'verse_text', 'verse_mode'], {
              'verse.ref': 'verse_ref',
              'verse.text': 'verse_text',
              'verse.preset': 'verse_mode',
              verse: 'verse_mode',
            }),
          )
        }
      })}
    >
      <FormAlert message={failure} />
      <Field label="Nom de l’église" error={errors.church_name?.message}>
        {(control) => <input {...control} {...register('church_name')} autoComplete="organization" className={inputClass} />}
      </Field>
      <Field
        label="Adresse publique du formulaire"
        hint="Adresse encodée dans le QR code (https en production)."
        error={errors.public_url?.message}
      >
        {(control) => <input {...control} {...register('public_url')} type="url" inputMode="url" autoComplete="url" className={inputClass} />}
      </Field>

      <fieldset className="flex flex-col gap-3" aria-describedby={errors.verse_mode ? 'verse-mode-error' : undefined}>
        <legend className="mb-1 text-sm font-bold text-gray-800">Verset affiché (formulaire et affiche)</legend>
        {settings.verse_presets.map((verse, index) => (
          <label key={verse.ref} className="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-300 p-3 has-checked:border-church-purple has-checked:bg-church-purple-xl/60">
            <input type="radio" value={String(index)} {...register('verse_mode')} className="mt-1 size-5 accent-church-purple" />
            <span className="text-sm">
              <span className="block font-bold text-gray-900">{verse.ref}</span>
              <span className="text-gray-800">« {verse.text} »</span>
            </span>
          </label>
        ))}
        <label className="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-300 p-3 has-checked:border-church-purple has-checked:bg-church-purple-xl/60">
          <input type="radio" value="custom" {...register('verse_mode')} className="mt-1 size-5 accent-church-purple" />
          <span className="text-sm font-bold text-gray-900">Verset personnalisé</span>
        </label>
        {errors.verse_mode && (
          <p id="verse-mode-error" className="text-sm font-bold text-red-700">
            {errors.verse_mode.message}
          </p>
        )}
      </fieldset>

      {mode === 'custom' && (
        <div className="grid gap-4 rounded-xl border border-gray-200 bg-gray-50 p-4">
          <Field label="Référence" hint="Par exemple : Jean 21:17" error={errors.verse_ref?.message}>
            {(control) => <input {...control} {...register('verse_ref')} maxLength={60} className={inputClass} />}
          </Field>
          <Field label="Texte du verset" error={errors.verse_text?.message}>
            {(control) => <textarea {...control} {...register('verse_text')} rows={3} maxLength={500} className={inputClass} />}
          </Field>
        </div>
      )}

      <div>
        <Button type="submit" pending={mutation.isPending} pendingLabel="Enregistrement…">
          Enregistrer
        </Button>
      </div>
    </form>
  )
}

function ChurchReadOnly({ settings }: { settings: Settings }) {
  return (
    <dl className="flex flex-col gap-4 text-sm">
      <div>
        <dt className="font-bold text-gray-800">Nom de l’église</dt>
        <dd className="text-gray-900">{settings.church_name}</dd>
      </div>
      <div>
        <dt className="font-bold text-gray-800">Adresse publique</dt>
        <dd className="break-all text-gray-900">{settings.public_url || '—'}</dd>
      </div>
      <div>
        <dt className="font-bold text-gray-800">Verset</dt>
        <dd className="text-gray-900">
          {settings.verse.text ? `« ${settings.verse.text} » — ${settings.verse.ref}` : '—'}
        </dd>
      </div>
    </dl>
  )
}

export function ChurchTab() {
  const settings = useSettings()
  const canUpdate = useCan('settings.update')
  return (
    <Card
      title="Église"
      description={canUpdate ? 'Informations affichées sur le formulaire visiteur et sur l’affiche QR code.' : 'Modification réservée aux super administrateurs.'}
      className="max-w-3xl"
    >
      {settings.isPending ? (
        <LoadingState label="Chargement des paramètres…" />
      ) : settings.isError ? (
        <ErrorState error={settings.error} onRetry={() => settings.refetch()} />
      ) : canUpdate ? (
        <ChurchForm settings={settings.data} />
      ) : (
        <ChurchReadOnly settings={settings.data} />
      )}
    </Card>
  )
}
