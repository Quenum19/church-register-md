// Types des échanges avec l'API — miroir de docs/api-contract.md.
// Toute évolution du contrat se fait d'abord dans ce document.

import type {
  CountryCode,
  ReturnReason,
  Role,
  Source,
  VisitorStatus,
  VisitReason,
} from './domain'

/* ─── Commun ─────────────────────────────────────────────────────── */

export interface FamilyRef {
  id: number
  name: string
}

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

export interface PhoneInput {
  country: CountryCode
  number: string
}

/* ─── Erreurs (contrat §0 et codes propres à chaque route) ───────── */

/** Valeurs connues du champ `code` d'une réponse d'erreur. */
export type ApiErrorCodeName =
  // Génériques (§0)
  | 'bad_request'
  | 'unauthenticated'
  | 'forbidden'
  | 'not_found'
  | 'validation'
  | 'payload_too_large'
  | 'account_locked'
  | 'too_many_requests'
  | 'csrf_expired'
  | 'server_error'
  // Parcours public (§2)
  | 'token_invalid'
  | 'token_expired'
  | 'token_used'
  | 'idempotency_conflict'
  | 'step_mismatch'
  | 'already_today'
  // Authentification (§3)
  | 'two_factor_expired'
  | 'two_factor_already_enabled'
  // Administration (§4)
  | 'not_eligible'
  | 'already_member'
  | 'not_member'
  | 'too_many_rows'
  | 'already_sent'
  | 'send_in_progress'
  | 'no_recipients'
  | 'mail_failed'
  | 'invitation_not_pending'
  | 'forbidden_self_change'
  | 'last_super_admin'

/** Corps d'une réponse d'erreur : `{ message, code?, errors? }`. */
export interface ApiErrorBody {
  message?: string
  code?: ApiErrorCodeName
  errors?: Record<string, string[]>
}

/* ─── API publique ───────────────────────────────────────────────── */

export interface PublicConfig {
  church_name: string
  public_url: string
  verse: { ref: string; text: string }
  current_family: FamilyRef | null
  families: FamilyRef[]
}

export type IdentifyStep = 1 | 2 | 3 | 'complete' | 'done_today'

export interface IdentifyRequest {
  country: CountryCode
  phone: string
}

export interface IdentifyResponse {
  step: IdentifyStep
  session_token: string | null
  expires_in: number | null
}

export interface Visit1Answers {
  full_name: string
  commune: string
  quartier: string
  source: Source
  source_other?: string | null
  invited_by?: string | null
  inviter_family_id?: number | null
  whatsapp?: PhoneInput | null
  whatsapp_same_as_phone?: boolean
  wants_whatsapp_group: boolean
}

export interface Visit2Answers {
  return_reasons: ReturnReason[]
  return_reasons_other?: string | null
}

export interface Visit3Answers {
  visit_reason: VisitReason
  visit_reason_other?: string | null
}

export type VisitAnswers = Visit1Answers | Visit2Answers | Visit3Answers

export interface CreateVisitRequest {
  session_token: string
  idempotency_key: string
  consent?: boolean
  answers: VisitAnswers
}

export interface CreateVisitResponse {
  visit_number: 1 | 2 | 3
  family: FamilyRef | null
  completed: boolean
}

/* ─── Authentification ───────────────────────────────────────────── */

export type Ability =
  | 'visitors.view'
  | 'visitors.update'
  | 'notes.create'
  | 'visitors.convert'
  | 'visitors.unconvert'
  | 'visitors.delete'
  | 'visitors.export'
  | 'reports.send'
  | 'recipients.manage'
  | 'rotations.manage'
  | 'settings.update'
  | 'users.manage'
  | 'audit.view'

export interface User {
  id: number
  name: string
  email: string
  role: Role
  is_active: boolean
  two_factor_enabled: boolean
  last_login_at: string | null
  created_at: string
  invitation_pending: boolean
}

export type LoginResponse = { user: User } | { two_factor_required: true }

/** PATCH /api/auth/profile : `current_password` est obligatoire si l'adresse e-mail change. */
export interface UpdateProfileRequest {
  name?: string
  email?: string
  current_password?: string
}

export interface MeResponse {
  user: User
  abilities: Ability[]
}

export interface TwoFactorSetup {
  secret: string
  otpauth_url: string
  recovery_codes: string[]
}

