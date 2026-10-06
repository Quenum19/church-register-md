// Variante « événement » : lien dédié /e/{slug}, identification avec le nom de
// l'événement, formulaire de première visite allégé. Visites 2 et 3 : parcours habituel.

import { screen, waitFor, within } from '@testing-library/react'
import userEvent, { type UserEvent } from '@testing-library/user-event'
import { beforeEach, describe, expect, it } from 'vitest'
import {
  currentPath,
  EVENT,
  EVENT_PATH,
  heading,
  identifyReply,
  identifyWith,
  readJourney,
  renderPublic,
  seedIdentified,
  setupPublicTest,
  visitReply,
  type ApiMock,
} from './helpers'

let api: ApiMock

const EVENT_HOME = `/e/${EVENT.slug}`
const EVENT_VISIT1 = `${EVENT_HOME}/visite/1`

beforeEach(() => {
  api = setupPublicTest()
  api.on('GET', EVENT_PATH, { status: 200, body: EVENT })
})

const submit = (user: UserEvent) => user.click(screen.getByRole('button', { name: 'Enregistrer ma visite' }))

async function fillIdentity(user: UserEvent) {
  await user.type(await screen.findByLabelText('Nom et prénoms'), 'Kouamé Jean-Pierre')
  await user.type(screen.getByLabelText('Commune de résidence'), 'Cocody')
  await user.type(screen.getByLabelText('Quartier'), 'Angré')
}

function sourceGroup() {
  return screen.getByRole('group', { name: "Comment avez-vous connu l'Église ?" })
}

function whatsappCheckbox() {
  return screen.getByRole('checkbox', { name: /Rejoindre le Groupe WhatsApp des nouvelles personnes/ })
}

