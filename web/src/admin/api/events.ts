import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { ChurchEvent, EventInput } from '../../shared/api-types'
import { adminFetch } from './client'
import { queryKeys } from './keys'

/** Événements triés par le serveur (date décroissante puis nom). Lecture : ability `visitors.view`. */
export function useEvents(enabled = true) {
  return useQuery({
    queryKey: queryKeys.events,
    queryFn: async ({ signal }) => (await adminFetch<{ data: ChurchEvent[] }>('/api/admin/events', { signal })).data,
    staleTime: 60_000,
    enabled,
  })
}

function useEventMutation<TVariables, TResult>(request: (variables: TVariables) => Promise<TResult>) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: request,
    // Le nom et le lien d'un événement apparaissent aussi sur les fiches visiteurs.
    onSuccess: () =>
      Promise.all([
        queryClient.invalidateQueries({ queryKey: queryKeys.events }),
        queryClient.invalidateQueries({ queryKey: queryKeys.visitors.all }),
      ]),
  })
}

export function useCreateEvent() {
  return useEventMutation((body: EventInput) =>
    adminFetch<{ data: ChurchEvent }>('/api/admin/events', { method: 'POST', body }),
  )
}

export function useUpdateEvent() {
  return useEventMutation(({ id, ...body }: Partial<EventInput> & { id: number }) =>
    adminFetch<{ data: ChurchEvent }>(`/api/admin/events/${id}`, { method: 'PATCH', body }),
  )
}

export function useDeleteEvent() {
  return useEventMutation((id: number) => adminFetch<void>(`/api/admin/events/${id}`, { method: 'DELETE' }))
}
