import type { ReactNode } from 'react'
import { useSearchParams } from 'react-router'
import type { Ability } from '../../../shared/api-types'
import { useAuth } from '../../auth/context'
import { PageHeader } from '../../components/PageHeader'
import { TabPanel, Tabs, type TabItem } from '../../components/Tabs'
import { ChurchTab } from './ChurchTab'
import { PasswordTab } from './PasswordTab'
import { ProfileTab } from './ProfileTab'
import { RecipientsTab } from './RecipientsTab'
import { RotationsTab } from './RotationsTab'
import { TwoFactorTab } from './TwoFactorTab'

interface SettingsTab extends TabItem {
  ability?: Ability
  render: () => ReactNode
}

const TABS: SettingsTab[] = [
  { id: 'profil', label: 'Profil', render: () => <ProfileTab /> },
  { id: 'mot-de-passe', label: 'Mot de passe', render: () => <PasswordTab /> },
  { id: 'double-authentification', label: 'Double authentification', render: () => <TwoFactorTab /> },
  { id: 'rotations', label: 'Rotation des familles', ability: 'visitors.view', render: () => <RotationsTab /> },
  { id: 'destinataires', label: 'Destinataires des rapports', ability: 'recipients.manage', render: () => <RecipientsTab /> },
  { id: 'eglise', label: 'Église', ability: 'visitors.view', render: () => <ChurchTab /> },
]

export function SettingsPage() {
  const { abilities } = useAuth()
  const [params, setParams] = useSearchParams()
  const tabs = TABS.filter((t) => !t.ability || abilities.has(t.ability))
  const selected = tabs.find((t) => t.id === params.get('onglet')) ?? tabs[0]

  return (
    <>
      <PageHeader title="Paramètres" documentTitle={selected ? `${selected.label} · Paramètres` : 'Paramètres'} />
      <Tabs
        tabs={tabs}
        selected={selected?.id ?? ''}
        idPrefix="parametres"
        label="Sections des paramètres"
        onSelect={(id) => setParams(id === tabs[0]?.id ? {} : { onglet: id }, { replace: true })}
      />
      {selected && (
        <TabPanel idPrefix="parametres" id={selected.id}>
          {selected.render()}
        </TabPanel>
      )}
    </>
  )
}
