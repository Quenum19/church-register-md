import { Link } from 'react-router'
import { PageHeader } from '../components/PageHeader'
import { buttonClass } from '../components/styles'

export function ForbiddenPage() {
  return (
    <div className="max-w-xl">
      <PageHeader title="Accès refusé" />
      <p className="text-gray-800">
        Votre rôle ne vous permet pas d’accéder à cette page. Si vous pensez qu’il s’agit d’une erreur, contactez un
        super administrateur.
      </p>
      <Link to="/admin" className={buttonClass('primary', 'md', 'mt-6')}>
        Retour à l’accueil
      </Link>
    </div>
  )
}
