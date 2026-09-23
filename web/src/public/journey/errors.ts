import { ApiError } from '../../shared/http'

export interface DisplayError {
  message: string
  /** Erreur passagère (réseau, délai, serveur) : proposer « Réessayer ». */
  retryable: boolean
}

export function retryAfterMinutes(seconds: number | null): number | null {
  if (seconds === null || !Number.isFinite(seconds) || seconds <= 0) return null
  return Math.max(1, Math.ceil(seconds / 60))
}

export function tooManyRequestsMessage(seconds: number | null): string {
  const minutes = retryAfterMinutes(seconds)
  if (minutes === null) return 'Trop de tentatives. Réessayez dans quelques minutes.'
  return `Trop de tentatives. Réessayez dans ${minutes} minute${minutes > 1 ? 's' : ''}.`
}

/** Traduit n'importe quelle erreur en message clair pour le visiteur. */
export function toDisplayError(error: unknown): DisplayError {
  if (!(error instanceof ApiError)) {
    return { message: 'Une erreur inattendue est survenue. Merci de réessayer.', retryable: true }
  }
  if (error.status === 429) return { message: tooManyRequestsMessage(error.retryAfter), retryable: false }
  if (error.code === 'network' || error.code === 'timeout') return { message: error.message, retryable: true }
  if (error.status >= 500) {
    return { message: 'Le service rencontre un problème momentané. Merci de réessayer dans un instant.', retryable: true }
  }
  return { message: error.message, retryable: false }
}
