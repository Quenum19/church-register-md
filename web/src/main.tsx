import { StrictMode, Suspense, lazy } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter, Route, Routes } from 'react-router'
// Sous-ensemble latin uniquement : couvre tout le français (é, è, à, ç, œ, « »).
import '@fontsource/lato/latin-400.css'
import '@fontsource/lato/latin-700.css'
import '@fontsource/playfair-display/latin-700.css'
import './styles.css'
import PublicApp from './public/PublicApp'
import { PageLoader } from './shared/PageLoader'

// Le dashboard n'est téléchargé que sur /admin : le parcours visiteur reste léger.
const AdminApp = lazy(() => import('./admin/AdminApp'))

// Après un déploiement, les anciens chunks n'existent plus : un chargement à la
// demande qui échoue recharge la page (au plus une fois par minute, pour éviter une boucle).
window.addEventListener('vite:preloadError', (event) => {
  const key = 'cr.preload-reload'
  try {
    const last = Number(sessionStorage.getItem(key) ?? 0)
    if (Date.now() - last < 60_000) return
    sessionStorage.setItem(key, String(Date.now()))
  } catch {
    return
  }
  event.preventDefault()
  window.location.reload()
})

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter>
      <Routes>
        <Route
          path="/admin/*"
          element={
            <Suspense fallback={<PageLoader />}>
              <AdminApp />
            </Suspense>
          }
        />
        <Route path="/*" element={<PublicApp />} />
      </Routes>
    </BrowserRouter>
  </StrictMode>,
)
