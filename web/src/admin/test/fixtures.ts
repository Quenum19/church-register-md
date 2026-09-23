import type {
  Ability,
  MeResponse,
  Paginated,
  Stats,
  User,
  VisitorDetail,
  VisitorSummary,
} from '../../shared/api-types'
import type { Role } from '../../shared/domain'
import type { MockServer } from './server'

const ALL: Ability[] = [
  'visitors.view',
  'visitors.update',
  'notes.create',
  'visitors.convert',
  'visitors.unconvert',
  'visitors.delete',
  'visitors.export',
  'reports.send',
  'recipients.manage',
  'rotations.manage',
  'settings.update',
  'users.manage',
  'audit.view',
]

export const ABILITIES: Record<Role, Ability[]> = {
  lecteur: ['visitors.view'],
  moderateur: ['visitors.view', 'visitors.update', 'notes.create'],
  super_admin: ALL,
}

export function makeUser(overrides: Partial<User> = {}): User {
  return {
    id: 1,
    name: 'Jean Kouassi',
    email: 'jean@exemple.org',
    role: 'super_admin',
    is_active: true,
    two_factor_enabled: false,
    last_login_at: '2026-09-22T08:00:00+00:00',
    created_at: '2026-01-01T08:00:00+00:00',
    invitation_pending: false,
    ...overrides,
  }
}

export function makeMe(role: Role = 'super_admin'): MeResponse {
  return { user: makeUser({ role }), abilities: ABILITIES[role] }
}

export function makeStats(overrides: Partial<Stats> = {}): Stats {
  return {
    total_visitors: 42,
    new_today: 3,
    visits_this_month: 17,
    by_status: { prospect: 20, recurrent: 10, membre_potentiel: 7, membre: 5 },
    current_family: { id: 4, name: 'Force' },
    next_family: { id: 5, name: 'Honneur' },
    visits_by_family: [{ family: { id: 4, name: 'Force' }, v1: 5, v2: 3, v3: 1 }],
    monthly_visits: [
      { year: 2026, month: 8, count: 12 },
      { year: 2026, month: 9, count: 17 },
    ],
    ...overrides,
  }
}

export function makeVisitor(overrides: Partial<VisitorSummary> = {}): VisitorSummary {
  return {
    id: 5,
    full_name: 'Awa Koné',
    phone: '+2250700000001',
    whatsapp: null,
    commune: 'Cocody',
    quartier: 'Riviera',
    status: 'recurrent',
    visit_count: 2,
    first_visit_date: '2026-08-02',
    last_visit_date: '2026-09-06',
    created_at: '2026-08-02T09:00:00+00:00',
    ...overrides,
  }
}

export function makeVisitorDetail(overrides: Partial<VisitorDetail> = {}): VisitorDetail {
  return {
    ...makeVisitor(),
    source: 'invite_membre',
    source_other: null,
    invited_by: 'Marie',
    inviter_family: { id: 2, name: 'Richesse' },
    wants_whatsapp_group: false,
    consent_at: '2026-08-02T09:00:00+00:00',
    visits: [
      { id: 10, visit_number: 1, visit_date: '2026-08-02', family: { id: 3, name: 'Sagesse' }, answers: {} },
      {
        id: 11,
        visit_number: 2,
        visit_date: '2026-09-06',
        family: { id: 4, name: 'Force' },
        answers: { return_reasons: ['enseignement', 'accueil'] },
      },
    ],
    notes: [],
    member: null,
    ...overrides,
  }
}

export function paginated<T>(data: T[], meta: Partial<Paginated<T>['meta']> = {}): Paginated<T> {
  return { data, meta: { current_page: 1, last_page: 1, per_page: 20, total: data.length, ...meta } }
}

/** Session authentifiée + données communes à la mise en page (famille du mois, familles). */
export function withSession(server: MockServer, role: Role = 'super_admin') {
  return server
    .on('GET', '/api/auth/me', { body: makeMe(role) })
    .on('GET', '/sanctum/csrf-cookie', { status: 204 })
    .on('GET', '/api/admin/stats', { body: makeStats() })
    .on('GET', '/api/admin/families', {
      body: {
        data: [
          { id: 1, name: 'Puissance', active: true, position: 1 },
          { id: 4, name: 'Force', active: true, position: 4 },
        ],
      },
    })
}
