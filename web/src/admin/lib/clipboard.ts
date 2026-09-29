// Copie d'un texte court (lien d'un événement) dans le presse-papiers.
// L'API n'existe pas partout et peut être refusée par le navigateur : l'appelant
// affiche alors un repli (le lien reste visible et sélectionnable à l'écran).

export async function copyText(text: string): Promise<boolean> {
  try {
    if (!navigator.clipboard?.writeText) return false
    await navigator.clipboard.writeText(text)
    return true
  } catch {
    return false
  }
}
