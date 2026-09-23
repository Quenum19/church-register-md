import { keepPreviousData, useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query'
import type {
  MemberSummary,
  Note,
  Paginated,
  UpdateVisitorRequest,
  VisitorDetail,
  VisitorFilters,
  VisitorSummary,
} from '../../shared/api-types'
import { isApiError } from '../lib/errors'
import { toApiQuery } from '../lib/visitorFilters'
import { adminFetch } from './client'
import { queryKeys, type MemberFilters } from './keys'

/** Après une modification de visiteur : listes, membres, statistiques et rapports sont périmés. */
export function invalidateVisitorViews(queryClient: QueryClient) {
  return Promise.all([
    queryClient.invalidateQueries({ queryKey: [...queryKeys.visitors.all, 'list'] }),
    queryClient.invalidateQueries({ queryKey: queryKeys.members.all }),
    queryClient.invalidateQueries({ queryKey: queryKeys.stats }),
    queryClient.invalidateQueries({ queryKey: queryKeys.reports.all }),
  ])
}

export function useVisitorList(filters: VisitorFilters, enabled = true) {
  return useQuery({
    queryKey: queryKeys.visitors.list(filters),
    queryFn: ({ signal }) =>
      adminFetch<Paginated<VisitorSummary>>('/api/admin/visitors', { query: toApiQuery(filters), signal }),
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useVisitor(id: number, enabled = true) {
  return useQuery({
    queryKey: queryKeys.visitors.detail(id),
    queryFn: async ({ signal }) =>
      (await adminFetch<{ data: VisitorDetail }>(`/api/admin/visitors/${id}`, { signal })).data,
    enabled,
  })
}

function useVisitorMutation<TVariables>(id: number, request: (variables: TVariables) => Promise<{ data: VisitorDetail }>) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: request,
    onSuccess: async ({ data }) => {
      queryClient.setQueryData(queryKeys.visitors.detail(id), data)
      await invalidateVisitorViews(queryClient)
    },
    onError: (error) => {
      // 409 (`not_eligible`, `already_member`, `not_member`) : la fiche affichée est périmée.
      if (isApiError(error, 409)) void queryClient.invalidateQueries({ queryKey: queryKeys.visitors.detail(id) })
    },
  })
}

export function useUpdateVisitor(id: number) {
  return useVisitorMutation(id, (body: UpdateVisitorRequest) =>
    adminFetch<{ data: VisitorDetail }>(`/api/admin/visitors/${id}`, { method: 'PATCH', body }),
  )
}

export function useConvertVisitor(id: number) {
  return useVisitorMutation(id, () =>
    adminFetch<{ data: VisitorDetail }>(`/api/admin/visitors/${id}/convert`, { method: 'POST' }),
  )
}

export function useUnconvertVisitor(id: number) {
  return useVisitorMutation(id, () =>
    adminFetch<{ data: VisitorDetail }>(`/api/admin/visitors/${id}/convert`, { method: 'DELETE' }),
  )
}

export function useDeleteVisitor(id: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => adminFetch<void>(`/api/admin/visitors/${id}`, { method: 'DELETE' }),
    onSuccess: async () => {
      // La fiche reste affichée jusqu'à la navigation : on la marque périmée sans la recharger.
      await queryClient.invalidateQueries({ queryKey: queryKeys.visitors.detail(id), refetchType: 'none' })
      await invalidateVisitorViews(queryClient)
    },
  })
}

export function useAddNote(visitorId: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (body: string) =>
      adminFetch<{ data: Note }>(`/api/admin/visitors/${visitorId}/notes`, { method: 'POST', body: { body } }),
    onSuccess: ({ data }) => {
      queryClient.setQueryData<VisitorDetail>(queryKeys.visitors.detail(visitorId), (current) =>
        current ? { ...current, notes: [data, ...current.notes.filter((n) => n.id !== data.id)] } : current,
      )
    },
  })
}

export function useDeleteNote(visitorId: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (noteId: number) => adminFetch<void>(`/api/admin/notes/${noteId}`, { method: 'DELETE' }),
    onSuccess: (_, noteId) => {
      queryClient.setQueryData<VisitorDetail>(queryKeys.visitors.detail(visitorId), (current) =>
        current ? { ...current, notes: current.notes.filter((n) => n.id !== noteId) } : current,
      )
    },
  })
}

export function useMemberList(filters: MemberFilters) {
  return useQuery({
    queryKey: queryKeys.members.list(filters),
    queryFn: ({ signal }) =>
      adminFetch<Paginated<MemberSummary>>('/api/admin/members', {
        query: { search: filters.search, page: filters.page ?? 1 },
        signal,
      }),
    placeholderData: keepPreviousData,
  })
}
