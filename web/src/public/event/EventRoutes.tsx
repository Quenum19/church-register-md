// Parcours d'un culte spécial : « /e/{slug} » (identification) puis
// « /e/{slug}/visite/1 » (formulaire allégé). Les visites 2 et 3 rejoignent le parcours
// habituel (« /visite/2 », « /visite/3 »), comme les pages de fin.
// Chargé à la demande : rien de tout ceci n'est dans le bundle initial.

import { useEffect, useState } from 'react'
import { Navigate, Route, Routes, useParams } from 'react-router'
import { PageLoader } from '../../shared/PageLoader'
import { ApiError } from '../../shared/http'
import { useJourneyStore } from '../journey/context'
import { toDisplayError, type DisplayError } from '../journey/errors'
import { eventPath, guardVisit } from '../journey/flow'
import type { JourneyEvent } from '../journey/store'
import IdentifyPage from '../pages/IdentifyPage'
import NotFoundPage from '../pages/NotFoundPage'
import { getPublicEvent } from './api'
import { EventErrorPage, InactiveEventPage } from './EventNotice'
import Visit1EventPage from './Visit1EventPage'

type LoadState =
  | { status: 'loading' }
  | { status: 'ready'; event: JourneyEvent }
  | { status: 'inactive' }
  | { status: 'error'; error: DisplayError }

/** Garde d'accès au formulaire allégé, évaluée à l'arrivée (même règle que le parcours habituel). */
function EventVisit1Route({ event }: { event: JourneyEvent }) {
  const store = useJourneyStore()
  const [target] = useState<string | null>(() => guardVisit(store.getState(), 1, eventPath(event.slug)))
  if (target) return <Navigate to={target} replace />
  return <Visit1EventPage event={event} />
}

/** Vérifie le lien auprès du serveur, puis ouvre le parcours de l'événement. */
function EventGate({ slug, onRetry }: { slug: string; onRetry: () => void }) {
  const store = useJourneyStore()
  const [state, setState] = useState<LoadState>({ status: 'loading' })

  useEffect(() => {
    const controller = new AbortController()
    const { signal } = controller
    getPublicEvent(slug, signal).then(
      (found) => {
        if (signal.aborted) return
        const event: JourneyEvent = { slug: found.slug, name: found.name }
        // Mémorisé dans l'état du parcours : il survit au rafraîchissement et accompagne
        // chaque identification, y compris les ré-identifications faites pendant un envoi.
        store.setState({ event })
        setState({ status: 'ready', event })
      },
      (error: unknown) => {
        if (signal.aborted) return
        // 404 = lien inconnu ou événement inactif ; tout le reste mérite un nouvel essai.
        if (error instanceof ApiError && error.status === 404) {
          if (store.getState().event) store.setState({ event: null })
          setState({ status: 'inactive' })
          return
        }
        setState({ status: 'error', error: toDisplayError(error) })
      },
    )
    return () => controller.abort()
  }, [slug, store])

  if (state.status === 'loading') return <PageLoader />
  if (state.status === 'inactive') return <InactiveEventPage />
  if (state.status === 'error') return <EventErrorPage error={state.error} onRetry={onRetry} />

  const { event } = state
  return (
    <Routes>
      <Route index element={<IdentifyPage event={event} />} />
      <Route path="visite/1" element={<EventVisit1Route event={event} />} />
      {/* Les visites 2 et 3 n'ont pas de variante : elles vivent sur le parcours habituel. */}
      <Route path="visite/2" element={<Navigate to="/visite/2" replace />} />
      <Route path="visite/3" element={<Navigate to="/visite/3" replace />} />
      <Route path="*" element={<NotFoundPage />} />
    </Routes>
  )
}

export default function EventRoutes() {
  const { slug = '' } = useParams()
  const [attempt, setAttempt] = useState(0)
  // « Réessayer » remonte la garde : nouvel état initial, nouvelle requête, sans dépendance
  // artificielle dans l'effet de chargement.
  return <EventGate key={`${slug}#${attempt}`} slug={slug} onRetry={() => setAttempt((n) => n + 1)} />
}
