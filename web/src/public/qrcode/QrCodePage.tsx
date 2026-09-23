// /qrcode — écran affiché sur la tablette de l'accueil.
// QR code généré localement depuis public_url, écran maintenu allumé (Wake Lock),
// configuration rafraîchie toutes les 5 minutes, nouvel essai automatique si le réseau tombe.

import { useEffect, useMemo, useState } from 'react'
import type { PublicConfig } from '../../shared/api-types'
import logoUrl from '../../assets/logo.webp'
import { getPublicConfig } from '../journey/api'
import { CHURCH_NAME } from '../ui/text'
import { buildQrSvg, type QrSvg } from './qr'

export const CONFIG_REFRESH_MS = 5 * 60_000
export const RETRY_MIN_MS = 10_000

/** Garde l'écran allumé ; ré-acquis au retour au premier plan ; silencieux si non supporté. */
function useWakeLock() {
  useEffect(() => {
    if (!('wakeLock' in navigator) || !navigator.wakeLock) return
    let sentinel: WakeLockSentinel | null = null
    let disposed = false

    const acquire = async () => {
      if (disposed || document.visibilityState !== 'visible') return
      if (sentinel && !sentinel.released) return
      try {
        const lock = await navigator.wakeLock.request('screen')
        if (disposed) void lock.release().catch(() => {})
        else sentinel = lock
      } catch {
        // Refus (batterie faible, permissions…) : l'affichage continue sans verrou.
      }
    }
    const onVisibilityChange = () => {
      if (document.visibilityState === 'visible') void acquire()
    }

    void acquire()
    document.addEventListener('visibilitychange', onVisibilityChange)
    return () => {
      disposed = true
      document.removeEventListener('visibilitychange', onVisibilityChange)
      if (sentinel && !sentinel.released) void sentinel.release().catch(() => {})
    }
  }, [])
}

/** Charge la configuration, la rafraîchit toutes les 5 min, réessaie avec un délai croissant. */
function usePolledConfig() {
  const [config, setConfig] = useState<PublicConfig | null>(null)
  const [offline, setOffline] = useState(false)

  useEffect(() => {
    const controller = new AbortController()
    let timer: number | undefined
    let retryDelay = RETRY_MIN_MS
    let inFlight = false

    const schedule = (delay: number) => {
      window.clearTimeout(timer)
      timer = window.setTimeout(() => void load(), delay)
    }

    const load = async () => {
      if (inFlight || controller.signal.aborted) return
      inFlight = true
      try {
        const next = await getPublicConfig(controller.signal)
        setConfig(next)
        setOffline(false)
        retryDelay = RETRY_MIN_MS
        schedule(CONFIG_REFRESH_MS)
      } catch {
        if (controller.signal.aborted) return
        setOffline(true)
        schedule(retryDelay)
        retryDelay = Math.min(retryDelay * 2, CONFIG_REFRESH_MS)
      } finally {
        inFlight = false
      }
    }

    const onOnline = () => void load()
    void load()
    window.addEventListener('online', onOnline)
    return () => {
      controller.abort()
      window.clearTimeout(timer)
      window.removeEventListener('online', onOnline)
    }
  }, [])

  return { config, offline }
}

function displayUrl(url: string): string {
  return url.replace(/^https?:\/\//, '').replace(/\/$/, '')
}

export default function QrCodePage() {
  const { config, offline } = usePolledConfig()
  useWakeLock()

  const churchName = config?.church_name?.trim() || CHURCH_NAME
  // Tant que la configuration n'est pas chargée, l'adresse de ce site est une entrée valide du parcours.
  const url = config?.public_url?.trim() || `${window.location.origin}/`
  const qr = useMemo<QrSvg | null>(() => {
    try {
      return buildQrSvg(url)
    } catch {
      return null
    }
  }, [url])

  useEffect(() => {
    document.title = `QR code d'accueil — ${churchName}`
  }, [churchName])

  return (
    <div className="flex min-h-dvh flex-col bg-church-purple-dk bg-linear-160 from-[#3d0a5a] via-church-purple to-church-purple-dk text-white">
      <div aria-hidden="true" className="h-2 w-full shrink-0 bg-linear-to-r from-church-gold via-church-gold-lt to-church-gold" />
      <main className="flex flex-1 flex-col items-center justify-center gap-5 px-6 py-8 text-center sm:gap-7">
        <img src={logoUrl} alt="" width={112} height={112} className="size-20 sm:size-28" />
        <h1 className="max-w-4xl font-display text-3xl leading-tight font-bold sm:text-5xl">{churchName}</h1>
        <p className="max-w-3xl text-xl sm:text-3xl">
          Bienvenue ! Scannez ce code avec l'appareil photo de votre téléphone pour enregistrer votre visite.
        </p>
        <div className="rounded-3xl bg-white p-3 shadow-2xl ring-4 ring-church-gold sm:p-5">
          {qr ? (
            <svg
              role="img"
              aria-label={`QR code menant à ${displayUrl(url)}`}
              viewBox={`0 0 ${qr.size} ${qr.size}`}
              shapeRendering="crispEdges"
              className="block size-[min(72vw,50vh)]"
            >
              <rect width={qr.size} height={qr.size} fill="#ffffff" />
              <path d={qr.path} fill="#1f0530" />
            </svg>
          ) : (
            <p className="flex size-[min(72vw,50vh)] items-center justify-center p-6 text-lg text-gray-900">
              QR code indisponible.
            </p>
          )}
        </div>
        <p className="text-lg sm:text-2xl">
          ou rendez-vous sur <strong className="break-all text-church-gold-lt">{displayUrl(url)}</strong>
        </p>
        <p role="status" className="min-h-6 text-base text-white">
          {offline ? 'Connexion au serveur interrompue : nouvelle tentative automatique…' : ''}
        </p>
      </main>
      {config?.verse?.text && (
        <figure className="shrink-0 border-t border-church-gold/40 bg-black/15 px-6 py-5 text-center sm:py-7">
          <blockquote className="mx-auto max-w-4xl text-lg italic sm:text-2xl">« {config.verse.text} »</blockquote>
          <figcaption className="mt-2 font-bold text-church-gold-lt sm:text-xl">{config.verse.ref}</figcaption>
        </figure>
      )}
      <div aria-hidden="true" className="h-2 w-full shrink-0 bg-linear-to-r from-church-gold via-church-gold-lt to-church-gold" />
    </div>
  )
}
