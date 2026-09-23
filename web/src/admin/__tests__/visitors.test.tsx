import { render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { Pagination } from '../components/Pagination'
import { PDF_EXPORT_MAX_ROWS } from '../lib/visitorFilters'
import { makeVisitor, paginated, withSession } from '../test/fixtures'
import { renderAdmin } from '../test/render'
import { createMockServer, type MockServer, type RecordedRequest } from '../test/server'

afterEach(() => {
  vi.unstubAllGlobals()
})

function visitorsServer(role: 'lecteur' | 'super_admin' = 'super_admin', meta?: (request: RecordedRequest) => object) {
  return withSession(createMockServer(), role).on('GET', '/api/admin/visitors', (request) => {
    const page = Number(request.url.searchParams.get('page') ?? 1)
    return { body: paginated([makeVisitor({ id: page, full_name: `Visiteur page ${page}` })], { current_page: page, ...meta?.(request) }) }
  })
}

function lastQuery(server: MockServer): Record<string, string> {
  return Object.fromEntries(server.last('GET', '/api/admin/visitors')?.url.searchParams ?? [])
}

describe('liste des visiteurs — filtres', () => {
  it('envoie les filtres présents dans l’URL (rafraîchissement, lien direct)', async () => {
    const server = visitorsServer()
    renderAdmin('/admin/visiteurs?search=kone&status=non_membre&family_id=4&from=2026-01-01&to=2026-06-30&sort=full_name&page=2')

    await screen.findAllByText('Visiteur page 2')
    await waitFor(() =>
      expect(lastQuery(server)).toEqual({
        search: 'kone',
        status: 'non_membre',
        family_id: '4',
        from: '2026-01-01',
        to: '2026-06-30',
        sort: 'full_name',
        page: '2',
      }),
    )
    expect(screen.getByLabelText('Rechercher')).toHaveValue('kone')
    expect(screen.getByLabelText('Statut')).toHaveValue('non_membre')
  })

  it('combine les filtres, les écrit dans l’URL et revient à la page 1', async () => {
    const server = visitorsServer('super_admin', () => ({ last_page: 5, total: 90 }))
    const { user, location } = renderAdmin('/admin/visiteurs?status=recurrent&page=3')
    await screen.findAllByText('Visiteur page 3')

    await user.selectOptions(screen.getByLabelText('Famille d’accueil'), '4')
    await waitFor(() => expect(location()).toBe('/admin/visiteurs?status=recurrent&family_id=4'))

    await user.type(screen.getByLabelText('Rechercher'), 'Koné')
    await user.click(screen.getByRole('button', { name: 'Rechercher' }))
    await waitFor(() => expect(location()).toBe('/admin/visiteurs?status=recurrent&family_id=4&search=Kon%C3%A9'))
    await waitFor(() => expect(lastQuery(server)).toMatchObject({ status: 'recurrent', family_id: '4', search: 'Koné', page: '1' }))

    await user.selectOptions(screen.getByLabelText('Statut'), 'non_membre')
    await waitFor(() => expect(lastQuery(server)).toMatchObject({ status: 'non_membre', family_id: '4', search: 'Koné' }))
  })

  it('n’interroge pas l’API si la période est incohérente', async () => {
    const server = visitorsServer()
    renderAdmin('/admin/visiteurs?from=2026-06-01&to=2026-01-01')
    expect(await screen.findByText('La date de début doit précéder la date de fin.')).toBeInTheDocument()
    expect(server.calls('GET', '/api/admin/visitors')).toHaveLength(0)
  })
})

describe('liste des visiteurs — pagination', () => {
  it('respecte les bornes et suit la page dans l’URL', async () => {
    const server = visitorsServer('super_admin', () => ({ last_page: 3, total: 45 }))
    const { user, location } = renderAdmin('/admin/visiteurs')

    const nav = await screen.findByRole('navigation', { name: 'Pagination' })
    await waitFor(() => expect(nav).toHaveTextContent('Page 1 sur 3'))
    expect(within(nav).getByRole('button', { name: 'Page précédente' })).toBeDisabled()

    await user.click(within(nav).getByRole('button', { name: 'Page suivante' }))
    await waitFor(() => expect(location()).toBe('/admin/visiteurs?page=2'))
    await waitFor(() => expect(nav).toHaveTextContent('Page 2 sur 3'))
    expect(lastQuery(server).page).toBe('2')

    await user.click(within(nav).getByRole('button', { name: 'Page suivante' }))
    await waitFor(() => expect(nav).toHaveTextContent('Page 3 sur 3'))
    expect(within(nav).getByRole('button', { name: 'Page suivante' })).toBeDisabled()
    expect(within(nav).getByRole('button', { name: 'Page précédente' })).toBeEnabled()
  })

  it('ramène une page hors bornes sur la dernière page', async () => {
    withSession(createMockServer()).on('GET', '/api/admin/visitors', (request) => {
      const page = Number(request.url.searchParams.get('page'))
      return { body: paginated(page > 3 ? [] : [makeVisitor()], { current_page: page, last_page: 3, total: 45 }) }
    })
    const { location } = renderAdmin('/admin/visiteurs?page=9')
    await waitFor(() => expect(location()).toBe('/admin/visiteurs?page=3'))
  })

  it('n’affiche jamais « page 1 sur 0 »', () => {
    render(<Pagination meta={{ current_page: 1, last_page: 0, per_page: 20, total: 0 }} noun="visiteur" onPageChange={() => {}} />)
    const nav = screen.getByRole('navigation', { name: 'Pagination' })
    expect(nav).toHaveTextContent('Page 1 sur 1')
    expect(nav).not.toHaveTextContent('sur 0')
    expect(screen.getByRole('button', { name: 'Page précédente' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Page suivante' })).toBeDisabled()
  })
})

describe('liste des visiteurs — exports', () => {
  it('propose des liens d’export contenant les filtres courants (sans pagination)', async () => {
    visitorsServer('super_admin')
    renderAdmin('/admin/visiteurs?search=kone&status=recurrent&family_id=4&page=2')

    const csv = await screen.findByRole('link', { name: 'Exporter en CSV' })
    expect(csv).toHaveAttribute('href', '/api/admin/exports/visitors.csv?search=kone&status=recurrent&family_id=4')
    expect(screen.getByRole('link', { name: 'Exporter en Excel' })).toHaveAttribute(
      'href',
      '/api/admin/exports/visitors.xlsx?search=kone&status=recurrent&family_id=4',
    )
    expect(screen.getByRole('link', { name: 'Exporter en PDF' })).toHaveAttribute(
      'href',
      '/api/admin/exports/visitors.pdf?search=kone&status=recurrent&family_id=4',
    )
  })

  it(`désactive le PDF au-delà de ${PDF_EXPORT_MAX_ROWS} lignes avec une explication accessible`, async () => {
    visitorsServer('super_admin', () => ({ total: PDF_EXPORT_MAX_ROWS + 1, last_page: 51 }))
    renderAdmin('/admin/visiteurs?status=recurrent')

    await screen.findAllByText(/^1\s001 visiteurs/)
    const pdf = screen.getByRole('link', { name: 'Exporter en PDF' })
    expect(pdf).toHaveAttribute('aria-disabled', 'true')
    expect(pdf).not.toHaveAttribute('href')
    expect(pdf).toHaveAccessibleDescription(/^Plus de 1\s000 lignes : utilisez CSV ou Excel/)
    expect(screen.getByText(/^Plus de 1\s000 lignes : utilisez CSV ou Excel/)).toBeVisible()
    // CSV et Excel restent disponibles.
    expect(screen.getByRole('link', { name: 'Exporter en CSV' })).toHaveAttribute('href')
    expect(screen.getByRole('link', { name: 'Exporter en Excel' })).toHaveAttribute('href')
  })

  it(`garde le PDF disponible jusqu’à ${PDF_EXPORT_MAX_ROWS} lignes incluses`, async () => {
    visitorsServer('super_admin', () => ({ total: PDF_EXPORT_MAX_ROWS, last_page: 50 }))
    renderAdmin('/admin/visiteurs')

    await screen.findAllByText(/^1\s000 visiteurs/)
    const pdf = screen.getByRole('link', { name: 'Exporter en PDF' })
    expect(pdf).toHaveAttribute('href', '/api/admin/exports/visitors.pdf')
    expect(pdf).not.toHaveAttribute('aria-disabled')
  })

  it('masque les exports sans l’ability visitors.export', async () => {
    visitorsServer('lecteur')
    renderAdmin('/admin/visiteurs')
    await screen.findAllByText('Visiteur page 1')
    expect(screen.queryByRole('link', { name: /Exporter en/ })).not.toBeInTheDocument()
  })
})
