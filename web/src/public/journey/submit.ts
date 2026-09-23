// Envoi d'une visite : idempotence, ré-identification transparente, gestion des conflits
// (contrat §2). Chargé avec les formulaires, hors du bundle initial.

import type { CreateVisitResponse, IdentifyResponse, VisitAnswers } from '../../shared/api-types'
import { ApiError } from '../../shared/http'
import { postVisit } from './api'
import { applyIdentifyResult, pathForStep, requestIdentify, tokenExpiry, unexpectedResponse } from './flow'
import type { JourneyStore, VisitStep } from './store'

/** UUID v4 ; repli sur getRandomValues pour les navigateurs sans randomUUID (iOS < 15.4). */
export function newIdempotencyKey(): string {
  const c = globalThis.crypto
  if (typeof c?.randomUUID === 'function') return c.randomUUID()
  const bytes = new Uint8Array(16)
  c.getRandomValues(bytes)
  bytes[6] = (bytes[6] & 0x0f) | 0x40
  bytes[8] = (bytes[8] & 0x3f) | 0x80
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

/** Clé d'idempotence de l'étape : créée une seule fois, conservée jusqu'au succès. */
export function ensureIdempotencyKey(store: JourneyStore, step: VisitStep): string {
  const existing = store.getState().idempotencyKeys[step]
  if (existing) return existing
  const key = newIdempotencyKey()
  store.setState((s) => ({ idempotencyKeys: { ...s.idempotencyKeys, [step]: key } }))
  return key
}

export type SubmitOutcome =
  | { kind: 'success'; response: CreateVisitResponse }
  | { kind: 'redirect'; to: string }
  | { kind: 'error'; error: unknown }

/** Codes qui justifient une ré-identification silencieuse avec le numéro mémorisé. */
const REIDENTIFY_CODES = new Set(['token_expired', 'token_invalid', 'token_used', 'step_mismatch', 'unauthenticated'])
const MAX_REIDENTIFICATIONS = 2

/**
 * Ré-identifie avec le numéro mémorisé. Renvoie null si l'on peut renvoyer la même
 * visite (même étape, nouveau jeton), sinon l'issue à appliquer (redirection ou erreur).
 */
async function reidentify(store: JourneyStore, step: VisitStep): Promise<SubmitOutcome | null> {
  const entry = store.getState().identified
  if (!entry) return { kind: 'redirect', to: '/' }
  let res: IdentifyResponse
  try {
    res = await requestIdentify(entry)
  } catch (error) {
    return { kind: 'error', error }
  }
  if (res.step === step) {
    store.setState({ token: res.session_token, tokenExpiresAt: tokenExpiry(res.expires_in) })
    return null
  }
  // L'étape a changé entre-temps (visite faite ailleurs, parcours terminé…).
  applyIdentifyResult(store, entry, res)
  return { kind: 'redirect', to: pathForStep(res.step) }
}

export interface SubmitOptions {
  consent?: boolean
  /** Nom saisi à la visite 1, conservé localement pour la page de remerciement. */
  name?: string | null
}

/**
 * Envoie la visite de l'étape. La clé d'idempotence est créée au premier essai et
 * réutilisée à chaque renvoi (coupure réseau, jeton expiré…) jusqu'au succès :
 * le serveur ne peut donc jamais enregistrer deux fois la même visite.
 */
export async function submitVisit(
  store: JourneyStore,
  step: VisitStep,
  answers: VisitAnswers,
  options: SubmitOptions = {},
): Promise<SubmitOutcome> {
  let key = ensureIdempotencyKey(store, step)
  let reidentifications = 0
  let keyRenewed = false

  for (;;) {
    const { token, identified } = store.getState()
    if (!identified) return { kind: 'redirect', to: '/' }

    if (!token) {
      if (reidentifications >= MAX_REIDENTIFICATIONS) return { kind: 'error', error: unexpectedResponse() }
      reidentifications++
      const outcome = await reidentify(store, step)
      if (outcome) return outcome
      continue
    }

    try {
      const response = await postVisit({
        session_token: token,
        idempotency_key: key,
        ...(step === 1 ? { consent: options.consent === true } : {}),
        answers,
      })
      store.setState({
        typed: null,
        identified: null,
        step: null,
        token: null,
        tokenExpiresAt: null,
        idempotencyKeys: {},
        drafts: {},
        result: {
          visitNumber: step,
          family: response?.family ?? null,
          completed: response?.completed === true,
          name: step === 1 && options.name?.trim() ? options.name.trim() : null,
        },
      })
      return { kind: 'success', response }
    } catch (error) {
      if (!(error instanceof ApiError)) return { kind: 'error', error }

      if (error.code === 'already_today') {
        store.reset()
        return { kind: 'redirect', to: '/deja-enregistre' }
      }

      // Clé déjà utilisée pour un autre numéro (changement de numéro dans le même onglet).
      if (error.code === 'idempotency_conflict' && !keyRenewed) {
        keyRenewed = true
        key = newIdempotencyKey()
        const renewed = key
        store.setState((s) => ({ idempotencyKeys: { ...s.idempotencyKeys, [step]: renewed } }))
        continue
      }

      if (error.status === 401 || error.status === 410 || REIDENTIFY_CODES.has(error.code)) {
        if (reidentifications >= MAX_REIDENTIFICATIONS) return { kind: 'error', error }
        reidentifications++
        store.setState({ token: null, tokenExpiresAt: null })
        const outcome = await reidentify(store, step)
        if (outcome) return outcome
        continue
      }

      // Réseau, délai, 422, 429, 5xx : la clé est conservée pour le prochain essai.
      return { kind: 'error', error }
    }
  }
}
