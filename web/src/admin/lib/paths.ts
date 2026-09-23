// Chemins internes du dashboard et validation des redirections (pas de redirection ouverte).

export const ADMIN_BASE = '/admin'
export const LOGIN_PATH = '/admin/connexion'

/** Renvoie `value` s'il s'agit d'un chemin interne au dashboard (hors écran de connexion), sinon `null`. */
export function safeAdminPath(value: unknown): string | null {
  if (typeof value !== 'string') return null
  if (!value.startsWith(ADMIN_BASE) || value.startsWith('//') || value.includes('\\')) return null
  const rest = value.slice(ADMIN_BASE.length)
  if (rest !== '' && !['/', '?', '#'].includes(rest[0])) return null
  if (value.startsWith(LOGIN_PATH)) return null
  return value
}

/** URL de l'écran de connexion mémorisant la page demandée. */
export function loginPathFor(requested: string): string {
  const target = safeAdminPath(requested)
  if (!target || target === ADMIN_BASE || target === `${ADMIN_BASE}/`) return LOGIN_PATH
  return `${LOGIN_PATH}?retour=${encodeURIComponent(target)}`
}

/** État de navigation transmis à la fiche visiteur pour revenir à la liste d'origine. */
export interface BackState {
  from: string
  label: string
}

export function readBackState(state: unknown, fallback: BackState): BackState {
  if (state && typeof state === 'object' && 'from' in state && 'label' in state) {
    const { from, label } = state as { from: unknown; label: unknown }
    const safe = safeAdminPath(from)
    if (safe && typeof label === 'string') return { from: safe, label }
  }
  return fallback
}
