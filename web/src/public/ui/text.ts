// Textes communs du parcours visiteur (orthographe validée par le cahier :
// « Église », « Pasteur », « Ravis »). Les rangs de visite s'écrivent en toutes
// lettres — « première / deuxième / troisième visite », jamais « 1re ».

export const CHURCH_NAME = 'Église La Maison de la Destinée'

const ORDINALS = { 1: 'première', 2: 'deuxième', 3: 'troisième' } as const

/** « première » — à insérer dans une phrase (« Votre première visite… »). */
export function ordinal(step: 1 | 2 | 3): string {
  return ORDINALS[step]
}

/** « Première visite » — badge ou intitulé isolé. */
export function visitLabel(step: 1 | 2 | 3): string {
  const word = ORDINALS[step]
  return `${word[0].toUpperCase()}${word.slice(1)} visite`
}

/** Titre du formulaire : « Votre première visite ». */
export function visitTitle(step: 1 | 2 | 3): string {
  return `Votre ${ORDINALS[step]} visite`
}
