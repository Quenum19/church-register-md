<?php

require_once __DIR__.'/helpers.php';

use App\Models\AuditLog;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    config(['auth.timebox_duration' => 0]);
    $this->user = User::factory()->create(['name' => 'Jean K.', 'email' => 'jean@exemple.org']);
});

describe('PATCH /api/auth/profile', function (): void {
    it('modifie le nom et l\'e-mail (normalisé) et journalise les champs modifiés', function (): void {
        $response = authActingAs($this->user)
            ->patchJson('/api/auth/profile', ['name' => 'Jean Kouassi', 'email' => ' Jean.K@Exemple.org ', 'current_password' => UserFactory::PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.name', 'Jean Kouassi')
            ->assertJsonPath('user.email', 'jean.k@exemple.org');

        authAssertNoSecrets($response, [UserFactory::PASSWORD]);
        expect($this->user->refresh()->email)->toBe('jean.k@exemple.org');

        $log = AuditLog::query()->where('action', 'user.updated')->sole();
        expect($log->user_id)->toBe($this->user->id)
            ->and($log->subject_type)->toBe('user')
            ->and($log->subject_id)->toBe($this->user->id)
            ->and($log->meta)->toBe(['fields' => ['name', 'email']]);
    });

    it('accepte une modification partielle et ne journalise rien sans changement', function (): void {
        authActingAs($this->user)->patchJson('/api/auth/profile', ['name' => 'Jean K.'])
            ->assertOk()
            ->assertJsonPath('user.email', 'jean@exemple.org');

        expect(AuditLog::query()->count())->toBe(0);
    });

    it('ne demande pas le mot de passe pour changer le nom, ni quand l\'e-mail envoyé est inchangé', function (): void {
        authActingAs($this->user)->patchJson('/api/auth/profile', ['name' => 'Jean Kouassi', 'email' => ' JEAN@exemple.org'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Jean Kouassi')
            ->assertJsonPath('user.email', 'jean@exemple.org');

        expect(AuditLog::query()->where('action', 'user.updated')->sole()->meta)->toBe(['fields' => ['name']]);
    });

    it('exige le mot de passe actuel pour changer d\'e-mail (422 sur current_password)', function (array $body, string $message): void {
        authActingAs($this->user)->patchJson('/api/auth/profile', ['name' => 'Jean Kouassi', 'email' => 'nouveau@exemple.org', ...$body])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation')
            ->assertJsonPath('errors.current_password.0', $message)
            ->assertJsonMissingValidationErrors(['email', 'name']);

        $this->user->refresh();
        expect($this->user->email)->toBe('jean@exemple.org')
            ->and($this->user->name)->toBe('Jean K.')
            ->and(AuditLog::query()->count())->toBe(0);
    })->with([
        'absent' => [[], 'Saisissez votre mot de passe actuel pour changer d\'adresse e-mail.'],
        'vide' => [['current_password' => ''], 'Saisissez votre mot de passe actuel pour changer d\'adresse e-mail.'],
        'incorrect' => [['current_password' => 'mauvais-mot-de-passe-1'], 'Le mot de passe est incorrect.'],
    ]);

    it('limite les essais du mot de passe lors d\'un changement d\'e-mail (compteur partagé, 5 / 15 min)', function (): void {
        foreach (range(1, 3) as $ignored) {
            authActingAs($this->user)
                ->patchJson('/api/auth/profile', ['email' => 'nouveau@exemple.org', 'current_password' => 'mauvais-mot-de-passe-1'])
                ->assertStatus(422);
        }

        foreach (range(1, 2) as $ignored) {
            authActingAs($this->user)->putJson('/api/auth/password', [
                'current_password' => 'mauvais-mot-de-passe-1',
                'password' => 'nouveaumotdepasse2027',
                'password_confirmation' => 'nouveaumotdepasse2027',
            ])->assertStatus(422);
        }

        authActingAs($this->user)
            ->patchJson('/api/auth/profile', ['email' => 'nouveau@exemple.org', 'current_password' => UserFactory::PASSWORD])
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests')
            ->assertHeader('Retry-After');

        expect($this->user->refresh()->email)->toBe('jean@exemple.org');
    });

    it('refuse un e-mail déjà utilisé (insensible à la casse) ou invalide', function (): void {
        User::factory()->create(['email' => 'marie@exemple.org']);

        authActingAs($this->user)->patchJson('/api/auth/profile', ['email' => 'MARIE@exemple.org'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        authActingAs($this->user)->patchJson('/api/auth/profile', ['email' => 'pas-un-email', 'name' => str_repeat('a', 101)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'name']);

        authActingAs($this->user)->patchJson('/api/auth/profile', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    });

    it('invalide un lien de réinitialisation envoyé à l\'ancienne adresse', function (): void {
        DB::table('password_reset_tokens')->insert(['email' => 'jean@exemple.org', 'token' => Hash::make('x'), 'created_at' => now()]);

        authActingAs($this->user)
            ->patchJson('/api/auth/profile', ['email' => 'nouveau@exemple.org', 'current_password' => UserFactory::PASSWORD])
            ->assertOk();

        expect(DB::table('password_reset_tokens')->where('email', 'jean@exemple.org')->exists())->toBeFalse();
    });

    it('répond 401 sans session', function (): void {
        $this->patchJson('/api/auth/profile', ['name' => 'X'])->assertUnauthorized();
    });
});

describe('PUT /api/auth/password', function (): void {
    it('change le mot de passe (haché une seule fois) et journalise auth.password_changed', function (): void {
        authActingAs($this->user)->putJson('/api/auth/password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => 'nouveaumotdepasse2027',
            'password_confirmation' => 'nouveaumotdepasse2027',
        ])->assertNoContent();

        $hash = (string) $this->user->refresh()->password;
        expect($hash)->toStartWith('$argon2id$')
            ->and(Hash::check('nouveaumotdepasse2027', $hash))->toBeTrue()
            ->and(Hash::check(UserFactory::PASSWORD, $hash))->toBeFalse();

        $log = AuditLog::query()->where('action', 'auth.password_changed')->sole();
        expect($log->user_id)->toBe($this->user->id)->and($log->meta)->toBe(['via' => 'profile']);

        // Connexion possible avec le nouveau mot de passe.
        authResetState();
        authLogin('jean@exemple.org', 'nouveaumotdepasse2027')->assertOk();
    });

    it('supprime toutes les autres sessions du compte (sessions en base)', function (): void {
        $other = User::factory()->create();
        foreach (['s-autre-1' => $this->user->id, 's-autre-2' => $this->user->id, 's-collegue' => $other->id] as $id => $userId) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => time()]);
        }

        authActingAs($this->user)->putJson('/api/auth/password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => 'nouveaumotdepasse2027',
            'password_confirmation' => 'nouveaumotdepasse2027',
        ])->assertNoContent();

        expect(DB::table('sessions')->where('user_id', $this->user->id)->count())->toBe(0)
            ->and(DB::table('sessions')->where('id', 's-collegue')->exists())->toBeTrue();
    });

    it('refuse un mot de passe actuel incorrect', function (): void {
        authActingAs($this->user)->putJson('/api/auth/password', [
            'current_password' => 'mauvais-mot-de-passe-1',
            'password' => 'nouveaumotdepasse2027',
            'password_confirmation' => 'nouveaumotdepasse2027',
        ])->assertStatus(422)->assertJsonPath('errors.current_password.0', 'Le mot de passe est incorrect.');

        expect(Hash::check(UserFactory::PASSWORD, (string) $this->user->refresh()->password))->toBeTrue()
            ->and(AuditLog::query()->count())->toBe(0);
    });

    it('limite les essais du mot de passe actuel (5 / 15 min par compte)', function (): void {
        $body = ['current_password' => 'mauvais-mot-de-passe-1', 'password' => 'nouveaumotdepasse2027', 'password_confirmation' => 'nouveaumotdepasse2027'];

        foreach (range(1, 5) as $ignored) {
            authActingAs($this->user)->putJson('/api/auth/password', $body)->assertStatus(422);
        }

        authActingAs($this->user)->putJson('/api/auth/password', [...$body, 'current_password' => UserFactory::PASSWORD])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    });

    it('applique la politique : 12 caractères, lettres et chiffres, confirmation', function (string $password, string $confirmation): void {
        authActingAs($this->user)->putJson('/api/auth/password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    })->with([
        'trop court' => ['court2026', 'court2026'],
        'sans chiffre' => ['motdepassesanschiffre', 'motdepassesanschiffre'],
        'sans lettre' => ['123456789012345', '123456789012345'],
        'confirmation différente' => ['nouveaumotdepasse2027', 'nouveaumotdepasse2028'],
        'forme de hachage' => fn () => [$hash = Hash::make('x'), $hash],
    ]);
});
