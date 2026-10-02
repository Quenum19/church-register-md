import type { Settings } from '../../shared/api-types'

/** Adresse publique de la page « Suivez-nous » (/reseaux), celle qu'encode le QR code. */
export function socialPageUrl(settings: Settings): string {
  const base = settings.public_url.replace(/\/+$/, '')
  return `${base}/reseaux`
}
