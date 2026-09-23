<?php

require_once __DIR__.'/helpers.php';

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\Auth\AccountLockedNotification;
use App\Services\Auth\Authenticator;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    config(['auth.timebox_duration' => 0]);
    Notification::fake();

    $this->user = User::factory()->withTwoFactor(AUTH_TOTP_SECRET)->create(['email' => 'jean@exemple.org']);
});

it('connecte avec un code TOTP valide après le mot de passe', function (): void {
    authLogin('jean@exemple.org')->assertOk()->assertExactJson(['two_factor_required' => true]);

    $response = authChallenge(['code' => authTotp(AUTH_TOTP_SECRET)])
        ->assertOk()
        ->assertJsonPath('user.id', $this->user->id)
        ->assertJsonPath('user.two_factor_enabled', true);

    authAssertNoSecrets($response, [AUTH_TOTP_SECRET, ...$this->user->two_factor_recovery_codes]);
    $this->assertAuthenticatedAs($this->user, 'web');
    expect(session(Authenticator::PENDING_SESSION_KEY))->toBeNull()
        ->and($this->user->refresh()->last_login_at)->not->toBeNull();

    $log = AuditLog::query()->where('action', 'auth.login')->sole();
    expect($log->user_id)->toBe($this->user->id)
        ->and($log->meta)->toBe(['two_factor' => 'totp']);
});

it('accepte le code de la période précédente ou suivante (fenêtre ±1) et les espaces', function (int $periods): void {
    authLogin('jean@exemple.org');

    $code = authTotp(AUTH_TOTP_SECRET, $periods);
    authChallenge(['code' => substr($code, 0, 3).' '.substr($code, 3)])->assertOk();
})->with([-1, 1]);

it('refuse un code TOTP invalide ou hors fenêtre', function (string $code): void {
    authLogin('jean@exemple.org');

    authChallenge(['code' => $code])
        ->assertStatus(422)
        ->assertJsonPath('errors.code.0', Authenticator::INVALID_CODE_MESSAGE);

    $this->assertGuest('web');
    expect($this->user->refresh()->failed_attempts)->toBe(1)
        ->and(session(Authenticator::PENDING_SESSION_KEY))->not->toBeNull();
})->with([
    'code faux' => fn () => authTotp(AUTH_TOTP_SECRET) === '000000' ? '111111' : '000000',
    'code trop ancien' => fn () => authTotp(AUTH_TOTP_SECRET, -3),
]);

it('refuse un code mal formé en validation (sans le compter comme un échec)', function (): void {
    authLogin('jean@exemple.org');

    authChallenge(['code' => '12345'])->assertStatus(422)->assertJsonValidationErrors('code');
    authChallenge([])->assertStatus(422)->assertJsonValidationErrors(['code', 'recovery_code']);

    expect($this->user->refresh()->failed_attempts)->toBe(0);
});

it('refuse de réutiliser un code TOTP déjà accepté', function (): void {
    $code = authTotp(AUTH_TOTP_SECRET);

    authLogin('jean@exemple.org');
    authChallenge(['code' => $code])->assertOk();

    $this->withHeaders(AUTH_SPA_HEADERS)->postJson('/api/auth/logout')->assertNoContent();

    authLogin('jean@exemple.org')->assertOk()->assertExactJson(['two_factor_required' => true]);
    authChallenge(['code' => $code])->assertStatus(422);
    $this->assertGuest('web');
});

it('connecte avec un code de récupération, qui ne sert qu\'une seule fois', function (): void {
    $codes = $this->user->two_factor_recovery_codes;
    $code = $codes[3];

    authLogin('jean@exemple.org');
    authChallenge(['recovery_code' => '  '.strtoupper($code).' '])->assertOk();

    $remaining = $this->user->refresh()->two_factor_recovery_codes;
    expect($remaining)->toHaveCount(7)->not->toContain($code);
    expect(AuditLog::query()->where('action', 'auth.login')->sole()->meta)
        ->toBe(['two_factor' => 'recovery_code', 'recovery_codes_left' => 7]);

    $this->withHeaders(AUTH_SPA_HEADERS)->postJson('/api/auth/logout')->assertNoContent();

    authLogin('jean@exemple.org');
    authChallenge(['recovery_code' => $code])
        ->assertStatus(422)
        ->assertJsonPath('errors.recovery_code.0', Authenticator::INVALID_CODE_MESSAGE);
    $this->assertGuest('web');
});

