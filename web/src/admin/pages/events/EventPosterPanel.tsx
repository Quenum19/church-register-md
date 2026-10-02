import { useEffect, useRef, useState } from 'react'
import type { ChurchEvent } from '../../../shared/api-types'
import { useSettings } from '../../api/settings'
import { Button } from '../../components/Button'
import { Icon } from '../../components/Icon'
import { PosterPreview } from '../../components/PosterPreview'
import { ErrorState, LoadingState } from '../../components/States'
import { useToast } from '../../components/toast/context'
import { buttonClass, cardClass } from '../../components/styles'
import { useQrMatrix } from '../../hooks/useQrMatrix'
import { copyText } from '../../lib/clipboard'
import { downloadBlob, renderPosterPng } from '../../lib/poster'

/** Affiche d'un événement : même rendu que la page QR code, au nom de l'événement. */
export function EventPosterPanel({ event, onClose }: { event: ChurchEvent; onClose: () => void }) {
  const settings = useSettings()
  const toast = useToast()
  const qr = useQrMatrix(event.url)
  const headingRef = useRef<HTMLHeadingElement>(null)
  const [downloading, setDownloading] = useState(false)

  // Le panneau apparaît à la demande : le lecteur d'écran et le clavier y arrivent directement.
  useEffect(() => {
    headingRef.current?.focus()
  }, [])

  const download = async () => {
    if (!settings.data || !qr.matrix) return
    setDownloading(true)
    try {
      const blob = await renderPosterPng({
        churchName: settings.data.church_name,
        eventName: event.name,
        eventDate: event.event_date,
        verse: settings.data.verse,
        url: event.url,
        matrix: qr.matrix,
      })
      downloadBlob(blob, `affiche-${event.slug}.png`)
      toast.success('Affiche téléchargée.')
    } catch {
      toast.error('Impossible de générer l’image. Essayez l’impression à la place.')
    } finally {
      setDownloading(false)
    }
  }

  const copy = async () => {
    if (await copyText(event.url)) toast.success('Lien copié dans le presse-papiers.')
    else toast.error('Copie impossible : sélectionnez le lien affiché puis copiez-le.')
  }

  return (
    <section aria-labelledby="affiche-evenement" className={`${cardClass} mb-6 p-5 sm:p-6`}>
      <div className="mb-4 flex flex-wrap items-start justify-between gap-3 print:hidden">
        <div>
          <h2
            id="affiche-evenement"
            ref={headingRef}
            tabIndex={-1}
            className="font-display text-lg font-bold text-church-purple-dk outline-none"
          >
            Affiche de « {event.name} »
          </h2>
          <p className="mt-1 break-all text-sm text-gray-700">{event.url}</p>
        </div>
        <Button variant="ghost" size="sm" onClick={onClose}>
          <Icon name="close" className="size-4" />
          Fermer l’affiche
        </Button>
      </div>

      {settings.isPending ? (
        <LoadingState label="Chargement des paramètres…" />
      ) : settings.isError ? (
        <ErrorState error={settings.error} onRetry={() => settings.refetch()} />
      ) : (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
          <PosterPreview
            churchName={settings.data.church_name}
            eventName={event.name}
            eventDate={event.event_date}
            verse={settings.data.verse}
            url={event.url}
            matrix={qr.matrix}
          />
          <div className="flex flex-col gap-2 print:hidden">
            <Button variant="secondary" onClick={copy}>
              <Icon name="copy" className="size-4" />
              Copier le lien
            </Button>
            <a href={`/api/admin/events/${event.id}/affiche.pdf`} download className={buttonClass('primary')}>
              <Icon name="download" className="size-4" />
              Affiche A5 (PDF)
            </a>
            <Button variant="secondary" onClick={download} pending={downloading} pendingLabel="Génération…" disabled={!qr.matrix}>
              <Icon name="download" className="size-4" />
              Télécharger en PNG
            </Button>
            <Button variant="secondary" onClick={() => window.print()} disabled={!qr.matrix}>
              <Icon name="printer" className="size-4" />
              Imprimer l’affiche
            </Button>
            {/* Écran plein à poser à l'accueil : nom et date du culte, QR de son lien dédié. */}
            <a
              href={`/qrcode/e/${event.slug}`}
              target="_blank"
              rel="noopener noreferrer"
              className={buttonClass('ghost')}
            >
              <Icon name="external" className="size-4" />
              Affichage tablette
              <span className="sr-only"> (nouvel onglet)</span>
            </a>
            {qr.failed && (
              <p role="alert" className="text-sm font-bold text-red-800">
                Le QR code n’a pas pu être généré.
              </p>
            )}
            {!event.active && (
              <p className="text-sm text-amber-900">
                Ce lien est désactivé : les personnes qui scannent ce QR code ne pourront pas s’inscrire tant qu’il n’est
                pas réactivé.
              </p>
            )}
          </div>
        </div>
      )}
    </section>
  )
}
