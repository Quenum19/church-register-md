import { useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import type { Ability, MeResponse, User } from '../../shared/api-types'
import { ensureCsrfCookie } from '../../shared/http'
import { authApi, type TwoFactorChallenge } from '../api/auth'
import { onUnauthenticated } from '../api/client'
import { isApiError } from '../lib/errors'
import { AuthContext, type AuthContextValue, type AuthStatus, type SessionEnd } from './context'

interface AuthState {
  status: AuthStatus
  user: User | null
  abilities: ReadonlySet<Ability>
}

const NO_ABILITIES: ReadonlySet<Ability> = new Set()
const LOADING: AuthState = { status: 'loading', user: null, abilities: NO_ABILITIES }
const GUEST: AuthState = { status: 'guest', user: null, abilities: NO_ABILITIES }
const ERROR: AuthState = { status: 'error', user: null, abilities: NO_ABILITIES }

function authenticated(me: MeResponse): AuthState {
  return { status: 'authenticated', user: me.user, abilities: new Set(me.abilities) }
}

/**
 * Session Sanctum (cookie httpOnly) : rien n'est stocké côté navigateur.
 * Au montage : GET /api/auth/me → authentifié, ou invité sur 401.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const [state, setState] = useState<AuthState>(LOADING)
  const [sessionEnd, setSessionEnd] = useState<SessionEnd>(null)
  const statusRef = useRef<AuthStatus>('loading')

  const update = useCallback((next: AuthState) => {
    statusRef.current = next.status
    setState(next)
  }, [])

  /** Vérifie la session courante : authentifié, invité (401) ou serveur injoignable. */
  const checkSession = useCallback(
    (signal?: AbortSignal) =>
      authApi
        .me(signal)
        .then((me) => update(authenticated(me)))
        .catch((error: unknown) => {
          if (signal?.aborted) return
          update(isApiError(error, 401) ? GUEST : ERROR)
        }),
    [update],
  )

  useEffect(() => {
    const controller = new AbortController()
    void checkSession(controller.signal)
    return () => controller.abort()
  }, [checkSession])

  // 401 sur une requête authentifiée : session expirée ou compte désactivé.
  useEffect(
    () =>
      onUnauthenticated(() => {
        if (statusRef.current !== 'authenticated') return
        queryClient.clear()
        setSessionEnd('expired')
        update(GUEST)
      }),
    [queryClient, update],
  )

  const loadMe = useCallback(async () => {
    const me = await authApi.me()
    setSessionEnd(null)
    update(authenticated(me))
  }, [update])

  const login = useCallback<AuthContextValue['login']>(
    async (credentials) => {
      await ensureCsrfCookie()
      const response = await authApi.login(credentials)
      if ('two_factor_required' in response && response.two_factor_required) return 'two_factor'
      await loadMe()
      return 'authenticated'
    },
    [loadMe],
  )

  const completeTwoFactor = useCallback(
    async (payload: TwoFactorChallenge) => {
      await authApi.twoFactorChallenge(payload)
      await loadMe()
    },
    [loadMe],
  )

  const logout = useCallback(async () => {
    try {
      await authApi.logout()
    } catch (error) {
      // Session déjà expirée côté serveur : la déconnexion est effective.
      if (!isApiError(error, 401)) throw error
    }
    statusRef.current = 'guest'
    queryClient.clear()
    setSessionEnd('manual')
    update(GUEST)
  }, [queryClient, update])

  const refresh = useCallback(async () => {
    try {
      await loadMe()
    } catch (error) {
      if (!isApiError(error, 401)) throw error
      queryClient.clear()
      setSessionEnd('expired')
      update(GUEST)
    }
  }, [loadMe, queryClient, update])

  const setUser = useCallback((user: User) => {
    setState((current) => (current.status === 'authenticated' ? { ...current, user } : current))
  }, [])

  const retry = useCallback(() => {
    update(LOADING)
    void checkSession()
  }, [checkSession, update])

  const value = useMemo<AuthContextValue>(
    () => ({
      status: state.status,
      user: state.user,
      abilities: state.abilities,
      sessionEnd,
      login,
      completeTwoFactor,
      logout,
      refresh,
      setUser,
      retry,
    }),
    [state, sessionEnd, login, completeTwoFactor, logout, refresh, setUser, retry],
  )

  return <AuthContext value={value}>{children}</AuthContext>
}
