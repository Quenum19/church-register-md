// Chargement à la demande : formulaires (react-hook-form + zod) et pages de fin ne font
// pas partie du bundle initial de « / ». Ils sont préchargés pendant la saisie du numéro.

export const loadVisit1 = () => import('./Visit1Page')
export const loadVisit2 = () => import('./Visit2Page')
export const loadVisit3 = () => import('./Visit3Page')
export const loadEndPages = () => import('../pages/EndPages')

export function preloadJourneyPages(): void {
  for (const load of [loadVisit1, loadVisit2, loadVisit3, loadEndPages]) {
    load().catch(() => {
      // Échec silencieux : la page sera rechargée (avec nouvel essai) à l'affichage.
    })
  }
}
