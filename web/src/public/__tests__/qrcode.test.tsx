import { act, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { CONFIG, heading, renderPublic, setupPublicTest, type ApiMock } from './helpers'

let api: ApiMock
const release = vi.fn(async () => {})
const request = vi.fn(async () => ({ released: false, release }))

beforeEach(() => {
  api = setupPublicTest()
  Object.defineProperty(navigator, 'wakeLock', { configurable: true, value: { request } })
})

afterEach(() => {
  Reflect.deleteProperty(navigator, 'wakeLock')
  request.mockClear()
})

describe('Page /qrcode', () => {
  it('affiche le nom, le verset et un QR code généré localement depuis public_url', async () => {
    renderPublic('/qrcode')
    await heading(CONFIG.church_name)
    expect(await screen.findByRole('img', { name: 'QR code menant à registre.exemple.org' })).toBeInTheDocument()
    expect(screen.getByText(/Si tu m'aimes, pais mes brebis\./)).toBeInTheDocument()
    expect(screen.getByText('Jean 21:17')).toBeInTheDocument()
    expect(api.callsTo('GET', '/api/public/config')).toHaveLength(1)
  })

  it('garde l’écran allumé et ré-acquiert le verrou au retour au premier plan', async () => {
    renderPublic('/qrcode')
    await heading(CONFIG.church_name)
    expect(request).toHaveBeenCalledWith('screen')
    const calls = request.mock.calls.length

    const lock = await request.mock.results[0].value
    lock.released = true
    await act(async () => {
      document.dispatchEvent(new Event('visibilitychange'))
    })
    expect(request.mock.calls.length).toBe(calls + 1)
  })

  it('réessaie automatiquement après une erreur réseau puis rafraîchit toutes les 5 minutes', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    try {
      api.set('GET', '/api/public/config', 'network', { status: 200, body: { ...CONFIG, public_url: 'https://nouvelle.exemple.org' } })
      renderPublic('/qrcode')
      expect(await screen.findByText(/nouvelle tentative automatique/)).toBeInTheDocument()

      await act(async () => {
        await vi.advanceTimersByTimeAsync(10_000)
      })
      expect(await screen.findByRole('img', { name: 'QR code menant à nouvelle.exemple.org' })).toBeInTheDocument()

      const before = api.callsTo('GET', '/api/public/config').length
      await act(async () => {
        await vi.advanceTimersByTimeAsync(5 * 60_000)
      })
      expect(api.callsTo('GET', '/api/public/config').length).toBe(before + 1)
    } finally {
      vi.useRealTimers()
    }
  })
})
