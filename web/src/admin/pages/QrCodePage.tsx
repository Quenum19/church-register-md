import { useState } from 'react'
import { Link } from 'react-router'
import { useSettings } from '../api/settings'
import { useCan } from '../auth/context'
import { Button } from '../components/Button'
import { Card } from '../components/Card'
import { Icon } from '../components/Icon'
import { PageHeader } from '../components/PageHeader'
import { PosterPreview } from '../components/PosterPreview'
import { ErrorState, LoadingState } from '../components/States'
import { useToast } from '../components/toast/context'
import { buttonClass, linkClass } from '../components/styles'
import { useQrMatrix } from '../hooks/useQrMatrix'
import { downloadBlob, renderPosterPng } from '../lib/poster'

export function QrCodePage() {
  const settings = useSettings()
  const toast = useToast()
  const canEdit = useCan('settings.update')
  const publicUrl = settings.data?.public_url ?? null
  const qr = useQrMatrix(publicUrl)
  const [downloading, setDownloading] = useState(false)

  const download = async () => {
    if (!settings.data || !qr.matrix) return
    setDownloading(true)
    try {
      const blob = await renderPosterPng({
        churchName: settings.data.church_name,
        verse: settings.data.verse,
        url: settings.data.public_url,
        matrix: qr.matrix,
      })
      downloadBlob(blob, 'affiche-qrcode-maison-de-la-destinee.png')
      toast.success('Affiche téléchargée.')
    } catch {
      toast.error('Impossible de générer l’image. Essayez l’impression à la place.')
    } finally {
      setDownloading(false)
    }
  }

  return (
    <>
      <PageHeader title="QR code" description="Affiche à imprimer pour que les visiteurs s’enregistrent depuis leur téléphone." />
      {settings.isPending ? (
        <LoadingState label="Chargement des paramètres…" />
      ) : settings.isError ? (
        <ErrorState error={settings.error} onRetry={() => settings.refetch()} />
      ) : !settings.data.public_url ? (
        <Card title="Adresse publique manquante">
          <p className="text-sm text-gray-800">
            Aucune adresse publique n’est configurée : le QR code ne peut pas être généré.
          </p>
          {canEdit && (
            <Link to="/admin/parametres?onglet=eglise" className={`${linkClass} mt-3 inline-block text-sm`}>
              Configurer l’adresse publique
            </Link>
          )}
        </Card>
      ) : (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
          <PosterPreview
            churchName={settings.data.church_name}
            verse={settings.data.verse}
            url={settings.data.public_url}
            matrix={qr.matrix}
          />
          <div className="flex flex-col gap-6 print:hidden">
            <Card title="Actions">
              <div className="flex flex-col gap-2">
                <a href="/api/admin/affiche.pdf" download className={buttonClass('primary')}>
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
                <a href="/qrcode" target="_blank" rel="noopener noreferrer" className={buttonClass('ghost')}>
                  <Icon name="external" className="size-4" />
                  Affichage tablette
                  {' '}<span className="sr-only">(nouvel onglet)</span>
                </a>
              </div>
              {qr.failed && (
                <p role="alert" className="mt-3 text-sm font-bold text-red-800">
                  Le QR code n’a pas pu être généré.
                </p>
              )}
            </Card>
            <Card title="Contenu">
              <dl className="flex flex-col gap-3 text-sm">
                <div>
                  <dt className="font-bold text-gray-800">Adresse encodée</dt>
                  <dd className="break-all text-gray-900">{settings.data.public_url}</dd>
                </div>
                <div>
                  <dt className="font-bold text-gray-800">Verset</dt>
                  <dd className="text-gray-900">{settings.data.verse.ref || 'Aucun'}</dd>
                </div>
              </dl>
              {canEdit && (
                <Link to="/admin/parametres?onglet=eglise" className={`${linkClass} mt-3 inline-block text-sm`}>
                  Modifier le nom, l’adresse ou le verset
                </Link>
              )}
            </Card>
          </div>
        </div>
      )}
    </>
  )
}
