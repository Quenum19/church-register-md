import type { ReactNode } from 'react'
import { Navigate, useLocation, useSearchParams } from 'react-router'
import type { Ability } from '../../shared/api-types'
import { PageLoader } from '../../shared/PageLoader'
import { ForbiddenPage } from '../pages/ForbiddenPage'
import { ServerUnavailablePage } from '../pages/auth/ServerUnavailablePage'
import { LOGIN_PATH, loginPathFor, safeAdminPath } from '../lib/paths'
import { useAuth, useCan } from './context'

/** Pages authentifiées : sinon redirection vers la connexion en mémorisant la page demandée. */
export function RequireAuth({ children }: { children: ReactNode }) {
  const { status, sessionEnd, retry } = useAuth()
  const location = useLocation()
  if (status === 'loading') return <PageLoader />
  if (status === 'error') return <ServerUnavailablePage onRetry={retry} />
  if (status === 'guest') {
    const target = sessionEnd === 'manual' ? LOGIN_PATH : loginPathFor(`${location.pathname}${location.search}`)
    return <Navigate to={target} replace />
  }
  return children
}

/** Écran de connexion : un utilisateur déjà connecté est renvoyé vers la page demandée. */
export function GuestOnly({ children }: { children: ReactNode }) {
  const { status } = useAuth()
  const [params] = useSearchParams()
  if (status === 'loading') return <PageLoader />
  if (status === 'authenticated') return <Navigate to={safeAdminPath(params.get('retour')) ?? '/admin'} replace />
  return children
}

export function RequireAbility({ ability, children }: { ability: Ability; children: ReactNode }) {
  return useCan(ability) ? children : <ForbiddenPage />
}

/** Affiche `children` seulement si l'utilisateur possède l'ability. */
export function Can({ ability, children }: { ability: Ability; children: ReactNode }) {
  return useCan(ability) ? children : null
}
