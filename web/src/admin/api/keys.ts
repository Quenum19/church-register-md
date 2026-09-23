// Clés de requête structurées : ['admin', <ressource>, <portée>, <paramètres>].
// Les invalidations visent un préfixe (ex. queryKeys.visitors.all).

import type { VisitorFilters } from '../../shared/api-types'

export interface MemberFilters {
  search?: string
  page?: number
}

export interface AuditFilters {
  action?: string
  user_id?: number
  page?: number
}

export const queryKeys = {
  all: ['admin'] as const,
  stats: ['admin', 'stats'] as const,
  visitors: {
    all: ['admin', 'visitors'] as const,
    list: (filters: VisitorFilters) => ['admin', 'visitors', 'list', filters] as const,
    detail: (id: number) => ['admin', 'visitors', 'detail', id] as const,
  },
  members: {
    all: ['admin', 'members'] as const,
    list: (filters: MemberFilters) => ['admin', 'members', 'list', filters] as const,
  },
  reports: {
    all: ['admin', 'reports'] as const,
    list: (year: number) => ['admin', 'reports', 'list', year] as const,
    detail: (year: number, month: number) => ['admin', 'reports', 'detail', year, month] as const,
  },
  users: ['admin', 'users'] as const,
  settings: ['admin', 'settings'] as const,
  families: ['admin', 'families'] as const,
  rotations: {
    all: ['admin', 'rotations'] as const,
    range: (from: string, months: number) => ['admin', 'rotations', from, months] as const,
  },
  recipients: ['admin', 'recipients'] as const,
  audit: (filters: AuditFilters) => ['admin', 'audit', filters] as const,
}
