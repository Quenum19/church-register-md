// Serveur simulé pour les tests : remplace `fetch` et enregistre chaque requête.

import { vi } from 'vitest'

export interface RecordedRequest {
  method: string
  path: string
  url: URL
  body: unknown
  headers: Record<string, string>
}

export interface Reply {
  status?: number
  body?: unknown
  headers?: Record<string, string>
}

type Handler = (request: RecordedRequest) => Reply | Response | Promise<Reply | Response>

interface Route {
  method: string
  path: string | RegExp
  handler: Handler
}

export function jsonResponse(status: number, body?: unknown, headers: Record<string, string> = {}): Response {
  if (status === 204 || body === undefined) return new Response(null, { status, headers })
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } })
}

function toUrl(input: RequestInfo | URL): URL {
  if (typeof input === 'string') return new URL(input, 'http://localhost')
  if (input instanceof URL) return input
  return new URL(input.url, 'http://localhost')
}

export function createMockServer() {
  const routes: Route[] = []
  const requests: RecordedRequest[] = []

  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = toUrl(input)
    const method = (init?.method ?? 'GET').toUpperCase()
    const body = typeof init?.body === 'string' ? (JSON.parse(init.body) as unknown) : undefined
    const headers = Object.fromEntries(Object.entries((init?.headers as Record<string, string> | undefined) ?? {}))
    const request: RecordedRequest = { method, path: url.pathname, url, body, headers }
    requests.push(request)
    // La dernière route déclarée l'emporte (surcharge des valeurs par défaut).
    for (let i = routes.length - 1; i >= 0; i--) {
      const route = routes[i]
      if (!route || route.method !== method) continue
      const matches = typeof route.path === 'string' ? route.path === url.pathname : route.path.test(url.pathname)
      if (!matches) continue
      const reply = await route.handler(request)
      return reply instanceof Response ? reply : jsonResponse(reply.status ?? 200, reply.body, reply.headers)
    }
    return jsonResponse(404, { message: `Route non simulée : ${method} ${url.pathname}`, code: 'not_found' })
  })

  vi.stubGlobal('fetch', fetchMock)

  const server = {
    fetch: fetchMock,
    requests,
    on(method: string, path: string | RegExp, reply: Handler | Reply) {
      routes.push({ method, path, handler: typeof reply === 'function' ? reply : () => reply })
      return server
    },
    /** Requêtes reçues pour une méthode et un chemin. */
    calls(method: string, path: string | RegExp) {
      return requests.filter(
        (r) => r.method === method && (typeof path === 'string' ? r.path === path : path.test(r.path)),
      )
    },
    last(method: string, path: string | RegExp) {
      const list = server.calls(method, path)
      return list[list.length - 1]
    },
  }
  return server
}

export type MockServer = ReturnType<typeof createMockServer>
