import { clsx } from 'clsx'
import { NavLink } from 'react-router'
import logoUrl from '../../assets/logo.webp'
import { useStats } from '../api/stats'
import { useAuth, useCan } from '../auth/context'
import { Icon } from '../components/Icon'
import { NAV_ITEMS } from '../lib/navigation'

function FamilyOfMonth() {
  const canView = useCan('visitors.view')
  const { data, isPending, isError } = useStats(canView)
  if (!canView) return null
  let value = '…'
  if (isError) value = 'Indisponible'
  else if (!isPending) value = data?.current_family?.name ?? 'Non définie'
  return (
    <div className="mx-3 mt-4 rounded-xl border border-white/15 bg-white/10 px-3 py-2.5">
      <p className="text-xs font-bold uppercase tracking-wide text-purple-200">Famille du mois</p>
      <p className="mt-0.5 text-base font-bold text-white">{value}</p>
    </div>
  )
}

/** Contenu de la barre latérale (fixe sur grand écran, tiroir sur mobile). */
export function SidebarContent({ onNavigate }: { onNavigate?: () => void }) {
  const { abilities } = useAuth()
  const items = NAV_ITEMS.filter((item) => !item.ability || abilities.has(item.ability))
  return (
    <div className="flex h-full flex-col bg-church-purple-dk text-white">
      <div className="flex items-center gap-3 border-b border-white/10 px-4 py-5">
        {/* Décoratif : le nom de l'église est affiché à côté. */}
        <img src={logoUrl} alt="" width={48} height={48} className="size-12 shrink-0" />
        <div className="min-w-0">
          <p className="font-display text-sm font-bold leading-tight">Église La Maison de la Destinée</p>
          <p className="text-xs text-purple-200">Administration</p>
        </div>
      </div>

      <FamilyOfMonth />

      <nav aria-label="Navigation principale" className="flex-1 overflow-y-auto px-3 py-4">
        <ul className="flex flex-col gap-1">
          {items.map((item) => (
            <li key={item.to}>
              <NavLink
                to={item.to}
                end={item.end}
                onClick={onNavigate}
                className={({ isActive }) =>
                  clsx(
                    'flex min-h-11 items-center gap-3 rounded-xl px-3 py-2 text-sm font-bold transition-colors',
                    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-church-gold-lt',
                    isActive ? 'bg-white text-church-purple-dk shadow-sm' : 'text-purple-100 hover:bg-white/10 hover:text-white',
                  )
                }
              >
                <Icon name={item.icon} />
                {item.label}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>

      <div className="border-t border-white/10 p-3">
        <a
          href="/"
          target="_blank"
          rel="noopener noreferrer"
          className="flex min-h-11 items-center gap-3 rounded-xl px-3 py-2 text-sm font-bold text-church-gold-lt hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-church-gold-lt"
        >
          <Icon name="external" />
          Formulaire visiteur
          {' '}<span className="sr-only">(nouvel onglet)</span>
        </a>
      </div>
    </div>
  )
}
