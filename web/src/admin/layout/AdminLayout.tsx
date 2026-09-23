import { useEffect, useRef, useState } from 'react'
import { Outlet, useLocation } from 'react-router'
import { Icon } from '../components/Icon'
import { capitalize, formatLongDay } from '../lib/format'
import { MobileDrawer } from './MobileDrawer'
import { SidebarContent } from './Sidebar'
import { UserMenu } from './UserMenu'

const DRAWER_ID = 'admin-mobile-nav'

export function AdminLayout() {
  const [drawerOpen, setDrawerOpen] = useState(false)
  const location = useLocation()
  const mainRef = useRef<HTMLElement>(null)
  const previousPath = useRef<string | null>(null)

  // À chaque changement de page, le focus va sur le titre (annoncé par les lecteurs d'écran).
  useEffect(() => {
    const previous = previousPath.current
    previousPath.current = location.pathname
    if (previous === null || previous === location.pathname) return
    const heading = mainRef.current?.querySelector<HTMLElement>('h1')
    ;(heading ?? mainRef.current)?.focus()
  }, [location.pathname])

  // Passage en grand écran pendant que le tiroir est ouvert : on le ferme (sinon la page resterait inerte).
  useEffect(() => {
    if (!drawerOpen || typeof window.matchMedia !== 'function') return
    const query = window.matchMedia('(min-width: 64rem)')
    const onChange = () => {
      if (query.matches) setDrawerOpen(false)
    }
    query.addEventListener('change', onChange)
    return () => query.removeEventListener('change', onChange)
  }, [drawerOpen])

  return (
    <div className="min-h-dvh bg-gray-50 lg:flex">
      <a
        href="#contenu"
        onClick={(event) => {
          event.preventDefault()
          mainRef.current?.focus()
        }}
        className="sr-only z-50 rounded-lg bg-church-gold px-4 py-2 font-bold text-church-purple-dk focus:not-sr-only focus:fixed focus:left-4 focus:top-4"
      >
        Aller au contenu
      </a>

      <aside className="sticky top-0 hidden h-dvh w-64 shrink-0 lg:block print:hidden">
        <SidebarContent />
      </aside>

      {drawerOpen && (
        <MobileDrawer id={DRAWER_ID} onClose={() => setDrawerOpen(false)}>
          <SidebarContent onNavigate={() => setDrawerOpen(false)} />
        </MobileDrawer>
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-30 flex min-h-16 items-center gap-3 border-b border-gray-200 bg-white px-4 sm:px-6 print:hidden">
          <button
            type="button"
            onClick={() => setDrawerOpen(true)}
            aria-expanded={drawerOpen}
            aria-controls={drawerOpen ? DRAWER_ID : undefined}
            aria-label="Ouvrir le menu de navigation"
            className="inline-flex size-11 items-center justify-center rounded-lg border border-gray-300 text-church-purple-dk hover:bg-church-purple-xl focus-visible:outline-2 focus-visible:outline-church-purple lg:hidden"
          >
            <Icon name="menu" />
          </button>
          <p className="font-display text-base font-bold text-church-purple-dk lg:hidden">Maison de la Destinée</p>
          <p className="hidden text-sm text-gray-700 lg:block">{capitalize(formatLongDay())}</p>
          <div className="ml-auto">
            <UserMenu />
          </div>
        </header>

        <main id="contenu" ref={mainRef} tabIndex={-1} className="flex-1 px-4 py-6 outline-none sm:px-6 lg:px-8 print:p-0">
          <div className="mx-auto max-w-7xl">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  )
}
