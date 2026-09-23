import { Button } from '../../components/Button'
import { AuthLayout } from './AuthLayout'

export function ServerUnavailablePage({ onRetry }: { onRetry: () => void }) {
  return (
    <AuthLayout title="Serveur injoignable">
      <div role="alert" className="flex flex-col gap-4 text-center text-gray-800">
        <p>Impossible de vérifier votre session. Vérifiez votre connexion internet puis réessayez.</p>
        <Button onClick={onRetry}>Réessayer</Button>
      </div>
    </AuthLayout>
  )
}
