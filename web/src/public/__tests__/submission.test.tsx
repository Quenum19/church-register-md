import { screen } from '@testing-library/react'
import userEvent, { type UserEvent } from '@testing-library/user-event'
import { beforeEach, describe, expect, it } from 'vitest'
import {
  currentPath,
  heading,
  identifyReply,
  readJourney,
  renderPublic,
  seedIdentified,
  setupPublicTest,
  visitReply,
  type ApiMock,
} from './helpers'

let api: ApiMock

beforeEach(() => {
  api = setupPublicTest()
  seedIdentified(2)
})

async function answerVisit2(user: UserEvent) {
  await heading('Votre 2e visite')
  await user.click(screen.getByRole('checkbox', { name: "L'enseignement de la Parole" }))
  await user.click(screen.getByRole('checkbox', { name: "L'accueil reçu" }))
  await user.click(screen.getByRole('button', { name: 'Enregistrer ma visite' }))
}

describe('Envoi d’une visite', () => {
  it('réutilise la même clé d’idempotence lors d’un renvoi après coupure réseau', async () => {
    const user = userEvent.setup({ delay: null })
    api.on('POST', '/api/public/visits', 'network', visitReply(2))
    renderPublic('/visite/2')
    await answerVisit2(user)

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent("Votre visite n'a pas pu être enregistrée.")
    expect(alert).toHaveTextContent('ne seront jamais enregistrées deux fois')
    const firstKey = readJourney()?.idempotencyKeys[2]
    expect(firstKey).toMatch(/^[0-9a-f-]{36}$/)

    await user.click(screen.getByRole('button', { name: 'Réessayer' }))
    await heading('Ravis de vous revoir !')

    const calls = api.callsTo('POST', '/api/public/visits')
    expect(calls).toHaveLength(2)
    expect(calls[0].body?.idempotency_key).toBe(firstKey)
    expect(calls[1].body?.idempotency_key).toBe(firstKey)
    expect(calls[1].body).toEqual({
      session_token: 'jeton-initial',
      idempotency_key: firstKey,
      answers: { return_reasons: ['enseignement', 'accueil'], return_reasons_other: null },
    })
  })

  it('traite un rejeu idempotent (200) comme un succès', async () => {
    const user = userEvent.setup({ delay: null })
    api.on('POST', '/api/public/visits', visitReply(2, 200, { id: 5, name: 'Honneur' }))
    renderPublic('/visite/2')
    await answerVisit2(user)
    await heading('Ravis de vous revoir !')
    expect(screen.getByText(/famille Honneur/)).toBeInTheDocument()
    // Aucune donnée personnelle venue du serveur : pas de nom affiché aux visites 2 et 3.
    expect(screen.queryByText(/Bienvenue parmi nous,/)).not.toBeInTheDocument()
  })

  it('ré-identifie de façon transparente sur token_expired puis renvoie avec la même clé', async () => {
    const user = userEvent.setup({ delay: null })
    api
      .on('POST', '/api/public/visits', { status: 410, body: { message: 'Session expirée.', code: 'token_expired' } }, visitReply(2))
      .on('POST', '/api/public/identify', identifyReply(2, 'jeton-neuf'))
    renderPublic('/visite/2')
    await answerVisit2(user)
    await heading('Ravis de vous revoir !')

    expect(api.callsTo('POST', '/api/public/identify').map((c) => c.body)).toEqual([
      { country: 'CI', phone: '0700000000' },
    ])
    const [first, second] = api.callsTo('POST', '/api/public/visits')
    expect(first.body?.session_token).toBe('jeton-initial')
    expect(second.body?.session_token).toBe('jeton-neuf')
    expect(second.body?.idempotency_key).toBe(first.body?.idempotency_key)
  })

  it('ré-identifie aussi sur un jeton invalide (401)', async () => {
    const user = userEvent.setup({ delay: null })
    api
      .on('POST', '/api/public/visits', { status: 401, body: { message: 'Jeton invalide.', code: 'token_invalid' } }, visitReply(2))
      .on('POST', '/api/public/identify', identifyReply(2, 'jeton-neuf'))
    renderPublic('/visite/2')
    await answerVisit2(user)
    await heading('Ravis de vous revoir !')
    expect(api.callsTo('POST', '/api/public/visits')).toHaveLength(2)
  })

  it('ré-identifie sur un jeton déjà utilisé (409 token_used) en gardant la même clé', async () => {
    const user = userEvent.setup({ delay: null })
    api
      .on('POST', '/api/public/visits', { status: 409, body: { message: 'Jeton déjà utilisé.', code: 'token_used' } }, visitReply(2))
      .on('POST', '/api/public/identify', identifyReply(2, 'jeton-neuf'))
    renderPublic('/visite/2')
    await answerVisit2(user)
    await heading('Ravis de vous revoir !')

    const [first, second] = api.callsTo('POST', '/api/public/visits')
    expect(second.body?.session_token).toBe('jeton-neuf')
    expect(second.body?.idempotency_key).toBe(first.body?.idempotency_key)
  })

  it('renouvelle une seule fois la clé d’idempotence sur 409 idempotency_conflict', async () => {
    const user = userEvent.setup({ delay: null })
    api.on(
      'POST',
      '/api/public/visits',
      { status: 409, body: { message: 'Clé déjà utilisée.', code: 'idempotency_conflict' } },
      visitReply(2),
    )
    renderPublic('/visite/2')
    await answerVisit2(user)
    await heading('Ravis de vous revoir !')

    const [first, second] = api.callsTo('POST', '/api/public/visits')
    expect(second.body?.idempotency_key).toMatch(/^[0-9a-f-]{36}$/)
    expect(second.body?.idempotency_key).not.toBe(first.body?.idempotency_key)
    expect(second.body?.session_token).toBe('jeton-initial')
    expect(api.callsTo('POST', '/api/public/identify')).toHaveLength(0)
  })

  it('redirige vers la bonne étape si elle a changé (409 step_mismatch)', async () => {
    const user = userEvent.setup({ delay: null })
    api
      .on('POST', '/api/public/visits', { status: 409, body: { message: 'Étape incorrecte.', code: 'step_mismatch' } })
      .on('POST', '/api/public/identify', identifyReply(3, 'jeton-3'))
    renderPublic('/visite/2')
    await answerVisit2(user)
    await heading('Votre 3e visite')
    expect(currentPath()).toBe('/visite/3')
    expect(api.callsTo('POST', '/api/public/visits')).toHaveLength(1)
  })

  it('409 already_today mène à « déjà enregistrée » et efface l’état', async () => {
    const user = userEvent.setup({ delay: null })
    api.on('POST', '/api/public/visits', {
      status: 409,
      body: { message: 'Déjà enregistrée aujourd’hui.', code: 'already_today' },
    })
    renderPublic('/visite/2')
    await answerVisit2(user)
    await heading('Votre visite est déjà enregistrée')
    expect(currentPath()).toBe('/deja-enregistre')
    expect(readJourney()).toBeNull()
  })

  it('affiche le message 429 sans perdre la saisie', async () => {
    const user = userEvent.setup({ delay: null })
    api.on('POST', '/api/public/visits', {
      status: 429,
      body: { message: 'Too Many Attempts.', code: 'too_many_requests' },
      headers: { 'Retry-After': '30' },
    })
    renderPublic('/visite/2')
    await answerVisit2(user)
    expect(await screen.findByRole('alert')).toHaveTextContent('Trop de tentatives. Réessayez dans 1 minute.')
    expect(screen.getByRole('checkbox', { name: "L'enseignement de la Parole" })).toBeChecked()
  })

  it('la 3e visite exige une précision pour « Autre raison » et termine le parcours', async () => {
    const user = userEvent.setup({ delay: null })
    seedIdentified(3)
    api.on('POST', '/api/public/visits', visitReply(3, 201, { id: 4, name: 'Force' }))
    renderPublic('/visite/3')
    await heading('Votre 3e visite')
    await user.click(screen.getByRole('radio', { name: 'Autre raison' }))
    await user.click(screen.getByRole('button', { name: 'Enregistrer ma visite' }))
    expect(await screen.findByRole('region', { name: 'Un point est à corriger :' })).toHaveTextContent(
      'Précisez la raison de vos visites.',
    )
    await user.type(screen.getByLabelText('Précisez la raison de vos visites'), 'Mariage à Abidjan')
    await user.click(screen.getByRole('button', { name: 'Enregistrer ma visite' }))
    await heading('Vous êtes des nôtres !')
    expect(screen.getByText(/Pasteur/)).toBeInTheDocument()
    expect(api.callsTo('POST', '/api/public/visits')[0].body?.answers).toEqual({
      visit_reason: 'autres',
      visit_reason_other: 'Mariage à Abidjan',
    })
  })
})
