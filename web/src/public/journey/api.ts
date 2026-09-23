// Appels de l'API publique (contrat §2). Aucune donnée personnelle ne revient du serveur.

import { apiFetch } from '../../shared/http'
import type {
  CreateVisitRequest,
  CreateVisitResponse,
  IdentifyRequest,
  IdentifyResponse,
  PublicConfig,
} from '../../shared/api-types'

export function postIdentify(body: IdentifyRequest, signal?: AbortSignal): Promise<IdentifyResponse> {
  return apiFetch<IdentifyResponse>('/api/public/identify', { method: 'POST', body, signal })
}

/** 201 (création) et 200 (rejeu idempotent) sont deux succès équivalents. */
export function postVisit(body: CreateVisitRequest): Promise<CreateVisitResponse> {
  return apiFetch<CreateVisitResponse>('/api/public/visits', { method: 'POST', body })
}

export function getPublicConfig(signal?: AbortSignal): Promise<PublicConfig> {
  return apiFetch<PublicConfig>('/api/public/config', { signal })
}

const CONFIG_TTL_MS = 60_000
let configCache: { at: number; promise: Promise<PublicConfig> } | null = null

/** Configuration publique partagée entre les pages (cache 60 s, comme le serveur). */
export function loadPublicConfig(): Promise<PublicConfig> {
  if (configCache && Date.now() - configCache.at < CONFIG_TTL_MS) return configCache.promise
  const promise = getPublicConfig()
  const entry = { at: Date.now(), promise }
  configCache = entry
  promise.catch(() => {
    if (configCache === entry) configCache = null
  })
  return promise
}

/** Réservé aux tests. */
export function clearPublicConfigCache(): void {
  configCache = null
}
