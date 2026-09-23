import type { ReactNode } from 'react'
import logoUrl from '../../../assets/logo.webp'
import { usePageTitle } from '../../hooks/usePageTitle'

/** Mise en page des écrans hors session (connexion, mot de passe). */
export function AuthLayout({ title, subtitle, children }: { title: string; subtitle?: ReactNode; children: ReactNode }) {
  usePageTitle(title)
  return (
    <div className="flex min-h-dvh items-center justify-center bg-linear-to-br from-church-purple-dk via-church-purple to-church-purple-dk px-4 py-10">
      <main className="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl">
        <div className="h-1.5 bg-linear-to-r from-church-gold-dk via-church-gold-lt to-church-gold-dk" aria-hidden="true" />
        <div className="px-6 py-8 sm:px-8">
          <div className="mb-6 flex flex-col items-center text-center">
            {/* Décoratif : le nom de l'église est écrit juste en dessous. */}
            <img src={logoUrl} alt="" width={80} height={80} className="mb-3 size-20" />
            <p className="text-sm font-bold text-gray-700">Église La Maison de la Destinée · Administration</p>
            <h1 className="mt-2 font-display text-2xl font-bold text-church-purple-dk">{title}</h1>
            {subtitle && <div className="mt-2 text-sm text-gray-700">{subtitle}</div>}
          </div>
          {children}
        </div>
      </main>
    </div>
  )
}