/** Remplit le minimum valide du formulaire allégé (identité, origine, consentement). */
async function fillMinimalEventForm(user: UserEvent) {
  await fillIdentity(user)
  await user.click(within(sourceGroup()).getByRole('radio', { name: 'Autre' }))
  await user.type(screen.getByLabelText("Précisez comment vous avez connu l'Église"), 'Une affiche au marché')
  await user.click(screen.getByRole('checkbox', { name: /J'accepte/ }))
}

describe('Lien de culte spécial', () => {
  it('affiche le nom de l’événement et l’envoie à identify, puis ouvre la variante allégée', async () => {
    const user = userEvent.setup({ delay: null })
    api.on('POST', '/api/public/identify', identifyReply(1))
    renderPublic(EVENT_HOME)

    await heading('Enregistrez votre visite')
    expect(screen.getByText(new RegExp(EVENT.name))).toBeInTheDocument()

    await identifyWith(user)
    await heading('Votre première visite')
    expect(currentPath()).toBe(EVENT_VISIT1)
    expect(api.callsTo('POST', '/api/public/identify')[0].body).toEqual({
      country: 'CI',
      phone: '0700000000',
      event: EVENT.slug,
    })
    // Mémorisé dans l'état du parcours, comme le reste.
    expect(readJourney()).toMatchObject({ step: 1, event: { slug: EVENT.slug, name: EVENT.name } })
    // Le nom de l'événement reste visible sur le formulaire.
    expect(screen.getByText(new RegExp(EVENT.name))).toBeInTheDocument()
  })

  it('garde le nom de l’événement sur l’écran de confirmation du numéro', async () => {
    const user = userEvent.setup({ delay: null })
    renderPublic(EVENT_HOME)
    await user.type(await screen.findByLabelText('Numéro de téléphone'), '07 00 00 00 00')
    await user.click(screen.getByRole('button', { name: 'Continuer' }))
    await heading("C'est bien votre numéro ?")
    expect(screen.getByText(new RegExp(EVENT.name))).toBeInTheDocument()
    expect(currentPath()).toBe(EVENT_HOME)
  })

  it('slug inconnu ou événement inactif : « Ce lien n’est plus actif » et lien vers le formulaire habituel', async () => {
    const user = userEvent.setup({ delay: null })
    api.set('GET', '/api/public/events/culte-inconnu', {
      status: 404,
      body: { message: 'Élément introuvable.', code: 'not_found' },
    })
    renderPublic('/e/culte-inconnu')

    await heading("Ce lien n'est plus actif")
    const link = screen.getByRole('link', { name: 'Enregistrer ma visite' })
    expect(link).toHaveAttribute('href', '/')
    expect(screen.queryByLabelText('Numéro de téléphone')).not.toBeInTheDocument()

    await user.click(link)
    await heading('Enregistrez votre visite')
    expect(currentPath()).toBe('/')
  })

  it('panne réseau : propose de réessayer sans déclarer le lien fermé', async () => {
    const user = userEvent.setup({ delay: null })
    api.set('GET', EVENT_PATH, 'network', { status: 200, body: EVENT })
    renderPublic(EVENT_HOME)

    await heading("Ce lien n'a pas pu être ouvert")
    expect(await screen.findByRole('alert')).toHaveTextContent('Connexion impossible')
    await user.click(screen.getByRole('button', { name: 'Réessayer' }))
    await heading('Enregistrez votre visite')
    expect(screen.getByText(new RegExp(EVENT.name))).toBeInTheDocument()
  })

  it('accès direct au formulaire allégé sans parcours → accueil de l’événement', async () => {
    renderPublic(EVENT_VISIT1)
    await heading('Enregistrez votre visite')
    expect(currentPath()).toBe(EVENT_HOME)
  })

  it('n’envoie pas l’événement depuis l’accueil habituel', async () => {
    const user = userEvent.setup({ delay: null })
    api.on('POST', '/api/public/identify', identifyReply(1))
    seedIdentified(1, { identified: null, step: null, token: null, event: { slug: EVENT.slug, name: EVENT.name } })
    renderPublic('/')
    await identifyWith(user)
    await heading('Votre première visite')
    expect(api.callsTo('POST', '/api/public/identify')[0].body).toEqual({ country: 'CI', phone: '0700000000' })
  })
})

describe('Formulaire allégé de la première visite', () => {
  beforeEach(() => {
    seedIdentified(1, { event: { slug: EVENT.slug, name: EVENT.name } })
  })

  it('ne propose que deux origines', async () => {
    renderPublic(EVENT_VISIT1)
    await heading('Votre première visite')
    const options = within(sourceGroup()).getAllByRole('radio')
    expect(options.map((o) => (o as HTMLInputElement).value)).toEqual(['invite_membre', 'autre'])
    expect(options.map((o) => o.getAttribute('aria-hidden'))).toEqual([null, null])
    expect(within(sourceGroup()).getByRole('radio', { name: 'Invité(e) par un membre' })).toBeInTheDocument()
    expect(within(sourceGroup()).getByRole('radio', { name: 'Autre' })).toBeInTheDocument()
    expect(screen.queryByRole('radio', { name: 'Saint-Esprit' })).not.toBeInTheDocument()
    expect(screen.queryByRole('radio', { name: 'Réseaux sociaux' })).not.toBeInTheDocument()
  })

  it('« Invité(e) par un membre » demande le nom de l’invitant et sa famille, « Autre » une précision', async () => {
    const user = userEvent.setup({ delay: null })
    renderPublic(EVENT_VISIT1)
    await heading('Votre première visite')

    await user.click(within(sourceGroup()).getByRole('radio', { name: 'Invité(e) par un membre' }))
    expect(screen.getByLabelText('Nom de la personne qui vous a invité(e)')).toBeInTheDocument()
    const family = await screen.findByRole('combobox', { name: /Sa famille dans l'Église/ })
    expect(within(family).getAllByRole('option').map((o) => o.textContent)).toEqual([
      'Je ne sais pas',
      'Famille Puissance',
      'Famille Force',
    ])

    await user.click(within(sourceGroup()).getByRole('radio', { name: 'Autre' }))
    expect(screen.queryByLabelText('Nom de la personne qui vous a invité(e)')).not.toBeInTheDocument()
    await submit(user)
    const region = await screen.findByRole('region', { name: /à corriger/ })
    expect(within(region).getByText("Précisez comment vous avez connu l'Église.")).toBeInTheDocument()
  })

  it('une seule case WhatsApp : ni les trois options, ni la ligne d’aide', async () => {
    renderPublic(EVENT_VISIT1)
    await heading('Votre première visite')

    expect(whatsappCheckbox()).not.toBeChecked()
    expect(screen.queryByRole('group', { name: 'Votre numéro WhatsApp' })).not.toBeInTheDocument()
    expect(screen.queryByRole('radio', { name: /celui saisi à l'accueil/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('radio', { name: "J'utilise un autre numéro WhatsApp" })).not.toBeInTheDocument()
    expect(screen.queryByRole('radio', { name: "Je n'ai pas de WhatsApp" })).not.toBeInTheDocument()
    expect(screen.queryByText(/Facultatif, sauf pour rejoindre le groupe WhatsApp/)).not.toBeInTheDocument()
    // Case du groupe et case de consentement, rien d'autre.
    expect(screen.getAllByRole('checkbox')).toHaveLength(2)
  })

  it('la case cochée révèle un autre numéro WhatsApp, facultatif', async () => {
    const user = userEvent.setup({ delay: null })
    renderPublic(EVENT_VISIT1)
    await heading('Votre première visite')
    expect(screen.queryByLabelText(/Autre numéro WhatsApp/)).not.toBeInTheDocument()

    await user.click(whatsappCheckbox())
    const number = screen.getByLabelText(/Autre numéro WhatsApp/)
    expect(number).toHaveAccessibleName('Autre numéro WhatsApp(facultatif)')
    expect(number).not.toHaveAttribute('aria-required')
    expect(screen.getByLabelText('Pays du numéro WhatsApp')).toBeInTheDocument()
    expect(screen.getByText(/Nous utiliserons le numéro saisi à l'accueil/)).toHaveTextContent('+225 07 00 00 00 00')

    // Laissé vide, il n'empêche pas l'envoi (contrairement au formulaire habituel).
    await fillIdentity(user)
    await user.click(within(sourceGroup()).getByRole('radio', { name: 'Autre' }))
    await user.type(screen.getByLabelText("Précisez comment vous avez connu l'Église"), 'Une affiche')
    await submit(user)
    const region = await screen.findByRole('region', { name: /à corriger/ })
    expect(within(region).getAllByRole('link').map((a) => a.textContent)).toEqual([
      'Votre accord est nécessaire pour enregistrer votre visite.',
    ])

    // Saisi, il reste validé comme un numéro.
    await user.type(number, '06')
    await submit(user)
    await waitFor(() =>
      expect(screen.getByRole('region', { name: /à corriger/ })).toHaveTextContent(
        'Le numéro WhatsApp doit comporter 10 chiffres (2 saisis).',
      ),
    )
    await user.clear(number)
    await waitFor(() =>
      expect(screen.getByRole('region', { name: /à corriger/ })).not.toHaveTextContent('Le numéro WhatsApp doit comporter'),
    )
  })

  it('le consentement reste obligatoire, avec le même texte', async () => {
    const user = userEvent.setup({ delay: null })
    renderPublic(EVENT_VISIT1)
    await heading('Votre première visite')
    const consent = screen.getByRole('checkbox', {
      name: "J'accepte que l'Église enregistre ces informations pour assurer mon suivi pastoral.",
    })
    expect(screen.getByRole('link', { name: "Lire la mention d'information" })).toHaveAttribute('href', '/confidentialite')
    await submit(user)
    expect(consent).toHaveAttribute('aria-invalid', 'true')
    const region = await screen.findByRole('region', { name: /à corriger/ })
    expect(within(region).getByText('Votre accord est nécessaire pour enregistrer votre visite.')).toBeInTheDocument()
    await user.click(consent)
    await waitFor(() => expect(consent).not.toHaveAttribute('aria-invalid'))
  })

  it('groupe non coché : aucun WhatsApp affirmé', async () => {
    const user = userEvent.setup({ delay: null })
    api.on('POST', '/api/public/visits', visitReply(1))
    renderPublic(EVENT_VISIT1)
    await fillMinimalEventForm(user)
    await submit(user)

    await heading(/Bienvenue parmi nous/)
    expect(currentPath()).toBe('/merci')
    const [call] = api.callsTo('POST', '/api/public/visits')
    expect(call.body).toEqual({
      session_token: 'jeton-initial',
      idempotency_key: expect.stringMatching(/^[0-9a-f-]{36}$/),
      consent: true,
      answers: {
        full_name: 'Kouamé Jean-Pierre',
        commune: 'Cocody',
        quartier: 'Angré',
        source: 'autre',
        source_other: 'Une affiche au marché',
        invited_by: null,
        inviter_congregation_id: null,
        inviter_family_id: null,
        whatsapp: null,
        whatsapp_same_as_phone: false,
        wants_whatsapp_group: false,
      },
    })
  })

  it('groupe coché sans autre numéro : le numéro de l’accueil', async () => {
    const user = userEvent.setup({ delay: null })
    api.on('POST', '/api/public/visits', visitReply(1))
    renderPublic(EVENT_VISIT1)
    await heading('Votre première visite')
    await user.click(whatsappCheckbox())
    await fillMinimalEventForm(user)
    await submit(user)

    await heading(/Bienvenue parmi nous/)
    expect(api.callsTo('POST', '/api/public/visits')[0].body?.answers).toMatchObject({
      whatsapp: null,
      whatsapp_same_as_phone: true,
      wants_whatsapp_group: true,
    })
  })

  it('groupe coché avec un autre numéro : ce numéro est envoyé', async () => {
    const user = userEvent.setup({ delay: null })
    api.on('POST', '/api/public/visits', visitReply(1, 201, { id: 4, name: 'Force' }))
    renderPublic(EVENT_VISIT1)
    await heading('Votre première visite')
    await user.click(whatsappCheckbox())
    await user.selectOptions(screen.getByLabelText('Pays du numéro WhatsApp'), 'FR')
    await user.type(screen.getByLabelText(/Autre numéro WhatsApp/), '06 12 34 56 78')
    await fillIdentity(user)
    await user.click(within(sourceGroup()).getByRole('radio', { name: 'Invité(e) par un membre' }))
    await user.type(screen.getByLabelText('Nom de la personne qui vous a invité(e)'), 'Koffi Adjoua')
    await user.selectOptions(await screen.findByRole('combobox', { name: /Sa congrégation/ }), '8')
    await user.selectOptions(await screen.findByRole('combobox', { name: /Sa famille/ }), '4')
    await user.click(screen.getByRole('checkbox', { name: /J'accepte/ }))
    await submit(user)

    await heading('Bienvenue parmi nous, Kouamé Jean-Pierre !')
    expect(api.callsTo('POST', '/api/public/visits')[0].body?.answers).toMatchObject({
      source: 'invite_membre',
      source_other: null,
      invited_by: 'Koffi Adjoua',
      inviter_congregation_id: 8,
      inviter_family_id: 4,
      whatsapp: { country: 'FR', number: '0612345678' },
      whatsapp_same_as_phone: false,
      wants_whatsapp_group: true,
    })
  })

  it('« Retour à l’accueil » revient au lien de l’événement', async () => {
    const user = userEvent.setup({ delay: null })
    renderPublic(EVENT_VISIT1)
    await heading('Votre première visite')
    await user.click(screen.getByRole('button', { name: "Retour à l'accueil" }))
    await heading('Enregistrez votre visite')
    expect(currentPath()).toBe(EVENT_HOME)
    expect(screen.getByText(new RegExp(EVENT.name))).toBeInTheDocument()
  })
})

describe('Persistance de l’événement', () => {
  it('survit à un rafraîchissement : brouillon et événement conservés', async () => {
    const user = userEvent.setup({ delay: null })
    seedIdentified(1, { event: { slug: EVENT.slug, name: EVENT.name } })
    const first = renderPublic(EVENT_VISIT1)
    await user.type(await screen.findByLabelText('Nom et prénoms'), 'Aya Konan')
    await user.click(whatsappCheckbox())
    await user.type(screen.getByLabelText(/Autre numéro WhatsApp/), '05 11 22 33 44')
    expect(readJourney()).toMatchObject({ event: { slug: EVENT.slug } })
    first.unmount()

    renderPublic(EVENT_VISIT1)
    await heading('Votre première visite')
    expect(screen.getByText(new RegExp(EVENT.name))).toBeInTheDocument()
    expect(screen.getByLabelText('Nom et prénoms')).toHaveValue('Aya Konan')
    expect(whatsappCheckbox()).toBeChecked()
    expect(screen.getByLabelText(/Autre numéro WhatsApp/)).toHaveValue('05 11 22 33 44')
  })

  it('redonne l’événement lors d’une ré-identification silencieuse (jeton invalide)', async () => {
    const user = userEvent.setup({ delay: null })
    seedIdentified(1, { event: { slug: EVENT.slug, name: EVENT.name } })
    api.on('POST', '/api/public/visits', { status: 401, body: { message: 'Jeton invalide.', code: 'token_invalid' } })
    api.on('POST', '/api/public/visits', visitReply(1))
    api.on('POST', '/api/public/identify', identifyReply(1, 'jeton-2'))
    renderPublic(EVENT_VISIT1)
    await fillMinimalEventForm(user)
    await submit(user)

    await heading(/Bienvenue parmi nous/)
    expect(api.callsTo('POST', '/api/public/identify')[0].body).toEqual({
      country: 'CI',
      phone: '0700000000',
      event: EVENT.slug,
    })
    expect(api.callsTo('POST', '/api/public/visits')[1].body?.session_token).toBe('jeton-2')
  })
})

describe('Visites 2 et 3 depuis un lien de culte spécial', () => {
  it('utilisent le formulaire habituel', async () => {
    const user = userEvent.setup({ delay: null })
    api.on('POST', '/api/public/identify', identifyReply(2))
    api.on('POST', '/api/public/visits', visitReply(2))
    renderPublic(EVENT_HOME)
    await identifyWith(user)

    await heading('Votre deuxième visite')
    expect(currentPath()).toBe('/visite/2')
    // Les questions habituelles de la deuxième visite, inchangées.
    expect(screen.getByRole('group', { name: /Pourquoi êtes-vous revenu\(e\) \?/ })).toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: "L'accueil reçu" })).toBeInTheDocument()
    expect(screen.getByText('Deuxième visite')).toBeInTheDocument()
    // L'événement reste attaché au parcours.
    expect(api.callsTo('POST', '/api/public/identify')[0].body).toMatchObject({ event: EVENT.slug })

    await user.click(screen.getByRole('checkbox', { name: "L'accueil reçu" }))
    await submit(user)
    await heading('Ravis de vous revoir !')
    expect(screen.getByText('Votre deuxième visite est enregistrée')).toBeInTheDocument()
  })

  it('/e/{slug}/visite/2 renvoie vers le parcours habituel', async () => {
    seedIdentified(2, { event: { slug: EVENT.slug, name: EVENT.name } })
    renderPublic(`${EVENT_HOME}/visite/2`)
    await heading('Votre deuxième visite')
    expect(currentPath()).toBe('/visite/2')
  })

  it('un parcours à l’étape 2 atteint par le formulaire allégé est redirigé', async () => {
    seedIdentified(2, { event: { slug: EVENT.slug, name: EVENT.name } })
    renderPublic(EVENT_VISIT1)
    await heading('Votre deuxième visite')
    expect(currentPath()).toBe('/visite/2')
  })
})
