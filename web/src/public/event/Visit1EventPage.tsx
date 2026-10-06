// Formulaire allégé de la première visite, réservé aux liens de culte spécial.
// Écarts avec le formulaire habituel : deux origines au lieu de sept, une seule case
// WhatsApp (rejoindre le groupe des nouvelles personnes) avec un numéro facultatif.
// Le reste — identité, invitant, consentement, contrat §2 — est identique.

import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { Controller } from 'react-hook-form'
import { Link } from 'react-router'
import type { Visit1Answers } from '../../shared/api-types'
import { findCountry, type CountryCode, type Source } from '../../shared/domain'
import { useJourneyStore } from '../journey/context'
import { eventPath } from '../journey/flow'
import { cleanPhoneInput, formatPhoneWithDial, phoneForApi, phoneHint } from '../journey/phone'
import type { JourneyEvent } from '../journey/store'
import { focusRing, hintBase } from '../ui/classes'
import { CountrySelect, TextField } from '../ui/Field'
import { visitTitle } from '../ui/text'
import { Checkbox } from '../visit/FormFields'
import { EVENT_SOURCES, visit1EventSchema, type Visit1EventValues } from '../visit/schemas'
import { useCongregations } from '../visit/useCongregations'
import { useFamilies } from '../visit/useFamilies'
import { Visit1IdentityFields } from '../visit/Visit1Fields'
import { useVisitForm } from '../visit/useVisitForm'
import { VisitFormShell } from '../visit/VisitFormParts'

const FIELD_ORDER = [
  'full_name',
  'commune',
  'quartier',
  'source',
  'invited_by',
  'inviter_congregation_id',
  'inviter_family_id',
  'source_other',
  'wants_whatsapp_group',
  'whatsapp_country',
  'whatsapp_number',
  'consent',
] as const satisfies readonly (keyof Visit1EventValues)[]

const textLink = `rounded font-bold text-church-purple underline underline-offset-2 hover:text-church-purple-dk ${focusRing}`

const resolver = zodResolver(visit1EventSchema)

const DEPENDENTS = {
  source: ['invited_by', 'inviter_congregation_id', 'inviter_family_id', 'source_other'],
  wants_whatsapp_group: ['whatsapp_number'],
  whatsapp_country: ['whatsapp_number'],
} satisfies Partial<Record<keyof Visit1EventValues, (keyof Visit1EventValues)[]>>

function toRequest(v: Visit1EventValues) {
  const invited = v.source === 'invite_membre'
  // Le numéro facultatif ne compte que si le groupe est demandé (sinon le champ est masqué).
  const otherNumber = v.wants_whatsapp_group ? v.whatsapp_number.trim() : ''
  const answers: Visit1Answers = {
    full_name: v.full_name.trim(),
    commune: v.commune.trim(),
    quartier: v.quartier.trim(),
    source: v.source as Source,
    source_other: v.source === 'autre' ? v.source_other.trim() : null,
    invited_by: invited ? v.invited_by.trim() : null,
    inviter_congregation_id: invited && v.inviter_congregation_id ? Number(v.inviter_congregation_id) : null,
    inviter_family_id: invited && v.inviter_family_id ? Number(v.inviter_family_id) : null,
    // Contrat §2 inchangé : un autre numéro renseigne `whatsapp`, sinon c'est celui de
    // l'accueil (`whatsapp_same_as_phone`). Sans demande de groupe, rien n'est affirmé.
    whatsapp: otherNumber
      ? { country: v.whatsapp_country, number: phoneForApi(otherNumber, v.whatsapp_country) }
      : null,
    whatsapp_same_as_phone: v.wants_whatsapp_group && !otherNumber,
    wants_whatsapp_group: v.wants_whatsapp_group,
  }
  return { answers, consent: v.consent, name: answers.full_name }
}

