import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import type { Settings } from '../../shared/api-types'
import { useSettings } from '../api/settings'
import { useCan } from '../auth/context'
import { Button } from '../components/Button'
import { Card } from '../components/Card'
import { Icon } from '../components/Icon'
import { PageHeader } from '../components/PageHeader'
import { QrCode } from '../components/QrCode'
import { ErrorState, LoadingState } from '../components/States'
import { useToast } from '../components/toast/context'
import { buttonClass, linkClass } from '../components/styles'
import { useQrMatrix, type QrMatrix } from '../hooks/useQrMatrix'
import { downloadBlob, renderPosterPng } from '../lib/poster'
import './qrcode-print.css'

function Poster({ settings, matrix }: { settings: Settings; matrix: QrMatrix | null }) {
  return (
    <article
      id="qr-poster"
      aria-label="Aperçu de l’affiche"
      className="mx-auto w-full max-w-md overflow-hidden rounded-3xl bg-white shadow-xl"
    >
      <div aria-hidden="true" className="h-2.5 bg-linear-to-r from-church-gold-dk via-church-gold-lt to-church-gold-dk" />
      <header className="bg-linear-to-br from-church-purple-dk via-church-purple to-church-purple-dk px-8 py-8 text-center text-white">
        <p className="font-display text-2xl font-bold leading-tight">{settings.church_name}</p>
        <div aria-hidden="true" className="mx-auto my-3 h-0.5 w-20 bg-church-gold" />
        <p className="text-sm italic text-purple-100">Bienvenue parmi nous</p>
      </header>
      <div className="px-8 py-6 text-center">
        <p className="text-lg font-bold text-church-purple-dk">Scannez pour enregistrer votre visite</p>
        <p className="mt-1 text-sm text-gray-700">Ouvrez l’appareil photo de votre téléphone et visez le code.</p>
        <div className="mx-auto mt-5 w-64 max-w-full rounded-2xl border-2 border-church-purple-xl bg-white p-2">
          {matrix ? (
            <QrCode matrix={matrix} label={`QR code vers ${settings.public_url}`} />
          ) : (
            <div className="grid aspect-square place-items-center text-sm text-gray-700">Génération…</div>
          )}
        </div>
      </div>
      {settings.verse.text && (
        <blockquote className="border-t-2 border-church-purple-xl px-8 py-6 text-center">
          <p className="italic leading-relaxed text-gray-700">« {settings.verse.text} »</p>
          <footer className="mt-2 text-sm font-bold text-church-gold-dk">— {settings.verse.ref}</footer>
        </blockquote>
      )}
      <p className="px-8 pb-4 text-center text-xs text-gray-600">{settings.public_url}</p>
      <div aria-hidden="true" className="h-2.5 bg-linear-to-r from-church-gold-dk via-church-gold-lt to-church-gold-dk" />
    </article>
  )
}

export function QrCodePage() {
  const settings = useSettings()
  const toast = useToast()
  const canEdit = useCan('settings.update')
  const publicUrl = settings.data?.public_url ?? null
  const qr = useQrMatrix(publicUrl)
  const [downloading, setDownloading] = useState(false)

  // Active la feuille d'impression dédiée tant que cette page est affichée.
  useEffect(() => {
    document.documentElement.dataset.printPoster = ''
    return () => {
      delete document.documentElement.dataset.printPoster
    }
  }, [])

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
          <Poster settings={settings.data} matrix={qr.matrix} />
          <div className="flex flex-col gap-6 print:hidden">
            <Card title="Actions">
              <div className="flex flex-col gap-2">
                <Button onClick={download} pending={downloading} pendingLabel="Génération…" disabled={!qr.matrix}>
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
