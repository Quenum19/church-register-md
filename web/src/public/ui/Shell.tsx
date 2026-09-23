import { useEffect, useRef, type ReactNode } from 'react'
import { Link, useLocation } from 'react-router'
import logoUrl from '../../assets/logo.webp'
import { CHURCH_NAME, visitLabel } from './text'
import { textLinkOnDark } from './classes'

interface ShellProps {
  /** Titre de la page : unique <h1>, repris dans le titre de l'onglet. */
  title: string
  subtitle?: ReactNode
  /** Numéro de visite affiché en badge (1re / 2e / 3e visite). */
  visit?: 1 | 2 | 3
  /** Grand logo centré (accueil, pages de fin). */
  hero?: boolean
  /** Contenu placé au-dessus du titre (ex. « Retour à l'accueil »). */
  top?: ReactNode
  /** Lien « Vos données » en pied de page. */
  privacyLink?: boolean
  children: ReactNode
}

/** Mise en page du parcours : fond violet dégradé, carte blanche, liseré or. */
export function Shell({ title, subtitle, visit, hero, top, privacyLink = true, children }: ShellProps) {
  const headingRef = useRef<HTMLHeadingElement>(null)
  const location = useLocation()
  // Premier affichage d'un onglet (pas une navigation interne) : on ne vole pas le focus.
  // Un titre déjà traité n'est pas refocalisé (effets rejoués par StrictMode).
  const handledTitle = useRef<string | null>(location.key === 'default' ? title : null)

  useEffect(() => {
    document.title = `${title} — ${CHURCH_NAME}`
  }, [title])

  useEffect(() => {
    if (handledTitle.current === title) return
    handledTitle.current = title
    // Navigation interne ou changement d'écran : on remonte en haut et on annonce le nouveau titre.
    window.scrollTo(0, 0)
    headingRef.current?.focus({ preventScroll: true })
  }, [title])

  return (
    <div className="flex min-h-dvh flex-col items-center bg-church-purple-dk bg-linear-160 from-[#3d0a5a] via-church-purple to-church-purple-dk px-4 py-6 sm:py-10">
      <main className="w-full max-w-lg">
        <div className="overflow-hidden rounded-3xl bg-white shadow-2xl shadow-black/30">
          <div aria-hidden="true" className="h-1.5 bg-linear-to-r from-church-gold via-church-gold-lt to-church-gold" />
          <div className="p-5 sm:p-8">
            {hero ? (
              <div className="flex flex-col items-center text-center">
                <img src={logoUrl} alt="" width={96} height={96} className="size-24" />
                <p className="mt-3 font-display text-lg leading-snug font-bold text-church-purple">{CHURCH_NAME}</p>
              </div>
            ) : (
              <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                  <img src={logoUrl} alt="" width={44} height={44} className="size-11" />
                  <p className="font-display text-sm leading-tight font-bold text-church-purple">
                    Église La Maison
                    <br />
                    de la Destinée
                  </p>
                </div>
                {visit && (
                  <p className="shrink-0 rounded-full border border-church-purple/40 bg-church-purple-xl px-3 py-1 text-sm font-bold text-church-purple">
                    {visitLabel(visit)}
                  </p>
                )}
              </div>
            )}
            {top && <div className="mt-4">{top}</div>}
            <div aria-hidden="true" className="my-5 h-px bg-linear-to-r from-transparent via-church-gold to-transparent" />
            <h1
              ref={headingRef}
              tabIndex={-1}
              className={
                'font-display text-2xl leading-tight font-bold text-church-purple-dk focus:outline-none sm:text-3xl' +
                (hero ? ' text-center' : '')
              }
            >
              {title}
            </h1>
            {subtitle && <div className={'mt-2 text-gray-700' + (hero ? ' text-center' : '')}>{subtitle}</div>}
            <div className="mt-6">{children}</div>
          </div>
        </div>
        {privacyLink && (
          <p className="mt-6 text-center text-sm text-white">
            <span aria-hidden="true">🔒 </span>
            Vos informations restent confidentielles.{' '}
            <Link
              to="/confidentialite"
              className={textLinkOnDark}
            >
              Vos données et vos droits
            </Link>
          </p>
        )}
      </main>
    </div>
  )
}
