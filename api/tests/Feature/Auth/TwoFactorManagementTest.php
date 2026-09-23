<?php

require_once __DIR__.'/helpers.php';

use App\Models\AuditLog;
use App\Models\User;
use App\Services\SettingsService;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config(['auth.timebox_duration' => 0]);
    $this->user = User::factory()->create(['email' => 'jean@exemple.org']);
});

it('prépare la 2FA avec le mot de passe : secret, URL otpauth (émetteur = nom de l\'église), 8 codes', function (): void {
    app(SettingsService::class)->set('church_name', 'Église de Test');

    $response = authActingAs($this->user)
        ->postJson('/api/auth/two-factor/enable', ['password' => UserFactory::PASSWORD])
        ->assertOk()
        ->assertExactJsonStructure(['secret', 'otpauth_url', 'recovery_codes']);

    $secret = $response->json('secret');
    expect($secret)->toMatch('/^[A-Z2-7]{32}$/')
        ->and($response->json('otpauth_url'))->toStartWith('otpauth://totp/'.rawurlencode('Église de Test').':'.rawurlencode('jean@exemple.org').'?secret='.$secret)
        ->and($response->json('otpauth_url'))->toContain('&issuer='.rawurlencode('Église de Test'))
        ->and($response->json('recovery_codes'))->toHaveCount(8)
        ->and(array_unique($response->json('recovery_codes')))->toHaveCount(8);

    // Non confirmée : la 2FA n'est pas encore active.
    $user = $this->user->refresh();
    expect($user->two_factor_secret)->toBe($secret)
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->hasTwoFactorEnabled())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'auth.two_factor_enabled')->exists())->toBeFalse();

    authActingAs($user)->getJson('/api/auth/me')->assertJsonPath('user.two_factor_enabled', false);
});

it('stocke le secret et les codes chiffrés en base', function (): void {
    $response = authActingAs($this->user)->postJson('/api/auth/two-factor/enable', ['password' => UserFactory::PASSWORD]);

    $row = DB::table('users')->where('id', $this->user->id)->first();
    expect($row->two_factor_secret)->not->toContain($response->json('secret'))
        ->and($row->two_factor_recovery_codes)->not->toContain($response->json('recovery_codes.0'));
});

it('refuse l\'activation avec un mot de passe incorrect', function (): void {
    authActingAs($this->user)->postJson('/api/auth/two-factor/enable', ['password' => 'mauvais-mot-de-passe-1'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');

    expect($this->user->refresh()->two_factor_secret)->toBeNull();
});

it('refuse de reconfigurer une 2FA déjà active (409)', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    authActingAs($user)->postJson('/api/auth/two-factor/enable', ['password' => UserFactory::PASSWORD])
        ->assertStatus(409)
        ->assertJsonPath('code', 'two_factor_already_enabled');

    expect($user->refresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('confirme la 2FA avec un code valide, journalise auth.two_factor_enabled et l\'exige ensuite à la connexion', function (): void {
    $secret = authActingAs($this->user)->postJson('/api/auth/two-factor/enable', ['password' => UserFactory::PASSWORD])->json('secret');

    $wrong = authTotp($secret) === '000000' ? '111111' : '000000';
    authActingAs($this->user)->postJson('/api/auth/two-factor/confirm', ['code' => $wrong])
        ->assertStatus(422)
        ->assertJsonPath('errors.code.0', 'Le code est invalide.');
    expect($this->user->refresh()->two_factor_confirmed_at)->toBeNull();

    authActingAs($this->user)->postJson('/api/auth/two-factor/confirm', ['code' => authTotp($secret)])->assertNoContent();

    expect($this->user->refresh()->hasTwoFactorEnabled())->toBeTrue();
    $log = AuditLog::query()->where('action', 'auth.two_factor_enabled')->sole();
    expect($log->user_id)->toBe($this->user->id)->and($log->meta)->toBeNull();

    authActingAs($this->user)->getJson('/api/auth/me')->assertJsonPath('user.two_factor_enabled', true);

    authResetState();
    authLogin('jean@exemple.org')->assertOk()->assertExactJson(['two_factor_required' => true]);
});

it('refuse la confirmation sans activation préalable ou avec un code mal formé', function (): void {
    authActingAs($this->user)->postJson('/api/auth/two-factor/confirm', ['code' => '123456'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');

    authActingAs($this->user)->postJson('/api/auth/two-factor/confirm', ['code' => 'abc'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

it('désactive la 2FA avec le mot de passe et journalise auth.two_factor_disabled', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    authActingAs($user)->deleteJson('/api/auth/two-factor', ['password' => 'mauvais-mot-de-passe-1'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
    expect($user->refresh()->hasTwoFactorEnabled())->toBeTrue();

    authActingAs($user)->deleteJson('/api/auth/two-factor', ['password' => UserFactory::PASSWORD])->assertNoContent();

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();
    expect(AuditLog::query()->where('action', 'auth.two_factor_disabled')->sole()->user_id)->toBe($user->id);
});

it('limite les essais de mot de passe sur les opérations 2FA', function (): void {
    foreach (range(1, 5) as $ignored) {
        authActingAs($this->user)->postJson('/api/auth/two-factor/enable', ['password' => 'mauvais-mot-de-passe-1'])->assertStatus(422);
    }

    authActingAs($this->user)->postJson('/api/auth/two-factor/enable', ['password' => UserFactory::PASSWORD])->assertStatus(429);
});

it('exige une session authentifiée', function (string $method, string $uri): void {
    $this->json($method, $uri, ['password' => UserFactory::PASSWORD, 'code' => '123456'])->assertUnauthorized();
})->with([
    ['POST', '/api/auth/two-factor/enable'],
    ['POST', '/api/auth/two-factor/confirm'],
    ['DELETE', '/api/auth/two-factor'],
]);
