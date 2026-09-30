import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ChurchEvent } from '../../shared/api-types'
import type { Role } from '../../shared/domain'
import { slugify } from '../lib/slug'
import { makeEvent, makeVisitor, makeVisitorDetail, paginated, withSession } from '../test/fixtures'
import { renderAdmin } from '../test/render'
import { createMockServer } from '../test/server'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

/** Serveur simulé dont la liste d'événements suit les modifications enregistrées. */
function eventsServer(events: ChurchEvent[] = [makeEvent()], role: Role = 'super_admin') {
  const state = { events }
  const server = withSession(createMockServer(), role)
  server.on('GET', '/api/admin/events', () => ({ body: { data: state.events } }))
  return { server, state }
}

const table = () => screen.getByRole('table')
/** Attend l'affichage de la liste puis renvoie une requête limitée au tableau. */
const eventRows = async () => within(await screen.findByRole('table'))
const openDialog = () => screen.getByRole('dialog')

describe('événements — création', () => {
  it('propose le lien d’après le nom, le laisse modifier, puis crée l’événement', async () => {
    const { server } = eventsServer([])
    server.on('POST', '/api/admin/events', () => ({ status: 201, body: { data: makeEvent() } }))
    const { user } = renderAdmin('/admin/evenements')

    await user.click(await screen.findByRole('button', { name: 'Créer un événement' }))
    const dialog = openDialog()
    await user.type(within(dialog).getByLabelText('Nom de l’événement'), 'Évangélisation du 4 octobre')

    // Lien proposé automatiquement (accents et espaces normalisés).
    const slug = within(dialog).getByLabelText('Adresse du lien')
    await waitFor(() => expect(slug).toHaveValue('evangelisation-du-4-octobre'))

    // …puis modifié à la main : la proposition ne reprend pas la main.
    await user.clear(slug)
    await user.type(slug, 'evangelisation-4-octobre')
    await user.type(within(dialog).getByLabelText('Nom de l’événement'), ' 2026')
    expect(slug).toHaveValue('evangelisation-4-octobre')

    fireEvent.change(within(dialog).getByLabelText(/Date du culte/), { target: { value: '2026-10-04' } })
    await user.click(within(dialog).getByRole('button', { name: 'Créer l’événement' }))

    await waitFor(() =>
      expect(server.last('POST', '/api/admin/events')?.body).toEqual({
        name: 'Évangélisation du 4 octobre 2026',
        slug: 'evangelisation-4-octobre',
        event_date: '2026-10-04',
        active: true,
      }),
    )
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('refuse un lien invalide sans appeler l’API', async () => {
    const { server } = eventsServer([])
    const { user } = renderAdmin('/admin/evenements')

    await user.click(await screen.findByRole('button', { name: 'Créer un événement' }))
    const dialog = openDialog()
    await user.type(within(dialog).getByLabelText('Nom de l’événement'), 'Soirée de louange')
    const slug = within(dialog).getByLabelText('Adresse du lien')
    await user.clear(slug)
    await user.type(slug, 'Soirée Louange')
    await user.click(within(dialog).getByRole('button', { name: 'Créer l’événement' }))

    expect(await within(dialog).findByText(/ne peut contenir que des lettres minuscules/)).toBeInTheDocument()
    expect(server.calls('POST', '/api/admin/events')).toHaveLength(0)
  })

  it('affiche l’erreur 422 du serveur sur le champ « Adresse du lien » quand le lien est déjà pris', async () => {
    const { server } = eventsServer([])
    server.on('POST', '/api/admin/events', {
      status: 422,
      body: {
        message: 'Certaines informations sont invalides.',
        code: 'validation',
        errors: { slug: ['Ce lien est déjà utilisé par un autre événement.'] },
      },
    })
    const { user } = renderAdmin('/admin/evenements')

    await user.click(await screen.findByRole('button', { name: 'Créer un événement' }))
    const dialog = openDialog()
    await user.type(within(dialog).getByLabelText('Nom de l’événement'), 'Évangélisation du 4 octobre')
    await user.click(within(dialog).getByRole('button', { name: 'Créer l’événement' }))

    expect(await within(dialog).findByText('Ce lien est déjà utilisé par un autre événement.')).toBeInTheDocument()
    expect(within(dialog).getByLabelText('Adresse du lien')).toHaveAccessibleDescription(/Ce lien est déjà utilisé/)
    // Le dialogue reste ouvert : la saisie n'est pas perdue.
    expect(within(dialog).getByLabelText('Nom de l’événement')).toHaveValue('Évangélisation du 4 octobre')
  })
})

describe('événements — modification', () => {
  it('avertit que les QR codes imprimés cesseront de fonctionner et n’envoie que les champs modifiés', async () => {
    const used = makeEvent({ visits_count: 15, visitors_count: 12 })
    const { server } = eventsServer([used])
    server.on('PATCH', '/api/admin/events/7', () => ({ body: { data: { ...used, slug: 'evangelisation-octobre' } } }))
    const { user } = renderAdmin('/admin/evenements')

    await user.click((await eventRows()).getByRole('button', { name: /^Modifier/ }))
    const dialog = openDialog()
    const slug = within(dialog).getByLabelText('Adresse du lien')
    expect(slug).toHaveValue('evangelisation-4-octobre')
    expect(within(dialog).queryByRole('alert')).not.toBeInTheDocument()

    await user.clear(slug)
    await user.type(slug, 'evangelisation-octobre')

    const warning = await within(dialog).findByRole('alert')
    expect(warning).toHaveTextContent(/12 personnes se sont déjà inscrites par le lien \/e\/evangelisation-4-octobre/)
    expect(warning).toHaveTextContent(/les QR codes et les affiches déjà imprimés cesseront de fonctionner/)

    await user.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))
    await waitFor(() => expect(server.last('PATCH', '/api/admin/events/7')?.body).toEqual({ slug: 'evangelisation-octobre' }))
  })

  it('n’avertit pas pour un événement sans inscription', async () => {
    eventsServer([makeEvent({ visits_count: 0, visitors_count: 0 })])
    const { user } = renderAdmin('/admin/evenements')

    await user.click((await eventRows()).getByRole('button', { name: /^Modifier/ }))
    const dialog = openDialog()
    await user.clear(within(dialog).getByLabelText('Adresse du lien'))
    await user.type(within(dialog).getByLabelText('Adresse du lien'), 'autre-lien')
    expect(within(dialog).queryByRole('alert')).not.toBeInTheDocument()
  })
})

