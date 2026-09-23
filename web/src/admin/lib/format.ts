// Formatage des dates et nombres en français, fuseau de l'église (Africa/Abidjan).

import { MONTH_LABELS } from '../../shared/domain'

export const TIME_ZONE = 'Africa/Abidjan'

const dateFormat = new Intl.DateTimeFormat('fr-FR', {
  timeZone: TIME_ZONE,
  day: 'numeric',
  month: 'long',
  year: 'numeric',
})
const shortDateFormat = new Intl.DateTimeFormat('fr-FR', {
  timeZone: TIME_ZONE,
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
})
const dateTimeFormat = new Intl.DateTimeFormat('fr-FR', {
  timeZone: TIME_ZONE,
  day: 'numeric',
  month: 'long',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
})
const longDayFormat = new Intl.DateTimeFormat('fr-FR', {
  timeZone: TIME_ZONE,
  weekday: 'long',
  day: 'numeric',
  month: 'long',
  year: 'numeric',
})
const partsFormat = new Intl.DateTimeFormat('en-CA', {
  timeZone: TIME_ZONE,
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
})
const numberFormat = new Intl.NumberFormat('fr-FR')

const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/

/** Convertit `YYYY-MM-DD` ou un horodatage ISO 8601 en Date ; `null` si invalide. */
export function parseDate(value: string | null | undefined): Date | null {
  if (!value) return null
  const date = DATE_ONLY.test(value) ? new Date(`${value}T12:00:00Z`) : new Date(value)
  return Number.isNaN(date.getTime()) ? null : date
}

function format(formatter: Intl.DateTimeFormat, value: string | null | undefined, fallback: string): string {
  const date = parseDate(value)
  return date ? formatter.format(date) : fallback
}

/** « 22 septembre 2026 » */
export const formatDate = (value: string | null | undefined, fallback = '—') => format(dateFormat, value, fallback)
/** « 22/09/2026 » */
export const formatShortDate = (value: string | null | undefined, fallback = '—') =>
  format(shortDateFormat, value, fallback)
/** « 22 septembre 2026 à 08:00 » */
export const formatDateTime = (value: string | null | undefined, fallback = '—') =>
  format(dateTimeFormat, value, fallback)

/** « mardi 22 septembre 2026 » */
export function formatLongDay(date: Date = new Date()): string {
  return longDayFormat.format(date)
}

/** « septembre 2026 » */
export function formatMonth(year: number, month: number): string {
  return `${MONTH_LABELS[month - 1] ?? '?'} ${year}`
}

/** « de septembre 2026 », « d’août 2026 » (élision devant une voyelle). */
export function ofMonth(year: number, month: number): string {
  const label = formatMonth(year, month)
  return /^[aeiouéè]/i.test(label) ? `d’${label}` : `de ${label}`
}

export function capitalize(text: string): string {
  return text.charAt(0).toUpperCase() + text.slice(1)
}

export function formatNumber(value: number): string {
  return numberFormat.format(value)
}

/** Date du jour dans le fuseau de l'église. */
export function todayParts(now: Date = new Date()): { year: number; month: number; day: number } {
  const parts = Object.fromEntries(partsFormat.formatToParts(now).map((p) => [p.type, p.value]))
  return { year: Number(parts.year), month: Number(parts.month), day: Number(parts.day) }
}

/** « 2026-09 » */
export function yearMonthKey(year: number, month: number): string {
  return `${year}-${String(month).padStart(2, '0')}`
}

/** Décale un couple (année, mois) de `delta` mois. */
export function addMonths(year: number, month: number, delta: number): { year: number; month: number } {
  const index = year * 12 + (month - 1) + delta
  return { year: Math.floor(index / 12), month: (index % 12) + 1 }
}

/** Durée lisible pour un délai d'attente (en-tête Retry-After). */
export function formatDelay(seconds: number): string {
  if (seconds < 60) return `${seconds} seconde${seconds > 1 ? 's' : ''}`
  const minutes = Math.ceil(seconds / 60)
  return `${minutes} minute${minutes > 1 ? 's' : ''}`
}
