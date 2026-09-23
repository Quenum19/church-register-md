import { Component, type ErrorInfo, type ReactNode } from 'react'
import { buttonPrimary } from './classes'

interface Props {
  /** Change à chaque navigation : l'erreur est oubliée en changeant de page. */
  resetKey: string
  children: ReactNode
}

interface State {
  failed: boolean
  resetKey: string
}

/**
 * Filet de sécurité pour les pages chargées à la demande (réseau instable de l'église,
 * nouvelle version déployée pendant la visite…) : on propose de recharger.
 */
export class LoadErrorBoundary extends Component<Props, State> {
  state: State = { failed: false, resetKey: this.props.resetKey }

  static getDerivedStateFromError(): Partial<State> {
    return { failed: true }
  }

  static getDerivedStateFromProps(props: Props, state: State): Partial<State> | null {
    return props.resetKey === state.resetKey ? null : { failed: false, resetKey: props.resetKey }
  }

  componentDidCatch(error: unknown, info: ErrorInfo) {
    console.error('Erreur d’affichage du parcours visiteur', error, info.componentStack)
  }

  render() {
    if (!this.state.failed) return this.props.children
    return (
      <div className="flex min-h-dvh items-center justify-center bg-church-purple-dk px-4">
        <main className="w-full max-w-md rounded-3xl bg-white p-6 text-center shadow-2xl">
          <h1 className="font-display text-2xl font-bold text-church-purple-dk">Page indisponible</h1>
          <p role="alert" className="mt-3 text-gray-800">
            Le chargement a échoué. Vérifiez votre connexion internet, puis réessayez.
          </p>
          <button type="button" className={`${buttonPrimary} mt-6`} onClick={() => window.location.reload()}>
            Réessayer
          </button>
        </main>
      </div>
    )
  }
}