describe('événements — activation et suppression', () => {
  it('désactive puis réactive le lien', async () => {
    const { server, state } = eventsServer([makeEvent()])
    server.on('PATCH', '/api/admin/events/7', (request) => {
      const body = request.body as { active: boolean }
      state.events = state.events.map((e) => ({ ...e, active: body.active }))
      return { body: { data: state.events[0] } }
    })
    const { user } = renderAdmin('/admin/evenements')

    await user.click((await eventRows()).getByRole('button', { name: /^Désactiver/ }))
    await waitFor(() => expect(server.last('PATCH', '/api/admin/events/7')?.body).toEqual({ active: false }))
    expect(await within(table()).findByText('Lien désactivé')).toBeInTheDocument()

    await user.click(within(table()).getByRole('button', { name: /^Activer/ }))
    await waitFor(() => expect(server.last('PATCH', '/api/admin/events/7')?.body).toEqual({ active: true }))
    expect(await within(table()).findByText('Lien actif')).toBeInTheDocument()
  })

  it('explique le refus 409 « event_has_visits » et invite à désactiver le lien', async () => {
    const { server } = eventsServer([makeEvent({ visits_count: 15, visitors_count: 12 })])
    server.on('DELETE', '/api/admin/events/7', {
      status: 409,
      body: { message: 'Des visites sont rattachées à cet événement.', code: 'event_has_visits' },
    })
    const { user } = renderAdmin('/admin/evenements')

    await user.click((await eventRows()).getByRole('button', { name: /^Supprimer/ }))
    const confirm = screen.getByRole('alertdialog')
    expect(confirm).toHaveTextContent(/la suppression sera refusée/)

    await user.click(within(confirm).getByRole('button', { name: 'Supprimer l’événement' }))
    expect(await within(confirm).findByRole('alert')).toHaveTextContent(
      /Des inscriptions sont déjà rattachées à cet événement.*Désactivez-le plutôt/,
    )
    // L'événement est toujours là.
    expect(within(table()).getByRole('rowheader', { name: /Évangélisation du 4 octobre/ })).toBeInTheDocument()
  })

  it('supprime un événement sans visite après confirmation', async () => {
    const { server, state } = eventsServer([makeEvent()])
    server.on('DELETE', '/api/admin/events/7', () => {
      state.events = []
      return { status: 204 }
    })
    const { user } = renderAdmin('/admin/evenements')

    await user.click((await eventRows()).getByRole('button', { name: /^Supprimer/ }))
    const confirm = screen.getByRole('alertdialog')
    expect(confirm).toHaveTextContent(/Cette action est irréversible/)
    await user.click(within(confirm).getByRole('button', { name: 'Supprimer l’événement' }))

    expect(await screen.findByText('Aucun événement')).toBeInTheDocument()
  })
})

