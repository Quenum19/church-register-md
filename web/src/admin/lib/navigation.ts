import type { Ability } from '../../shared/api-types'
import type { IconName } from '../components/Icon'

export interface NavItem {
  to: string
  label: string
  icon: IconName
  /** Lien affiché seulement si l'utilisateur possède cette ability. */
  ability?: Ability
  end?: boolean
}

export const NAV_ITEMS: NavItem[] = [
  { to: '/admin', label: 'Accueil', icon: 'home', end: true },
  { to: '/admin/visiteurs', label: 'Visiteurs', icon: 'users', ability: 'visitors.view' },
  { to: '/admin/membres', label: 'Membres', icon: 'member', ability: 'visitors.view' },
  { to: '/admin/rapports', label: 'Rapports', icon: 'chart', ability: 'visitors.view' },
  { to: '/admin/qrcode', label: 'QR code', icon: 'qr', ability: 'visitors.view' },
  { to: '/admin/administrateurs', label: 'Administrateurs', icon: 'shield', ability: 'users.manage' },
  { to: '/admin/journal', label: 'Journal d’audit', icon: 'list', ability: 'audit.view' },
  { to: '/admin/parametres', label: 'Paramètres', icon: 'cog' },
]
