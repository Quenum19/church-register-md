// État du parcours visiteur, persisté dans sessionStorage.
// Il survit à un rafraîchissement et aux boutons Précédent/Suivant, mais pas à la
// fermeture de l'onglet. Toute lecture/écriture est protégée : navigation privée,
// stockage plein ou désactivé ne doivent jamais casser le parcours (repli en mémoire).

import type { FamilyRef } from '../../shared/api-types'
import { COUNTRY_CODES, type CountryCode } from '../../shared/domain'

export type VisitStep = 1 | 2 | 3

export const STORAGE_KEY = 'church-register:journey:v1'
const STATE_VERSION = 1
/**
 * Au-delà, un état abandonné est ignoré **et effacé du stockage**. Volontairement court :
 * l'accueil se fait souvent sur une tablette partagée, et un parcours laissé en plan ne doit
 * pas rester lisible par le visiteur suivant. Le délai repart à chaque saisie (`updatedAt`),
 * donc il ne gêne pas un parcours en cours.
 */
export const STATE_MAX_AGE_MS = 60 * 60 * 1000

export interface PhoneEntry {
  country: CountryCode
  phone: string
}

export interface VisitResult {
  visitNumber: VisitStep
  family: FamilyRef | null
  completed: boolean
  /** Nom saisi dans ce navigateur (visite 1 uniquement) — jamais reçu du serveur. */
  name: string | null
}

export interface JourneyState {
  version: typeof STATE_VERSION
  updatedAt: number
  /** Numéro saisi sur l'écran d'identification, en attente de confirmation. */
  typed: PhoneEntry | null
  /** Numéro confirmé et reconnu par le serveur : sert à la ré-identification. */
  identified: PhoneEntry | null
  step: VisitStep | null
  token: string | null
  tokenExpiresAt: number | null
  idempotencyKeys: Partial<Record<VisitStep, string>>
  drafts: Partial<Record<VisitStep, Record<string, unknown>>>
  result: VisitResult | null
}

export interface JourneyStore {
  getState(): JourneyState
  setState(patch: Partial<JourneyState> | ((state: JourneyState) => Partial<JourneyState>)): void
  /** Enregistre le brouillon d'une étape. */
  saveDraft(step: VisitStep, values: Record<string, unknown>): void
  /** Efface tout le parcours (nouveau visiteur). */
  reset(): void
}

