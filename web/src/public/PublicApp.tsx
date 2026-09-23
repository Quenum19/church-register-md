// Parcours visiteur public (monté sous « /* » par main.tsx).
// Bundle initial : accueil/identification et état du parcours.
// Chargés à la demande : formulaires et pages de fin (préchargés depuis l'accueil),
// /qrcode, /confidentialite, 404.

import { lazy, Suspense, useState, type ComponentType } from 'react'
import { Navigate, Route, Routes, useLocation } from 'react-router'
import { PageLoader } from '../shared/PageLoader'
import { JourneyContext, useJourneyStore } from './journey/context'
import { createJourneyStore, type VisitStep } from './journey/store'
import IdentifyPage from './pages/IdentifyPage'
import { LoadErrorBoundary } from './ui/LoadErrorBoundary'
import { loadEndPages, loadVisit1, loadVisit2, loadVisit3 } from './visit/preload'

/** Un nouvel essai après 1,5 s : absorbe les coupures brèves du Wi-Fi. */
function lazyWithRetry<T extends ComponentType>(load: () => Promise<{ default: T }>) {
  return lazy(() =>
    load().catch(
      () =>
        new Promise<{ default: T }>((resolve, reject) => {
          window.setTimeout(() => load().then(resolve, reject), 1500)
        }),
    ),
  )
}

const VISIT_PAGES: Record<VisitStep, ComponentType> = {
  1: lazyWithRetry(loadVisit1),
  2: lazyWithRetry(loadVisit2),
  3: lazyWithRetry(loadVisit3),
}
const ThanksPage = lazyWithRetry(() => loadEndPages().then((m) => ({ default: m.ThanksPage })))
const CompletePage = lazyWithRetry(() => loadEndPages().then((m) => ({ default: m.CompletePage })))
const AlreadyTodayPage = lazyWithRetry(() => loadEndPages().then((m) => ({ default: m.AlreadyTodayPage })))
const PrivacyPage = lazyWithRetry(() => import('./pages/PrivacyPage'))
const QrCodePage = lazyWithRetry(() => import('./qrcode/QrCodePage'))
const NotFoundPage = lazyWithRetry(() => import('./pages/NotFoundPage'))

/**
 * Garde d'accès aux formulaires, évaluée à l'arrivée sur la page : sans parcours en cours
 * → accueil ; parcours à une autre étape → bonne étape. Ensuite, la page gère elle-même
 * ses redirections (succès, étape changée…), sans course avec la mise à jour de l'état.
 */
function VisitRoute({ step }: { step: VisitStep }) {
  const store = useJourneyStore()
  const [target] = useState<string | null>(() => {
    const { identified, step: current } = store.getState()
    if (!identified || current === null) return '/'
    return current === step ? null : `/visite/${current}`
  })
  if (target) return <Navigate to={target} replace />
  const Page = VISIT_PAGES[step]
  return <Page />
}

export default function PublicApp() {
  const [store] = useState(() => createJourneyStore())
  const location = useLocation()
  return (
    <JourneyContext value={store}>
      <LoadErrorBoundary resetKey={location.pathname}>
        <Suspense fallback={<PageLoader />}>
          <Routes>
            <Route index element={<IdentifyPage />} />
            <Route path="visite/1" element={<VisitRoute key={1} step={1} />} />
            <Route path="visite/2" element={<VisitRoute key={2} step={2} />} />
            <Route path="visite/3" element={<VisitRoute key={3} step={3} />} />
            <Route path="merci" element={<ThanksPage />} />
            <Route path="parcours-complet" element={<CompletePage />} />
            <Route path="deja-enregistre" element={<AlreadyTodayPage />} />
            <Route path="confidentialite" element={<PrivacyPage />} />
            <Route path="qrcode" element={<QrCodePage />} />
            <Route path="*" element={<NotFoundPage />} />
          </Routes>
        </Suspense>
      </LoadErrorBoundary>
    </JourneyContext>
  )
}
