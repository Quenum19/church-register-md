// Textes communs du parcours visiteur (orthographe validée par le cahier :
// « Église », « 1re / 2e / 3e », « Pasteur », « Ravis »).

export const CHURCH_NAME = 'Église La Maison de la Destinée'

const ORDINALS = { 1: '1re', 2: '2e', 3: '3e' } as const

export function ordinal(step: 1 | 2 | 3): string {
  return ORDINALS[step]
}

export function visitLabel(step: 1 | 2 | 3): string {
  return `${ORDINALS[step]} visite`
}
