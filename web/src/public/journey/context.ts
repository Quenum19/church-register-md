import { createContext, useContext } from 'react'
import type { JourneyStore } from './store'

export const JourneyContext = createContext<JourneyStore | null>(null)

export function useJourneyStore(): JourneyStore {
  const store = useContext(JourneyContext)
  if (!store) throw new Error('useJourneyStore doit être utilisé sous <PublicApp>.')
  return store
}
