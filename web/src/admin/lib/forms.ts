// Rattachement des erreurs 422 du serveur aux champs react-hook-form.

import type { FieldValues, Path, UseFormSetError } from 'react-hook-form'
import { ApiError } from '../../shared/http'
import { errorMessage } from './errors'

/**
 * Place les erreurs de validation serveur sur les champs connus.
 * Renvoie le message à afficher au niveau du formulaire (`null` si tout a été rattaché à un champ).
 */
export function applyServerErrors<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
  fields: readonly Path<T>[],
  aliases: Record<string, Path<T>> = {},
): string | null {
  if (!(error instanceof ApiError) || error.status !== 422 || Object.keys(error.errors).length === 0) {
    return errorMessage(error)
  }
  const unmatched: string[] = []
  let focused = false
  for (const [key, messages] of Object.entries(error.errors)) {
    const message = messages[0]
    if (!message) continue
    const field = aliases[key] ?? (fields.includes(key as Path<T>) ? (key as Path<T>) : undefined)
    if (field) {
      setError(field, { type: 'server', message }, { shouldFocus: !focused })
      focused = true
    } else {
      unmatched.push(message)
    }
  }
  if (unmatched.length > 0) return unmatched.join(' ')
  return null
}