describe('événements — lien et affiche', () => {
  it('copie le lien dédié dans le presse-papiers', async () => {
    eventsServer([makeEvent()])
    const { user } = renderAdmin('/admin/evenements')

    await user.click((await eventRows()).getByRole('button', { name: /^Copier le lien/ }))
    await waitFor(async () =>
      expect(await navigator.clipboard.readText()).toBe('https://registre.newinechurch.org/e/evangelisation-4-octobre'),
    )
    expect(await screen.findByText('Lien copié dans le presse-papiers.')).toBeInTheDocument()
  })

  it('affiche le QR code de l’événement et permet d’imprimer l’affiche à son nom', async () => {
    eventsServer([makeEvent()])
    const print = vi.spyOn(window, 'print').mockImplementation(() => {})
    const { user } = renderAdmin('/admin/evenements')

    await user.click((await eventRows()).getByRole('button', { name: /^QR code/ }))
    const poster = await screen.findByRole('article', { name: 'Aperçu de l’affiche' })
    expect(within(poster).getByText('Évangélisation du 4 octobre')).toBeInTheDocument()
    expect(within(poster).getByText(/4 octobre 2026/)).toBeInTheDocument()
    expect(
      await within(poster).findByRole('img', { name: 'QR code vers https://registre.newinechurch.org/e/evangelisation-4-octobre' }),
    ).toBeInTheDocument()

    const printButton = screen.getByRole('button', { name: 'Imprimer l’affiche' })
    await waitFor(() => expect(printButton).toBeEnabled())
    await user.click(printButton)
    expect(print).toHaveBeenCalled()
    expect(document.documentElement.dataset.printPoster).toBe('')
  })

  it('propose la fiche d’inscription papier en 2 fiches par page, et en 1 par page', async () => {
    eventsServer([makeEvent()])
    renderAdmin('/admin/evenements')

    const group = within(await screen.findByRole('table')).getByRole('group', {
      name: 'Fiche d’inscription papier de Évangélisation du 4 octobre',
    })

    const two = within(group).getByRole('link', { name: /^Fiche papier \(PDF\)/ })
    expect(two).toHaveAttribute('href', '/api/admin/events/7/formulaire.pdf?par_page=2')
    expect(two).toHaveAttribute('download')

    const one = within(group).getByRole('link', { name: '1 par page' })
    expect(one).toHaveAttribute('href', '/api/admin/events/7/formulaire.pdf?par_page=1')
    expect(one).toHaveAttribute('download')
  })
})

