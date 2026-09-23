// Appels d'authentification (contrat §3).

import type { LoginResponse, MeResponse, TwoFactorSetup, UpdateProfileRequest, User } from '../../shared/api-types'
import { adminFetch } from './client'

export type TwoFactorChallenge = { code: string } | { recovery_code: string }

export const authApi = {
  me: (signal?: AbortSignal) => adminFetch<MeResponse>('/api/auth/me', { signal, ignoreUnauthenticated: true }),

  login: (body: { email: string; password: string }) =>
    adminFetch<LoginResponse>('/api/auth/login', { method: 'POST', body, ignoreUnauthenticated: true }),

  twoFactorChallenge: (body: TwoFactorChallenge) =>
    adminFetch<{ user: User }>('/api/auth/two-factor/challenge', {
      method: 'POST',
      body,
      ignoreUnauthenticated: true,
    }),

  logout: () => adminFetch<void>('/api/auth/logout', { method: 'POST', ignoreUnauthenticated: true }),

  forgotPassword: (email: string) =>
    adminFetch<unknown>('/api/auth/forgot-password', { method: 'POST', body: { email }, ignoreUnauthenticated: true }),

  resetPassword: (body: { token: string; email: string; password: string; password_confirmation: string }) =>
    adminFetch<void>('/api/auth/reset-password', { method: 'POST', body, ignoreUnauthenticated: true }),

  updateProfile: (body: UpdateProfileRequest) =>
    adminFetch<{ user: User }>('/api/auth/profile', { method: 'PATCH', body }),

  updatePassword: (body: { current_password: string; password: string; password_confirmation: string }) =>
    adminFetch<void>('/api/auth/password', { method: 'PUT', body }),

  enableTwoFactor: (password: string) =>
    adminFetch<TwoFactorSetup>('/api/auth/two-factor/enable', { method: 'POST', body: { password } }),

  confirmTwoFactor: (code: string) =>
    adminFetch<void>('/api/auth/two-factor/confirm', { method: 'POST', body: { code } }),

  disableTwoFactor: (password: string) =>
    adminFetch<void>('/api/auth/two-factor', { method: 'DELETE', body: { password } }),
}
