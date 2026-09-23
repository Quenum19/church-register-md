import { screen, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { AuditLog } from '../../shared/api-types'
import { FAMILY_NAMES } from '../../shared/domain'
import { makeStats, makeUser, paginated, withSession } from '../test/fixtures'
import { renderAdmin } from '../test/render'
import { createMockServer } from '../test/server'

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('accueil — visites par famille', () => {
  it('affiche l’état vide quand les 7 familles sont à 0', async () => {
    const visits_by_family = FAMILY_NAMES.map((name, index) => ({ family: { id: index + 1, name }, v1: 0, v2: 0, v3: 0 }))
    withSession(createMockServer()).on('GET', '/api/admin/stats', { body: makeStats({ visits_by_family }) })
    renderAdmin('/admin')

    expect(await screen.findByText('Aucune visite enregistrée cette année.')).toBeInTheDocument()
    expect(screen.queryByRole('list', { name: 'Légende' })).not.toBeInTheDocument()
    expect(screen.queryByText('Louange')).not.toBeInTheDocument()
  })

  it('affiche le détail dès qu’une famille a une visite', async () => {
    withSession(createMockServer())
    renderAdmin('/admin')

    expect(await screen.findByRole('list', { name: 'Légende' })).toBeInTheDocument()
    expect(screen.getByText('(1re : 5 · 2e : 3 · 3e : 1)')).toBeInTheDocument()
    expect(screen.queryByText('Aucune visite enregistrée cette année.')).not.toBeInTheDocument()
  })
})

describe('accueil — visites des 12 derniers mois', () => {
  it('donne le mois complet aux lecteurs d’écran et une initiale non tronquée en mobile', async () => {
    const monthly_visits = [
      { year: 2026, month: 1, count: 4 },
      { year: 2026, month: 8, count: 12 },
    ]
    withSession(createMockServer()).on('GET', '/api/admin/stats', { body: makeStats({ monthly_visits }) })
    renderAdmin('/admin')

    const chart = within(await screen.findByRole('region', { name: 'Visites des 12 derniers mois' }))
    // Information complète, lue par les lecteurs d'écran (les libellés visibles sont aria-hidden).
    expect(chart.getByText('Janvier 2026 : 4 visites')).toBeInTheDocument()
    expect(chart.getByText('Août 2026 : 12 visites')).toBeInTheDocument()

    // Libellés visibles : initiale en mobile, abréviation à partir de « sm », rien de tronqué.
    const [january] = chart.getAllByRole('listitem')
    expect(within(january).getByText('J')).toHaveClass('sm:hidden')
    expect(within(january).getByText('janv.')).toHaveClass('hidden', 'sm:inline')
    expect(within(january).getByText('’26')).toHaveClass('sm:hidden')
    expect(within(january).getByText('2026')).toHaveClass('hidden', 'sm:inline')
    expect(january.querySelector('.truncate')).toBeNull()
  })
})

describe('journal d’audit', () => {
  function log(overrides: Partial<AuditLog>): AuditLog {
    return {
      id: 1,
      action: 'visitor.updated',
      user: { id: 1, name: 'Jean Kouassi' },
      subject_type: 'visitor',
      subject_id: 5,
      ip: '127.0.0.1',
      created_at: '2026-09-22T08:00:00+00:00',
      meta: null,
      ...overrides,
    }
  }

  it('traduit les alias subject_type du contrat', async () => {
    withSession(createMockServer())
      .on('GET', '/api/admin/users', { body: { data: [makeUser()] } })
      .on('GET', '/api/admin/audit-logs', {
        body: paginated([
          log({ id: 1, subject_type: 'visit', subject_id: 12 }),
          log({ id: 2, action: 'visitor.converted', subject_type: 'member', subject_id: 5 }),
          log({ id: 3, action: 'rotation.updated', subject_type: 'rotation', subject_id: 40 }),
          log({ id: 4, action: 'recipient.created', subject_type: 'recipient', subject_id: 2 }),
          log({ id: 5, action: 'report.sent', subject_type: 'report', subject_id: 7 }),
          log({ id: 6, action: 'user.updated', subject_type: 'user', subject_id: 3 }),
          log({ id: 7, action: 'note.created', subject_type: 'note', subject_id: 9 }),
          log({ id: 8, action: 'rotation.updated', subject_type: 'family', subject_id: 4 }),
          log({ id: 9, action: 'settings.updated', subject_type: null, subject_id: null, meta: { fields: ['church_name'] } }),
        ]),
      })
    renderAdmin('/admin/journal')

    const table = await screen.findByRole('table', { name: 'Journal d’audit' })
    const subjects = within(table)
      .getAllByRole('row')
      .slice(1)
      .map((row) => within(row).getAllByRole('cell')[2]?.textContent)
    expect(subjects).toEqual([
      'Visite n° 12',
      'Membre n° 5',
      'Rotation n° 40',
      'Destinataire n° 2',
      'Rapport n° 7',
      'Administrateur n° 3',
      'Note n° 9',
      'Famille n° 4',
      '—',
    ])
  })
})
