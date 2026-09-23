/** Valeur d'aria-describedby à partir des ids présents (indication, erreur). */
export function describedBy(...ids: (string | false | null | undefined)[]): string | undefined {
  const value = ids.filter(Boolean).join(' ')
  return value || undefined
}
