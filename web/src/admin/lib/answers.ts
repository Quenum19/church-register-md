// Traduction des réponses brutes d'une visite (codes du contrat) en libellés affichables.

import type { Visit } from '../../shared/api-types'
import {
  RETURN_REASON_LABELS,
  VISIT_REASON_LABELS,
  type ReturnReason,
  type VisitReason,
} from '../../shared/domain'

export interface AnswerLine {
  label: string
  value: string
}

function returnReasonLabel(code: string): string {
  return RETURN_REASON_LABELS[code as ReturnReason] ?? code
}

function visitReasonLabel(code: string): string {
  return VISIT_REASON_LABELS[code as VisitReason] ?? code
}

export function describeAnswers(answers: Visit['answers'] | null | undefined): AnswerLine[] {
  if (!answers) return []
  const lines: AnswerLine[] = []
  if (Array.isArray(answers.return_reasons) && answers.return_reasons.length > 0) {
    lines.push({ label: 'Raisons du retour', value: answers.return_reasons.map(returnReasonLabel).join(', ') })
  }
  if (answers.return_reasons_other) {
    lines.push({ label: 'Autre(s) raison(s)', value: answers.return_reasons_other })
  }
  if (answers.visit_reason) {
    lines.push({ label: 'Motivation', value: visitReasonLabel(answers.visit_reason) })
  }
  if (answers.visit_reason_other) {
    lines.push({ label: 'Précision', value: answers.visit_reason_other })
  }
  return lines
}

const ORDINALS: Record<number, string> = { 1: '1re', 2: '2e', 3: '3e' }

export function ordinal(visitNumber: number): string {
  return ORDINALS[visitNumber] ?? `${visitNumber}e`
}