export function emptyState(now = Date.now()): JourneyState {
  return {
    version: STATE_VERSION,
    updatedAt: now,
    typed: null,
    identified: null,
    step: null,
    token: null,
    tokenExpiresAt: null,
    idempotencyKeys: {},
    drafts: {},
    result: null,
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function isStep(value: unknown): value is VisitStep {
  return value === 1 || value === 2 || value === 3
}

function parsePhoneEntry(value: unknown): PhoneEntry | null {
  if (!isRecord(value)) return null
  const { country, phone } = value
  if (typeof phone !== 'string' || !(COUNTRY_CODES as readonly unknown[]).includes(country)) return null
  return { country: country as CountryCode, phone }
}

function parseStepRecord<T>(value: unknown, accept: (v: unknown) => v is T): Partial<Record<VisitStep, T>> {
  const out: Partial<Record<VisitStep, T>> = {}
  if (!isRecord(value)) return out
  for (const step of [1, 2, 3] as const) {
    const item = value[step]
    if (accept(item)) out[step] = item
  }
  return out
}

function parseResult(value: unknown): VisitResult | null {
  if (!isRecord(value) || !isStep(value.visitNumber)) return null
  const family = isRecord(value.family) && typeof value.family.id === 'number' && typeof value.family.name === 'string'
    ? { id: value.family.id, name: value.family.name }
    : null
  return {
    visitNumber: value.visitNumber,
    family,
    completed: value.completed === true,
    name: typeof value.name === 'string' && value.name.trim() ? value.name : null,
  }
}

export interface StoredState {
  state: JourneyState
  /**
   * Le stockage contenait un état inutilisable (illisible, d'une autre version, ou périmé) :
   * l'appelant doit l'effacer au lieu de le laisser traîner en clair dans le navigateur.
   */
  stale: boolean
}

/** Valide un état lu depuis le stockage ; tout ce qui est douteux est écarté. */
export function readStoredState(raw: string | null, now = Date.now()): StoredState {
  const discard = (): StoredState => ({ state: emptyState(now), stale: true })
  if (!raw) return { state: emptyState(now), stale: false }
  let data: unknown
  try {
    data = JSON.parse(raw)
  } catch {
    return discard()
  }
  if (!isRecord(data) || data.version !== STATE_VERSION) return discard()
  const updatedAt = typeof data.updatedAt === 'number' ? data.updatedAt : 0
  // Périmé, ou horodaté dans le futur (horloge de l'appareil remise à l'heure).
  if (now - updatedAt > STATE_MAX_AGE_MS || updatedAt > now + 60_000) return discard()

  const identified = parsePhoneEntry(data.identified)
  const step = identified && isStep(data.step) ? data.step : null
  return {
    state: {
      version: STATE_VERSION,
      updatedAt,
      typed: parsePhoneEntry(data.typed),
      identified,
      step,
      token: step && typeof data.token === 'string' && data.token ? data.token : null,
      tokenExpiresAt: typeof data.tokenExpiresAt === 'number' ? data.tokenExpiresAt : null,
      idempotencyKeys: parseStepRecord(data.idempotencyKeys, (v): v is string => typeof v === 'string' && v.length > 0),
      drafts: parseStepRecord(data.drafts, isRecord),
      result: parseResult(data.result),
    },
    stale: false,
  }
}

export function parseState(raw: string | null, now = Date.now()): JourneyState {
  return readStoredState(raw, now).state
}

/** sessionStorage si utilisable, sinon null (le parcours fonctionne alors en mémoire). */
export function safeSessionStorage(): Storage | null {
  try {
    const storage = window.sessionStorage
    const probe = `${STORAGE_KEY}:probe`
    storage.setItem(probe, '1')
    storage.removeItem(probe)
    return storage
  } catch {
    return null
  }
}

export function createJourneyStore(storage: Storage | null = safeSessionStorage()): JourneyStore {
  let state: JourneyState
  let stale = false
  try {
    const stored = readStoredState(storage?.getItem(STORAGE_KEY) ?? null)
    state = stored.state
    stale = stored.stale
  } catch {
    state = emptyState()
    stale = true
  }

  const clearStorage = () => {
    if (!storage) return
    try {
      storage.removeItem(STORAGE_KEY)
    } catch {
      // ignoré
    }
  }

  // Un état périmé ou illisible ne doit pas survivre à sa lecture : sur une tablette d'accueil
  // partagée, il contient encore le numéro, le nom et l'adresse du visiteur précédent.
  if (stale) clearStorage()

  const persist = () => {
    if (!storage) return
    try {
      storage.setItem(STORAGE_KEY, JSON.stringify(state))
    } catch {
      // Stockage plein ou refusé : l'état reste disponible en mémoire pour cet affichage.
    }
  }

  return {
    getState: () => state,
    setState(patch) {
      const next = typeof patch === 'function' ? patch(state) : patch
      state = { ...state, ...next, updatedAt: Date.now() }
      persist()
    },
    saveDraft(step, values) {
      state = { ...state, drafts: { ...state.drafts, [step]: values }, updatedAt: Date.now() }
      persist()
    },
    reset() {
      state = emptyState()
      clearStorage()
    },
  }
}

export function samePhone(a: PhoneEntry | null, b: PhoneEntry | null): boolean {
  if (!a || !b || a.country !== b.country) return false
  return a.phone.replace(/[^\d+]/g, '') === b.phone.replace(/[^\d+]/g, '')
}
