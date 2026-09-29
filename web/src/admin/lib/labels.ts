// Libellés du dashboard écrits en toutes lettres : aucune abréviation ordinale
// (« 1re », « 2e », « 3e ») n'est affichée aux responsables. Les libellés du
// parcours public restent ceux de shared/domain.ts.

import { VISITOR_STATUSES, type VisitorStatus } from '../../shared/domain'

export const ADMIN_STATUS_LABELS: Record<VisitorStatus, string> = {
  prospect: 'Première visite',
  recurrent: 'Deuxième visite',
  membre_potentiel: 'Membre potentiel',
  membre: 'Membre',
}

export function statusLabel(status: VisitorStatus): string {
  return ADMIN_STATUS_LABELS[status] ?? status
}

export const STATUS_OPTIONS = VISITOR_STATUSES.map((status) => ({ value: status, label: ADMIN_STATUS_LABELS[status] }))

const VISIT_LABELS: Record<number, string> = {
  1: 'Première visite',
  2: 'Deuxième visite',
  3: 'Troisième visite',
}

/** « Première visite », « Deuxième visite », « Troisième visite ». */
export function visitLabel(visitNumber: number): string {
  return VISIT_LABELS[visitNumber] ?? `Visite n° ${visitNumber}`
}

/** Forme courte pour les tableaux et légendes : « Première », « Deuxième », « Troisième ». */
export function visitOrdinalLabel(visitNumber: number): string {
  return visitLabel(visitNumber).replace(' visite', '')
}
