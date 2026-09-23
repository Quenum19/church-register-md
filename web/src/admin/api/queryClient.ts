import { QueryClient } from '@tanstack/react-query'
import { ApiError } from '../../shared/http'

/** Pas de nouvel essai sur une erreur client (4xx) ni sur une annulation ; 2 essais max sinon. */
export function shouldRetry(failureCount: number, error: unknown): boolean {
  if (error instanceof ApiError) {
    if (error.status >= 400 && error.status < 500) return false
    if (error.code === 'aborted') return false
  }
  return failureCount < 2
}

export function createAdminQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        retry: shouldRetry,
        staleTime: 30_000,
      },
      mutations: {
        retry: false,
      },
    },
  })
}
