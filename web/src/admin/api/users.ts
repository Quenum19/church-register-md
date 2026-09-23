import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { CreateUserRequest, UpdateUserRequest, User } from '../../shared/api-types'
import { isApiError } from '../lib/errors'
import { adminFetch } from './client'
import { queryKeys } from './keys'

export function useUsers(enabled = true) {
  return useQuery({
    queryKey: queryKeys.users,
    queryFn: async ({ signal }) => (await adminFetch<{ data: User[] }>('/api/admin/users', { signal })).data,
    enabled,
  })
}

function useUsersMutation<TVariables, TResult>(request: (variables: TVariables) => Promise<TResult>) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: request,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.users }),
  })
}

export function useInviteUser() {
  return useUsersMutation((body: CreateUserRequest) =>
    adminFetch<{ data: User }>('/api/admin/users', { method: 'POST', body }),
  )
}

export function useUpdateUser() {
  return useUsersMutation(({ id, ...body }: UpdateUserRequest & { id: number }) =>
    adminFetch<{ data: User }>(`/api/admin/users/${id}`, { method: 'PATCH', body }),
  )
}

export function useDeleteUser() {
  return useUsersMutation((id: number) => adminFetch<void>(`/api/admin/users/${id}`, { method: 'DELETE' }))
}

export function useResendInvitation() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => adminFetch<void>(`/api/admin/users/${id}/invitation`, { method: 'POST' }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.users }),
    onError: (error) => {
      // Compte activé entre-temps : la liste affichée (« Invitation en attente ») est périmée.
      if (isApiError(error, 409, 'invitation_not_pending')) void queryClient.invalidateQueries({ queryKey: queryKeys.users })
    },
  })
}
