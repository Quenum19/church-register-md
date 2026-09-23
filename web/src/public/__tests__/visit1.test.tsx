import { screen, waitFor, within } from '@testing-library/react'
import userEvent, { type UserEvent } from '@testing-library/user-event'
import { beforeEach, describe, expect, it } from 'vitest'
import {
  currentPath,
  heading,
  renderPublic,
  seedIdentified,
  setupPublicTest,
  visitReply,
  type ApiMock,
} from './helpers'

let api: ApiMock

beforeEach(() => {
  api = setupPublicTest()
  seedIdentified(1)
})

async function fillIdentity(user: UserEvent) {
  await user.type(await screen.findByLabelText('Nom et prénoms'), 'Kouamé Jean-Pierre')
  await user.type(screen.getByLabelText('Commune de résidence'), 'Cocody')
  await user.type(screen.getByLabelText('Quartier'), 'Angré')
}

const submit = (user: UserEvent) => user.click(screen.getByRole('button', { name: 'Enregistrer ma visite' }))

function summary() {
  return screen.getByRole('region', { name: /à corriger/ })
}

describe('Formulaire de la 1re visite', () => {
  it('affiche un résumé d’erreurs focalisé et relie chaque erreur à son champ', async () => {
    const user = userEvent.setup({ delay: null })
    renderPublic('/visite/1')
    await heading('Votre 1re visite')
    await submit(user)

    const region = await screen.findByRole('region', { name: '5 points sont à corriger :' })
    expect(region).toHaveFocus()
    expect(within(region).getAllByRole('link').map((a) => a.textContent)).toEqual([
      'Indiquez votre nom et vos prénoms.',
      'Indiquez votre commune.',
      'Indiquez votre quartier.',
      "Indiquez comment vous avez connu l'Église.",
      'Votre accord est nécessaire pour enregistrer votre visite.',
    ])
    const name = screen.getByLabelText('Nom et prénoms')
    expect(name).toHaveAttribute('aria-invalid', 'true')
    expect(name).toHaveAccessibleDescription(expect.stringContaining('Indiquez votre nom et vos prénoms.'))

    await user.click(within(region).getByRole('link', { name: 'Indiquez votre quartier.' }))
    expect(screen.getByLabelText('Quartier')).toHaveFocus()
    expect(api.callsTo('POST', '/api/public/visits')).toHaveLength(0)
  })

  it('« Invité(e) par un membre » exige le nom de l’invitant et propose sa famille', async () => {
    const user = userEvent.setup({ delay: null })
    renderPublic('/visite/1')
    await heading('Votre 1re visite')
    expect(screen.queryByLabelText('Nom de la personne qui vous a invité(e)')).not.toBeInTheDocument()

    await user.click(screen.getByRole('radio', { name: 'Invité(e) par un membre' }))
    expect(screen.getByLabelText('Nom de la personne qui vous a invité(e)')).toBeInTheDocument()
    const family = await screen.findByRole('combobox', { name: /Sa famille dans l'Église/ })
    expect(within(family).getAllByRole('option').map((o) => o.textContent)).toEqual([
      'Je ne sais pas',
      'Famille Puissance',
      'Famille Force',
    ])

    await submit(user)
    expect(within(summary()).getByText('Indiquez le nom de la personne qui vous a invité(e).')).toBeInTheDocument()

    await user.click(screen.getByRole('radio', { name: 'Autre' }))
    expect(screen.queryByLabelText('Nom de la personne qui vous a invité(e)')).not.toBeInTheDocument()
    expect(screen.getByLabelText("Précisez comment vous avez connu l'Église")).toBeInTheDocument()
    await submit(user)
    expect(within(summary()).getByText("Précisez comment vous avez connu l'Église.")).toBeInTheDocument()
  })

  it('le groupe WhatsApp exige un numéro WhatsApp ou « même numéro »', async () => {
    const user = userEvent.setup({ delay: null })
    renderPublic('/visite/1')
    await heading('Votre 1re visite')
    await user.click(screen.getByRole('checkbox', { name: /rejoindre le groupe WhatsApp/ }))
    await submit(user)
    const message = 'Pour rejoindre le groupe WhatsApp, indiquez votre numéro WhatsApp ou cochez « même numéro ».'
    expect(within(summary()).getByText(message)).toBeInTheDocument()
    expect(screen.getByLabelText(/Numéro WhatsApp/)).toHaveAccessibleDescription(expect.stringContaining(message))

    // « Même numéro » masque le champ et lève l'erreur.
    await user.click(screen.getByRole('checkbox', { name: /Mon numéro WhatsApp est celui saisi à l'accueil/ }))
    expect(screen.queryByLabelText(/Numéro WhatsApp/)).not.toBeInTheDocument()
    await waitFor(() => expect(within(summary()).queryByText(message)).not.toBeInTheDocument())
  })

  it('le consentement est obligatoire et renvoie vers la mention d’information', async () => {
    const user = userEvent.setup({ delay: null })
    renderPublic('/visite/1')
    await heading('Votre 1re visite')
    const consent = screen.getByRole('checkbox', { name: /J'accepte que l'Église enregistre ces informations/ })
    expect(screen.getByRole('link', { name: "Lire la mention d'information" })).toHaveAttribute('href', '/confidentialite')
    await submit(user)
    expect(consent).toHaveAttribute('aria-invalid', 'true')
    await user.click(consent)
    await waitFor(() => expect(consent).not.toHaveAttribute('aria-invalid'))
  })

  it('envoie une visite conforme au contrat', async () => {
    const user = userEvent.setup({ delay: null })
    api.on('POST', '/api/public/visits', visitReply(1, 201, { id: 4, name: 'Force' }))
    renderPublic('/visite/1')
    await fillIdentity(user)
    await user.click(screen.getByRole('radio', { name: 'Invité(e) par un membre' }))
    await user.type(screen.getByLabelText('Nom de la personne qui vous a invité(e)'), 'Koffi Adjoua')
    await user.selectOptions(await screen.findByRole('combobox', { name: /Sa famille/ }), '4')
    await user.selectOptions(screen.getByLabelText('Pays du numéro WhatsApp'), 'FR')
    await user.type(screen.getByLabelText(/Numéro WhatsApp/), '06 12 34 56 78')
    await user.click(screen.getByRole('checkbox', { name: /rejoindre le groupe WhatsApp/ }))
    await user.click(screen.getByRole('checkbox', { name: /J'accepte/ }))
    await submit(user)

    await heading('Bienvenue parmi nous, Kouamé Jean-Pierre !')
    expect(currentPath()).toBe('/merci')
    expect(screen.getByText(/famille Force/)).toBeInTheDocument()

    const [call] = api.callsTo('POST', '/api/public/visits')
    expect(call.body).toEqual({
      session_token: 'jeton-initial',
      idempotency_key: expect.stringMatching(/^[0-9a-f-]{36}$/),
      consent: true,
      answers: {
        full_name: 'Kouamé Jean-Pierre',
        commune: 'Cocody',
        quartier: 'Angré',
        source: 'invite_membre',
        source_other: null,
        invited_by: 'Koffi Adjoua',
        inviter_family_id: 4,
        whatsapp: { country: 'FR', number: '0612345678' },
        whatsapp_same_as_phone: false,
        wants_whatsapp_group: true,
      },
    })
  })

  it('« même numéro » envoie whatsapp = null et whatsapp_same_as_phone = true', async () => {
    const user = userEvent.setup({ delay: null })
    api.on('POST', '/api/public/visits', visitReply(1))
    renderPublic('/visite/1')
    await fillIdentity(user)
    await user.click(screen.getByRole('radio', { name: 'Réseaux sociaux' }))
    await user.click(screen.getByRole('checkbox', { name: /Mon numéro WhatsApp est celui saisi/ }))
    await user.click(screen.getByRole('checkbox', { name: /rejoindre le groupe WhatsApp/ }))
    await user.click(screen.getByRole('checkbox', { name: /J'accepte/ }))
    await submit(user)
    await heading(/Bienvenue parmi nous/)
    expect(api.callsTo('POST', '/api/public/visits')[0].body?.answers).toMatchObject({
      source: 'reseaux_sociaux',
      invited_by: null,
      inviter_family_id: null,
      whatsapp: null,
      whatsapp_same_as_phone: true,
      wants_whatsapp_group: true,
    })
  })

  it('rattache les erreurs 422 du serveur aux champs (clés answers.*)', async () => {
    const user = userEvent.setup({ delay: null })
    api.on('POST', '/api/public/visits', {
      status: 422,
      body: {
        message: 'Certaines informations sont invalides.',
        code: 'validation',
        errors: { 'answers.full_name': ['Le nom contient des caractères non autorisés.'] },
      },
    })
    renderPublic('/visite/1')
    await fillIdentity(user)
    await user.click(screen.getByRole('radio', { name: 'Saint-Esprit' }))
    await user.click(screen.getByRole('checkbox', { name: /J'accepte/ }))
    await submit(user)

    const region = await screen.findByRole('region', { name: 'Un point est à corriger :' })
    await waitFor(() => expect(region).toHaveFocus())
    expect(screen.getByLabelText('Nom et prénoms')).toHaveAccessibleDescription(
      expect.stringContaining('Le nom contient des caractères non autorisés.'),
    )
    expect(currentPath()).toBe('/visite/1')
  })
})