it('répond 422 two_factor_expired quand la connexion en attente a expiré (5 min)', function (): void {
    authLogin('jean@exemple.org');

    $this->travel(6)->minutes();

    authChallenge(['code' => authTotp(AUTH_TOTP_SECRET)])
        ->assertStatus(422)
        ->assertExactJson([
            'message' => Authenticator::PENDING_EXPIRED_MESSAGE,
            'code' => 'two_factor_expired',
            'errors' => ['code' => [Authenticator::PENDING_EXPIRED_MESSAGE]],
        ]);

    $this->assertGuest('web');
    expect(session(Authenticator::PENDING_SESSION_KEY))->toBeNull()
        // Une attente expirée n'est pas un échec d'authentification.
        ->and($this->user->refresh()->failed_attempts)->toBe(0);
});

it('accepte encore le code juste avant l\'expiration de l\'attente', function (): void {
    authLogin('jean@exemple.org');

    $this->travel(Authenticator::PENDING_TTL_MINUTES * 60 - 5)->seconds();

    authChallenge(['code' => authTotp(AUTH_TOTP_SECRET)])->assertOk();
});

it('répond 422 two_factor_expired sans connexion en attente', function (): void {
    authChallenge(['recovery_code' => 'abcde-fghij'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'two_factor_expired')
        ->assertJsonPath('message', Authenticator::PENDING_EXPIRED_MESSAGE)
        ->assertJsonPath('errors.recovery_code.0', Authenticator::PENDING_EXPIRED_MESSAGE);
});

it('abandonne l\'attente (two_factor_expired) si la 2FA a été désactivée ou le compte désactivé entre-temps', function (): void {
    authLogin('jean@exemple.org');
    $this->user->forceFill(['is_active' => false])->save();

    authChallenge(['code' => authTotp(AUTH_TOTP_SECRET)])
        ->assertStatus(422)
        ->assertJsonPath('code', 'two_factor_expired')
        ->assertJsonPath('errors.code.0', Authenticator::PENDING_EXPIRED_MESSAGE);
    $this->assertGuest('web');
});

it('garde le code « validation » pour un code faux (attente toujours valide)', function (): void {
    authLogin('jean@exemple.org');

    authChallenge(['code' => authTotp(AUTH_TOTP_SECRET) === '000000' ? '111111' : '000000'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation');
});

it('applique la limite de 5 échecs / 15 min (e-mail + IP) au code 2FA', function (): void {
    authLogin('jean@exemple.org');

    foreach (range(1, 5) as $ignored) {
        authChallenge(['code' => '000001'])->assertStatus(422);
    }

    authChallenge(['code' => authTotp(AUTH_TOTP_SECRET)])
        ->assertStatus(429)
        ->assertJsonPath('code', 'too_many_requests')
        ->assertHeader('Retry-After');
    $this->assertGuest('web');
});

it('partage le compteur (e-mail + IP) entre mot de passe et code 2FA', function (): void {
    foreach (range(1, 3) as $ignored) {
        authLogin('jean@exemple.org', 'mauvais-mot-de-passe-1')->assertStatus(422);
    }

    authLogin('jean@exemple.org')->assertOk();
    authChallenge(['code' => '000001'])->assertStatus(422);
    authChallenge(['code' => '000001'])->assertStatus(422);

    authChallenge(['code' => authTotp(AUTH_TOTP_SECRET)])->assertStatus(429);
});

it('bloque l\'IP après 20 échecs en une heure, codes 2FA compris', function (): void {
    $ip = '198.51.100.7';

    // 15 échecs de mot de passe depuis une MÊME IP (la limite e-mail + IP impose une pause
    // de 15 min toutes les 5 tentatives), puis 5 échecs de code depuis cette même IP :
    // les deux étapes partagent exactement les mêmes compteurs.
    foreach (range(1, 3) as $ignored) {
        foreach (range(1, 5) as $ignored2) {
            authLogin('jean@exemple.org', 'mauvais-mot-de-passe-1', $ip)->assertStatus(422);
        }

        $this->travel(16)->minutes();
    }

    // Le mot de passe correct ouvre l'étape 2FA sans remettre les compteurs à zéro.
    authLogin('jean@exemple.org', UserFactory::PASSWORD, $ip)->assertOk();

    foreach (range(1, 4) as $ignored) {
        authChallenge(['code' => '000001'], $ip)->assertStatus(422);
    }

    authChallenge(['code' => '000001'], $ip)->assertStatus(423)->assertJsonPath('code', 'account_locked');

    // Blocage de l'IP seulement : le compte reste ouvert depuis une autre connexion.
    expect($this->user->refresh()->isLocked())->toBeFalse()
        ->and(session(Authenticator::PENDING_SESSION_KEY))->toBeNull();
    Notification::assertSentToTimes($this->user, AccountLockedNotification::class, 1);
    $this->assertGuest('web');

    authLogin('jean@exemple.org', UserFactory::PASSWORD, '192.0.2.10')->assertOk()
        ->assertExactJson(['two_factor_required' => true]);
});
