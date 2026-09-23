// Logique commune aux trois formulaires de visite : brouillon persistant,
// envoi idempotent, erreurs serveur rattachées aux champs, résumé d'erreurs.

import { useEffect, useState } from 'react'
import { useForm, type DefaultValues, type FieldValues, type Path, type Resolver } from 'react-hook-form'
import { useNavigate } from 'react-router'
import type { VisitAnswers } from '../../shared/api-types'
import { ApiError } from '../../shared/http'
import { useJourneyStore } from '../journey/context'
import { toDisplayError, type DisplayError } from '../journey/errors'
import { submitVisit } from '../journey/submit'
import type { VisitStep } from '../journey/store'
import type { SummaryItem } from './FormFeedback'

export interface VisitRequest {
  answers: VisitAnswers
  consent?: boolean
  name?: string | null
}

interface UseVisitFormOptions<T extends FieldValues> {
  step: VisitStep
  resolver: Resolver<T>
  defaults: T
  /** Ordre d'affichage des erreurs dans le résumé. */
  fieldOrder: Path<T>[]
  /** id de l'élément à focaliser pour un champ (1re option d'un groupe radio…). */
  targetOf?: (field: Path<T>, values: T) => string
  /**
   * Champs conditionnels à revalider quand un champ change (après un 1er envoi) :
   * une erreur ne reste jamais affichée sur un champ masqué ou devenu valide.
   */
  dependents?: Partial<Record<Path<T>, Path<T>[]>>
  /** Renomme une clé d'erreur serveur (déjà débarrassée de « answers. »). */
  aliasOf?: (serverKey: string, values: T) => string
  toRequest: (values: T) => VisitRequest
}

/** Ne restaure que les clés connues, avec le bon type (brouillon venant du stockage). */
function restoreDraft<T extends FieldValues>(defaults: T, draft: Record<string, unknown> | undefined): T {
  if (!draft) return defaults
  const restored: Record<string, unknown> = { ...defaults }
  for (const [key, fallback] of Object.entries(defaults)) {
    const value = draft[key]
    if (Array.isArray(fallback)) {
      if (Array.isArray(value) && value.every((item) => typeof item === 'string')) restored[key] = value
    } else if (typeof value === typeof fallback) {
      restored[key] = value
    }
  }
  return restored as T
}

/** Vrai si la personne a commencé à répondre (hors valeurs par défaut). */
function hasMeaningfulValues<T extends FieldValues>(values: T, defaults: T): boolean {
  return Object.entries(values).some(([key, value]) => {
    const fallback = (defaults as Record<string, unknown>)[key]
    if (typeof value === 'string') return value.trim() !== '' && value !== fallback
    if (Array.isArray(value)) return value.length > 0
    if (typeof value === 'boolean') return value !== fallback
    return false
  })
}

export function useVisitForm<T extends FieldValues>(options: UseVisitFormOptions<T>) {
  const { step, resolver, defaults, fieldOrder, targetOf, aliasOf, toRequest, dependents } = options
  const store = useJourneyStore()
  const navigate = useNavigate()
  const [initialValues] = useState(() => restoreDraft(defaults, store.getState().drafts[step]))
  const [submitError, setSubmitError] = useState<DisplayError | null>(null)
  const [focusKey, setFocusKey] = useState(0)

  const form = useForm<T>({
    resolver,
    defaultValues: initialValues as DefaultValues<T>,
    mode: 'onSubmit',
    reValidateMode: 'onChange',
    shouldFocusError: false,
  })

  // Brouillon enregistré à chaque modification : rafraîchissement, retour arrière ou
  // aller-retour vers la mention d'information ne perdent rien.
  useEffect(
    () =>
      form.subscribe({
        formState: { values: true },
        callback: ({ values, name }) => {
          store.saveDraft(step, values as Record<string, unknown>)
          const linked = name ? dependents?.[name as Path<T>] : undefined
          if (linked && form.formState.isSubmitted) void form.trigger(linked)
        },
      }),
    [form, store, step, dependents],
  )

  const onValid = async (values: T) => {
    setSubmitError(null)
    const request = toRequest(values)
    const outcome = await submitVisit(store, step, request.answers, { consent: request.consent, name: request.name })

    if (outcome.kind === 'success') {
      navigate('/merci', { replace: true })
      return
    }
    if (outcome.kind === 'redirect') {
      navigate(outcome.to, { replace: true })
      return
    }

    const { error } = outcome
    if (error instanceof ApiError && error.status === 422) {
      const general: string[] = []
      let attached = 0
      for (const [rawKey, messages] of Object.entries(error.errors)) {
        const message = messages?.[0]
        if (!message) continue
        const key = rawKey.replace(/^answers\./, '')
        const aliased = aliasOf ? aliasOf(key, values) : key
        const field = aliased.split('.')[0] as Path<T>
        const target = targetOf ? targetOf(field, values) : field
        // Champ inconnu ou masqué : le message va dans l'alerte générale.
        if (field in defaults && document.getElementById(target)) {
          form.setError(field, { type: 'server', message })
          attached++
        } else {
          general.push(message)
        }
      }
      if (attached > 0) setFocusKey((k) => k + 1)
      if (general.length > 0) setSubmitError({ message: general.join(' '), retryable: false })
      else if (attached === 0) setSubmitError({ message: error.message, retryable: false })
      return
    }
    setSubmitError(toDisplayError(error))
  }

  const onSubmit = form.handleSubmit(onValid, () => setFocusKey((k) => k + 1))

  const { errors, isSubmitting } = form.formState
  const currentValues = form.getValues()
  const summaryItems: SummaryItem[] = []
  for (const field of fieldOrder) {
    const message = (errors as Record<string, { message?: unknown } | undefined>)[field]?.message
    if (typeof message === 'string' && message) {
      summaryItems.push({ target: targetOf ? targetOf(field, currentValues) : field, message })
    }
  }

  const errorOf = (field: Path<T>): string | undefined => {
    const message = (errors as Record<string, { message?: unknown } | undefined>)[field]?.message
    return typeof message === 'string' ? message : undefined
  }

  return {
    form,
    onSubmit,
    submitting: isSubmitting,
    submitError,
    summaryItems,
    focusKey,
    errorOf,
    hasDraft: () => hasMeaningfulValues(form.getValues(), defaults),
  }
}
