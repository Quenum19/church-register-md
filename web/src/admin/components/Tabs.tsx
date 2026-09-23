import { clsx } from 'clsx'
import { useRef, type KeyboardEvent, type ReactNode } from 'react'

export interface TabItem {
  id: string
  label: string
}

function tabId(prefix: string, id: string) {
  return `${prefix}-tab-${id}`
}

function panelId(prefix: string, id: string) {
  return `${prefix}-panel-${id}`
}

interface TabsProps {
  tabs: TabItem[]
  selected: string
  onSelect: (id: string) => void
  idPrefix: string
  label: string
}

/** Onglets ARIA (tablist) : flèches, Début/Fin, tabindex itinérant, activation automatique. */
export function Tabs({ tabs, selected, onSelect, idPrefix, label }: TabsProps) {
  const refs = useRef(new Map<string, HTMLButtonElement>())

  const onKeyDown = (event: KeyboardEvent<HTMLButtonElement>) => {
    const index = tabs.findIndex((t) => t.id === selected)
    let next: number | null = null
    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') next = (index + 1) % tabs.length
    else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') next = (index - 1 + tabs.length) % tabs.length
    else if (event.key === 'Home') next = 0
    else if (event.key === 'End') next = tabs.length - 1
    if (next === null) return
    event.preventDefault()
    const target = tabs[next]
    if (!target) return
    onSelect(target.id)
    refs.current.get(target.id)?.focus()
  }

  return (
    <div role="tablist" aria-label={label} className="-mx-1 flex gap-1 overflow-x-auto border-b border-gray-300 px-1">
      {tabs.map((tab) => {
        const active = tab.id === selected
        return (
          <button
            key={tab.id}
            ref={(el) => {
              if (el) refs.current.set(tab.id, el)
              else refs.current.delete(tab.id)
            }}
            type="button"
            role="tab"
            id={tabId(idPrefix, tab.id)}
            aria-selected={active}
            aria-controls={panelId(idPrefix, tab.id)}
            tabIndex={active ? 0 : -1}
            onClick={() => onSelect(tab.id)}
            onKeyDown={onKeyDown}
            className={clsx(
              '-mb-px min-h-11 shrink-0 whitespace-nowrap rounded-t-lg border-b-4 px-4 text-sm font-bold transition-colors',
              'focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-church-purple',
              active
                ? 'border-church-purple text-church-purple-dk'
                : 'border-transparent text-gray-700 hover:border-gray-300 hover:text-gray-900',
            )}
          >
            {tab.label}
          </button>
        )
      })}
    </div>
  )
}

export function TabPanel({ idPrefix, id, children }: { idPrefix: string; id: string; children: ReactNode }) {
  return (
    <div role="tabpanel" id={panelId(idPrefix, id)} aria-labelledby={tabId(idPrefix, id)} tabIndex={0} className="pt-6 outline-none">
      {children}
    </div>
  )
}
