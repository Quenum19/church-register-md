import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { ReportDetail, ReportDispatch, ReportList } from '../../shared/api-types'
import { adminFetch } from './client'
import { queryKeys } from './keys'

export function useReports(year: number) {
  return useQuery({
    queryKey: queryKeys.reports.list(year),
    queryFn: ({ signal }) => adminFetch<ReportList>('/api/admin/reports', { query: { year }, signal }),
  })
}

export function useReport(year: number, month: number, enabled = true) {
  return useQuery({
    queryKey: queryKeys.reports.detail(year, month),
    queryFn: async ({ signal }) =>
      (await adminFetch<{ data: ReportDetail }>(`/api/admin/reports/${year}/${month}`, { signal })).data,
    enabled,
  })
}

export function useSendReport(year: number, month: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (force: boolean) =>
      adminFetch<{ data: ReportDispatch }>(`/api/admin/reports/${year}/${month}/send`, {
        method: 'POST',
        body: force ? { force: true } : {},
      }),
    onSuccess: ({ data }) => {
      queryClient.setQueryData<ReportDetail>(queryKeys.reports.detail(year, month), (current) =>
        current ? { ...current, dispatch: data } : current,
      )
      return queryClient.invalidateQueries({ queryKey: queryKeys.reports.all })
    },
  })
}
