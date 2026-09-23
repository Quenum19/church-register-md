// Identification (contrat §2). L'envoi des visites est dans submit.ts, chargé avec
// les formulaires pour alléger le bundle initial.

import type { IdentifyResponse, IdentifyStep } from '../../shared/api-types'
import { ApiError } from '../../shared/http'
import { postIdentify } from './api'
import { phoneForApi } from './phone'
import { samePhone, type JourneyStore, type PhoneEntry } from './store'

export function pathForStep(step: IdentifyStep): string {
  switch (step) {
    case 1:
    case 2:
    case 3:
      return `/visite/${step}`
    case 'complete':
      return '/parcours-complet'
    case 'done_today':
      return '/deja-enregistre'
  }
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

export function requestIdentify(entry: PhoneEntry, signal?: AbortSignal): Promise<IdentifyResponse> {
  return postIdentify({ country: entry.country, phone: phoneForApi(entry.phone, entry.country) }, signal).then(
    checkIdentifyResponse,
  )
}

/** Identification depuis l'écran d'accueil ; renvoie l'étape pour le routage. */
export async function identify(store: JourneyStore, entry: PhoneEntry, signal?: AbortSignal): Promise<IdentifyStep> {
  const res = await requestIdentify(entry, signal)
  applyIdentifyResult(store, entry, res)
  return res.step
}
