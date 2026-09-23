// Envoi et erreurs des formulaires de visite (hors du bundle initial).

import { useEffect, useRef, type ReactNode } from 'react'
import { buttonPrimary } from '../ui/classes'
import { Spinner } from '../ui/Feedback'

interface SubmitButtonProps {
  busy: boolean
  busyLabel?: string
  children: ReactNode
  disabled?: boolean
}

/** Bouton d'envoi : désactivé et accompagné d'un indicateur pendant la requête. */
export function SubmitButton({ busy, busyLabel = 'Envoi en cours…', children, disabled }: SubmitButtonProps) {
  return (
    <button type="submit" className={buttonPrimary} disabled={busy || disabled}>
      {busy && <Spinner />}
      {busy ? busyLabel : children}
    </button>
  )
}

export interface SummaryItem {
  /** id de l'élément à focaliser (champ ou première option d'un groupe). */
  target: string
  message: string
}

function focusField(id: string) {
  const el = document.getElementById(id)
  if (!el) return
  el.focus()
  el.scrollIntoView?.({ block: 'center' })
}

interface ErrorSummaryProps {
  items: SummaryItem[]
  /** Incrémenté à chaque envoi refusé : le résumé reçoit alors le focus. */
  focusKey: number
}

/** Résumé des erreurs, focalisé à l'envoi ; chaque lien place le focus sur le champ. */
export function ErrorSummary({ items, focusKey }: ErrorSummaryProps) {
  const ref = useRef<HTMLElement>(null)
  const focusedKey = useRef(0)
  const visible = items.length > 0
  // Au 1er envoi refusé, react-hook-form publie les erreurs APRÈS l'appel qui incrémente
  // focusKey : dans un vrai navigateur (clic ou Entrée), les deux mises à jour peuvent être
  // rendues séparément, et le résumé n'existe pas encore quand focusKey change. Le focus
  // demandé reste donc en attente jusqu'à l'affichage du résumé, une seule fois par envoi.
  useEffect(() => {
    if (focusKey > focusedKey.current && visible) {
      focusedKey.current = focusKey
      ref.current?.focus()
    }
  }, [focusKey, visible])

  if (!visible) return null
  return (
    <section
      ref={ref}
      tabIndex={-1}
      aria-labelledby="error-summary-title"
      className="rounded-xl border-2 border-red-700 bg-red-50 p-4 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"
    >
      <h2 id="error-summary-title" className="font-bold text-red-900">
        {items.length === 1 ? 'Un point est à corriger :' : `${items.length} points sont à corriger :`}
      </h2>
      <ul className="mt-2 list-disc space-y-1 pl-5 text-red-900">
        {items.map((item) => (
          <li key={item.target}>
            <a
              href={`#${item.target}`}
              className="font-bold underline underline-offset-2"
              onClick={(event) => {
                event.preventDefault()
                focusField(item.target)
              }}
            >
              {item.message}
            </a>
          </li>
        ))}
      </ul>
    </section>
  )
}
