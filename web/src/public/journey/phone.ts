// Saisie des numéros : on accepte chiffres et espaces (et « + » pour « Autre pays »),
// on plafonne le nombre de CHIFFRES au maximum du pays — jamais un maxLength sur la
// longueur brute, qui bloquerait « 07 00 00 00 00 » (14 caractères, 10 chiffres).
// Le serveur fait foi (libphonenumber) ; cette validation n'est qu'une aide.

import { findCountry, sanitizePhone, type CountryCode } from '../../shared/domain'

/** Retire un préfixe international collé (« +225 », « 00225 ») en ignorant les espaces. */
function stripInternationalPrefix(value: string, dialDigits: string): string {
  for (const prefix of [`+${dialDigits}`, `00${dialDigits}`]) {
    let i = 0
    let matched = 0
    while (i < value.length && matched < prefix.length) {
      const ch = value[i]
      if (ch === ' ') {
        i++
        continue
      }
      if (ch !== prefix[matched]) break
      i++
      matched++
    }
    if (matched === prefix.length) return value.slice(i).trimStart()
  }
  return value
}

/** Nettoie la saisie au fil de la frappe (aussi appliqué au collage et à l'autoremplissage). */
export function cleanPhoneInput(raw: string, country: CountryCode): string {
  const { dial, maxDigits } = findCountry(country)
  let value = raw.replace(/[.\-/() \t]/g, ' ').trimStart()
  if (country !== 'OTHER') value = stripInternationalPrefix(value, dial.slice(1))

  let out = ''
  let digits = 0
  for (const ch of value) {
    if (ch >= '0' && ch <= '9') {
      if (digits < maxDigits) {
        out += ch
        digits++
      }
    } else if (ch === ' ') {
      if (out !== '' && out !== '+' && !out.endsWith(' ')) out += ' '
    } else if (ch === '+' && country === 'OTHER' && out === '') {
      out = '+'
    }
  }
  return out
}

export function countDigits(value: string): number {
  return value.replace(/\D/g, '').length
}

function digitsRule(min: number, max: number): string {
  return min === max ? `${min} chiffres` : `entre ${min} et ${max} chiffres`
}

/** Indication affichée sous le champ (format attendu). */
export function phoneHint(country: CountryCode): string {
  const c = findCountry(country)
  if (country === 'OTHER') return `Numéro complet avec l'indicatif du pays, par exemple ${c.placeholder}.`
  return `${digitsRule(c.minDigits, c.maxDigits)}, par exemple ${c.placeholder}.`.replace(/^./, (m) => m.toUpperCase())
}

const SUBJECTS = {
  phone: { empty: 'Saisissez votre numéro de téléphone.', name: 'Le numéro' },
  whatsapp: { empty: 'Saisissez votre numéro WhatsApp.', name: 'Le numéro WhatsApp' },
} as const

/** Message d'erreur en français, ou null si le numéro est plausible. */
export function validatePhone(value: string, country: CountryCode, kind: keyof typeof SUBJECTS = 'phone'): string | null {
  const subject = SUBJECTS[kind]
  const trimmed = value.trim()
  if (!trimmed) return subject.empty
  if (country === 'OTHER' && !trimmed.startsWith('+')) {
    return `Pour un autre pays, saisissez le numéro complet en commençant par « + » et l'indicatif (par exemple ${findCountry('OTHER').placeholder}).`
  }
  const c = findCountry(country)
  const n = countDigits(trimmed)
  if (n < c.minDigits || n > c.maxDigits) {
    return `${subject.name} doit comporter ${digitsRule(c.minDigits, c.maxDigits)} (${n} saisi${n > 1 ? 's' : ''}).`
  }
  return null
}

/** Valeur envoyée à l'API : chiffres seuls (et « + » initial pour OTHER). */
export function phoneForApi(value: string, country: CountryCode): string {
  return sanitizePhone(value, country)
}

/** Affichage lisible : « 07 00 00 00 00 » pour la Côte d'Ivoire, sinon la saisie normalisée. */
export function formatPhone(value: string, country: CountryCode): string {
  const compact = value.trim().replace(/\s+/g, ' ')
  if (country === 'CI') {
    const digits = compact.replace(/\D/g, '')
    if (digits.length === 10) return digits.replace(/(\d{2})(?=\d)/g, '$1 ')
  }
  return compact
}

/** « +225 07 00 00 00 00 » (ou la saisie internationale pour OTHER). */
export function formatPhoneWithDial(value: string, country: CountryCode): string {
  const formatted = formatPhone(value, country)
  return country === 'OTHER' ? formatted : `${findCountry(country).dial} ${formatted}`
}
