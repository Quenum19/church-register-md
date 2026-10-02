// Page publique « Suivez-nous » (/reseaux) : cible du QR code posé sur les tables.
// Elle n'affiche que les réseaux renseignés dans les paramètres, et ne collecte rien.

import { useEffect, useState } from 'react'
import type { PublicConfig, SocialNetwork } from '../../shared/api-types'
import { loadPublicConfig } from '../journey/api'
import { Shell } from '../ui/Shell'
import { Alert } from '../ui/Feedback'
import { buttonSecondary, focusRing } from '../ui/classes'
import { toDisplayError } from '../journey/errors'

/** Logos officiels, tracés en SVG : aucune ressource externe (CSP stricte). */
function NetworkIcon({ network }: { network: SocialNetwork['key'] }) {
  const common = { className: 'size-7 shrink-0', viewBox: '0 0 24 24', fill: 'currentColor', 'aria-hidden': true }

  if (network === 'facebook') {
    return (
      <svg {...common}>
        <path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5.02 3.66 9.18 8.44 9.94v-7.03H7.9v-2.9h2.54V9.85c0-2.52 1.49-3.91 3.77-3.91 1.1 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.78-1.63 1.57v1.89h2.78l-.45 2.9h-2.33V22c4.78-.76 8.44-4.92 8.44-9.94Z" />
      </svg>
    )
  }

  if (network === 'youtube') {
    return (
      <svg {...common}>
        <path d="M23.5 6.5a3.02 3.02 0 0 0-2.12-2.14C19.5 3.85 12 3.85 12 3.85s-7.5 0-9.38.51A3.02 3.02 0 0 0 .5 6.5C0 8.39 0 12.33 0 12.33s0 3.94.5 5.83a3.02 3.02 0 0 0 2.12 2.14c1.88.51 9.38.51 9.38.51s7.5 0 9.38-.51a3.02 3.02 0 0 0 2.12-2.14c.5-1.89.5-5.83.5-5.83s0-3.94-.5-5.83ZM9.55 15.93V8.73l6.27 3.6-6.27 3.6Z" />
      </svg>
    )
  }

  if (network === 'instagram') {
    return (
      <svg {...common}>
        <path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.8 3.8 0 0 1-1.38-.9 3.8 3.8 0 0 1-.9-1.38c-.16-.42-.36-1.06-.41-2.23C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16Zm0 3.18a6.66 6.66 0 1 0 0 13.32 6.66 6.66 0 0 0 0-13.32Zm0 10.99a4.33 4.33 0 1 1 0-8.66 4.33 4.33 0 0 1 0 8.66Zm8.48-11.25a1.56 1.56 0 1 1-3.11 0 1.56 1.56 0 0 1 3.11 0Z" />
      </svg>
    )
  }

  return (
    <svg {...common}>
      <path d="M16.6 5.82A4.28 4.28 0 0 1 15.54 3h-3.09v12.4a2.59 2.59 0 1 1-1.79-2.46V9.8a5.95 5.95 0 1 0 5.14 5.9V9.01a7.35 7.35 0 0 0 4.2 1.32V7.25a4.3 4.3 0 0 1-3.4-1.43Z" />
    </svg>
  )
}

export default function SocialPage() {
  const [config, setConfig] = useState<PublicConfig | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let active = true
    loadPublicConfig().then(
      (value) => active && setConfig(value),
      (cause: unknown) => active && setError(toDisplayError(cause).message),
    )
    return () => {
      active = false
    }
  }, [])

  const networks = config?.social_networks ?? []

  return (
    <Shell title="Suivez-nous" subtitle="Retrouvez l'Église sur ses réseaux." hero privacyLink={false}>
      {error !== null && <Alert tone="error">{error}</Alert>}

      {config !== null && networks.length === 0 && (
        <p className="text-center text-gray-700">
          Les réseaux de l'Église seront bientôt disponibles ici. Merci de revenir plus tard.
        </p>
      )}

      <ul className="flex flex-col gap-3">
        {networks.map((network) => (
          <li key={network.key}>
            <a
              href={network.url}
              target="_blank"
              rel="noopener noreferrer"
              className={`${buttonSecondary} justify-start gap-4 px-5 text-left`}
            >
              <NetworkIcon network={network.key} />
              <span className="flex-1">
                {network.label}
                <span className="sr-only"> (nouvel onglet)</span>
              </span>
              <svg className="size-5 shrink-0 text-church-purple" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path
                  d="M9 6l6 6-6 6"
                  stroke="currentColor"
                  strokeWidth="2.2"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                />
              </svg>
            </a>
          </li>
        ))}
      </ul>

      <p className="mt-6 text-center text-sm text-gray-700">
        Vous venez pour la première fois ?{' '}
        <a href="/" className={`font-bold text-church-purple underline underline-offset-2 ${focusRing}`}>
          Enregistrez votre visite
        </a>
      </p>
    </Shell>
  )
}
