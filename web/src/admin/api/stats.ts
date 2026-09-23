import { useQuery } from '@tanstack/react-query'
import type { Stats } from '../../shared/api-types'
import { adminFetch } from './client'
import { queryKeys } from './keys'

/** Statistiques du tableau de bord (mises en cache 5 min côté serveur). */
export function useStats(enabled = true) {
  return useQuery({
    queryKey: queryKeys.stats,
    queryFn: ({ signal }) => adminFetch<Stats>('/api/admin/stats', { signal }),
    staleTime: 5 * 60_000,
    enabled,
  })
}
