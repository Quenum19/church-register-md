import { screen } from '@testing-library/react'
import { beforeEach, describe, expect, it } from 'vitest'
import { CONFIG, heading, renderPublic, setupPublicTest, type ApiMock } from './helpers'

let api: ApiMock

beforeEach(() => {
  api = setupPublicTest()
})

describe('Page /reseaux (cible du QR code posé sur les tables)', () => {
  it('liste les réseaux renseignés, en lien externe sûr', async () => {
    renderPublic('/reseaux')
    await heading('Suivez-nous')

    const facebook = await screen.findByRole('link', { name: /^Facebook/ })
    expect(facebook).toHaveAttribute('href', 'https://facebook.com/exemple')
    expect(facebook).toHaveAttribute('target', '_blank')
    expect(facebook).toHaveAttribute('rel', expect.stringContaining('noopener'))

    expect(screen.getByRole('link', { name: /^YouTube/ })).toHaveAttribute('href', 'https://youtube.com/@exemple')
    // Seuls les réseaux renseignés apparaissent.
    expect(screen.queryByRole('link', { name: /Instagram/ })).not.toBeInTheDocument()
    expect(api.callsTo('GET', '/api/public/config')).toHaveLength(1)
  })

  it('invite à s’enregistrer et ne demande aucune donnée', async () => {
    renderPublic('/reseaux')
    await heading('Suivez-nous')

    expect(screen.getByRole('link', { name: 'Enregistrez votre visite' })).toHaveAttribute('href', '/')
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })

  it('reste lisible quand aucun réseau n’est encore renseigné', async () => {
    api.set('GET', '/api/public/config', { status: 200, body: { ...CONFIG, social_networks: [] } })

    renderPublic('/reseaux')
    await heading('Suivez-nous')

    expect(await screen.findByText(/bientôt disponibles/)).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /^Facebook/ })).not.toBeInTheDocument()
  })

  it('affiche un message clair si la configuration ne se charge pas', async () => {
    api.set('GET', '/api/public/config', { status: 500, body: { message: 'Erreur' } })

    renderPublic('/reseaux')
    await heading('Suivez-nous')

    expect(await screen.findByRole('alert')).toHaveTextContent(/problème momentané|réessayer/i)
  })
})
