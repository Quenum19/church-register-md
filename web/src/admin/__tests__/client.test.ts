import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../shared/http'
import { adminFetch, onUnauthenticated } from '../api/client'
import { shouldRetry } from '../api/queryClient'
import { createMockServer } from '../test/server'

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('adminFetch — CSRF expiré (419)', () => {
  it('renouvelle le cookie XSRF puis réessaie une seule fois', async () => {
    let attempts = 0
    const server = createMockServer()
      .on('GET', '/sanctum/csrf-cookie', { status: 204 })
      .on('POST', '/api/admin/visitors/5/notes', () => {
        attempts++
        return attempts === 1
          ? { status: 419, body: { message: 'CSRF token mismatch.', code: 'csrf_expired' } }
          : { status: 201, body: { data: { id: 1 } } }
      })

    const result = await adminFetch<{ data: { id: number } }>('/api/admin/visitors/5/notes', { method: 'POST', body: { body: 'x' } })

    expect(result.data.id).toBe(1)
    expect(server.requests.map((r) => `${r.method} ${r.path}`)).toEqual([
      'POST /api/admin/visitors/5/notes',
      'GET /sanctum/csrf-cookie',
      'POST /api/admin/visitors/5/notes',
    ])
  })

  it('n’essaie pas une troisième fois si le 419 persiste', async () => {
    const server = createMockServer()
      .on('GET', '/sanctum/csrf-cookie', { status: 204 })
      .on('POST', '/api/admin/users', { status: 419, body: { message: 'CSRF token mismatch.' } })

    await expect(adminFetch('/api/admin/users', { method: 'POST', body: {} })).rejects.toMatchObject({ status: 419 })
    expect(server.calls('POST', '/api/admin/users')).toHaveLength(2)
    expect(server.calls('GET', '/sanctum/csrf-cookie')).toHaveLength(1)
  })
})

describe('adminFetch — session expirée (401)', () => {
  it('prévient les abonnés, sauf si le 401 est attendu', async () => {
    createMockServer().on('GET', '/api/admin/stats', { status: 401, body: { message: 'Non authentifié.' } })
    const listener = vi.fn()
    const unsubscribe = onUnauthenticated(listener)

    await expect(adminFetch('/api/admin/stats')).rejects.toBeInstanceOf(ApiError)
    expect(listener).toHaveBeenCalledTimes(1)

    await expect(adminFetch('/api/admin/stats', { ignoreUnauthenticated: true })).rejects.toMatchObject({ status: 401 })
    expect(listener).toHaveBeenCalledTimes(1)

    unsubscribe()
    await expect(adminFetch('/api/admin/stats')).rejects.toMatchObject({ status: 401 })
    expect(listener).toHaveBeenCalledTimes(1)
  })
})

describe('politique de nouvel essai des requêtes', () => {
  it('ne réessaie jamais une erreur 4xx', () => {
    for (const status of [401, 403, 404, 409, 422, 429]) {
      expect(shouldRetry(0, new ApiError(status, 'x', 'x'))).toBe(false)
    }
  })

  it('réessaie deux fois au plus une erreur réseau ou serveur', () => {
    const error = new ApiError(500, 'server_error', 'x')
    expect(shouldRetry(0, error)).toBe(true)
    expect(shouldRetry(1, error)).toBe(true)
    expect(shouldRetry(2, error)).toBe(false)
    expect(shouldRetry(0, new ApiError(0, 'network', 'x'))).toBe(true)
  })
})
