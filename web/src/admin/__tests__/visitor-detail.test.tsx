import { screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { VisitorDetail } from '../../shared/api-types'
import type { Role } from '../../shared/domain'
import { makeVisitorDetail, withSession } from '../test/fixtures'
import { renderAdmin } from '../test/render'
import { createMockServer } from '../test/server'

afterEach(() => {
  vi.unstubAllGlobals()
})

function detailServer(role: Role, overrides: Partial<VisitorDetail> = {}) {
  const detail = makeVisitorDetail(overrides)
  const server = withSession(createMockServer(), role).on('GET', '/api/admin/visitors/5', { body: { data: detail } })
  return { server, detail }
}

describe('fiche visiteur — droits', () => {
  it('masque les actions d’écriture pour un lecteur', async () => {
    detailServer('lecteur')
    renderAdmin('/admin/visiteurs/5')

    expect(await screen.findByRole('heading', { level: 1, name: 'Awa Koné' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Modifier/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Convertir en membre/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Supprimer/ })).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Nouvelle note')).not.toBeInTheDocument()
  })

  it('permet au modérateur de modifier et d’annoter, sans convertir ni supprimer', async () => {
    detailServer('moderateur')
    renderAdmin('/admin/visiteurs/5')

    expect(await screen.findByRole('button', { name: /Modifier/ })).toBeInTheDocument()
    expect(screen.getByLabelText('Nouvelle note')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Convertir en membre/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Supprimer/ })).not.toBeInTheDocument()
  })
})

describe('fiche visiteur — conversion', () => {
  it('désactive la conversion tant que le statut n’est pas « membre potentiel »', async () => {
    detailServer('super_admin', { status: 'recurrent' })
    renderAdmin('/admin/visiteurs/5')

    const button = await screen.findByRole('button', { name: /Convertir en membre/ })
    expect(button).toBeDisabled()
    expect(button).toHaveAccessibleDescription(/Conversion possible après la 3e visite/)
  })

  it('convertit un membre potentiel après confirmation', async () => {
    const { server, detail } = detailServer('super_admin', { status: 'membre_potentiel', visit_count: 3 })
    server.on('POST', '/api/admin/visitors/5/convert', {
      body: {
        data: {
          ...detail,
          status: 'membre',
          member: { converted_at: '2026-09-22T10:00:00+00:00', converted_by: { id: 1, name: 'Jean Kouassi' } },
        },
      },
    })
    const { user } = renderAdmin('/admin/visiteurs/5')

    await user.click(await screen.findByRole('button', { name: /Convertir en membre/ }))
    const dialog = screen.getByRole('alertdialog', { name: 'Convertir en membre ?' })
    await user.click(within(dialog).getByRole('button', { name: 'Convertir en membre' }))

    expect(await screen.findByText('Awa Koné est désormais membre.')).toBeInTheDocument()
    expect(server.calls('POST', '/api/admin/visitors/5/convert')).toHaveLength(1)
    expect(screen.getByRole('button', { name: 'Annuler la conversion' })).toBeInTheDocument()
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  })

  it('affiche le refus du serveur (409 not_eligible) dans le dialogue', async () => {
    const { server } = detailServer('super_admin', { status: 'membre_potentiel' })
    server.on('POST', '/api/admin/visitors/5/convert', { status: 409, body: { message: 'Conflit.', code: 'not_eligible' } })
    const { user } = renderAdmin('/admin/visiteurs/5')

    await user.click(await screen.findByRole('button', { name: /Convertir en membre/ }))
    await user.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Convertir en membre' }))
    expect(await within(screen.getByRole('alertdialog')).findByRole('alert')).toHaveTextContent(/Membre potentiel/)
  })
})

describe('fiche visiteur — contenu et navigation', () => {
  it('traduit les réponses des visites en libellés', async () => {
    detailServer('lecteur')
    renderAdmin('/admin/visiteurs/5')
    expect(await screen.findByText("L'enseignement de la Parole, L'accueil reçu")).toBeInTheDocument()
    expect(screen.getByText('Invité(e) par un membre')).toBeInTheDocument()
  })

  it('ramène à la liste d’origine avec ses filtres', async () => {
    detailServer('lecteur')
    renderAdmin({
      pathname: '/admin/visiteurs/5',
      state: { from: '/admin/membres?search=kone&page=2', label: 'la liste des membres' },
    })
    const back = await screen.findByRole('link', { name: 'Retour à la liste des membres' })
    expect(back).toHaveAttribute('href', '/admin/membres?search=kone&page=2')
  })

  it('ignore une origine externe et revient à la liste des visiteurs', async () => {
    detailServer('lecteur')
    renderAdmin({ pathname: '/admin/visiteurs/5', state: { from: 'https://exemple.com', label: 'ailleurs' } })
    expect(await screen.findByRole('link', { name: 'Retour à la liste des visiteurs' })).toHaveAttribute('href', '/admin/visiteurs')
  })

  it('ajoute une note, même après un CSRF expiré (419 → un seul nouvel essai)', async () => {
    const { server } = detailServer('moderateur')
    let attempts = 0
    server.on('POST', '/api/admin/visitors/5/notes', (request) => {
      attempts++
      if (attempts === 1) return { status: 419, body: { message: 'CSRF token mismatch.' } }
      return {
        status: 201,
        body: {
          data: {
            id: 99,
            body: (request.body as { body: string }).body,
            author: { id: 1, name: 'Jean Kouassi' },
            created_at: '2026-09-22T10:00:00+00:00',
            can_delete: true,
          },
        },
      }
    })
    const { user } = renderAdmin('/admin/visiteurs/5')

    await user.type(await screen.findByLabelText('Nouvelle note'), 'Appelée le lundi.')
    await user.click(screen.getByRole('button', { name: 'Ajouter la note' }))

    expect(await screen.findByText('Appelée le lundi.')).toBeInTheDocument()
    expect(server.calls('POST', '/api/admin/visitors/5/notes')).toHaveLength(2)
    expect(server.calls('GET', '/sanctum/csrf-cookie')).toHaveLength(1)
    await waitFor(() => expect(screen.getByLabelText('Nouvelle note')).toHaveValue(''))
  })

  it('affiche une page introuvable pour un visiteur supprimé (404)', async () => {
    withSession(createMockServer(), 'lecteur').on('GET', '/api/admin/visitors/77', {
      status: 404,
      body: { message: 'Introuvable.', code: 'not_found' },
    })
    renderAdmin('/admin/visiteurs/77')
    expect(await screen.findByRole('heading', { level: 1, name: 'Page introuvable' })).toBeInTheDocument()
  })
})
