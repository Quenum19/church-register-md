// Schémas de validation client, alignés sur le contrat §2 (longueurs, champs
// conditionnels). zod/mini : même moteur que zod, bundle bien plus léger.
// Toutes les vérifications sont « continuables » : toutes les erreurs s'affichent d'un coup.

import * as z from 'zod/mini'
import { COUNTRY_CODES, RETURN_REASONS, SOURCES, VISIT_REASONS } from '../../shared/domain'
import { validatePhone } from '../journey/phone'

function requiredText(max: number, missing: string, tooLong: string) {
  return z.string().check(
    z.refine((v) => v.trim().length > 0, missing),
    z.refine((v) => v.trim().length <= max, tooLong),
  )
}

function oneOf(values: readonly string[], message: string) {
  return z.string().check(z.refine((v) => values.includes(v), message))
}

type AddIssue = (path: string, message: string) => void

/** Texte requis sous condition (précision « Autre », nom de l'invitant…). */
function checkConditionalText(value: string, max: number, path: string, missing: string, tooLong: string, add: AddIssue) {
  const text = value.trim()
  if (!text) add(path, missing)
  else if (text.length > max) add(path, tooLong)
}

function issueAdder(ctx: { addIssue: (issue: { code: 'custom'; message: string; path: string[] }) => void }): AddIssue {
  return (path, message) => ctx.addIssue({ code: 'custom', message, path: [path] })
}

/* ─── Visite 1 ───────────────────────────────────────────────────── */

export const visit1Schema = z
  .object({
    full_name: requiredText(100, 'Indiquez votre nom et vos prénoms.', 'Le nom ne doit pas dépasser 100 caractères.'),
    commune: requiredText(80, 'Indiquez votre commune.', 'La commune ne doit pas dépasser 80 caractères.'),
    quartier: requiredText(80, 'Indiquez votre quartier.', 'Le quartier ne doit pas dépasser 80 caractères.'),
    source: oneOf(SOURCES, "Indiquez comment vous avez connu l'Église."),
    source_other: z.string(),
    invited_by: z.string(),
    inviter_family_id: z.string(),
    whatsapp_same_as_phone: z.boolean(),
    whatsapp_country: z.enum(COUNTRY_CODES),
    whatsapp_number: z.string(),
    wants_whatsapp_group: z.boolean(),
    consent: z
      .boolean()
      .check(z.refine((v) => v, 'Votre accord est nécessaire pour enregistrer votre visite.')),
  })
  .check(
    z.superRefine((v, ctx) => {
      const add = issueAdder(ctx)
      if (v.source === 'invite_membre') {
        checkConditionalText(
          v.invited_by,
          100,
          'invited_by',
          'Indiquez le nom de la personne qui vous a invité(e).',
          'Le nom ne doit pas dépasser 100 caractères.',
          add,
        )
      }
      if (v.source === 'autre') {
        checkConditionalText(
          v.source_other,
          200,
          'source_other',
          "Précisez comment vous avez connu l'Église.",
          'La précision ne doit pas dépasser 200 caractères.',
          add,
        )
      }
      if (!v.whatsapp_same_as_phone) {
        if (v.whatsapp_number.trim()) {
          const error = validatePhone(v.whatsapp_number, v.whatsapp_country, 'whatsapp')
          if (error) add('whatsapp_number', error)
        } else if (v.wants_whatsapp_group) {
          add(
            'whatsapp_number',
            'Pour rejoindre le groupe WhatsApp, indiquez votre numéro WhatsApp ou cochez « même numéro ».',
          )
        }
      }
    }),
  )

export type Visit1Values = z.output<typeof visit1Schema>

/* ─── Visite 2 ───────────────────────────────────────────────────── */

export const visit2Schema = z
  .object({
    return_reasons: z
      .array(z.string())
      .check(
        z.refine((a) => a.length > 0, 'Choisissez au moins une raison.'),
        z.refine((a) => a.every((r) => (RETURN_REASONS as readonly string[]).includes(r)), 'Choix invalide.'),
      ),
    return_reasons_other: z.string(),
  })
  .check(
    z.superRefine((v, ctx) => {
      if (v.return_reasons.includes('autres')) {
        checkConditionalText(
          v.return_reasons_other,
          200,
          'return_reasons_other',
          'Précisez vos autres raisons.',
          'La précision ne doit pas dépasser 200 caractères.',
          issueAdder(ctx),
        )
      }
    }),
  )

export type Visit2Values = z.output<typeof visit2Schema>

/* ─── Visite 3 ───────────────────────────────────────────────────── */

export const visit3Schema = z
  .object({
    visit_reason: oneOf(VISIT_REASONS, 'Choisissez la principale raison de vos visites.'),
    visit_reason_other: z.string(),
  })
  .check(
    z.superRefine((v, ctx) => {
      if (v.visit_reason === 'autres') {
        checkConditionalText(
          v.visit_reason_other,
          200,
          'visit_reason_other',
          'Précisez la raison de vos visites.',
          'La précision ne doit pas dépasser 200 caractères.',
          issueAdder(ctx),
        )
      }
    }),
  )

export type Visit3Values = z.output<typeof visit3Schema>