export default function Visit1EventPage({ event }: { event: JourneyEvent }) {
  const store = useJourneyStore()
  // Le numéro saisi dans ce navigateur (jamais renvoyé par le serveur).
  const [identified] = useState(() => store.getState().identified)
  const [defaults] = useState<Visit1EventValues>(() => ({
    full_name: '',
    commune: '',
    quartier: '',
    source: '',
    source_other: '',
    invited_by: '',
    inviter_congregation_id: '',
    inviter_family_id: '',
    whatsapp_country: identified?.country ?? 'CI',
    whatsapp_number: '',
    wants_whatsapp_group: false,
    consent: false,
  }))
  const families = useFamilies()
  const congregations = useCongregations()

  const visit = useVisitForm<Visit1EventValues>({
    step: 1,
    resolver,
    defaults,
    fieldOrder: [...FIELD_ORDER],
    dependents: DEPENDENTS,
    targetOf: (field, values) => (field === 'source' ? `source-${values.source || EVENT_SOURCES[0]}` : field),
    aliasOf: (key, values) => {
      // Le serveur ne connaît que `whatsapp` / `whatsapp_same_as_phone` : sans champ
      // « numéro » à l'écran, l'erreur se rattache à la case du groupe.
      if (key === 'whatsapp_same_as_phone') return 'wants_whatsapp_group'
      if (key === 'whatsapp' || key.startsWith('whatsapp.')) {
        if (!values.wants_whatsapp_group) return 'wants_whatsapp_group'
        return key === 'whatsapp.country' ? 'whatsapp_country' : 'whatsapp_number'
      }
      return key
    },
    toRequest,
  })
  const { form, errorOf } = visit
  const { register, control, watch, setValue, getValues } = form
  const wantsGroup = watch('wants_whatsapp_group')
  const whatsappCountry = watch('whatsapp_country')
  const whatsappDial = findCountry(whatsappCountry).dial

  return (
    <VisitFormShell
      step={1}
      homePath={eventPath(event.slug)}
      title={visitTitle(1)}
      subtitle={
        <p>
          Ravis de vous accueillir à <strong className="text-church-purple-dk">{event.name}</strong> ! Quelques
          informations pour mieux vous connaître.
        </p>
      }
      submitting={visit.submitting}
      hasDraft={visit.hasDraft}
      onSubmit={visit.onSubmit}
      summaryItems={visit.summaryItems}
      focusKey={visit.focusKey}
      submitError={visit.submitError}
    >
      <Visit1IdentityFields
        form={form}
        errorOf={errorOf}
        sources={EVENT_SOURCES}
        families={families}
        congregations={congregations}
      />

      <div className="flex min-w-0 flex-col gap-4">
        <Checkbox
          id="wants_whatsapp_group"
          label="Rejoindre le Groupe WhatsApp des nouvelles personnes pour une période de 3 mois."
          error={errorOf('wants_whatsapp_group')}
          {...register('wants_whatsapp_group')}
        />

        {/* Révélé par la case : placé juste après, donc lu dans la foulée par les lecteurs d'écran. */}
        {wantsGroup && (
          <div className="flex flex-col gap-4 border-l-4 border-church-gold pl-4">
            <p className={hintBase}>
              {identified ? (
                <>
                  Nous utiliserons le numéro saisi à l'accueil :{' '}
                  <strong className="whitespace-nowrap">{formatPhoneWithDial(identified.phone, identified.country)}</strong>.
                  Indiquez un autre numéro seulement s'il est différent sur WhatsApp.
                </>
              ) : (
                <>Nous utiliserons le numéro saisi à l'accueil. Indiquez un autre numéro s'il est différent sur WhatsApp.</>
              )}
            </p>
            <CountrySelect
              id="whatsapp_country"
              label="Pays du numéro WhatsApp"
              error={errorOf('whatsapp_country')}
              {...register('whatsapp_country', {
                onChange: (changed: { target: { value: string } }) =>
                  setValue('whatsapp_number', cleanPhoneInput(getValues('whatsapp_number'), changed.target.value as CountryCode)),
              })}
            />
            <Controller
              control={control}
              name="whatsapp_number"
              render={({ field }) => (
                <TextField
                  id="whatsapp_number"
                  label="Autre numéro WhatsApp"
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
                  onChange={(changed) => field.onChange(cleanPhoneInput(changed.target.value, whatsappCountry))}
                />
              )}
            />
          </div>
        )}
      </div>

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
