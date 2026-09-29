import { useEffect } from 'react'
import type { Verse } from '../../shared/api-types'
import type { QrMatrix } from '../hooks/useQrMatrix'
import { posterTexts, type PosterHeading } from '../lib/poster'
import { QrCode } from './QrCode'
import './poster-print.css'

export interface PosterPreviewProps extends PosterHeading {
  churchName: string
  verse?: Verse | null
  url: string
  matrix: QrMatrix | null
}

/**
 * Aperçu de l'affiche à imprimer (même contenu que l'export PNG de `renderPosterPng`).
 * Tant qu'il est monté, `window.print()` n'imprime que l'affiche (voir poster-print.css).
 */
export function PosterPreview({ churchName, eventName, eventDate, verse, url, matrix }: PosterPreviewProps) {
  const texts = posterTexts({ eventName, eventDate })

  useEffect(() => {
    document.documentElement.dataset.printPoster = ''
    return () => {
      delete document.documentElement.dataset.printPoster
    }
  }, [])

  return (
    <article
      id="qr-poster"
      aria-label="Aperçu de l’affiche"
      className="mx-auto w-full max-w-md overflow-hidden rounded-3xl bg-white shadow-xl"
    >
      <div aria-hidden="true" className="h-2.5 bg-linear-to-r from-church-gold-dk via-church-gold-lt to-church-gold-dk" />
      <header className="bg-linear-to-br from-church-purple-dk via-church-purple to-church-purple-dk px-8 py-8 text-center text-white">
        <p className={texts.eventName ? 'font-display text-lg font-bold leading-tight' : 'font-display text-2xl font-bold leading-tight'}>
          {churchName}
        </p>
        <div aria-hidden="true" className="mx-auto my-3 h-0.5 w-20 bg-church-gold" />
        {texts.eventName && <p className="font-display text-xl font-bold leading-tight text-church-gold-lt">{texts.eventName}</p>}
        <p className="mt-1 text-sm italic text-purple-100">{texts.subtitle}</p>
      </header>
      <div className="px-8 py-6 text-center">
        <p className="text-lg font-bold text-church-purple-dk">{texts.headline}</p>
        <p className="mt-1 text-sm text-gray-700">{texts.instruction}</p>
        <div className="mx-auto mt-5 w-64 max-w-full rounded-2xl border-2 border-church-purple-xl bg-white p-2">
          {matrix ? (
            <QrCode matrix={matrix} label={`QR code vers ${url}`} />
          ) : (
            <div className="grid aspect-square place-items-center text-sm text-gray-700">Génération…</div>
          )}
        </div>
      </div>
      {verse?.text && (
        <blockquote className="border-t-2 border-church-purple-xl px-8 py-6 text-center">
          <p className="italic leading-relaxed text-gray-700">« {verse.text} »</p>
          <footer className="mt-2 text-sm font-bold text-church-gold-dk">— {verse.ref}</footer>
        </blockquote>
      )}
      <p className="px-8 pb-4 text-center text-xs text-gray-600">{url}</p>
      <div aria-hidden="true" className="h-2.5 bg-linear-to-r from-church-gold-dk via-church-gold-lt to-church-gold-dk" />
    </article>
  )
}
