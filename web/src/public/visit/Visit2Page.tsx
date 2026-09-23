import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { RETURN_REASON_LABELS, RETURN_REASONS, type ReturnReason } from '../../shared/domain'
import { ChoiceGroup, ChoiceOption, TextArea } from './FormFields'
import { visit2Schema, type Visit2Values } from './schemas'
import { useVisitForm } from './useVisitForm'
import { VisitFormShell } from './VisitFormParts'

const resolver = zodResolver(visit2Schema)
const DEPENDENTS = { return_reasons: ['return_reasons_other'] } satisfies Partial<Record<keyof Visit2Values, (keyof Visit2Values)[]>>

function toRequest(v: Visit2Values) {
  const reasons = [...new Set(v.return_reasons)] as ReturnReason[]
  return {
    answers: {
      return_reasons: reasons,
      return_reasons_other: reasons.includes('autres') ? v.return_reasons_other.trim() : null,
    },
  }
}

/** 2e visite : le nom n'est ni redemandé ni affiché (aucune donnée personnelle ne vient du serveur). */
export default function Visit2Page() {
  const [defaults] = useState<Visit2Values>(() => ({ return_reasons: [], return_reasons_other: '' }))
  const visit = useVisitForm<Visit2Values>({
    step: 2,
    resolver,
    defaults,
    fieldOrder: ['return_reasons', 'return_reasons_other'],
    dependents: DEPENDENTS,
    targetOf: (field) => (field === 'return_reasons' ? `return_reasons-${RETURN_REASONS[0]}` : field),
    toRequest,
  })
  const { form, errorOf } = visit
  const reasons = form.watch('return_reasons')
  const reasonsError = errorOf('return_reasons')

  return (
    <VisitFormShell
      step={2}
      title="Votre 2e visite"
      subtitle={<p>Ravis de vous revoir ! Dites-nous ce qui vous a donné envie de revenir.</p>}
      submitting={visit.submitting}
      hasDraft={visit.hasDraft}
      onSubmit={visit.onSubmit}
      summaryItems={visit.summaryItems}
      focusKey={visit.focusKey}
      submitError={visit.submitError}
    >
      <ChoiceGroup
        id="return_reasons"
        legend="Pourquoi êtes-vous revenu(e) ?"
        hint="Plusieurs réponses possibles."
        error={reasonsError}
      >
        {RETURN_REASONS.map((value) => (
          <ChoiceOption
            key={value}
            id={`return_reasons-${value}`}
            type="checkbox"
            value={value}
            label={RETURN_REASON_LABELS[value]}
            invalid={Boolean(reasonsError)}
            aria-describedby={reasonsError ? 'return_reasons-error' : undefined}
            {...form.register('return_reasons')}
          />
        ))}
      </ChoiceGroup>

      {Array.isArray(reasons) && reasons.includes('autres') && (
        <div className="border-l-4 border-church-gold pl-4">
          <TextArea
            id="return_reasons_other"
            label="Précisez vos autres raisons"
            maxLength={200}
            rows={2}
            error={errorOf('return_reasons_other')}
            {...form.register('return_reasons_other')}
          />
        </div>
      )}
    </VisitFormShell>
  )
}