/* ─── Admin : visiteurs ──────────────────────────────────────────── */

export interface VisitorSummary {
  id: number
  full_name: string
  phone: string
  whatsapp: string | null
  commune: string
  quartier: string
  status: VisitorStatus
  visit_count: number
  first_visit_date: string | null
  last_visit_date: string | null
  created_at: string
}

export interface Visit {
  id: number
  visit_number: 1 | 2 | 3
  visit_date: string
  family: FamilyRef | null
  answers: Partial<Visit2Answers & Visit3Answers>
}

export interface Note {
  id: number
  body: string
  author: { id: number; name: string } | null
  created_at: string
  can_delete: boolean
}

export interface VisitorDetail extends VisitorSummary {
  source: Source
  source_other: string | null
  invited_by: string | null
  inviter_family: FamilyRef | null
  wants_whatsapp_group: boolean
  consent_at: string | null
  visits: Visit[]
  notes: Note[]
  member: { converted_at: string; converted_by: { id: number; name: string } | null } | null
}

export interface MemberSummary extends VisitorSummary {
  converted_at: string
  converted_by: { id: number; name: string } | null
}

export type VisitorSort = '-created_at' | 'created_at' | 'full_name' | '-last_visit_date'

export interface VisitorFilters {
  page?: number
  per_page?: number
  search?: string
  status?: VisitorStatus | 'non_membre'
  family_id?: number
  from?: string
  to?: string
  sort?: VisitorSort
}

export interface UpdateVisitorRequest {
  full_name?: string
  whatsapp?: PhoneInput | null
  commune?: string
  quartier?: string
  invited_by?: string | null
  wants_whatsapp_group?: boolean
}

/* ─── Admin : stats ──────────────────────────────────────────────── */

export interface Stats {
  total_visitors: number
  new_today: number
  visits_this_month: number
  by_status: Record<VisitorStatus, number>
  current_family: FamilyRef | null
  next_family: FamilyRef | null
  visits_by_family: { family: FamilyRef; v1: number; v2: number; v3: number }[]
  monthly_visits: { year: number; month: number; count: number }[]
}

/* ─── Admin : rapports ───────────────────────────────────────────── */

export interface ReportDispatch {
  sent_at: string
  recipients: string[]
}

export interface ReportSummary {
  year: number
  month: number
  family: FamilyRef | null
  counts: { v1: number; v2: number; v3: number; total: number; conversions: number }
  dispatch: ReportDispatch | null
}

export interface ReportDetail extends ReportSummary {
  visitors: { id: number; full_name: string; phone: string; visit_number: 1 | 2 | 3; visit_date: string }[]
  conversions: { id: number; full_name: string; converted_at: string }[]
}

export interface ReportList {
  data: ReportSummary[]
  meta: { available_years: number[] }
}

export interface Recipient {
  id: number
  family: FamilyRef | null
  name: string
  email: string
  active: boolean
}

export interface RecipientInput {
  family_id: number | null
  name: string
  email: string
  active: boolean
}

/* ─── Admin : familles, rotations, paramètres ────────────────────── */

export interface Family extends FamilyRef {
  active: boolean
  position: number
}

export interface Rotation {
  year: number
  month: number
  family: FamilyRef | null
}

export interface Verse {
  ref: string
  text: string
}

export interface Settings {
  church_name: string
  public_url: string
  verse: Verse & { preset: number | null }
  verse_presets: Verse[]
}

export interface UpdateSettingsRequest {
  church_name?: string
  public_url?: string
  verse?: { preset: number } | { preset: null; ref: string; text: string }
}

/* ─── Admin : utilisateurs, journal ──────────────────────────────── */

export interface CreateUserRequest {
  name: string
  email: string
  role: Role
}

export interface UpdateUserRequest {
  name?: string
  email?: string
  role?: Role
  is_active?: boolean
}

/** Alias courts du morph map (contrat §4, journal d'audit). */
export type AuditSubjectType =
  | 'visitor'
  | 'visit'
  | 'note'
  | 'member'
  | 'user'
  | 'family'
  | 'rotation'
  | 'recipient'
  | 'report'

export interface AuditLog {
  id: number
  action: string
  user: { id: number; name: string } | null
  subject_type: AuditSubjectType | null
  subject_id: number | null
  ip: string | null
  created_at: string
  meta: Record<string, unknown> | null
}
