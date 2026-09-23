// Client HTTP unique du SPA (public et admin).
// Même origine que l'API : cookies de session Sanctum envoyés automatiquement,
// jeton CSRF lu dans le cookie XSRF-TOKEN et renvoyé dans l'en-tête X-XSRF-TOKEN.

import type { ApiErrorBody, ApiErrorCodeName } from './api-types'

export const DEFAULT_TIMEOUT_MS = 15_000

/** Codes du contrat, codes propres au client (réseau, délai, annulation) et `http_<statut>` en repli. */
export type ApiErrorCode = 'network' | 'timeout' | 'aborted' | ApiErrorCodeName | (string & {})

export class ApiError extends Error {
  readonly status: number
  readonly code: ApiErrorCode
  readonly errors: Record<string, string[]>
  readonly retryAfter: number | null

  constructor(
    status: number,
    code: ApiErrorCode,
    message: string,
    errors: Record<string, string[]> = {},
    retryAfter: number | null = null,
  ) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.errors = errors
    this.retryAfter = retryAfter
  }

  /** Premier message d'erreur pour un champ donné (erreurs 422). */
  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0]
  }
}

const DEFAULT_MESSAGES: Record<number, string> = {
  0: 'Connexion impossible. Vérifiez votre connexion internet puis réessayez.',
  401: 'Votre session a expiré. Merci de vous reconnecter.',
  403: "Vous n'avez pas les droits nécessaires pour cette action.",
  404: 'Élément introuvable.',
  409: 'Cette action entre en conflit avec une autre opération.',
  419: 'Votre session a expiré. Merci de réessayer.',
  422: 'Certaines informations sont invalides.',
  423: 'Compte temporairement verrouillé. Réessayez dans quelques minutes.',
  429: 'Trop de tentatives. Merci de patienter un instant.',
  500: 'Une erreur est survenue. Merci de réessayer.',
}

const CODE_BY_STATUS: Record<number, ApiErrorCode> = {
  401: 'unauthenticated',
  403: 'forbidden',
  404: 'not_found',
  419: 'csrf_expired',
  422: 'validation',
  423: 'account_locked',
  429: 'too_many_requests',
}

function readCookie(name: string): string | null {
  const match = document.cookie.split('; ').find((c) => c.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  query?: Record<string, string | number | boolean | null | undefined>
  signal?: AbortSignal
  timeoutMs?: number
}

export function buildQuery(query: RequestOptions['query']): string {
  if (!query) return ''
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(query)) {
    if (value === undefined || value === null || value === '') continue
    params.set(key, String(value))
  }
  const qs = params.toString()
  return qs ? `?${qs}` : ''
}

export async function apiFetch<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', body, query, signal, timeoutMs = DEFAULT_TIMEOUT_MS } = options

  const controller = new AbortController()
  let timedOut = false
  const timer = setTimeout(() => {
    timedOut = true
    controller.abort()
  }, timeoutMs)
  const onAbort = () => controller.abort()
  signal?.addEventListener('abort', onAbort, { once: true })

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  const xsrf = readCookie('XSRF-TOKEN')
  if (xsrf) headers['X-XSRF-TOKEN'] = xsrf

  let response: Response
  try {
    response = await fetch(`${path}${buildQuery(query)}`, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      credentials: 'same-origin',
      signal: controller.signal,
    })
  } catch {
    if (timedOut) throw new ApiError(0, 'timeout', 'Le serveur met trop de temps à répondre. Merci de réessayer.')
    if (signal?.aborted) throw new ApiError(0, 'aborted', 'Requête annulée.')
    throw new ApiError(0, 'network', DEFAULT_MESSAGES[0])
  } finally {
    clearTimeout(timer)
    signal?.removeEventListener('abort', onAbort)
  }

  if (response.status === 204) return undefined as T

  let payload: unknown = null
  const text = await response.text()
  if (text) {
    try {
      payload = JSON.parse(text)
    } catch {
      payload = null
    }
  }

  if (!response.ok) {
    const data = (payload ?? {}) as ApiErrorBody
    const status = response.status
    const code = data.code ?? CODE_BY_STATUS[status] ?? (status >= 500 ? 'server_error' : `http_${status}`)
    const message =
      status >= 500 ? DEFAULT_MESSAGES[500] : (data.message ?? DEFAULT_MESSAGES[status] ?? DEFAULT_MESSAGES[500])
    const retryAfterHeader = response.headers.get('Retry-After')
    throw new ApiError(status, code, message, data.errors ?? {}, retryAfterHeader ? Number(retryAfterHeader) : null)
  }

  return payload as T
}

let csrfPromise: Promise<void> | null = null

/** Pose le cookie XSRF-TOKEN (Sanctum). À appeler avant login et après un 419. */
export function ensureCsrfCookie(force = false): Promise<void> {
  if (force || !csrfPromise || !readCookie('XSRF-TOKEN')) {
    csrfPromise = apiFetch<void>('/sanctum/csrf-cookie').catch((err: unknown) => {
      csrfPromise = null
      throw err
    })
  }
  return csrfPromise
}
