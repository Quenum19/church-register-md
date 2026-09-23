import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router'
import { useJourneyStore } from '../journey/context'
import { buttonPrimary, buttonSecondary, focusRing } from './classes'

const linkButton =
  '-mx-2 inline-flex min-h-11 items-center gap-2 rounded-lg px-2 font-bold text-church-purple underline-offset-4 hover:underline ' +
  `disabled:cursor-not-allowed disabled:opacity-60 ${focusRing}`

interface BackHomeProps {
  /** Désactivé pendant un envoi. */
  disabled?: boolean
  /** Vrai si une saisie est en cours : on demande alors confirmation. */
  hasDraft: () => boolean
}

/** « Retour à l'accueil » : abandonne le parcours, avec confirmation si un brouillon existe. */
export function BackHome({ disabled = false, hasDraft }: BackHomeProps) {
  const store = useJourneyStore()
  const navigate = useNavigate()
  const [confirming, setConfirming] = useState(false)
  const buttonRef = useRef<HTMLButtonElement>(null)
  const titleRef = useRef<HTMLParagraphElement>(null)
  const restoreFocus = useRef(false)

  useEffect(() => {
    if (confirming) {
      titleRef.current?.focus()
    } else if (restoreFocus.current) {
      restoreFocus.current = false
      buttonRef.current?.focus()
    }
  }, [confirming])

  const leave = () => {
    store.reset()
    navigate('/')
  }

  if (confirming) {
    return (
      <div role="group" aria-labelledby="leave-title" className="rounded-xl border-2 border-church-gold-dk bg-church-gold-pale p-4">
        <p id="leave-title" ref={titleRef} tabIndex={-1} className="font-bold text-gray-900 focus:outline-none">
          Revenir à l'accueil ?
        </p>
        <p className="mt-1 text-gray-800">Les réponses que vous avez saisies seront effacées.</p>
        <div className="mt-3 grid gap-2 sm:grid-cols-2">
          <button
            type="button"
            className={buttonSecondary}
            onClick={() => {
              restoreFocus.current = true
              setConfirming(false)
            }}
          >
            Continuer ma saisie
          </button>
          <button type="button" className={buttonPrimary.replace('text-lg', 'text-base')} disabled={disabled} onClick={leave}>
            Oui, revenir à l'accueil
          </button>
        </div>
      </div>
    )
  }

  return (
    <button
      ref={buttonRef}
      type="button"
      className={linkButton}
      disabled={disabled}
      onClick={() => (hasDraft() ? setConfirming(true) : leave())}
    >
      <span aria-hidden="true">←</span> Retour à l'accueil
    </button>
  )
}