describe('événements — droits', () => {
  it('masque création, modification, activation et suppression sans l’ability events.manage', async () => {
    eventsServer([makeEvent()], 'lecteur')
    renderAdmin('/admin/evenements')

    expect((await eventRows()).getByText('Évangélisation du 4 octobre')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Créer un événement' })).not.toBeInTheDocument()
    expect(within(table()).queryByRole('button', { name: /^Modifier/ })).not.toBeInTheDocument()
    expect(within(table()).queryByRole('button', { name: /^Désactiver/ })).not.toBeInTheDocument()
    expect(within(table()).queryByRole('button', { name: /^Supprimer/ })).not.toBeInTheDocument()
    // La consultation reste possible.
    expect(within(table()).getByRole('button', { name: /^Copier le lien/ })).toBeInTheDocument()
    expect(within(table()).getByRole('button', { name: /^QR code/ })).toBeInTheDocument()
    // La fiche papier suit l'ability de lecture (visitors.view), pas events.manage.
    expect(within(table()).getByRole('link', { name: /^Fiche papier \(PDF\)/ })).toBeInTheDocument()
  })
})

describe('liste des visiteurs — filtre par événement', () => {
  it('écrit event_id dans l’URL, l’envoie à l’API et le transmet aux exports', async () => {
    const server = withSession(createMockServer())
      .on('GET', '/api/admin/events', { body: { data: [makeEvent()] } })
      .on('GET', '/api/admin/visitors', { body: paginated([makeVisitor()]) })
    const { user, location } = renderAdmin('/admin/visiteurs?status=recurrent')
    await screen.findAllByText('Awa Koné')

    const select = screen.getByLabelText('Événement')
    await waitFor(() => expect(within(select).getByRole('option', { name: 'Évangélisation du 4 octobre' })).toBeInTheDocument())
    await user.selectOptions(select, '7')

    await waitFor(() => expect(location()).toBe('/admin/visiteurs?status=recurrent&event_id=7'))
    await waitFor(() =>
      expect(Object.fromEntries(server.last('GET', '/api/admin/visitors')?.url.searchParams ?? [])).toMatchObject({
        status: 'recurrent',
        event_id: '7',
      }),
    )
    expect(screen.getByRole('link', { name: 'Exporter en CSV' })).toHaveAttribute(
      'href',
      '/api/admin/exports/visitors.csv?status=recurrent&event_id=7',
    )
  })

  it('conserve un event_id inconnu présent dans l’URL', async () => {
    withSession(createMockServer())
      .on('GET', '/api/admin/events', { body: { data: [] } })
      .on('GET', '/api/admin/visitors', { body: paginated([makeVisitor()]) })
    renderAdmin('/admin/visiteurs?event_id=42')

    const select = await screen.findByLabelText('Événement')
    expect(select).toHaveValue('42')
    expect(within(select).getByRole('option', { name: 'Événement n° 42' })).toBeInTheDocument()
  })
})

describe('fiche visiteur — événement d’inscription', () => {
  it('indique l’événement sur la visite concernée', async () => {
    const detail = makeVisitorDetail({
      visits: [
        {
          id: 10,
          visit_number: 1,
          visit_date: '2026-10-04',
          family: null,
          answers: {},
          event: { id: 7, name: 'Évangélisation du 4 octobre', slug: 'evangelisation-4-octobre' },
        },
        { id: 11, visit_number: 2, visit_date: '2026-10-11', family: null, answers: {}, event: null },
      ],
    })
    withSession(createMockServer()).on('GET', '/api/admin/visitors/5', { body: { data: detail } })
    renderAdmin('/admin/visiteurs/5')

    const first = await screen.findByRole('heading', { level: 3, name: /Première visite/ })
    expect(first.closest('li')).toHaveTextContent('Inscrit lors de : Évangélisation du 4 octobre')

    const second = screen.getByRole('heading', { level: 3, name: /Deuxième visite/ })
    expect(second.closest('li')).not.toHaveTextContent('Inscrit lors de')
  })
})

describe('proposition de lien', () => {
  it('normalise accents, ligatures et ponctuation', () => {
    expect(slugify('Évangélisation du 4 octobre')).toBe('evangelisation-du-4-octobre')
    expect(slugify('Sœur & Frère : soirée !')).toBe('soeur-frere-soiree')
    expect(slugify('  ---  ')).toBe('')
    expect(slugify('A'.repeat(80))).toHaveLength(60)
  })
})
