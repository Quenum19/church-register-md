// Culte spécial ouvert par son lien dédié. Hors du bundle initial : seuls les visiteurs
// qui suivent un lien « /e/{slug} » téléchargent ce code.

import type { PublicEvent } from '../../shared/api-types'
import { apiFetch } from '../../shared/http'

/** 404 si le slug est inconnu ou l'événement inactif. */
export function getPublicEvent(slug: string, signal?: AbortSignal): Promise<PublicEvent> {
  return apiFetch<PublicEvent>(`/api/public/events/${encodeURIComponent(slug)}`, { signal })
}
