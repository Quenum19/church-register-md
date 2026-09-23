import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ErrorSummary, type SummaryItem } from '../visit/FormFeedback'

const items: SummaryItem[] = [
  { target: 'full_name', message: 'Indiquez votre nom et vos prénoms.' },
  { target: 'commune', message: 'Indiquez votre commune.' },
]

function Harness({ focusKey, errors }: { focusKey: number; errors: SummaryItem[] }) {
  return (
    <form>
      <ErrorSummary items={errors} focusKey={focusKey} />
      <input id="full_name" aria-label="Nom et prénoms" />
      <input id="commune" aria-label="Commune" />
    </form>
  )
}

describe('ErrorSummary', () => {
  // Constaté dans Edge (test de bout en bout) : au 1er envoi refusé par un vrai clic,
  // focusKey change dans un rendu où les erreurs ne sont pas encore publiées.
  it('reçoit le focus quand il apparaît dans un rendu postérieur à l’incrément de focusKey', () => {
    const { rerender } = render(<Harness focusKey={0} errors={[]} />)
    rerender(<Harness focusKey={1} errors={[]} />)
    expect(screen.queryByRole('region')).toBeNull()

    rerender(<Harness focusKey={1} errors={items} />)
    expect(screen.getByRole('region', { name: '2 points sont à corriger :' })).toHaveFocus()
  })

  it('reçoit le focus quand focusKey et les erreurs changent dans le même rendu', () => {
    const { rerender } = render(<Harness focusKey={0} errors={[]} />)
    rerender(<Harness focusKey={1} errors={items} />)
    expect(screen.getByRole('region', { name: '2 points sont à corriger :' })).toHaveFocus()
  })

  it('ne vole pas le focus du champ en cours de saisie sans nouvel envoi', () => {
    const { rerender } = render(<Harness focusKey={1} errors={items} />)
    const field = screen.getByLabelText('Commune')
    field.focus()

    // Correction de toutes les erreurs, puis réapparition d'une erreur (revalidation à la saisie).
    rerender(<Harness focusKey={1} errors={[]} />)
    rerender(<Harness focusKey={1} errors={items.slice(1)} />)
    expect(field).toHaveFocus()

    // Nouvel envoi refusé : le résumé reprend le focus.
    rerender(<Harness focusKey={2} errors={items.slice(1)} />)
    expect(screen.getByRole('region', { name: 'Un point est à corriger :' })).toHaveFocus()
  })
})
