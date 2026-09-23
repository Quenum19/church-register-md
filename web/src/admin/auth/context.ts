import { createContext, useContext } from 'react'
import type { Ability, User } from '../../shared/api-types'
import type { TwoFactorChallenge } from '../api/auth'

export type AuthStatus = 'loading' | 'authenticated' | 'guest' | 'error'

/** Raison de la dernière fin de session : déconnexion volontaire ou session expirée (401). */
export type SessionEnd = 'manual' | 'expired' | null

export interface AuthContextValue {
  status: AuthStatus
  user: User | null
  abilities: ReadonlySet<Ability>
  sessionEnd: SessionEnd
  /** Connexion ; renvoie `two_factor` si un code TOTP est demandé. */
  login: (credentials: { email: string; password: string }) => Promise<'authenticated' | 'two_factor'>
  completeTwoFactor: (payload: TwoFactorChallenge) => Promise<void>
  logout: () => Promise<void>
  /** Recharge l'utilisateur et ses abilities (GET /api/auth/me). */
  refresh: () => Promise<void>
  setUser: (user: User) => void
  /** Relance la vérification de session après une erreur réseau. */
  retry: () => void
}

export const AuthContext = createContext<AuthContextValue | null>(null)

export function useAuth(): AuthContextValue {
  const value = useContext(AuthContext)
  if (!value) throw new Error('useAuth doit être utilisé dans <AuthProvider>.')
  return value
}

/** Autorisation basée sur les abilities renvoyées par le serveur (jamais sur le rôle en dur). */
export function useCan(ability: Ability): boolean {
  return useAuth().abilities.has(ability)
}
