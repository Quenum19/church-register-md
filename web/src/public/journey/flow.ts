// Identification (contrat §2). L'envoi des visites est dans submit.ts, chargé avec
// les formulaires pour alléger le bundle initial.

import type { IdentifyEventRequest, IdentifyRequest, IdentifyResponse, IdentifyStep } from '../../shared/api-types'
import { ApiError } from '../../shared/http'
import { postIdentify } from './api'
import { phoneForApi } from './phone'
import { samePhone, type JourneyState, type JourneyStore, type PhoneEntry, type VisitStep } from './store'

/** Chemin de l'étape ; la 1re visite d'un événement garde son lien dédié. */
export function pathForStep(step: IdentifyStep, eventSlug?: string | null): string {
  switch (step) {
    case 1:
      return eventSlug ? `${eventPath(eventSlug)}/visite/1` : '/visite/1'
    case 2:
    case 3:
      return `/visite/${step}`
    case 'complete':
      return '/parcours-complet'
    case 'done_today':
      return '/deja-enregistre'
  }
}

/** Accueil d'un événement : « /e/{slug} ». */
export function eventPath(slug: string): string {
  return `/e/${encodeURIComponent(slug)}`
}

/**
 * Redirection à appliquer à l'arrivée sur un formulaire : sans parcours en cours → accueil
 * (celui de l'événement le cas échéant), parcours à une autre étape → bonne étape ;
 * null si l'étape demandée est la bonne.
 */
export function guardVisit(state: JourneyState, step: VisitStep, home = '/'): string | null {
  if (!state.identified || state.step === null) return home
  return state.step === step ? null : pathForStep(state.step, state.event?.slug)
}

export function unexpectedResponse(): ApiError {
  return new ApiError(500, 'server_error', 'Une erreur est survenue. Merci de réessayer.')
}

function checkIdentifyResponse(res: IdentifyResponse): IdentifyResponse {
  const { step } = res
  const known = step === 1 || step === 2 || step === 3 || step === 'complete' || step === 'done_today'
  if (!known || (typeof step === 'number' && !res.session_token)) throw unexpectedResponse()
  return res
}

export function tokenExpiry(expiresIn: number | null): number | null {
  return expiresIn && expiresIn > 0 ? Date.now() + expiresIn * 1000 : null
}

/** Enregistre le résultat d'une identification dans l'état du parcours. */
export function applyIdentifyResult(store: JourneyStore, entry: PhoneEntry, res: IdentifyResponse): void {
  const { step } = res
  if (step === 'complete' || step === 'done_today') {
    // Rien à remplir : on n'a plus besoin de garder le numéro dans ce navigateur.
    store.reset()
    return
  }
  const state = store.getState()
  // Même numéro qu'avant : on garde brouillons et clés d'idempotence (retour arrière, rafraîchissement…).
  const same = samePhone(state.identified, entry)
  store.setState({
    typed: null,
    identified: { country: entry.country, phone: entry.phone.trim() },
    step,
    token: res.session_token,
    tokenExpiresAt: tokenExpiry(res.expires_in),
    idempotencyKeys: same ? state.idempotencyKeys : {},
    drafts: same ? state.drafts : {},
    result: null,
  })
}

export function requestIdentify(
  entry: PhoneEntry,
  signal?: AbortSignal,
  eventSlug?: string | null,
): Promise<IdentifyResponse> {
  const body: IdentifyRequest = { country: entry.country, phone: phoneForApi(entry.phone, entry.country) }
  const payload: IdentifyRequest | IdentifyEventRequest = eventSlug ? { ...body, event: eventSlug } : body
  return postIdentify(payload, signal).then(checkIdentifyResponse)
}

/** Identification depuis l'écran d'accueil ; renvoie l'étape pour le routage. */
export async function identify(store: JourneyStore, entry: PhoneEntry, signal?: AbortSignal): Promise<IdentifyStep> {
  const res = await requestIdentify(entry, signal, store.getState().event?.slug)
  applyIdentifyResult(store, entry, res)
  return res.step
}
