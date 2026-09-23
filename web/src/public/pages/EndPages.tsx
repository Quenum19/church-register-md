import { useState, type ReactNode } from 'react'
import { Navigate, useNavigate } from 'react-router'
import { useJourneyStore } from '../journey/context'
import { buttonPrimary } from '../ui/classes'
import { Shell } from '../ui/Shell'
import { ordinal } from '../ui/text'

interface EndLayoutProps {
  title: string
  icon: string
  buttonLabel: string
  children: ReactNode
}

function EndLayout({ title, icon, buttonLabel, children }: EndLayoutProps) {
  const store = useJourneyStore()
  const navigate = useNavigate()
  const finish = () => {
    store.reset()
    navigate('/', { replace: true })
  }
  return (
    <Shell hero title={title}>
      <div className="flex flex-col items-center gap-4 text-center text-gray-900">
        <p aria-hidden="true" className="text-5xl leading-none">
          {icon}
        </p>
        {children}
        <button type="button" className={`${buttonPrimary} mt-2`} onClick={finish}>
          {buttonLabel}
        </button>
      </div>
    </Shell>
  )
}

const THANKS = {
  1: {
    icon: '🙏',
    title: 'Bienvenue parmi nous !',
    text: 'Nous espérons vous revoir dimanche prochain.',
  },
  2: {
    icon: '💛',
    title: 'Ravis de vous revoir !',
    text: 'Vous faites déjà partie de notre communauté.',
  },
  3: {
    icon: '⭐',
    title: 'Vous êtes des nôtres !',
    text: "Rapprochez-vous d'un responsable ou du Pasteur pour découvrir la suite de votre parcours dans l'Église.",
  },
} as const

/** /merci — résultat lu une seule fois dans l'état local ; « Terminer » efface tout. */
export function ThanksPage() {
  const store = useJourneyStore()
  const [result] = useState(() => store.getState().result)
  if (!result) return <Navigate to="/" replace />

  const message = THANKS[result.visitNumber]
  // Le nom affiché est uniquement celui saisi dans ce navigateur à la 1re visite.
  const title = result.visitNumber === 1 && result.name ? `Bienvenue parmi nous, ${result.name} !` : message.title
  return (
    <EndLayout title={title} icon={message.icon} buttonLabel="Terminer">
      <p className="rounded-full border border-church-purple/40 bg-church-purple-xl px-4 py-1 font-bold text-church-purple">
        Votre {ordinal(result.visitNumber)} visite est enregistrée
      </p>
      <p className="text-lg">{message.text}</p>
      {result.family && (
        <p className="w-full rounded-xl border-2 border-church-gold bg-church-gold-pale px-4 py-3">
          Aujourd'hui, vous avez été accueilli(e) par la <strong className="text-church-purple-dk">famille {result.family.name}</strong>.
        </p>
      )}
    </EndLayout>
  )
}

/** /parcours-complet — les trois visites de découverte sont déjà faites. */
export function CompletePage() {
  return (
    <EndLayout title="Merci pour votre fidélité !" icon="🤝" buttonLabel="Retour à l'accueil">
      <p className="text-lg">Vous avez déjà effectué vos trois visites de découverte.</p>
      <p className="w-full rounded-xl border-2 border-church-gold bg-church-gold-pale px-4 py-3 font-bold text-church-purple-dk">
        Parlez à un responsable ou au Pasteur : il vous présentera la suite de votre parcours dans l'Église.
      </p>
    </EndLayout>
  )
}

/** /deja-enregistre — une visite existe déjà aujourd'hui pour ce numéro. */
export function AlreadyTodayPage() {
  return (
    <EndLayout title="Votre visite est déjà enregistrée" icon="✅" buttonLabel="Retour à l'accueil">
      <p className="text-lg">Une visite a déjà été enregistrée aujourd'hui avec ce numéro.</p>
      <p>Merci, et à très bientôt !</p>
    </EndLayout>
  )
}
