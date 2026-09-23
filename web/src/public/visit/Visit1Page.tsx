import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useState } from 'react'
import { Controller } from 'react-hook-form'
import { Link } from 'react-router'
import type { FamilyRef, Visit1Answers } from '../../shared/api-types'
import { findCountry, SOURCE_LABELS, SOURCES, type CountryCode, type Source } from '../../shared/domain'
import { loadPublicConfig } from '../journey/api'
import { useJourneyStore } from '../journey/context'
import { cleanPhoneInput, formatPhoneWithDial, phoneForApi, phoneHint } from '../journey/phone'
import { focusRing, hintBase, labelBase } from '../ui/classes'
import { CountrySelect, SelectField, TextField } from '../ui/Field'
import { Checkbox, ChoiceGroup, ChoiceOption, TextArea } from './FormFields'
import { visit1Schema, type Visit1Values } from './schemas'
import { useVisitForm } from './useVisitForm'
import { VisitFormShell } from './VisitFormParts'

const FIELD_ORDER = [
  'full_name',
  'commune',
  'quartier',
  'source',
  'invited_by',
  'inviter_family_id',
  'source_other',
  'whatsapp_same_as_phone',
  'whatsapp_country',
  'whatsapp_number',
  'wants_whatsapp_group',
  'consent',
] as const satisfies readonly (keyof Visit1Values)[]

const textLink = `rounded font-bold text-church-purple underline underline-offset-2 hover:text-church-purple-dk ${focusRing}`

const resolver = zodResolver(visit1Schema)

const DEPENDENTS = {
  source: ['invited_by', 'inviter_family_id', 'source_other'],
  whatsapp_same_as_phone: ['whatsapp_number'],
  whatsapp_country: ['whatsapp_number'],
  wants_whatsapp_group: ['whatsapp_number'],
} satisfies Partial<Record<keyof Visit1Values, (keyof Visit1Values)[]>>

function toRequest(v: Visit1Values) {
  const invited = v.source === 'invite_membre'
  const whatsappNumber = v.whatsapp_number.trim()
  const answers: Visit1Answers = {
    full_name: v.full_name.trim(),
    commune: v.commune.trim(),
    quartier: v.quartier.trim(),
    source: v.source as Source,
    source_other: v.source === 'autre' ? v.source_other.trim() : null,
    invited_by: invited ? v.invited_by.trim() : null,
    inviter_family_id: invited && v.inviter_family_id ? Number(v.inviter_family_id) : null,
    whatsapp:
      !v.whatsapp_same_as_phone && whatsappNumber
        ? { country: v.whatsapp_country, number: phoneForApi(whatsappNumber, v.whatsapp_country) }
        : null,
    whatsapp_same_as_phone: v.whatsapp_same_as_phone,
    wants_whatsapp_group: v.wants_whatsapp_group,
  }
  return { answers, consent: v.consent, name: answers.full_name }
}

