import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type {
  Family,
  Recipient,
  RecipientInput,
  Rotation,
  Settings,
  UpdateSettingsRequest,
} from '../../shared/api-types'
import { adminFetch } from './client'
import { queryKeys } from './keys'

/* ─── Paramètres de l'église ─────────────────────────────────────── */

export function useSettings() {
  return useQuery({
    queryKey: queryKeys.settings,
    queryFn: ({ signal }) => adminFetch<Settings>('/api/admin/settings', { signal }),
    staleTime: 5 * 60_000,
  })
}

export function useUpdateSettings() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (body: UpdateSettingsRequest) => adminFetch<unknown>('/api/admin/settings', { method: 'PUT', body }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.settings }),
  })
}

/* ─── Familles et rotations ──────────────────────────────────────── */

export function useFamilies() {
  return useQuery({
    queryKey: queryKeys.families,
    queryFn: async ({ signal }) => (await adminFetch<{ data: Family[] }>('/api/admin/families', { signal })).data,
    staleTime: 10 * 60_000,
  })
}

export function useRotations(from: string, months: number) {
  return useQuery({
    queryKey: queryKeys.rotations.range(from, months),
    queryFn: async ({ signal }) =>
      (await adminFetch<{ data: Rotation[] }>('/api/admin/rotations', { query: { from, months }, signal })).data,
  })
}

export function useUpdateRotation() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ year, month, family_id }: { year: number; month: number; family_id: number }) =>
      adminFetch<{ data: Rotation }>(`/api/admin/rotations/${year}/${month}`, { method: 'PUT', body: { family_id } }),
    onSuccess: () =>
      Promise.all([
        queryClient.invalidateQueries({ queryKey: queryKeys.rotations.all }),
        queryClient.invalidateQueries({ queryKey: queryKeys.stats }),
        queryClient.invalidateQueries({ queryKey: queryKeys.reports.all }),
      ]),
  })
}

/* ─── Destinataires des rapports ─────────────────────────────────── */

export function useRecipients() {
  return useQuery({
    queryKey: queryKeys.recipients,
    queryFn: async ({ signal }) =>
      (await adminFetch<{ data: Recipient[] }>('/api/admin/report-recipients', { signal })).data,
  })
}

export function useSaveRecipient() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ...body }: RecipientInput & { id?: number }) =>
      id === undefined
        ? adminFetch<unknown>('/api/admin/report-recipients', { method: 'POST', body })
        : adminFetch<unknown>(`/api/admin/report-recipients/${id}`, { method: 'PATCH', body }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.recipients }),
  })
}

export function useDeleteRecipient() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => adminFetch<void>(`/api/admin/report-recipients/${id}`, { method: 'DELETE' }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.recipients }),
  })
}

export function useSendTestEmail() {
  return useMutation({
    mutationFn: (email: string | null) =>
      adminFetch<void>('/api/admin/report-recipients/test', { method: 'POST', body: email ? { email } : {} }),
  })
}
