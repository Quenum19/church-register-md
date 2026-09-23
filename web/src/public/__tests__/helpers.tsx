// Utilitaires de test du parcours visiteur : routeur en mémoire, API mockée (fetch), état initial.

import { configure, render, screen } from '@testing-library/react'
import type { UserEvent } from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router'
import { vi } from 'vitest'
import type { PublicConfig } from '../../shared/api-types'
import PublicApp from '../PublicApp'
import { LocationProbe } from './LocationProbe'
import { clearPublicConfigCache } from '../journey/api'
import { STORAGE_KEY, emptyState, type JourneyState } from '../journey/store'

// Les pages chargées à la demande sont transformées à la volée au premier import, et ces
// tests simulent des parcours complets : on laisse de la marge sur une machine chargée.
configure({ asyncUtilTimeout: 10_000 })
vi.setConfig({ testTimeout: 30_000 })

export const CONFIG: PublicConfig = {
  church_name: 'Église La Maison de la Destinée',
  public_url: 'https://registre.exemple.org',
  verse: { ref: 'Jean 21:17', text: "Si tu m'aimes, pais mes brebis." },
  current_family: { id: 4, name: 'Force' },
  families: [
    { id: 1, name: 'Puissance' },
    { id: 4, name: 'Force' },
  ],
}

export type Reply = { status: number; body?: unknown; headers?: Record<string, string> } | 'network'

export interface RecordedCall {
  method: string
  path: string
  body: Record<string, unknown> | undefined
}

/**
 * Remplace fetch. Chaque route a une file de réponses ; la dernière est rejouée
 * pour les appels suivants. Un appel non prévu fait échouer la requête.
 */
export function mockApi() {
  const queues = new Map<string, Reply[]>()
  const calls: RecordedCall[] = []

  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = typeof input === 'string' ? input : input instanceof URL ? input.toString() : input.url
    const path = url.split('?')[0]
    const method = (init?.method ?? 'GET').toUpperCase()
    const body = init?.body ? (JSON.parse(String(init.body)) as Record<string, unknown>) : undefined
    calls.push({ method, path, body })
    const queue = queues.get(`${method} ${path}`)
    const reply = queue && queue.length > 1 ? queue.shift() : queue?.[0]
    if (!reply) throw new Error(`Appel non prévu : ${method} ${path}`)
    if (reply === 'network') throw new TypeError('Failed to fetch')
    return new Response(reply.body === undefined ? null : JSON.stringify(reply.body), {
      status: reply.status,
      headers: { 'Content-Type': 'application/json', ...reply.headers },
    })
  })
  vi.stubGlobal('fetch', fetchMock)

  const api = {
    calls,
    on(method: string, path: string, ...replies: Reply[]) {
      queues.set(`${method} ${path}`, [...(queues.get(`${method} ${path}`) ?? []), ...replies])
      return api
    },
    /** Remplace la file de réponses d'une route. */
    set(method: string, path: string, ...replies: Reply[]) {
      queues.set(`${method} ${path}`, replies)
      return api
    },
    callsTo(method: string, path: string) {
      return calls.filter((c) => c.method === method && c.path === path)
    },
  }
  return api
}

export type ApiMock = ReturnType<typeof mockApi>

/** À appeler dans beforeEach : fetch mocké, cache de configuration vidé, scrollTo neutralisé. */
export function setupPublicTest(): ApiMock {
  clearPublicConfigCache()
  vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
  return mockApi().on('GET', '/api/public/config', { status: 200, body: CONFIG })
}

/** Pré-remplit l'état du parcours comme si l'identification avait déjà eu lieu. */
export function seedJourney(patch: Partial<JourneyState>): void {
  sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ ...emptyState(), ...patch }))
}

export function seedIdentified(step: 1 | 2 | 3, patch: Partial<JourneyState> = {}): void {
  seedJourney({
    identified: { country: 'CI', phone: '07 00 00 00 00' },
    step,
    token: 'jeton-initial',
    tokenExpiresAt: Date.now() + 900_000,
    ...patch,
  })
}

export function readJourney(): JourneyState | null {
  const raw = sessionStorage.getItem(STORAGE_KEY)
  return raw ? (JSON.parse(raw) as JourneyState) : null
}

export function renderPublic(path = '/') {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/*" element={<PublicApp />} />
      </Routes>
      <LocationProbe />
    </MemoryRouter>,
  )
}

export function currentPath(): string {
  return screen.getByTestId('location').textContent ?? ''
}

export function heading(name: string | RegExp) {
  return screen.findByRole('heading', { level: 1, name })
}

/** Saisit un numéro ivoirien, confirme, et laisse l'identification se faire. */
export async function identifyWith(user: UserEvent, phone = '07 00 00 00 00') {
  await user.type(await screen.findByLabelText('Numéro de téléphone'), phone)
  await user.click(screen.getByRole('button', { name: 'Continuer' }))
  await heading("C'est bien votre numéro ?")
  await user.click(screen.getByRole('button', { name: "Oui, c'est mon numéro" }))
}

export function identifyReply(step: 1 | 2 | 3 | 'complete' | 'done_today', token = 'jeton-1'): Reply {
  const numeric = typeof step === 'number'
  return {
    status: 200,
    body: { step, session_token: numeric ? token : null, expires_in: numeric ? 900 : null },
  }
}

export function visitReply(visitNumber: 1 | 2 | 3, status = 201, family: { id: number; name: string } | null = null): Reply {
  return { status, body: { visit_number: visitNumber, family, completed: visitNumber === 3 } }
}
