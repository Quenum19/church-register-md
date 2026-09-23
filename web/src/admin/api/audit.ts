import { keepPreviousData, useQuery } from '@tanstack/react-query'
import type { AuditLog, Paginated } from '../../shared/api-types'
import { adminFetch } from './client'
import { queryKeys, type AuditFilters } from './keys'

export function useAuditLogs(filters: AuditFilters) {
  return useQuery({
    queryKey: queryKeys.audit(filters),
    queryFn: ({ signal }) =>
      adminFetch<Paginated<AuditLog>>('/api/admin/audit-logs', {
        query: { action: filters.action, user_id: filters.user_id, page: filters.page ?? 1 },
        signal,
      }),
    placeholderData: keepPreviousData,
  })
}
