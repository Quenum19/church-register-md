import { screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeMe, makeStats, makeUser, withSession } from '../test/fixtures'
import { renderAdmin } from '../test/render'
import { createMockServer } from '../test/server'

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('garde d’authentification', () => {
  it('redirige vers la connexion sur 401 en mémorisant la page demandée', async () => {
    const server = createMockServer().on('GET', '/api/auth/me', { status: 401, body: { message: 'Non authentifié.' } })
    const { location } = renderAdmin('/admin/visiteurs?status=recurrent')

    expect(await screen.findByRole('heading', { level: 1, name: 'Connexion' })).toBeInTheDocument()
    expect(location()).toBe(`/admin/connexion?retour=${encodeURIComponent('/admin/visiteurs?status=recurrent')}`)
    expect(server.calls('GET', '/api/admin/visitors')).toHaveLength(0)
  })

  it('vide le cache et affiche « session expirée » quand une requête reçoit un 401', async () => {
    const server = withSession(createMockServer()).on('GET', '/api/admin/visitors', {
      status: 401,
      body: { message: 'Non authentifié.', code: 'unauthenticated' },
    })
    const { location, queryClient } = renderAdmin('/admin/visiteurs')

    expect(await screen.findByText('Votre session a expiré. Merci de vous reconnecter.')).toBeInTheDocument()
    expect(location()).toMatch(/^\/admin\/connexion\?retour=/)
    expect(queryClient.getQueryCache().getAll()).toHaveLength(0)
    expect(server.calls('GET', '/api/admin/visitors')).toHaveLength(1)
  })

  it('refuse une page réservée quand l’ability manque', async () => {
    withSession(createMockServer(), 'lecteur')
    renderAdmin('/admin/administrateurs')
    expect(await screen.findByRole('heading', { level: 1, name: 'Accès refusé' })).toBeInTheDocument()
  })
})

