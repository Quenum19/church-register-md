import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { z } from 'zod'
import type { UpdateVisitorRequest, VisitorDetail } from '../../../shared/api-types'
import { COUNTRIES, COUNTRY_CODES, findCountry, isPlausiblePhone, sanitizePhone } from '../../../shared/domain'
import { useUpdateVisitor } from '../../api/visitors'
import { Button } from '../../components/Button'
import { Dialog, DialogActions } from '../../components/Dialog'
import { CheckboxField, Field, FormAlert } from '../../components/Field'
import { useToast } from '../../components/toast/context'
import { checkboxClass, inputClass } from '../../components/styles'
import { applyServerErrors } from '../../lib/forms'
import { splitE164 } from '../../lib/phone'
import { optionalText, requiredText } from '../../lib/schemas'

const schema = z
  .object({
    full_name: requiredText('Le nom', 100),
    commune: requiredText('La commune', 80),
    quartier: requiredText('Le quartier', 80),
    invited_by: optionalText('Le nom de l’invitant', 100),
    whatsapp_country: z.enum(COUNTRY_CODES),
    whatsapp_number: z.string().trim().max(25, 'Numéro trop long.'),
    wants_whatsapp_group: z.boolean(),
  })
  .superRefine((values, ctx) => {
    if (values.whatsapp_number && !isPlausiblePhone(values.whatsapp_number, values.whatsapp_country)) {
      ctx.addIssue({ code: 'custom', path: ['whatsapp_number'], message: 'Numéro WhatsApp invalide pour ce pays.' })
    }
    if (values.wants_whatsapp_group && !values.whatsapp_number) {
      ctx.addIssue({
        code: 'custom',
        path: ['whatsapp_number'],
        message: 'Un numéro WhatsApp est nécessaire pour rejoindre le groupe.',
      })
    }
  })
type FormValues = z.infer<typeof schema>

const FIELDS = [
  'full_name',
  'commune',
  'quartier',
  'invited_by',
  'whatsapp_country',
  'whatsapp_number',
  'wants_whatsapp_group',
] as const

export function VisitorEditDialog({ visitor, onClose }: { visitor: VisitorDetail; onClose: () => void }) {
  const toast = useToast()
  const mutation = useUpdateVisitor(visitor.id)
  const [failure, setFailure] = useState<string | null>(null)
  const whatsapp = splitE164(visitor.whatsapp)
  const {
    register,
    handleSubmit,
    setError,
    control: formControl,
    formState: { errors, dirtyFields },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      full_name: visitor.full_name,
      commune: visitor.commune,
      quartier: visitor.quartier,
      invited_by: visitor.invited_by ?? '',
      whatsapp_country: visitor.whatsapp ? whatsapp.country : 'CI',
      whatsapp_number: visitor.whatsapp ? whatsapp.number : '',
      wants_whatsapp_group: visitor.wants_whatsapp_group,
    },
  })
  const country = findCountry(useWatch({ control: formControl, name: 'whatsapp_country' }))

  const onSubmit = handleSubmit(async (values) => {
    setFailure(null)
    // Seuls les champs modifiés sont envoyés (PATCH).
    const body: UpdateVisitorRequest = {}
    if (dirtyFields.full_name) body.full_name = values.full_name
    if (dirtyFields.commune) body.commune = values.commune
    if (dirtyFields.quartier) body.quartier = values.quartier
    if (dirtyFields.invited_by) body.invited_by = values.invited_by || null
    if (dirtyFields.wants_whatsapp_group) body.wants_whatsapp_group = values.wants_whatsapp_group
    if (dirtyFields.whatsapp_country || dirtyFields.whatsapp_number) {
      body.whatsapp = values.whatsapp_number
        ? { country: values.whatsapp_country, number: sanitizePhone(values.whatsapp_number, values.whatsapp_country) }
        : null
    }
    if (Object.keys(body).length === 0) {
      onClose()
      return
    }
    try {
      await mutation.mutateAsync(body)
      toast.success('Fiche du visiteur mise à jour.')
      onClose()
    } catch (error) {
      setFailure(
        applyServerErrors(error, setError, FIELDS, {
          whatsapp: 'whatsapp_number',
          'whatsapp.number': 'whatsapp_number',
          'whatsapp.country': 'whatsapp_country',
        }),
      )
    }
  })

  return (
    <Dialog title="Modifier la fiche" description={visitor.full_name} onClose={onClose} busy={mutation.isPending} size="lg">
      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
        <FormAlert message={failure} />
        <Field label="Nom complet" error={errors.full_name?.message}>
          {(control) => <input {...control} {...register('full_name')} autoComplete="off" className={inputClass} />}
        </Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Commune" error={errors.commune?.message}>
            {(control) => <input {...control} {...register('commune')} autoComplete="off" className={inputClass} />}
          </Field>
          <Field label="Quartier" error={errors.quartier?.message}>
            {(control) => <input {...control} {...register('quartier')} autoComplete="off" className={inputClass} />}
          </Field>
        </div>
        <Field label="Invité(e) par" optional error={errors.invited_by?.message}>
          {(control) => <input {...control} {...register('invited_by')} autoComplete="off" className={inputClass} />}
        </Field>
        <fieldset className="flex flex-col gap-3 rounded-xl border border-gray-300 p-4">
          <legend className="px-1 text-sm font-bold text-gray-800">WhatsApp</legend>
          <div className="grid gap-4 sm:grid-cols-[minmax(0,14rem)_1fr]">
            <Field label="Pays" error={errors.whatsapp_country?.message}>
              {(control) => (
                <select {...control} {...register('whatsapp_country')} className={inputClass}>
                  {COUNTRIES.map((c) => (
                    <option key={c.code} value={c.code}>
                      {c.name} ({c.dial})
                    </option>
                  ))}
                </select>
              )}
            </Field>
            <Field label="Numéro" optional hint="Laisser vide pour supprimer le numéro." error={errors.whatsapp_number?.message}>
              {(control) => (
                <input
                  {...control}
                  {...register('whatsapp_number')}
                  type="tel"
                  inputMode="tel"
                  autoComplete="off"
                  placeholder={country.placeholder}
                  className={inputClass}
                />
              )}
            </Field>
          </div>
          <CheckboxField label="Souhaite rejoindre le groupe WhatsApp">
            {(control) => <input {...control} {...register('wants_whatsapp_group')} type="checkbox" className={checkboxClass} />}
          </CheckboxField>
        </fieldset>
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
