import type { PhoneInput } from '../../shared/api-types'
import { COUNTRIES } from '../../shared/domain'

// Indicatifs du plus long au plus court pour éviter les préfixes ambigus.
const DIAL_CODES = COUNTRIES.filter((c) => c.code !== 'OTHER').sort((a, b) => b.dial.length - a.dial.length)

/** Décompose un numéro E.164 en (pays, numéro national) pour préremplir un formulaire. */
export function splitE164(e164: string | null | undefined): PhoneInput {
  if (!e164) return { country: 'CI', number: '' }
  const match = DIAL_CODES.find((c) => e164.startsWith(c.dial))
  if (!match) return { country: 'OTHER', number: e164 }
  return { country: match.code, number: e164.slice(match.dial.length) }
}

/** Affichage lisible : « +225 07 00 00 00 00 » (groupes de 2 chiffres pour les indicatifs connus). */
export function formatPhone(e164: string | null | undefined): string {
  if (!e164) return '—'
  const { country, number } = splitE164(e164)
  if (country === 'OTHER' || !/^\d+$/.test(number)) return e164
  const dial = COUNTRIES.find((c) => c.code === country)?.dial ?? ''
  const groups = number.length % 2 === 0 ? number.match(/\d{2}/g) : [number[0], ...(number.slice(1).match(/\d{2}/g) ?? [])]
  return `${dial} ${(groups ?? [number]).join(' ')}`
}

/** Lien WhatsApp (wa.me) à partir d'un numéro E.164. */
export function whatsappLink(e164: string): string {
  return `https://wa.me/${e164.replace(/\D/g, '')}`
}
