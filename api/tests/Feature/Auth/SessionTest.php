<?php

require_once __DIR__.'/helpers.php';

use App\Enums\Ability;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

beforeEach(function (): void {
    config(['auth.timebox_duration' => 0]);
});

describe('GET /api/auth/me', function (): void {
    it('renvoie l\'utilisateur et ses abilities selon le rôle', function (Role $role): void {
        $user = User::factory()->role($role)->create();

        $response = authActingAs($user)->getJson('/api/auth/me')->assertOk();

        $response->assertExactJsonStructure(['user' => [
            'id', 'name', 'email', 'role', 'is_active', 'two_factor_enabled', 'last_login_at', 'created_at', 'invitation_pending',
        ], 'abilities'])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', $role->value)
            ->assertJsonPath('abilities', $role->abilities());

        authAssertNoSecrets($response);
    })->with(Role::cases());

    it('donne toutes les abilities au super_admin et seulement visitors.view au lecteur', function (): void {
        authActingAs(userWithRole(Role::SuperAdmin))->getJson('/api/auth/me')
            ->assertJsonPath('abilities', Ability::values());

        authActingAs(userWithRole(Role::Lecteur))->getJson('/api/auth/me')
            ->assertJsonPath('abilities', ['visitors.view']);
    });

    it('répond 401 sans session', function (): void {
        $this->getJson('/api/auth/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    });

    it('répond 401 et détruit la session d\'un compte désactivé', function (): void {
        $user = User::factory()->inactive()->create();

        authActingAs($user)->withHeaders(AUTH_SPA_HEADERS)->getJson('/api/auth/me')->assertUnauthorized();
        $this->assertGuest('web');
    });
});

describe('sessions en base (SESSION_DRIVER=database)', function (): void {
    beforeEach(function (): void {
        config(['session.driver' => 'database']);
    });

    it('ouvre une session en base à la connexion, avec un nouvel identifiant', function (): void {
        $user = User::factory()->create(['email' => 'jean@exemple.org']);
        $browser = new AuthSpaClient($this);

        $browser->json('GET', '/api/auth/me')->assertUnauthorized();
        $before = $browser->sessionId;

        $browser->json('POST', '/api/auth/login', ['email' => 'jean@exemple.org', 'password' => UserFactory::PASSWORD])->assertOk();

        expect($browser->sessionId)->not->toBeNull()->not->toBe($before)
            ->and(DB::table('sessions')->where('id', $browser->sessionId)->value('user_id'))->toBe($user->id)
            ->and(DB::table('sessions')->where('id', $before)->exists())->toBeFalse();

        $browser->json('GET', '/api/auth/me')->assertOk()->assertJsonPath('user.id', $user->id);
    });

    it('déconnecte réellement : session supprimée côté serveur, ancien cookie inutilisable', function (): void {
        $user = User::factory()->create(['email' => 'jean@exemple.org']);
        $browser = new AuthSpaClient($this);
        $browser->json('POST', '/api/auth/login', ['email' => 'jean@exemple.org', 'password' => UserFactory::PASSWORD])->assertOk();
        $loggedInSession = $browser->sessionId;

        $browser->json('POST', '/api/auth/logout')->assertNoContent();

        expect(DB::table('sessions')->where('id', $loggedInSession)->exists())->toBeFalse()
            ->and(AuditLog::query()->where('action', 'auth.logout')->sole()->user_id)->toBe($user->id);

        // Le nouveau cookie (session vide) comme l'ancien (rejoué par un attaquant) : 401.
        $browser->json('GET', '/api/auth/me')->assertUnauthorized();
        $browser->sessionId = $loggedInSession;
        $browser->json('GET', '/api/auth/me')->assertUnauthorized();
    });

    it('supprime toutes les AUTRES sessions au changement de mot de passe et conserve la session courante', function (): void {
        $user = User::factory()->create(['email' => 'jean@exemple.org']);
        $other = User::factory()->create(['email' => 'autre@exemple.org']);
        $credentials = ['email' => 'jean@exemple.org', 'password' => UserFactory::PASSWORD];

        $laptop = new AuthSpaClient($this, '203.0.113.1');
        $phone = new AuthSpaClient($this, '203.0.113.2');
        $colleague = new AuthSpaClient($this, '203.0.113.3');
        $laptop->json('POST', '/api/auth/login', $credentials)->assertOk();
        $phone->json('POST', '/api/auth/login', $credentials)->assertOk();
        $colleague->json('POST', '/api/auth/login', ['email' => 'autre@exemple.org', 'password' => UserFactory::PASSWORD])->assertOk();
        $laptopBefore = $laptop->sessionId;

        $laptop->json('PUT', '/api/auth/password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => 'nouveaumotdepasse2027',
            'password_confirmation' => 'nouveaumotdepasse2027',
        ])->assertNoContent();

        // Session courante régénérée, toujours valide ; l'autre appareil est déconnecté.
        expect($laptop->sessionId)->not->toBe($laptopBefore)
            ->and(DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all())->toBe([$laptop->sessionId]);
        $laptop->json('GET', '/api/auth/me')->assertOk();
        $phone->json('GET', '/api/auth/me')->assertUnauthorized();

        // Les sessions des autres comptes ne sont pas touchées.
        $colleague->json('GET', '/api/auth/me')->assertOk()->assertJsonPath('user.id', $other->id);
    });

    it('coupe un compte désactivé par un administrateur dès sa requête suivante', function (): void {
        $admin = userWithRole(Role::SuperAdmin);
        User::factory()->create(['email' => 'jean@exemple.org']);
        $browser = new AuthSpaClient($this);
        $browser->json('POST', '/api/auth/login', ['email' => 'jean@exemple.org', 'password' => UserFactory::PASSWORD])->assertOk();
        $jean = User::query()->where('email', 'jean@exemple.org')->sole();

        authResetState();
        authActingAs($admin)->patchJson("/api/admin/users/{$jean->id}", ['is_active' => false])->assertOk();

        expect(DB::table('sessions')->where('user_id', $jean->id)->exists())->toBeFalse();
        $browser->json('GET', '/api/auth/me')->assertUnauthorized();
    });
});

describe('CSRF', function (): void {
    beforeEach(function (): void {
        config(['session.driver' => 'database']);
        authEnableCsrf();
    });

    it('répond 419 csrf_expired en JSON à une requête SPA sans jeton CSRF', function (): void {
        User::factory()->create(['email' => 'jean@exemple.org']);

        $this->withHeaders(AUTH_SPA_HEADERS)
            ->postJson('/api/auth/login', ['email' => 'jean@exemple.org', 'password' => UserFactory::PASSWORD])
            ->assertStatus(419)
            ->assertExactJson([
                'message' => 'Votre session a expiré. Rechargez la page puis réessayez.',
                'code' => 'csrf_expired',
            ]);

        $this->assertGuest('web');
    });

    it('accepte la connexion avec le jeton de /sanctum/csrf-cookie renvoyé dans X-XSRF-TOKEN', function (): void {
        User::factory()->create(['email' => 'jean@exemple.org']);
        $browser = new AuthSpaClient($this);

        $browser->json('GET', '/sanctum/csrf-cookie')->assertNoContent();
        expect($browser->xsrfCookie)->not->toBeNull();

        $browser->json('POST', '/api/auth/login', ['email' => 'jean@exemple.org', 'password' => UserFactory::PASSWORD], [
            'X-XSRF-TOKEN' => (string) $browser->xsrfCookie,
        ])->assertOk();

        // La connexion régénère le jeton : l'ancien ne vaut plus rien.
        $browser->json('POST', '/api/auth/logout', [], ['X-XSRF-TOKEN' => 'jeton-invalide'])->assertStatus(419);
    });
});

describe('chiffrement des sessions (revue de sécurité)', function (): void {
    it('active SESSION_ENCRYPT par défaut, dans la config comme dans .env.example', function (): void {
        // Un accès en lecture seule à la table `sessions` (sauvegarde, incident hébergeur)
        // ne doit rien révéler du contenu des sessions d'administration.
        expect(file_get_contents(config_path('session.php')))->toContain("env('SESSION_ENCRYPT', true)")
            ->and(file_get_contents(base_path('.env.example')))->toContain("\nSESSION_ENCRYPT=true\n");
    });

    it('rend la charge utile des sessions illisible en base', function (): void {
        config(['session.driver' => 'database', 'session.encrypt' => true]);
        authResetState();

        $user = User::factory()->create(['email' => 'jean@exemple.org']);
        $browser = new AuthSpaClient($this);
        $browser->json('POST', '/api/auth/login', ['email' => 'jean@exemple.org', 'password' => UserFactory::PASSWORD])->assertOk();

        $payload = (string) DB::table('sessions')->where('user_id', $user->id)->value('payload');

        // La colonne est en base64, la valeur chiffrée aussi : on déballe jusqu'à l'enveloppe
        // de chiffrement, sans dépendre du nombre exact de couches.
        $layer = $payload;

        for ($i = 0; $i < 5 && ! str_contains($layer, '"iv"'); $i++) {
            $next = base64_decode($layer, true);

            if ($next === false || $next === '') {
                break;
            }

            $layer = $next;
        }

        expect($payload)->not->toBe('')
            // Sans chiffrement, on lirait ici « login_web_… » et le jeton CSRF.
            ->and($payload)->not->toContain('login_web_')
            ->and($layer)->not->toContain('login_web_')
            ->and($layer)->not->toContain('_token')
            ->and($layer)->toContain('"iv"')
            ->and($layer)->toContain('"mac"');
    });
});

it('n\'accepte aucun jeton porteur : l\'authentification est uniquement par cookie de session', function (): void {
    $user = userWithRole(Role::SuperAdmin);
    $plain = Str::random(40);

    // Même une ligne insérée à la main dans personal_access_tokens n'ouvre rien : le modèle
    // User n'utilise pas HasApiTokens, donc Sanctum refuse d'authentifier par jeton.
    $id = DB::table('personal_access_tokens')->insertGetId([
        'tokenable_type' => 'user',
        'tokenable_id' => $user->id,
        'name' => 'jeton-forge',
        'token' => hash('sha256', $plain),
        'abilities' => '["*"]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->withHeader('Authorization', "Bearer {$id}|{$plain}")
        ->getJson('/api/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');

    $this->withHeader('Authorization', "Bearer {$id}|{$plain}")
        ->getJson('/api/admin/users')
        ->assertUnauthorized();

    expect(class_uses_recursive(User::class))->not->toContain(HasApiTokens::class);
});
