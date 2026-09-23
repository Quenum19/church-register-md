import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it } from 'vitest'
import { STORAGE_KEY } from '../journey/store'
import {
  currentPath,
  heading,
  readJourney,
  renderPublic,
  seedIdentified,
  seedJourney,
  setupPublicTest,
  visitReply,
  type ApiMock,
} from './helpers'

let api: ApiMock

beforeEach(() => {
  api = setupPublicTest()
})

describe('Garde d’accès aux étapes', () => {
  it('accès direct à /visite/2 sans parcours → accueil', async () => {
    renderPublic('/visite/2')
    await heading('Enregistrez votre visite')
    expect(currentPath()).toBe('/')
  })

  it('accès à une autre étape que celle du parcours → bonne étape', async () => {
    seedIdentified(1)
    renderPublic('/visite/3')
    await heading('Votre 1re visite')
    expect(currentPath()).toBe('/visite/1')
  })

  it('/merci sans résultat → accueil', async () => {
    renderPublic('/merci')
    await heading('Enregistrez votre visite')
  })

  it('ignore un état périmé (plus de 6 h) ou corrompu', async () => {
    seedIdentified(2, { updatedAt: Date.now() - 7 * 60 * 60 * 1000 })
    const first = renderPublic('/visite/2')
    await heading('Enregistrez votre visite')
    first.unmount()

    sessionStorage.setItem(STORAGE_KEY, '{pas du json')
    renderPublic('/visite/2')
    await heading('Enregistrez votre visite')
  })

  it('affiche une page 404 en français', async () => {
    renderPublic('/page-inexistante')
    await heading('Page introuvable')
    expect(screen.getByRole('link', { name: "Aller à l'accueil" })).toHaveAttribute('href', '/')
  })
})

describe('Persistance du parcours', () => {
  it('restaure l’étape, la saisie et la clé d’idempotence après un remontage (rafraîchissement)', async () => {
    const user = userEvent.setup({ delay: null })
    seedIdentified(1)
    api.on('POST', '/api/public/visits', 'network')
    const first = renderPublic('/visite/1')
    await user.type(await screen.findByLabelText('Nom et prénoms'), 'Aya Konan')
    await user.type(screen.getByLabelText('Commune de résidence'), 'Yopougon')
    await user.type(screen.getByLabelText('Quartier'), 'Niangon')
    await user.click(screen.getByRole('radio', { name: 'Autre' }))
    await user.type(screen.getByLabelText("Précisez comment vous avez connu l'Église"), 'Une amie')
    await user.click(screen.getByRole('checkbox', { name: /J'accepte/ }))
    await user.click(screen.getByRole('button', { name: 'Enregistrer ma visite' }))
    await screen.findByRole('alert')
    const key = readJourney()?.idempotencyKeys[1]
    expect(key).toBeTruthy()
    first.unmount()

    renderPublic('/visite/1')
    await heading('Votre 1re visite')
    expect(screen.getByLabelText('Nom et prénoms')).toHaveValue('Aya Konan')
    expect(screen.getByLabelText('Quartier')).toHaveValue('Niangon')
    expect(screen.getByRole('radio', { name: 'Autre' })).toBeChecked()
    expect(screen.getByLabelText("Précisez comment vous avez connu l'Église")).toHaveValue('Une amie')
    expect(screen.getByRole('checkbox', { name: /J'accepte/ })).toBeChecked()

    api.set('POST', '/api/public/visits', visitReply(1))
    await user.click(screen.getByRole('button', { name: 'Enregistrer ma visite' }))
    await heading('Bienvenue parmi nous, Aya Konan !')
    const calls = api.callsTo('POST', '/api/public/visits')
    expect(calls.at(-1)?.body?.idempotency_key).toBe(key)
  })

  it('l’aller-retour vers la mention d’information conserve le brouillon', async () => {
    const user = userEvent.setup({ delay: null })
    seedIdentified(1)
    renderPublic('/visite/1')
    await user.type(await screen.findByLabelText('Nom et prénoms'), 'Aya Konan')
    await user.click(screen.getByRole('link', { name: "Lire la mention d'information" }))
    await heading('Vos données personnelles')
    expect(screen.getByText(/24 mois après votre dernière visite/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Revenir à la page précédente' }))
    await heading('Votre 1re visite')
    expect(screen.getByLabelText('Nom et prénoms')).toHaveValue('Aya Konan')
  })

  it('« Terminer » efface l’état du parcours', async () => {
    const user = userEvent.setup({ delay: null })
    seedJourney({ result: { visitNumber: 2, family: { id: 4, name: 'Force' }, completed: false, name: null } })
    renderPublic('/merci')
    await heading('Ravis de vous revoir !')
    expect(screen.getByText('Votre 2e visite est enregistrée')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Terminer' }))
    await heading('Enregistrez votre visite')
    expect(readJourney()).toBeNull()
    expect(screen.getByLabelText('Numéro de téléphone')).toHaveValue('')
  })
})

describe('Retour à l’accueil', () => {
  it('sans saisie : retour immédiat', async () => {
    const user = userEvent.setup({ delay: null })
    seedIdentified(2)
    renderPublic('/visite/2')
    await heading('Votre 2e visite')
    await user.click(screen.getByRole('button', { name: "Retour à l'accueil" }))
    await heading('Enregistrez votre visite')
    expect(readJourney()).toBeNull()
  })

  it('avec un brouillon : demande confirmation', async () => {
    const user = userEvent.setup({ delay: null })
    seedIdentified(2)
    renderPublic('/visite/2')
    await heading('Votre 2e visite')
    await user.click(screen.getByRole('checkbox', { name: "L'accueil reçu" }))
    await user.click(screen.getByRole('button', { name: "Retour à l'accueil" }))

    expect(screen.getByRole('group', { name: "Revenir à l'accueil ?" })).toBeInTheDocument()
    await waitFor(() => expect(screen.getByText("Revenir à l'accueil ?")).toHaveFocus())
    await user.click(screen.getByRole('button', { name: 'Continuer ma saisie' }))
    expect(screen.getByRole('checkbox', { name: "L'accueil reçu" })).toBeChecked()
    await waitFor(() => expect(screen.getByRole('button', { name: "Retour à l'accueil" })).toHaveFocus())

    await user.click(screen.getByRole('button', { name: "Retour à l'accueil" }))
    await user.click(screen.getByRole('button', { name: "Oui, revenir à l'accueil" }))
    await heading('Enregistrez votre visite')
    expect(readJourney()).toBeNull()
  })

  it('est désactivé pendant un envoi', async () => {
    const user = userEvent.setup({ delay: null })
    seedIdentified(2)
    let release: (value: Response) => void = () => {}
    api.on('POST', '/api/public/visits', visitReply(2))
    const pending = new Promise<Response>((resolve) => {
      release = resolve
    })
    const original = globalThis.fetch
    globalThis.fetch = ((input: RequestInfo | URL, init?: RequestInit) =>
      String(input).includes('/visits') ? pending : original(input, init)) as typeof fetch

    renderPublic('/visite/2')
    await heading('Votre 2e visite')
    await user.click(screen.getByRole('checkbox', { name: "L'accueil reçu" }))
    await user.click(screen.getByRole('button', { name: 'Enregistrer ma visite' }))

    expect(await screen.findByRole('button', { name: 'Envoi en cours…' })).toBeDisabled()
    expect(screen.getByRole('button', { name: "Retour à l'accueil" })).toBeDisabled()

    release(new Response(JSON.stringify({ visit_number: 2, family: null, completed: false }), { status: 201 }))
    await heading('Ravis de vous revoir !')
  })
})
