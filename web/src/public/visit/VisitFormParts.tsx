import type { BaseSyntheticEvent, ReactNode } from 'react'
import type { DisplayError } from '../journey/errors'
import { BackHome } from '../ui/BackHome'
import { buttonSecondary } from '../ui/classes'
import { Alert, LiveStatus } from '../ui/Feedback'
import { ErrorSummary, SubmitButton, type SummaryItem } from './FormFeedback'
import { Shell } from '../ui/Shell'

interface VisitFormShellProps {
  step: 1 | 2 | 3
  title: string
  subtitle: ReactNode
  submitting: boolean
  hasDraft: () => boolean
  onSubmit: (event?: BaseSyntheticEvent) => Promise<void>
  summaryItems: SummaryItem[]
  focusKey: number
  submitError: DisplayError | null
  children: ReactNode
}

/** Cadre commun des formulaires de visite : titre, retour, résumé d'erreurs, envoi. */
export function VisitFormShell({
  step,
  title,
  subtitle,
  submitting,
  hasDraft,
  onSubmit,
  summaryItems,
  focusKey,
  submitError,
  children,
}: VisitFormShellProps) {
  return (
    <Shell visit={step} title={title} subtitle={subtitle} top={<BackHome disabled={submitting} hasDraft={hasDraft} />}>
      <form noValidate onSubmit={(event) => void onSubmit(event)} className="flex flex-col gap-6" aria-busy={submitting}>
        <ErrorSummary items={summaryItems} focusKey={focusKey} />
        {children}
        {submitError && (
          <Alert
            title="Votre visite n'a pas pu être enregistrée."
            action={
              submitError.retryable && (
                <button type="submit" className={buttonSecondary} disabled={submitting}>
                  Réessayer
                </button>
              )
            }
          >
            <p>{submitError.message}</p>
            {submitError.retryable && (
              <p className="mt-1">Vos réponses sont conservées et ne seront jamais enregistrées deux fois.</p>
            )}
          </Alert>
        )}
        <SubmitButton busy={submitting}>Enregistrer ma visite</SubmitButton>
        <LiveStatus message={submitting ? 'Envoi de votre visite en cours…' : ''} />
      </form>
    </Shell>
  )
}
