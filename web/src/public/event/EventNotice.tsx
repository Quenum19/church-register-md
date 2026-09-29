// Écrans d'erreur propres aux liens de culte spécial.

import { Link } from 'react-router'
import type { DisplayError } from '../journey/errors'
import { buttonPrimary, buttonSecondary } from '../ui/classes'
import { Alert } from '../ui/Feedback'
import { Shell } from '../ui/Shell'

/** Slug inconnu ou événement désactivé : le formulaire habituel reste ouvert. */
export function InactiveEventPage() {
  return (
    <Shell hero title="Ce lien n'est plus actif">
      <div className="flex flex-col gap-5 text-center text-gray-900">
        <p aria-hidden="true" className="text-5xl leading-none">
          🕊️
        </p>
        <p className="text-lg">Le culte spécial auquel ce lien correspond n'accepte plus d'enregistrement.</p>
        <p>Vous pouvez enregistrer votre visite avec le formulaire habituel.</p>
        <Link to="/" className={`${buttonPrimary} mt-1`}>
          Enregistrer ma visite
        </Link>
      </div>
    </Shell>
  )
}

/** Panne réseau ou serveur : on ne déclare pas le lien fermé, on propose de réessayer. */
export function EventErrorPage({ error, onRetry }: { error: DisplayError; onRetry: () => void }) {
  return (
    <Shell hero title="Ce lien n'a pas pu être ouvert">
      <div className="flex flex-col gap-5 text-gray-900">
        <Alert
          title="Le culte spécial n'a pas pu être chargé."
          action={
            error.retryable && (
              <button type="button" className={buttonSecondary} onClick={onRetry}>
                Réessayer
              </button>
            )
          }
        >
          {error.message}
        </Alert>
        <Link to="/" className={buttonPrimary}>
          Utiliser le formulaire habituel
        </Link>
      </div>
    </Shell>
  )
}
