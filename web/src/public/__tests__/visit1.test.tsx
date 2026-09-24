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

  it('propose trois choix WhatsApp et ne révèle les champs que pour « un autre numéro »', async () => {
    const user = userEvent.setup({ delay: null })
    renderPublic('/visite/1')
    await heading('Votre 1re visite')

    const group = screen.getByRole('group', { name: 'Votre numéro WhatsApp' })
    const options = within(group).getAllByRole('radio')
    expect(options.map((r) => (r as HTMLInputElement).value)).toEqual(['same', 'other', 'none'])
    expect(options[0]).toHaveAccessibleName("Mon numéro WhatsApp est celui saisi à l'accueil : +225 07 00 00 00 00")
    expect(options[1]).toHaveAccessibleName("J'utilise un autre numéro WhatsApp")
    expect(options[2]).toHaveAccessibleName("Je n'ai pas de WhatsApp")
    // Le numéro de l'accueil est proposé par défaut : aucun champ à remplir.
    expect(within(group).getByRole('radio', { name: /celui saisi à l'accueil/ })).toBeChecked()
    expect(screen.queryByLabelText(/Numéro WhatsApp/)).not.toBeInTheDocument()

    await user.click(within(group).getByRole('radio', { name: "J'utilise un autre numéro WhatsApp" }))
    expect(screen.getByLabelText('Pays du numéro WhatsApp')).toBeInTheDocument()
    expect(screen.getByLabelText(/Numéro WhatsApp/)).toBeInTheDocument()

    await user.click(within(group).getByRole('radio', { name: "Je n'ai pas de WhatsApp" }))
    expect(screen.queryByLabelText('Pays du numéro WhatsApp')).not.toBeInTheDocument()
    expect(screen.queryByLabelText(/Numéro WhatsApp/)).not.toBeInTheDocument()
  })

  it('« Je n’ai pas de WhatsApp » est incompatible avec la demande de groupe', async () => {
    const user = userEvent.setup({ delay: null })
    renderPublic('/visite/1')
    await heading('Votre 1re visite')
    await user.click(screen.getByRole('checkbox', { name: /rejoindre le groupe WhatsApp/ }))
    const none = screen.getByRole('radio', { name: "Je n'ai pas de WhatsApp" })
    await user.click(none)
    await submit(user)

    const onChoice =
      'Pour rejoindre le groupe WhatsApp, un numéro WhatsApp est nécessaire : ' +
      'choisissez un numéro ci-dessus, ou ne demandez pas à rejoindre le groupe.'
    const region = await screen.findByRole('region', { name: /à corriger/ })
    expect(within(region).getByText(onChoice)).toBeInTheDocument()
    expect(none).toHaveAttribute('aria-invalid', 'true')
    expect(none).toHaveAccessibleDescription(expect.stringContaining(onChoice))
    // Le résumé renvoie sur l'option cochée, pas sur un champ masqué.
    await user.click(within(region).getByRole('link', { name: onChoice }))
    expect(none).toHaveFocus()

    // « Un autre numéro » lève l'erreur du groupe et la reporte sur le champ numéro.
    await user.click(screen.getByRole('radio', { name: "J'utilise un autre numéro WhatsApp" }))
    const onNumber = 'Pour rejoindre le groupe WhatsApp, indiquez votre numéro WhatsApp.'
    await waitFor(() => expect(within(summary()).getByText(onNumber)).toBeInTheDocument())
    expect(within(summary()).queryByText(onChoice)).not.toBeInTheDocument()
    expect(screen.getByLabelText(/Numéro WhatsApp/)).toHaveAccessibleDescription(expect.stringContaining(onNumber))

    await user.type(screen.getByLabelText(/Numéro WhatsApp/), '05 11 22 33 44')
    await waitFor(() => expect(within(summary()).queryByText(onNumber)).not.toBeInTheDocument())
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
    await user.click(screen.getByRole('radio', { name: "J'utilise un autre numéro WhatsApp" }))
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

  it('« le numéro de l’accueil » envoie whatsapp = null et whatsapp_same_as_phone = true', async () => {
    const user = userEvent.setup({ delay: null })
    api.on('POST', '/api/public/visits', visitReply(1))
    renderPublic('/visite/1')
    await fillIdentity(user)
    await user.click(screen.getByRole('radio', { name: 'Réseaux sociaux' }))
    await user.click(screen.getByRole('radio', { name: /Mon numéro WhatsApp est celui saisi/ }))
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

  it.each(["J'utilise un autre numéro WhatsApp", "Je n'ai pas de WhatsApp"])(
    '« %s » sans numéro saisi n’enregistre aucun WhatsApp',
    async (choice) => {
      const user = userEvent.setup({ delay: null })
      api.on('POST', '/api/public/visits', visitReply(1))
      renderPublic('/visite/1')
      await fillIdentity(user)
      await user.click(screen.getByRole('radio', { name: 'Bouche-à-oreille' }))
      await user.click(screen.getByRole('radio', { name: choice }))
      await user.click(screen.getByRole('checkbox', { name: /J'accepte/ }))
      await submit(user)
      await heading(/Bienvenue parmi nous/)
      expect(api.callsTo('POST', '/api/public/visits')[0].body?.answers).toMatchObject({
        whatsapp: null,
        whatsapp_same_as_phone: false,
        wants_whatsapp_group: false,
      })
    },
  )

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
