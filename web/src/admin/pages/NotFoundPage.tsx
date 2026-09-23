import { Link } from 'react-router'
import { PageHeader } from '../components/PageHeader'
import { buttonClass } from '../components/styles'

export function NotFoundPage({ message }: { message?: string }) {
  return (
    <div className="max-w-xl">
      <PageHeader title="Page introuvable" />
      <p className="text-gray-800">
        {message ?? 'Cette page n’existe pas ou a été déplacée. Vérifiez l’adresse ou revenez à l’accueil du tableau de bord.'}
      </p>
      <Link to="/admin" className={buttonClass('primary', 'md', 'mt-6')}>
        Retour à l’accueil
      </Link>
    </div>
  )
}
