// Client HTTP du dashboard : surcouche de `apiFetch` (src/shared/http.ts).
// - 419 (CSRF expiré) : renouvellement du cookie XSRF-TOKEN puis UN seul nouvel essai.
// - 401 (session expirée / compte désactivé) : notification globale → l'AuthProvider
//   vide le cache TanStack Query et renvoie vers l'écran de connexion.

import { ApiError, apiFetch, ensureCsrfCookie, type RequestOptions } from '../../shared/http'

type Listener = () => void

const unauthenticatedListeners = new Set<Listener>()

/** S'abonne aux 401 reçus par n'importe quelle requête du dashboard. */
export function onUnauthenticated(listener: Listener): () => void {
  unauthenticatedListeners.add(listener)
  return () => {
    unauthenticatedListeners.delete(listener)
  }
}

export interface AdminRequestOptions extends RequestOptions {
  /** Un 401 est une réponse attendue (ex. GET /api/auth/me, connexion) : pas de déconnexion globale. */
  ignoreUnauthenticated?: boolean
}

export async function adminFetch<T>(path: string, options: AdminRequestOptions = {}): Promise<T> {
  const { ignoreUnauthenticated = false, ...request } = options
  try {
    try {
      return await apiFetch<T>(path, request)
    } catch (error) {
      if (!(error instanceof ApiError) || error.status !== 419) throw error
      await ensureCsrfCookie(true)
      return await apiFetch<T>(path, request)
    }
  } catch (error) {
    if (!ignoreUnauthenticated && error instanceof ApiError && error.status === 401) {
      for (const listener of [...unauthenticatedListeners]) listener()
    }
    throw error
  }
}

export { ApiError }
