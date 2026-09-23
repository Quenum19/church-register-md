import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it } from 'vitest'
import {
  currentPath,
  heading,
  identifyReply,
  identifyWith,
  readJourney,
  renderPublic,
  setupPublicTest,
  type ApiMock,
} from './helpers'

let api: ApiMock

beforeEach(() => {
  api = setupPublicTest()
})

describe('Identification', () => {
  it('accepte un numéro ivoirien de 10 chiffres saisi avec des espaces', async () => {
    const user = userEvent.setup({ delay: null })
    api.on('POST', '/api/public/identify', identifyReply(1))
    renderPublic('/')

    const input = await screen.findByLabelText('Numéro de téléphone')
    expect(input).toHaveAttribute('type', 'tel')
    expect(input).toHaveAttribute('inputmode', 'tel')
    expect(input).toHaveAttribute('autocomplete', 'tel-national')
    expect(input).not.toHaveAttribute('maxlength')

    await user.type(input, '07 00 00 00 00')
    expect(input).toHaveValue('07 00 00 00 00')
    await user.click(screen.getByRole('button', { name: 'Continuer' }))

    await heading("C'est bien votre numéro ?")
    expect(screen.getByText('+225 07 00 00 00 00')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: "Oui, c'est mon numéro" }))

    await heading('Votre 1re visite')
    expect(api.callsTo('POST', '/api/public/identify')[0].body).toEqual({ country: 'CI', phone: '0700000000' })
    expect(currentPath()).toBe('/visite/1')
  })

  it('accepte aussi 10 chiffres sans espaces et plafonne au nombre de chiffres du pays', async () => {
    const user = userEvent.setup({ delay: null })
    renderPublic('/')
    const input = await screen.findByLabelText('Numéro de téléphone')
    await user.type(input, '0700000000999')
    expect(input).toHaveValue('0700000000')
  })

  it("retire l'indicatif international collé et ignore les caractères parasites", async () => {
    const user = userEvent.setup({ delay: null })
    renderPublic('/')
    const input = await screen.findByLabelText('Numéro de téléphone')
    await user.click(input)
    await user.paste('+225 07.00.00.00.00')
    expect(input).toHaveValue('07 00 00 00 00')
  })

  it('refuse un numéro trop court avec un message relié au champ', async () => {
    const user = userEvent.setup({ delay: null })
    renderPublic('/')
    const input = await screen.findByLabelText('Numéro de téléphone')
    await user.type(input, '07 00 00')
    await user.click(screen.getByRole('button', { name: 'Continuer' }))
    expect(input).toHaveAttribute('aria-invalid', 'true')
    expect(input).toHaveAccessibleDescription(expect.stringContaining('Le numéro doit comporter 10 chiffres (6 saisis).'))
    expect(input).toHaveFocus()
    expect(api.callsTo('POST', '/api/public/identify')).toHaveLength(0)
  })

  it('exige le « + » pour un numéro d’un autre pays', async () => {
    const user = userEvent.setup({ delay: null })
    api.on('POST', '/api/public/identify', identifyReply(1))
    renderPublic('/')
    await user.selectOptions(await screen.findByLabelText('Pays du numéro'), 'OTHER')
    const input = screen.getByLabelText('Numéro de téléphone')
    expect(input).toHaveAttribute('autocomplete', 'tel')
    await user.type(input, '+44 7700 900000')
    await user.click(screen.getByRole('button', { name: 'Continuer' }))
    await user.click(await screen.findByRole('button', { name: "Oui, c'est mon numéro" }))
    await heading('Votre 1re visite')
    expect(api.callsTo('POST', '/api/public/identify')[0].body).toEqual({ country: 'OTHER', phone: '+447700900000' })
  })

  it.each([
    [1, 'Votre 1re visite', '/visite/1'],
    [2, 'Votre 2e visite', '/visite/2'],
    [3, 'Votre 3e visite', '/visite/3'],
    ['complete', 'Merci pour votre fidélité !', '/parcours-complet'],
    ['done_today', 'Votre visite est déjà enregistrée', '/deja-enregistre'],
  ] as const)('route selon step = %s', async (step, title, path) => {
    const user = userEvent.setup({ delay: null })
    api.on('POST', '/api/public/identify', identifyReply(step))
    renderPublic('/')
    await identifyWith(user)
    await heading(title)
    expect(currentPath()).toBe(path)
    if (typeof step === 'number') {
      expect(readJourney()).toMatchObject({ step, token: 'jeton-1', identified: { country: 'CI' } })
    } else {
      // Rien à remplir : le numéro n'est pas conservé dans le navigateur.
      expect(readJourney()).toBeNull()
    }
  })

  it('« Non, modifier le numéro » revient à la saisie sans appeler l’API', async () => {
    const user = userEvent.setup({ delay: null })
    renderPublic('/')
    await user.type(await screen.findByLabelText('Numéro de téléphone'), '0700000000')
    await user.click(screen.getByRole('button', { name: 'Continuer' }))
    await user.click(await screen.findByRole('button', { name: 'Non, modifier le numéro' }))
    await heading('Enregistrez votre visite')
    expect(screen.getByLabelText('Numéro de téléphone')).toHaveValue('0700000000')
    expect(api.callsTo('POST', '/api/public/identify')).toHaveLength(0)
  })

  it('affiche « Trop de tentatives » avec le délai en minutes (429)', async () => {
    const user = userEvent.setup({ delay: null })
    api.on('POST', '/api/public/identify', {
      status: 429,
      body: { message: 'Too Many Attempts.', code: 'too_many_requests' },
      headers: { 'Retry-After': '120' },
    })
    renderPublic('/')
    await identifyWith(user)
    expect(await screen.findByRole('alert')).toHaveTextContent('Trop de tentatives. Réessayez dans 2 minutes.')
    expect(screen.queryByRole('button', { name: 'Réessayer' })).not.toBeInTheDocument()
  })

  it('propose « Réessayer » après une erreur réseau, puis poursuit le parcours', async () => {
    const user = userEvent.setup({ delay: null })
    api.on('POST', '/api/public/identify', 'network', identifyReply(2))
    renderPublic('/')
    await identifyWith(user)
    expect(await screen.findByRole('alert')).toHaveTextContent('Connexion impossible')
    await user.click(screen.getByRole('button', { name: 'Réessayer' }))
    await heading('Votre 2e visite')
    expect(api.callsTo('POST', '/api/public/identify')).toHaveLength(2)
  })

  it('rattache au champ le message 422 renvoyé par le serveur', async () => {
    const user = userEvent.setup({ delay: null })
    api.on('POST', '/api/public/identify', {
      status: 422,
      body: { message: 'Numéro invalide.', code: 'validation', errors: { phone: ["Ce numéro n'est pas valide."] } },
    })
    renderPublic('/')
    await identifyWith(user)
    await heading('Enregistrez votre visite')
    const input = screen.getByLabelText('Numéro de téléphone')
    await waitFor(() => expect(input).toHaveFocus())
    expect(input).toHaveAttribute('aria-invalid', 'true')
    expect(input).toHaveAccessibleDescription(expect.stringContaining("Ce numéro n'est pas valide."))
  })
})
