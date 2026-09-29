// Lien d'un événement (`/e/{slug}`) : proposition automatique d'après le nom et validation.
// Contrainte du serveur : 60 caractères au plus, `^[a-z0-9]+(-[a-z0-9]+)*$`.

import { z } from 'zod'

export const SLUG_MAX_LENGTH = 60
export const SLUG_PATTERN = /^[a-z0-9]+(-[a-z0-9]+)*$/

export const SLUG_HINT =
  'Lettres minuscules, chiffres et tirets uniquement (ex. « evangelisation-4-octobre »).'

const SLUG_ERROR =
  'Le lien ne peut contenir que des lettres minuscules non accentuées, des chiffres et des tirets (ex. « evangelisation-4-octobre »).'

// Les ligatures ne se décomposent pas en NFD : on les remplace avant.
const LIGATURES: Record<string, string> = { œ: 'oe', Œ: 'oe', æ: 'ae', Æ: 'ae', ß: 'ss' }

/** Transforme un nom d'événement en lien valide (« Évangélisation 4 octobre » → « evangelisation-4-octobre »). */
export function slugify(value: string): string {
  return value
    .replace(/[œŒæÆß]/g, (c) => LIGATURES[c] ?? c)
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .slice(0, SLUG_MAX_LENGTH)
    .replace(/^-+|-+$/g, '')
}

export const slugField = z
  .string()
  .trim()
  .min(1, 'Le lien est obligatoire.')
  .max(SLUG_MAX_LENGTH, `Le lien ne doit pas dépasser ${SLUG_MAX_LENGTH} caractères.`)
  .regex(SLUG_PATTERN, SLUG_ERROR)
