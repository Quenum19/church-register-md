import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { VISIT_REASON_LABELS, VISIT_REASONS, type VisitReason } from '../../shared/domain'
import { ChoiceGroup, ChoiceOption, TextArea } from './FormFields'
import { visit3Schema, type Visit3Values } from './schemas'
import { useVisitForm } from './useVisitForm'
import { VisitFormShell } from './VisitFormParts'

const resolver = zodResolver(visit3Schema)
const DEPENDENTS = { visit_reason: ['visit_reason_other'] } satisfies Partial<Record<keyof Visit3Values, (keyof Visit3Values)[]>>

function toRequest(v: Visit3Values) {
  const reason = v.visit_reason as VisitReason
  return {
    answers: {
      visit_reason: reason,
      visit_reason_other: reason === 'autres' ? v.visit_reason_other.trim() : null,
    },
  }
}

/** 3e visite : le nom n'est ni redemandé ni affiché (aucune donnée personnelle ne vient du serveur). */
export default function Visit3Page() {
  const [defaults] = useState<Visit3Values>(() => ({ visit_reason: '', visit_reason_other: '' }))
  const visit = useVisitForm<Visit3Values>({
    step: 3,
    resolver,
    defaults,
    fieldOrder: ['visit_reason', 'visit_reason_other'],
    dependents: DEPENDENTS,
    targetOf: (field, values) =>
      field === 'visit_reason' ? `visit_reason-${values.visit_reason || VISIT_REASONS[0]}` : field,
    toRequest,
  })
  const { form, errorOf } = visit
  const reason = form.watch('visit_reason')
  const reasonError = errorOf('visit_reason')

  return (
    <VisitFormShell
      step={3}
      title="Votre 3e visite"
      subtitle={<p>Votre fidélité nous touche ! Une dernière question pour mieux vous accompagner.</p>}
      submitting={visit.submitting}
      hasDraft={visit.hasDraft}
      onSubmit={visit.onSubmit}
      summaryItems={visit.summaryItems}
      focusKey={visit.focusKey}
      submitError={visit.submitError}
    >
      <ChoiceGroup id="visit_reason" legend="Quelle est la principale raison de vos visites ?" error={reasonError}>
        {VISIT_REASONS.map((value) => (
          <ChoiceOption
            key={value}
            id={`visit_reason-${value}`}
            type="radio"
            value={value}
            label={VISIT_REASON_LABELS[value]}
            invalid={Boolean(reasonError)}
            aria-describedby={reasonError ? 'visit_reason-error' : undefined}
            {...form.register('visit_reason')}
          />
        ))}
      </ChoiceGroup>

      {reason === 'autres' && (
        <div className="border-l-4 border-church-gold pl-4">
          <TextArea
            id="visit_reason_other"
            label="Précisez la raison de vos visites"
            maxLength={200}
            rows={2}
            error={errorOf('visit_reason_other')}
            {...form.register('visit_reason_other')}
          />
        </div>
      )}
    </VisitFormShell>
  )
}
