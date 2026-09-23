import { z } from 'zod'

export const PASSWORD_HINT = '12 caractères minimum, avec au moins une lettre et un chiffre.'

/** Politique du contrat : 12 caractères minimum (255 au plus), au moins une lettre et un chiffre. */
export const passwordPolicy = z
  .string()
  .min(12, 'Le mot de passe doit contenir au moins 12 caractères.')
  .max(255, 'Le mot de passe ne doit pas dépasser 255 caractères.')
  .regex(/\p{L}/u, 'Le mot de passe doit contenir au moins une lettre.')
  .regex(/\p{N}/u, 'Le mot de passe doit contenir au moins un chiffre.')

export const emailField = z.string().trim().pipe(z.email('Saisissez une adresse e-mail valide.'))

export function requiredText(label: string, max: number) {
  return z
    .string()
    .trim()
    .min(1, `${label} est obligatoire.`)
    .max(max, `${label} ne doit pas dépasser ${max} caractères.`)
}

export function optionalText(label: string, max: number) {
  return z.string().trim().max(max, `${label} ne doit pas dépasser ${max} caractères.`)
}
