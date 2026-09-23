import { screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ReportDetail } from '../../shared/api-types'
import { todayParts } from '../lib/format'
import { makeUser, withSession } from '../test/fixtures'
import { renderAdmin } from '../test/render'
import { createMockServer } from '../test/server'

afterEach(() => {
  vi.unstubAllGlobals()
})

const CURRENT_YEAR = todayParts().year

const REPORT: ReportDetail = {
  year: 2026,
  month: 8,
  family: { id: 3, name: 'Sagesse' },
  counts: { v1: 2, v2: 1, v3: 0, total: 3, conversions: 0 },
  dispatch: null,
  visitors: [{ id: 5, full_name: 'Awa Koné', phone: '+2250700000001', visit_number: 1, visit_date: '2026-08-02' }],
  conversions: [],
}

describe('rapports', () => {
  it('alimente le sélecteur d’années avec meta.available_years', async () => {
    const server = withSession(createMockServer()).on('GET', '/api/admin/reports', (request) => ({
      body: { data: [{ ...REPORT, year: Number(request.url.searchParams.get('year')) }], meta: { available_years: [2025, 2026] } },
    }))
    const { user, location } = renderAdmin('/admin/rapports')

    const select = await screen.findByLabelText('Année')
    await waitFor(() => expect(within(select).getAllByRole('option').map((o) => o.textContent)).toEqual(['2026', '2025']))
    await user.selectOptions(select, '2025')
    await waitFor(() => expect(location()).toBe('/admin/rapports?annee=2025'))
    expect(server.last('GET', '/api/admin/reports')?.url.searchParams.get('year')).toBe('2025')
  })

  it('demande une confirmation explicite puis force l’envoi si le rapport a déjà été envoyé (409)', async () => {
    const server = withSession(createMockServer())
      .on('GET', '/api/admin/reports/2026/8', { body: { data: REPORT } })
      .on('POST', '/api/admin/reports/2026/8/send', (request) =>
        (request.body as { force?: boolean }).force
          ? { body: { data: { sent_at: '2026-09-22T10:00:00+00:00', recipients: ['a@b.c'] } } }
          : { status: 409, body: { message: 'Déjà envoyé.', code: 'already_sent' } },
      )
    const { user } = renderAdmin('/admin/rapports/2026/8')

    await user.click(await screen.findByRole('button', { name: 'Envoyer le rapport' }))
    await user.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Envoyer' }))

    const force = await screen.findByRole('alertdialog', { name: 'Ce rapport a déjà été envoyé' })
    await user.click(within(force).getByRole('button', { name: 'Renvoyer quand même' }))

    expect(await screen.findByText('Rapport d’août 2026 envoyé à 1 destinataire.')).toBeInTheDocument()
    expect(server.calls('POST', '/api/admin/reports/2026/8/send').map((r) => r.body)).toEqual([{}, { force: true }])
  })

  it.each([
    ['absente de meta.available_years', { body: { data: [], meta: { available_years: [CURRENT_YEAR] } } }],
    [
      'refusée par l’API (422 sur year)',
      { status: 422, body: { message: 'Année invalide.', code: 'validation', errors: { year: ['Année invalide.'] } } },
    ],
  ])('revient à l’année courante sans écran d’erreur quand l’année demandée est %s', async (_, reply) => {
    const server = withSession(createMockServer()).on('GET', '/api/admin/reports', (request) =>
      request.url.searchParams.get('year') === '2019'
        ? reply
        : { body: { data: [{ ...REPORT, year: CURRENT_YEAR }], meta: { available_years: [CURRENT_YEAR] } } },
    )
    const { location } = renderAdmin('/admin/rapports?annee=2019')

    await waitFor(() => expect(location()).toBe('/admin/rapports'))
    expect(await screen.findByLabelText('Année')).toHaveValue(String(CURRENT_YEAR))
    await waitFor(() => expect(server.last('GET', '/api/admin/reports')?.url.searchParams.get('year')).toBe(String(CURRENT_YEAR)))
    expect(await screen.findByRole('table', { name: 'Rapports mensuels' })).toBeInTheDocument()
    expect(screen.queryByText('Impossible de charger ces données.')).not.toBeInTheDocument()
    expect(within(screen.getByLabelText('Année')).queryByRole('option', { name: '2019' })).not.toBeInTheDocument()
  })

  it('explique un mois indisponible (404 : futur ou antérieur aux données)', async () => {
    withSession(createMockServer()).on('GET', '/api/admin/reports/2030/1', {
      status: 404,
      body: { message: 'Ressource introuvable.', code: 'not_found' },
    })
    renderAdmin('/admin/rapports/2030/1')
    expect(await screen.findByText('Ce mois est dans le futur ou antérieur aux données disponibles.')).toBeInTheDocument()
  })

  it.each([
    [{ status: 409, body: { message: 'Envoi en cours.', code: 'send_in_progress' } }, /Un envoi de ce rapport est déjà en cours/],
    [{ status: 503, body: { message: 'Transport indisponible.', code: 'mail_failed' } }, /L'envoi de l'e-mail a échoué/],
  ])('affiche un message clair si l’envoi échoue (%#)', async (reply, message) => {
    withSession(createMockServer())
      .on('GET', '/api/admin/reports/2026/8', { body: { data: REPORT } })
      .on('POST', '/api/admin/reports/2026/8/send', reply)
    const { user } = renderAdmin('/admin/rapports/2026/8')

    await user.click(await screen.findByRole('button', { name: 'Envoyer le rapport' }))
    await user.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Envoyer' }))
    expect(await within(screen.getByRole('alertdialog')).findByRole('alert')).toHaveTextContent(message)
  })

  it('explique l’absence de destinataires (422 no_recipients)', async () => {
    withSession(createMockServer())
      .on('GET', '/api/admin/reports/2026/8', { body: { data: REPORT } })
      .on('POST', '/api/admin/reports/2026/8/send', { status: 422, body: { message: 'Aucun destinataire.', code: 'no_recipients' } })
    const { user } = renderAdmin('/admin/rapports/2026/8')

    await user.click(await screen.findByRole('button', { name: 'Envoyer le rapport' }))
    await user.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Envoyer' }))
    expect(await within(screen.getByRole('alertdialog')).findByRole('alert')).toHaveTextContent(/Aucun destinataire actif/)
  })

  it('masque l’envoi sans l’ability reports.send', async () => {
    withSession(createMockServer(), 'lecteur').on('GET', '/api/admin/reports/2026/8', { body: { data: REPORT } })
    renderAdmin('/admin/rapports/2026/8')
    expect(await screen.findByRole('heading', { level: 1, name: 'Rapport d’août 2026' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Envoyer/ })).not.toBeInTheDocument()
  })
})

describe('administrateurs', () => {
  it('affiche clairement le refus « dernier super administrateur » (409)', async () => {
    withSession(createMockServer())
      .on('GET', '/api/admin/users', {
        body: { data: [makeUser(), makeUser({ id: 2, name: 'Paul Yao', email: 'paul@exemple.org' })] },
      })
      .on('DELETE', '/api/admin/users/2', { status: 409, body: { message: 'Conflit.', code: 'last_super_admin' } })
    const { user } = renderAdmin('/admin/administrateurs')

    // Son propre compte ne peut pas être supprimé.
    const table = await screen.findByRole('table', { name: 'Administrateurs' })
    expect(within(table).queryByRole('button', { name: 'Supprimer Jean Kouassi' })).not.toBeInTheDocument()

    await user.click(within(table).getByRole('button', { name: 'Supprimer Paul Yao' }))
    await user.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Supprimer le compte' }))
    expect(await within(screen.getByRole('alertdialog')).findByRole('alert')).toHaveTextContent(
      'il doit toujours rester au moins un super administrateur actif',
    )
  })
})
