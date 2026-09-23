// Lecture défensive des paramètres d'URL (valeurs invalides ignorées).

export function parseYear(value: string | null | undefined): number | undefined {
  if (!value || !/^\d{4}$/.test(value)) return undefined
  const year = Number(value)
  return year >= 2000 && year <= 2100 ? year : undefined
}

export function parseMonth(value: string | null | undefined): number | undefined {
  if (!value || !/^\d{1,2}$/.test(value)) return undefined
  const month = Number(value)
  return month >= 1 && month <= 12 ? month : undefined
}
