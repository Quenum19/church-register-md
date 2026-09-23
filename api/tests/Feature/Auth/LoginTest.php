<?php

require_once __DIR__.'/helpers.php';

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\Auth\AccountLockedNotification;
use App\Services\Auth\Authenticator;
use App\Services\Auth\LoginThrottle;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    // Durée minimale des échecs (Timebox) : sans intérêt ici, elle ralentirait seulement les tests.
    config(['auth.timebox_duration' => 0]);
    Notification::fake();
});

it('connecte un compte actif et renvoie l\'objet User du contrat', function (): void {
    $user = User::factory()->moderateur()->create(['email' => 'jean@exemple.org', 'name' => 'Jean K.']);

    $response = authLogin('jean@exemple.org')->assertOk();

    $response->assertExactJsonStructure(['user' => [
        'id', 'name', 'email', 'role', 'is_active', 'two_factor_enabled', 'last_login_at', 'created_at', 'invitation_pending',
    ]])->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.name', 'Jean K.')
        ->assertJsonPath('user.email', 'jean@exemple.org')
        ->assertJsonPath('user.role', 'moderateur')
        ->assertJsonPath('user.is_active', true)
        ->assertJsonPath('user.two_factor_enabled', false)
        ->assertJsonPath('user.invitation_pending', false);

    expect($response->json('user.last_login_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
    authAssertNoSecrets($response, [UserFactory::PASSWORD]);
    $this->assertAuthenticatedAs($user, 'web');

    $user->refresh();
    expect($user->last_login_at)->not->toBeNull()
        ->and($user->failed_attempts)->toBe(0);

    $log = AuditLog::query()->where('action', 'auth.login')->sole();
    expect($log->user_id)->toBe($user->id)->and($log->ip)->toBe('127.0.0.1');
});

it('accepte l\'e-mail sans tenir compte de la casse ni des espaces', function (): void {
    $user = User::factory()->create(['email' => 'jean@exemple.org']);

    authLogin('  Jean@Exemple.ORG ')->assertOk()->assertJsonPath('user.id', $user->id);
});

it('répond le même message générique (422) pour un mot de passe faux, un compte inconnu, désactivé ou invité', function (): void {
    User::factory()->create(['email' => 'actif@exemple.org']);
    User::factory()->inactive()->create(['email' => 'inactif@exemple.org']);
    User::factory()->invited()->create(['email' => 'invite@exemple.org']);

    $expected = [
        'message' => 'Identifiants incorrects.',
        'code' => 'validation',
        'errors' => ['email' => ['Identifiants incorrects.']],
    ];

    authLogin('actif@exemple.org', 'mauvais-mot-de-passe-1')->assertStatus(422)->assertExactJson($expected);
    authLogin('inconnu@exemple.org')->assertStatus(422)->assertExactJson($expected);
    // Même avec le bon mot de passe, un compte désactivé ne se connecte pas.
    authLogin('inactif@exemple.org')->assertStatus(422)->assertExactJson($expected);
    authLogin('invite@exemple.org', 'nimportequoi2026')->assertStatus(422)->assertExactJson($expected);

    $this->assertGuest('web');
    expect(AuditLog::query()->where('action', 'auth.login')->count())->toBe(0);
});

it('vérifie un hachage factice (temps constant) pour un compte inconnu, invité ou désactivé', function (string $case): void {
    $email = match ($case) {
        'inconnu' => 'inconnu@exemple.org',
        'invité' => User::factory()->invited()->create()->email,
        'désactivé' => User::factory()->inactive()->create()->email,
    };

    $spy = new class(app('hash.driver')) implements Hasher
    {
        /** @var list<mixed> */
        public array $checked = [];

        public function __construct(private readonly Hasher $inner) {}

        public function info($hashedValue)
        {
            return $this->inner->info($hashedValue);
        }

        public function make(#[SensitiveParameter] $value, array $options = [])
        {
            return $this->inner->make($value, $options);
        }

        public function check(#[SensitiveParameter] $value, $hashedValue, array $options = [])
        {
            $this->checked[] = $hashedValue;

            return $this->inner->check($value, $hashedValue, $options);
        }

        public function needsRehash($hashedValue, array $options = [])
        {
            return $this->inner->needsRehash($hashedValue, $options);
        }
    };
    app()->instance('hash.driver', $spy);

    authLogin($email, 'unmotdepasse2026')->assertStatus(422);

    // Exactement une vérification argon2id, comme pour un vrai compte.
    expect($spy->checked)->toHaveCount(1)
        ->and($spy->checked[0])->toStartWith('$argon2id$');
})->with(['inconnu', 'invité', 'désactivé']);

it('ne compte pas une requête invalide (422 de validation) comme un échec', function (): void {
    User::factory()->create(['email' => 'jean@exemple.org']);

    foreach (range(1, 6) as $ignored) {
        $this->withHeaders(AUTH_SPA_HEADERS)->postJson('/api/auth/login', ['email' => 'jean@exemple.org'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    authLogin('jean@exemple.org')->assertOk();
});

it('répond 429 avec Retry-After après 5 échecs en 15 min pour un couple (e-mail + IP)', function (): void {
    User::factory()->create(['email' => 'jean@exemple.org']);

    foreach (range(1, 5) as $ignored) {
        authLogin('jean@exemple.org', 'mauvais-mot-de-passe-1')->assertStatus(422);
    }

    // Même le bon mot de passe est refusé tant que la limite est atteinte.
    $response = authLogin('JEAN@exemple.org')->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(900);

    // Une autre IP n'est pas concernée.
    authLogin('jean@exemple.org', UserFactory::PASSWORD, '203.0.113.9')->assertOk();
});

it('lève la limite (e-mail + IP) après 15 minutes', function (): void {
    User::factory()->create(['email' => 'jean@exemple.org']);

    foreach (range(1, 5) as $ignored) {
        authLogin('jean@exemple.org', 'mauvais-mot-de-passe-1');
    }
    authLogin('jean@exemple.org')->assertStatus(429);

    $this->travel(16)->minutes();

    authLogin('jean@exemple.org')->assertOk();
});

it('ne compte que les échecs : une connexion réussie remet les compteurs à zéro', function (): void {
    $user = User::factory()->create(['email' => 'jean@exemple.org']);

    foreach (range(1, 4) as $ignored) {
        authLogin('jean@exemple.org', 'mauvais-mot-de-passe-1')->assertStatus(422);
    }
    expect($user->refresh()->failed_attempts)->toBe(4);

    authLogin('jean@exemple.org')->assertOk();
    expect($user->refresh()->failed_attempts)->toBe(0);

    // Compteur (e-mail + IP) remis à zéro : 5 nouveaux échecs sont possibles avant le 429.
    foreach (range(1, 5) as $ignored) {
        authLogin('jean@exemple.org', 'mauvais-mot-de-passe-1')->assertStatus(422);
    }
    authLogin('jean@exemple.org', 'mauvais-mot-de-passe-1')->assertStatus(429);
});

it('bloque une IP après 20 échecs en une heure sur un compte, et laisse le titulaire se connecter ailleurs', function (): void {
    $user = User::factory()->create(['email' => 'jean@exemple.org']);
    $wrong = 'mauvais-mot-de-passe-1';
    $ip = '198.51.100.4';

    // 20 échecs depuis UNE IP : la limite (e-mail + IP) impose une pause tous les 5 essais.
    foreach (range(1, 3) as $ignored) {
        foreach (range(1, 5) as $ignored2) {
            // Un mot de passe faux ne révèle JAMAIS le verrouillage : toujours 422.
            authLogin('jean@exemple.org', $wrong, $ip)->assertStatus(422);
        }

        $this->travel(16)->minutes();
    }

    // 4e salve étalée, pour que la fenêtre (e-mail + IP) se referme avant le blocage de l'IP.
    foreach (range(1, 4) as $ignored) {
        authLogin('jean@exemple.org', $wrong, $ip)->assertStatus(422);
    }

    $this->travel(10)->minutes();
    authLogin('jean@exemple.org', $wrong, $ip)->assertStatus(422); // 20e échec : l'IP est écartée
    $this->travel(6)->minutes();

    // Même avec le BON mot de passe, cette IP reste écartée (et ce n'est plus la 429).
    $response = authLogin('jean@exemple.org', UserFactory::PASSWORD, $ip)
        ->assertStatus(423)
        ->assertJsonPath('code', 'account_locked')
        ->assertHeader('Retry-After');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(900);

    // Le déni de service ciblé est neutralisé : le compte n'est PAS verrouillé globalement
    // et son titulaire se connecte normalement depuis sa propre connexion.
    expect($user->refresh()->isLocked())->toBeFalse()
        ->and($user->locked_until)->toBeNull();

    authLogin('jean@exemple.org', UserFactory::PASSWORD, '192.0.2.10')->assertOk();

    Notification::assertSentToTimes($user, AccountLockedNotification::class, 1);
    Notification::assertSentTo($user, AccountLockedNotification::class, function (AccountLockedNotification $notification) use ($user, $wrong): bool {
        $mail = $notification->toMail($user);
        $text = implode("\n", [...$mail->introLines, ...$mail->outroLines, $mail->subject ?? '']);

        return $notification->maskedIp === '198.51.x.x'
            && $notification->wholeAccount === false
            && ! str_contains($text, '198.51.100.4')
            && ! str_contains($text, $wrong)
            && ! str_contains($text, UserFactory::PASSWORD)
            && str_contains($text, '198.51.x.x');
    });

    // Journal d'audit : IP tronquée, jamais le mot de passe.
    $locked = AuditLog::query()->where('action', 'auth.account_locked')->sole();
    expect($locked->user_id)->toBe($user->id)
        ->and($locked->ip)->toBe('198.51.x.x')
        ->and($locked->meta)->toBe(['scope' => 'ip', 'minutes' => 15]);

    expect(AuditLog::query()->where('action', 'auth.login_failed')->count())->toBe(20);
    expect(AuditLog::query()->where('action', 'auth.login_failed')->pluck('ip')->unique()->all())->toBe(['198.51.x.x']);
});

it('libère l\'IP bloquée après 15 minutes', function (): void {
    $user = User::factory()->create(['email' => 'jean@exemple.org']);
    $throttle = app(LoginThrottle::class);

    // Les 19 premiers échecs sont enregistrés directement (le trajet HTTP est déjà couvert
    // par le test précédent) ; seul le 20e, qui déclenche le blocage, passe par l'API.
    foreach (range(1, 19) as $ignored) {
        $throttle->recordFailure('jean@exemple.org', '198.51.100.4', $user->refresh());
    }

    expect($throttle->isIpLocked($user->refresh(), '198.51.100.4'))->toBeFalse();

    $throttle->recordFailure('jean@exemple.org', '198.51.100.4', $user->refresh());

    expect($throttle->isIpLocked($user->refresh(), '198.51.100.4'))->toBeTrue()
        ->and($user->isLocked())->toBeFalse();

    $this->travel(16)->minutes();

    expect($throttle->isIpLocked($user->refresh(), '198.51.100.4'))->toBeFalse();
    authLogin('jean@exemple.org', UserFactory::PASSWORD, '198.51.100.4')->assertOk();
    expect($user->refresh()->failed_attempts)->toBe(0);
});

it('verrouille le compte entier au-delà de 100 échecs par heure, toutes IP confondues', function (): void {
    $user = User::factory()->create(['email' => 'jean@exemple.org']);
    $throttle = app(LoginThrottle::class);

    // 99 échecs répartis sur 33 adresses : aucune n'atteint le seuil par IP (20).
    foreach (range(1, 33) as $n) {
        foreach (range(1, 3) as $ignored) {
            expect($throttle->recordFailure('jean@exemple.org', "198.51.100.{$n}", $user))->toBeFalse();
        }
    }

    expect($user->refresh()->isLocked())->toBeFalse();

    // Le 100e échec, depuis une 34e adresse, verrouille le compte pour tout le monde.
    expect($throttle->recordFailure('jean@exemple.org', '198.51.100.34', $user))->toBeTrue()
        ->and($user->refresh()->isLocked())->toBeTrue();

    authLogin('jean@exemple.org', UserFactory::PASSWORD, '192.0.2.10')
        ->assertStatus(423)
        ->assertJsonPath('code', 'account_locked');

    Notification::assertSentToTimes($user, AccountLockedNotification::class, 1);
    Notification::assertSentTo($user, AccountLockedNotification::class, fn (AccountLockedNotification $n): bool => $n->wholeAccount === true);

    expect(AuditLog::query()->where('action', 'auth.account_locked')->sole()->meta)
        ->toBe(['scope' => 'account', 'minutes' => 15]);

    // Déverrouillage automatique après 15 minutes.
    $this->travel(16)->minutes();
    authLogin('jean@exemple.org', UserFactory::PASSWORD, '192.0.2.10')->assertOk();
    expect($user->refresh()->locked_until)->toBeNull()->and($user->failed_attempts)->toBe(0);
});

it('n\'envoie qu\'un e-mail de verrouillage par heure et par compte', function (): void {
    $user = User::factory()->create(['email' => 'jean@exemple.org']);
    $throttle = app(LoginThrottle::class);

    $lock = function () use ($throttle, $user): void {
        foreach (range(1, LoginThrottle::ACCOUNT_IP_MAX_FAILURES) as $ignored) {
            $throttle->recordFailure('jean@exemple.org', '198.51.100.4', $user->refresh());
        }
    };

    $lock();
    Notification::assertSentToTimes($user, AccountLockedNotification::class, 1);

    // Blocage expiré puis reposé dans l'heure : pas de second e-mail (pas de flood possible).
    $this->travel(16)->minutes();
    $lock();
    Notification::assertSentToTimes($user, AccountLockedNotification::class, 1);

    // Plus d'une heure après le premier : l'alerte repart.
    $this->travel(61)->minutes();
    $lock();
    Notification::assertSentToTimes($user, AccountLockedNotification::class, 2);
});

it('ne distingue pas un compte verrouillé d\'un compte inconnu sans le bon mot de passe', function (): void {
    $locked = User::factory()->locked()->create(['email' => 'verrouille@exemple.org']);

    $expected = [
        'message' => 'Identifiants incorrects.',
        'code' => 'validation',
        'errors' => ['email' => ['Identifiants incorrects.']],
    ];

    // Adresse verrouillée et adresse inconnue : réponses rigoureusement identiques.
    authLogin('verrouille@exemple.org', 'mauvais-mot-de-passe-1')->assertStatus(422)->assertExactJson($expected);
    authLogin('inconnu@exemple.org', 'mauvais-mot-de-passe-1')->assertStatus(422)->assertExactJson($expected);

    // Seul le bon mot de passe obtient la 423 (information utile, et seulement au titulaire).
    authLogin('verrouille@exemple.org')->assertStatus(423)->assertJsonPath('code', 'account_locked');

    Notification::assertNothingSent();
    $this->assertGuest('web');
    expect($locked->refresh()->isLocked())->toBeTrue();
});

it('ne connecte pas un compte avec 2FA confirmée : attente du code en session', function (): void {
    $user = User::factory()->withTwoFactor()->create(['email' => 'jean@exemple.org']);

    authLogin('jean@exemple.org')->assertOk()->assertExactJson(['two_factor_required' => true]);

    $this->assertGuest('web');
    expect(session(Authenticator::PENDING_SESSION_KEY))->toMatchArray(['user_id' => $user->id])
        ->and($user->refresh()->last_login_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'auth.login')->exists())->toBeFalse();
});

it('connecte normalement un compte dont la 2FA est préparée mais non confirmée', function (): void {
    $user = User::factory()->create(['email' => 'jean@exemple.org']);
    $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => null])->save();

    authLogin('jean@exemple.org')->assertOk()->assertJsonPath('user.two_factor_enabled', false);
});

it('refuse une connexion hors session SPA (requête non « stateful ») sans erreur 500', function (): void {
    User::factory()->create(['email' => 'jean@exemple.org']);

    $this->postJson('/api/auth/login', ['email' => 'jean@exemple.org', 'password' => UserFactory::PASSWORD])
        ->assertStatus(400)
        ->assertJsonPath('code', 'bad_request');
});