export default function Visit1Page() {
  const store = useJourneyStore()
  // Le numéro saisi dans ce navigateur (jamais renvoyé par le serveur).
  const [identified] = useState(() => store.getState().identified)
  const identifiedCountry: CountryCode = identified?.country ?? 'CI'
  const [defaults] = useState<Visit1Values>(() => ({
    full_name: '',
    commune: '',
    quartier: '',
    source: '',
    source_other: '',
    invited_by: '',
    inviter_family_id: '',
    whatsapp_same_as_phone: false,
    whatsapp_country: identifiedCountry,
    whatsapp_number: '',
    wants_whatsapp_group: false,
    consent: false,
  }))
  const [families, setFamilies] = useState<FamilyRef[]>([])

  useEffect(() => {
    let active = true
    loadPublicConfig()
      .then((config) => {
        if (active && Array.isArray(config.families)) setFamilies(config.families)
      })
      .catch(() => {
        // Liste facultative : sans elle, le champ « famille de l'invitant » est simplement masqué.
      })
    return () => {
      active = false
    }
  }, [])

  const visit = useVisitForm<Visit1Values>({
    step: 1,
    resolver,
    defaults,
    fieldOrder: [...FIELD_ORDER],
    dependents: DEPENDENTS,
    targetOf: (field, values) => {
      if (field === 'source') return `source-${values.source || SOURCES[0]}`
      return field
    },
    aliasOf: (key, values) => {
      if (key === 'whatsapp' || key.startsWith('whatsapp.')) {
        if (values.whatsapp_same_as_phone) return 'whatsapp_same_as_phone'
        return key === 'whatsapp.country' ? 'whatsapp_country' : 'whatsapp_number'
      }
      return key
    },
    toRequest,
  })
  const { form, errorOf } = visit
  const { register, control, watch, setValue, getValues } = form
  const source = watch('source')
  const sameAsPhone = watch('whatsapp_same_as_phone')
  const whatsappCountry = watch('whatsapp_country')
  const whatsappDial = findCountry(whatsappCountry).dial

  return (
    <VisitFormShell
      step={1}
      title="Votre 1re visite"
      subtitle={<p>Ravis de vous accueillir ! Quelques informations pour mieux vous connaître.</p>}
      submitting={visit.submitting}
      hasDraft={visit.hasDraft}
      onSubmit={visit.onSubmit}
      summaryItems={visit.summaryItems}
      focusKey={visit.focusKey}
      submitError={visit.submitError}
    >
      <TextField
        id="full_name"
        label="Nom et prénoms"
        autoComplete="name"
        autoCapitalize="words"
        maxLength={100}
        error={errorOf('full_name')}
        {...register('full_name')}
      />
      <TextField
        id="commune"
        label="Commune de résidence"
        hint="Par exemple : Cocody, Yopougon…"
        autoComplete="address-level2"
        maxLength={80}
        error={errorOf('commune')}
        {...register('commune')}
      />
      <TextField
        id="quartier"
        label="Quartier"
        hint="Par exemple : Angré, Riviera 2…"
        autoComplete="address-level3"
        maxLength={80}
        error={errorOf('quartier')}
        {...register('quartier')}
      />

      <ChoiceGroup id="source" legend="Comment avez-vous connu l'Église ?" error={errorOf('source')}>
        {SOURCES.map((value) => (
          <ChoiceOption
            key={value}
            id={`source-${value}`}
            type="radio"
            value={value}
            label={SOURCE_LABELS[value]}
            invalid={Boolean(errorOf('source'))}
            aria-describedby={errorOf('source') ? 'source-error' : undefined}
            {...register('source')}
          />
        ))}
      </ChoiceGroup>

      {source === 'invite_membre' && (
        <div className="flex flex-col gap-4 border-l-4 border-church-gold pl-4">
          <TextField
            id="invited_by"
            label="Nom de la personne qui vous a invité(e)"
            autoComplete="off"
            maxLength={100}
            error={errorOf('invited_by')}
            {...register('invited_by')}
          />
          {families.length > 0 && (
            <Controller
              control={control}
              name="inviter_family_id"
              render={({ field }) => (
                <SelectField
                  id="inviter_family_id"
                  label="Sa famille dans l'Église"
                  optional
                  error={errorOf('inviter_family_id')}
                  name={field.name}
                  ref={field.ref}
                  value={field.value}
                  onChange={field.onChange}
                  onBlur={field.onBlur}
                >
                  <option value="">Je ne sais pas</option>
                  {families.map((family) => (
                    <option key={family.id} value={String(family.id)}>
                      Famille {family.name}
                    </option>
                  ))}
                </SelectField>
              )}
            />
          )}
        </div>
      )}

      {source === 'autre' && (
        <div className="border-l-4 border-church-gold pl-4">
          <TextArea
            id="source_other"
            label="Précisez comment vous avez connu l'Église"
            maxLength={200}
            rows={2}
            error={errorOf('source_other')}
            {...register('source_other')}
          />
        </div>
      )}

      <fieldset className="flex min-w-0 flex-col gap-4">
        {/* La légende d'un <fieldset> n'est pas un élément flex : le « gap » ne s'applique pas
            entre elle et le texte d'aide, dont l'espacement est donc porté par les deux éléments. */}
        <legend className={`${labelBase} mb-2 text-lg`}>WhatsApp</legend>
        <p className={`${hintBase} mt-1`}>Facultatif, sauf pour rejoindre le groupe WhatsApp de l'Église.</p>
        {identified && (
          <Checkbox
            id="whatsapp_same_as_phone"
            label={
              <>
                Mon numéro WhatsApp est celui saisi à l'accueil :{' '}
                <strong className="whitespace-nowrap">{formatPhoneWithDial(identified.phone, identified.country)}</strong>
              </>
            }
            error={errorOf('whatsapp_same_as_phone')}
            {...register('whatsapp_same_as_phone')}
          />
        )}
        {!sameAsPhone && (
          <>
            <CountrySelect
              id="whatsapp_country"
              label="Pays du numéro WhatsApp"
              error={errorOf('whatsapp_country')}
              {...register('whatsapp_country', {
                onChange: (event: { target: { value: string } }) =>
                  setValue('whatsapp_number', cleanPhoneInput(getValues('whatsapp_number'), event.target.value as CountryCode)),
              })}
            />
            <Controller
              control={control}
              name="whatsapp_number"
              render={({ field }) => (
                <TextField
                  id="whatsapp_number"
                  label="Numéro WhatsApp"
                  optional
                  hint={phoneHint(whatsappCountry)}
                  error={errorOf('whatsapp_number')}
                  type="tel"
                  inputMode="tel"
                  autoComplete={whatsappCountry === 'OTHER' ? 'tel' : 'tel-national'}
                  dialPrefix={whatsappCountry === 'OTHER' ? undefined : whatsappDial}
                  name={field.name}
                  ref={field.ref}
                  value={field.value}
                  onBlur={field.onBlur}
                  onChange={(event) => field.onChange(cleanPhoneInput(event.target.value, whatsappCountry))}
                />
              )}
            />
          </>
        )}
        <Checkbox
          id="wants_whatsapp_group"
          label="Je souhaite rejoindre le groupe WhatsApp de l'Église"
          error={errorOf('wants_whatsapp_group')}
          {...register('wants_whatsapp_group')}
        />
      </fieldset>

      <Checkbox
        id="consent"
        required
        label="J'accepte que l'Église enregistre ces informations pour assurer mon suivi pastoral."
        hint={
          <>
            Elles sont réservées aux responsables de l'Église et conservées 24 mois après votre dernière visite.{' '}
            <Link to="/confidentialite" className={textLink}>
              Lire la mention d'information
            </Link>
          </>
        }
        error={errorOf('consent')}
        {...register('consent')}
      />
    </VisitFormShell>
  )
}