describe('connexion', () => {
  function guestServer() {
    let authenticated = false
    const server = createMockServer()
      .on('GET', '/sanctum/csrf-cookie', { status: 204 })
      .on('GET', '/api/auth/me', () => (authenticated ? { body: makeMe() } : { status: 401, body: {} }))
      .on('GET', '/api/admin/stats', { body: makeStats() })
    return { server, signIn: () => (authenticated = true) }
  }

  it('pose le cookie CSRF, se connecte puis revient à la page demandée', async () => {
    const { server, signIn } = guestServer()
    server
      .on('POST', '/api/auth/login', () => {
        signIn()
        return { body: { user: makeMe().user } }
      })
      .on('GET', '/api/admin/members', { body: { data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } } })
    const { user, location } = renderAdmin(`/admin/connexion?retour=${encodeURIComponent('/admin/membres')}`)

    await user.type(await screen.findByLabelText('Adresse e-mail'), 'jean@exemple.org')
    await user.type(screen.getByLabelText('Mot de passe'), 'motdepasse-solide-1')
    await user.click(screen.getByRole('button', { name: 'Se connecter' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'Membres' })).toBeInTheDocument()
    expect(location()).toBe('/admin/membres')
    const paths = server.requests.map((r) => `${r.method} ${r.path}`)
    expect(paths.indexOf('GET /sanctum/csrf-cookie')).toBeLessThan(paths.indexOf('POST /api/auth/login'))
    expect(server.last('POST', '/api/auth/login')?.body).toEqual({ email: 'jean@exemple.org', password: 'motdepasse-solide-1' })
  })

  it('demande le code TOTP quand la double authentification est active', async () => {
    const { server, signIn } = guestServer()
    server
      .on('POST', '/api/auth/login', { body: { two_factor_required: true } })
      .on('POST', '/api/auth/two-factor/challenge', (request) => {
        if ((request.body as { code?: string }).code !== '123456') {
          return {
            status: 422,
            body: {
              message: 'Les données fournies sont invalides.',
              code: 'validation',
              errors: { code: ['Le code de vérification est incorrect.'] },
            },
          }
        }
        signIn()
        return { body: { user: makeMe().user } }
      })
    const { user, location } = renderAdmin('/admin/connexion')

    await user.type(await screen.findByLabelText('Adresse e-mail'), 'jean@exemple.org')
    await user.type(screen.getByLabelText('Mot de passe'), 'motdepasse-solide-1')
    await user.click(screen.getByRole('button', { name: 'Se connecter' }))

    expect(await screen.findByRole('heading', { name: 'Vérification en deux étapes' })).toBeInTheDocument()
    const code = screen.getByLabelText('Code de vérification')

    // Code erroné : message du serveur (pas un texte figé), on reste sur l'étape 2FA.
    await user.type(code, '000000')
    await user.click(screen.getByRole('button', { name: 'Valider' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Le code de vérification est incorrect.')
    expect(screen.getByRole('heading', { level: 1, name: 'Vérification en deux étapes' })).toBeInTheDocument()

    await user.clear(code)
    await user.type(code, '123 456')
    await user.click(screen.getByRole('button', { name: 'Valider' }))

    await waitFor(() => expect(location()).toBe('/admin'))
    expect(server.last('POST', '/api/auth/two-factor/challenge')?.body).toEqual({ code: '123456' })
  })

  it('accepte un code de récupération à la place du code TOTP', async () => {
    const { server, signIn } = guestServer()
    server.on('POST', '/api/auth/login', { body: { two_factor_required: true } }).on('POST', '/api/auth/two-factor/challenge', () => {
      signIn()
      return { body: { user: makeMe().user } }
    })
    const { user, location } = renderAdmin('/admin/connexion')

    await user.type(await screen.findByLabelText('Adresse e-mail'), 'jean@exemple.org')
    await user.type(screen.getByLabelText('Mot de passe'), 'motdepasse-solide-1')
    await user.click(screen.getByRole('button', { name: 'Se connecter' }))
    await user.click(await screen.findByRole('button', { name: 'Utiliser un code de récupération' }))
    await user.type(screen.getByLabelText('Code de récupération'), 'abcd-efgh-ijkl')
    await user.click(screen.getByRole('button', { name: 'Valider' }))

    await waitFor(() => expect(location()).toBe('/admin'))
    expect(server.last('POST', '/api/auth/two-factor/challenge')?.body).toEqual({ recovery_code: 'abcd-efgh-ijkl' })
  })

  it('affiche le message global du serveur sur un autre refus 422 du code de récupération', async () => {
    const { server } = guestServer()
    server
      .on('POST', '/api/auth/login', { body: { two_factor_required: true } })
      .on('POST', '/api/auth/two-factor/challenge', {
        status: 422,
        body: { message: 'Ce code de récupération a déjà été utilisé.', code: 'validation' },
      })
    const { user } = renderAdmin('/admin/connexion')

    await user.type(await screen.findByLabelText('Adresse e-mail'), 'jean@exemple.org')
    await user.type(screen.getByLabelText('Mot de passe'), 'motdepasse-solide-1')
    await user.click(screen.getByRole('button', { name: 'Se connecter' }))
    await user.click(await screen.findByRole('button', { name: 'Utiliser un code de récupération' }))
    await user.type(screen.getByLabelText('Code de récupération'), 'abcd-efgh-ijkl')
    await user.click(screen.getByRole('button', { name: 'Valider' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Ce code de récupération a déjà été utilisé.')
    expect(screen.queryByText(/Code invalide/)).not.toBeInTheDocument()
  })

  it('revient à la saisie des identifiants avec le message du serveur si la vérification a expiré (two_factor_expired)', async () => {
    const { server } = guestServer()
    server.on('POST', '/api/auth/login', { body: { two_factor_required: true } }).on('POST', '/api/auth/two-factor/challenge', {
      status: 422,
      body: { message: 'La vérification a expiré, reconnectez-vous.', code: 'two_factor_expired' },
    })
    const { user, location } = renderAdmin('/admin/connexion')

    await user.type(await screen.findByLabelText('Adresse e-mail'), 'jean@exemple.org')
    await user.type(screen.getByLabelText('Mot de passe'), 'motdepasse-solide-1')
    await user.click(screen.getByRole('button', { name: 'Se connecter' }))
    await user.type(await screen.findByLabelText('Code de vérification'), '123456')
    await user.click(screen.getByRole('button', { name: 'Valider' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'Connexion' })).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent('La vérification a expiré, reconnectez-vous.')
    expect(screen.getByLabelText('Adresse e-mail')).toHaveValue('')
    expect(screen.queryByLabelText('Code de vérification')).not.toBeInTheDocument()
    expect(location()).toBe('/admin/connexion')
  })

  it.each([
    [{ status: 422, body: { message: 'Identifiants incorrects.', code: 'validation' } }, /Identifiants incorrects/],
    [{ status: 423, body: { message: 'Verrouillé.', code: 'account_locked' } }, /temporairement verrouillé/],
    [{ status: 429, body: { message: 'Trop.' }, headers: { 'Retry-After': '120' } }, /patienter 2 minutes/],
    [{ status: 500, body: { message: 'SQLSTATE…' } }, /Le serveur rencontre un problème/],
  ])('affiche un message distinct selon l’échec (%#)', async (reply, message) => {
    const { server } = guestServer()
    server.on('POST', '/api/auth/login', reply)
    const { user } = renderAdmin('/admin/connexion')

    await user.type(await screen.findByLabelText('Adresse e-mail'), 'jean@exemple.org')
    await user.type(screen.getByLabelText('Mot de passe'), 'mauvais')
    await user.click(screen.getByRole('button', { name: 'Se connecter' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent(message)
    expect(alert).not.toHaveTextContent('SQLSTATE')
    if (reply.status === 429) expect(screen.getByRole('button', { name: 'Se connecter' })).toBeDisabled()
  })

  it('signale une erreur réseau', async () => {
    const { server } = guestServer()
    server.on('POST', '/api/auth/login', () => {
      throw new TypeError('Failed to fetch')
    })
    const { user } = renderAdmin('/admin/connexion')

    await user.type(await screen.findByLabelText('Adresse e-mail'), 'jean@exemple.org')
    await user.type(screen.getByLabelText('Mot de passe'), 'motdepasse')
    await user.click(screen.getByRole('button', { name: 'Se connecter' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(/Connexion au serveur impossible/)
  })
})

describe('déconnexion', () => {
  it('appelle /api/auth/logout, vide le cache et revient à la connexion', async () => {
    const server = withSession(createMockServer()).on('POST', '/api/auth/logout', { status: 204 })
    const { user, location, queryClient } = renderAdmin('/admin')

    await screen.findByRole('heading', { level: 1, name: 'Accueil' })
    await user.click(screen.getByRole('button', { name: /menu du compte/ }))
    await user.click(screen.getByRole('button', { name: 'Se déconnecter' }))

    expect(await screen.findByText('Vous avez été déconnecté.')).toBeInTheDocument()
    expect(location()).toBe('/admin/connexion')
    expect(server.calls('POST', '/api/auth/logout')).toHaveLength(1)
    expect(queryClient.getQueryCache().getAll()).toHaveLength(0)
  })
})

describe('mise en page', () => {
  it('filtre les liens de navigation selon les abilities', async () => {
    withSession(createMockServer(), 'lecteur')
    renderAdmin('/admin')
    const nav = await screen.findByRole('navigation', { name: 'Navigation principale' })

    expect(within(nav).getByRole('link', { name: 'Visiteurs' })).toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Administrateurs' })).not.toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Journal d’audit' })).not.toBeInTheDocument()
    // Famille du mois lue depuis /api/admin/stats (barre latérale).
    expect(await screen.findByText('Famille du mois')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByText('Famille du mois').nextElementSibling).toHaveTextContent('Force'))
  })

  it('affiche tous les liens pour un super administrateur et met à jour le titre', async () => {
    withSession(createMockServer(), 'super_admin')
    renderAdmin('/admin')
    const nav = await screen.findByRole('navigation', { name: 'Navigation principale' })

    expect(within(nav).getByRole('link', { name: 'Administrateurs' })).toBeInTheDocument()
    expect(within(nav).getByRole('link', { name: 'Journal d’audit' })).toBeInTheDocument()
    await waitFor(() => expect(document.title).toMatch(/^Accueil · Administration/))
  })
})

describe('définition du mot de passe', () => {
  function guestResetServer() {
    return createMockServer()
      .on('GET', '/api/auth/me', { status: 401, body: {} })
      .on('GET', '/sanctum/csrf-cookie', { status: 204 })
      .on('POST', '/api/auth/reset-password', { status: 204 })
  }

  it('accueille un nouvel administrateur invité (invitation=1)', async () => {
    const server = guestResetServer()
    const { user, location } = renderAdmin('/admin/reinitialiser?token=abc123&email=paul%40exemple.org&invitation=1')

    expect(await screen.findByRole('heading', { level: 1, name: 'Bienvenue' })).toBeInTheDocument()
    expect(screen.getByText('Choisissez votre mot de passe pour activer votre compte.')).toBeInTheDocument()
    // Le jeton est retiré de l'URL, sans quitter la page ni perdre le mode « invitation ».
    expect(location()).toBe('/admin/reinitialiser')

    await user.type(screen.getByLabelText('Nouveau mot de passe'), 'motdepasse-solide-1')
    await user.type(screen.getByLabelText('Confirmation du mot de passe'), 'motdepasse-solide-1')
    await user.click(screen.getByRole('button', { name: 'Enregistrer le mot de passe' }))

    expect(await screen.findByText('Votre compte est activé. Vous pouvez maintenant vous connecter.')).toBeInTheDocument()
    expect(server.last('POST', '/api/auth/reset-password')?.body).toEqual({
      token: 'abc123',
      email: 'paul@exemple.org',
      password: 'motdepasse-solide-1',
      password_confirmation: 'motdepasse-solide-1',
    })
  })

  it('garde le texte de réinitialisation sans le paramètre invitation', async () => {
    guestResetServer()
    renderAdmin('/admin/reinitialiser?token=abc123&email=paul%40exemple.org')

    expect(await screen.findByRole('heading', { level: 1, name: 'Choisir un mot de passe' })).toBeInTheDocument()
    expect(screen.getByText('Pour activer votre accès ou remplacer votre mot de passe.')).toBeInTheDocument()
    expect(screen.queryByText(/Bienvenue/)).not.toBeInTheDocument()
  })

  it('retire le jeton de l’URL sans ajouter d’entrée d’historique, et envoie quand même le bon jeton', async () => {
    const server = guestResetServer()
    const { user, location } = renderAdmin('/admin/reinitialiser?token=abc123&email=paul%40exemple.org')

    expect(await screen.findByRole('heading', { level: 1, name: 'Choisir un mot de passe' })).toBeInTheDocument()
    // L'URL est nettoyée : ni jeton ni adresse e-mail dans l'historique du navigateur.
    // (nettoyage par remplacement de l'entrée courante : aucune entrée d'historique ajoutée)
    await waitFor(() => expect(location()).toBe('/admin/reinitialiser'))
    // L'adresse e-mail reste affichée et la page reste utilisable.
    expect(screen.getByLabelText('Adresse e-mail')).toHaveValue('paul@exemple.org')

    await user.type(screen.getByLabelText('Nouveau mot de passe'), 'motdepasse-solide-1')
    await user.type(screen.getByLabelText('Confirmation du mot de passe'), 'motdepasse-solide-1')
    await user.click(screen.getByRole('button', { name: 'Enregistrer le mot de passe' }))

    expect(await screen.findByText(/Votre mot de passe est enregistré/)).toBeInTheDocument()
    // Le jeton lu à l'arrivée est bien celui envoyé au serveur, malgré l'URL nettoyée.
    expect(server.last('POST', '/api/auth/reset-password')?.body).toMatchObject({
      token: 'abc123',
      email: 'paul@exemple.org',
    })
  })
})

describe('profil', () => {
  it('exige le mot de passe actuel pour changer d’adresse e-mail et y rattache le refus du serveur (422)', async () => {
    const server = withSession(createMockServer()).on('PATCH', '/api/auth/profile', (request) => {
      const body = request.body as { email?: string; current_password?: string }
      if (body.current_password !== 'motdepasse-solide-1') {
        return {
          status: 422,
          body: {
            message: 'Le mot de passe est incorrect.',
            code: 'validation',
            errors: { current_password: ['Le mot de passe est incorrect.'] },
          },
        }
      }
      return { body: { user: makeUser({ email: body.email }) } }
    })
    const { user } = renderAdmin('/admin/parametres')

    const email = await screen.findByLabelText('Adresse e-mail')
    expect(screen.queryByLabelText('Mot de passe actuel')).not.toBeInTheDocument()

    await user.clear(email)
    await user.type(email, 'jean.nouveau@exemple.org')
    const password = await screen.findByLabelText('Mot de passe actuel')

    // Champ vide : refus côté client, aucune requête.
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))
    await waitFor(() =>
      expect(password).toHaveAccessibleDescription(/Saisissez votre mot de passe actuel pour changer d’adresse e-mail/),
    )
    expect(server.calls('PATCH', '/api/auth/profile')).toHaveLength(0)

    // Mot de passe erroné : l'erreur 422 est rattachée au champ.
    await user.type(password, 'mauvais-mot-de-passe-1')
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))
    await waitFor(() => expect(password).toHaveAccessibleDescription(/Le mot de passe est incorrect\./))
    expect(password).toHaveAttribute('aria-invalid', 'true')
    expect(password).toHaveFocus()

    await user.clear(password)
    await user.type(password, 'motdepasse-solide-1')
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByText('Profil mis à jour.')).toBeInTheDocument()
    expect(server.last('PATCH', '/api/auth/profile')?.body).toEqual({
      email: 'jean.nouveau@exemple.org',
      current_password: 'motdepasse-solide-1',
    })
    await waitFor(() => expect(screen.queryByLabelText('Mot de passe actuel')).not.toBeInTheDocument())
  })

  it('n’envoie pas de mot de passe quand seul le nom change', async () => {
    const server = withSession(createMockServer()).on('PATCH', '/api/auth/profile', {
      body: { user: makeUser({ name: 'Jean-Marc Kouassi' }) },
    })
    const { user } = renderAdmin('/admin/parametres')

    const name = await screen.findByLabelText('Nom')
    await user.clear(name)
    await user.type(name, 'Jean-Marc Kouassi')
    expect(screen.queryByLabelText('Mot de passe actuel')).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByText('Profil mis à jour.')).toBeInTheDocument()
    expect(server.last('PATCH', '/api/auth/profile')?.body).toEqual({ name: 'Jean-Marc Kouassi' })
  })
})
