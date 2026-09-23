import { Link } from 'react-router'
import { buttonPrimary } from '../ui/classes'
import { Shell } from '../ui/Shell'

export default function NotFoundPage() {
  return (
    <Shell hero title="Page introuvable">
      <div className="flex flex-col gap-6 text-center text-gray-900">
        <p>La page demandée n'existe pas ou a été déplacée.</p>
        <Link to="/" className={buttonPrimary}>
          Aller à l'accueil
        </Link>
      </div>
    </Shell>
  )
}
