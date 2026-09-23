<?php

require_once __DIR__.'/helpers.php';

use App\Enums\Role;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\Auth\InvitationNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    config(['auth.timebox_duration' => 0]);
    Notification::fake();
});

function authForgot(string $email, string $ip = '127.0.0.1'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeaders(AUTH_SPA_HEADERS)
        ->postJson('/api/auth/forgot-password', ['email' => $email]);
}

/**
 * @param  array<string, string>  $overrides
 */
function authResetPassword(string $email, string $token, array $overrides = []): TestResponse
{
    return test()->withHeaders(AUTH_SPA_HEADERS)->postJson('/api/auth/reset-password', [
        'token' => $token,
        'email' => $email,
        'password' => 'nouveaumotdepasse2027',
        'password_confirmation' => 'nouveaumotdepasse2027',
        ...$overrides,
    ]);
}

/**
 * Jeton du dernier e-mail de réinitialisation envoyé au compte.
 */
function authSentResetToken(User $user): string
{
    $token = null;
    Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $n) use (&$token): bool {
        $token = $n->token;

        return true;
    });

    return (string) $token;
}

function authSentInvitationToken(User $user): string
{
    $token = null;
    Notification::assertSentTo($user, InvitationNotification::class, function (InvitationNotification $n) use (&$token): bool {
        $token = $n->token;

        return true;
    });

    return (string) $token;
}

describe('POST /api/auth/forgot-password', function (): void {
    it('envoie un lien de réinitialisation français vers le SPA (60 min)', function (): void {
        $user = User::factory()->create(['email' => 'jean@exemple.org']);

        authForgot('Jean@Exemple.org')->assertOk()->assertExactJson(['message' => PasswordResetController::NEUTRAL_MESSAGE]);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user): bool {
            $mail = $notification->toMail($user);
            $expected = 'http://localhost/admin/reinitialiser?token='.$notification->token.'&email='.rawurlencode('jean@exemple.org');

            return $mail->actionUrl === $expected
                && $mail->subject === 'Réinitialisation de votre mot de passe'
                && str_contains(implode(' ', $mail->outroLines), '60 minutes')
                && $notification instanceof ShouldQueue;
        });
        expect(DB::table('password_reset_tokens')->where('email', 'jean@exemple.org')->exists())->toBeTrue();
    });

    it('répond exactement pareil pour un compte inconnu, désactivé ou invité, sans rien envoyer', function (): void {
        User::factory()->inactive()->create(['email' => 'inactif@exemple.org']);
        $invited = User::factory()->invited()->create(['email' => 'invite@exemple.org']);
        DB::table('invitation_tokens')->insert(['email' => 'invite@exemple.org', 'token' => Hash::make('invitation'), 'created_at' => now()]);

        foreach (['inconnu@exemple.org', 'inactif@exemple.org', 'invite@exemple.org'] as $email) {
            authForgot($email)->assertOk()->assertExactJson(['message' => PasswordResetController::NEUTRAL_MESSAGE]);
        }

        Notification::assertNothingSent();
        // L'invitation en cours n'est pas écrasée par une demande de réinitialisation.
        expect(Hash::check('invitation', (string) DB::table('invitation_tokens')->where('email', $invited->email)->value('token')))->toBeTrue()
            ->and(DB::table('password_reset_tokens')->count())->toBe(0);
    });

    it('n\'envoie qu\'un e-mail par minute pour un même compte (réponse toujours neutre)', function (): void {
        $user = User::factory()->create(['email' => 'jean@exemple.org']);

        authForgot('jean@exemple.org')->assertOk();
        authForgot('jean@exemple.org')->assertOk()->assertExactJson(['message' => PasswordResetController::NEUTRAL_MESSAGE]);

        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
    });

    it('limite les demandes à 10 / 15 min par IP (429)', function (): void {
        foreach (range(1, 10) as $n) {
            authForgot("inconnu{$n}@exemple.org")->assertOk();
        }

        authForgot('encore@exemple.org')->assertStatus(429)->assertHeader('Retry-After');
    });

    it('valide l\'adresse e-mail', function (): void {
        authForgot('pas-un-email')->assertStatus(422)->assertJsonValidationErrors('email');
    });
});

describe('POST /api/auth/reset-password', function (): void {
    it('définit le nouveau mot de passe (haché une fois), supprime toutes les sessions et journalise', function (): void {
        $user = User::factory()->create(['email' => 'jean@exemple.org']);
        DB::table('sessions')->insert(['id' => 'session-volee', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        authForgot('jean@exemple.org');
        $token = authSentResetToken($user);

        authResetPassword('JEAN@exemple.org', $token)->assertNoContent();

        $hash = (string) $user->refresh()->password;
        expect(Hash::check('nouveaumotdepasse2027', $hash))->toBeTrue()
            ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
            ->and(DB::table('password_reset_tokens')->where('email', 'jean@exemple.org')->exists())->toBeFalse();

        $log = AuditLog::query()->where('action', 'auth.password_changed')->sole();
        expect($log->user_id)->toBe($user->id)->and($log->meta)->toBe(['via' => 'reset']);

        authLogin('jean@exemple.org', 'nouveaumotdepasse2027')->assertOk();
    });

    it('n\'accepte le lien qu\'une seule fois', function (): void {
        $user = User::factory()->create(['email' => 'jean@exemple.org']);
        authForgot('jean@exemple.org');
        $token = authSentResetToken($user);

        authResetPassword('jean@exemple.org', $token)->assertNoContent();
        authResetPassword('jean@exemple.org', $token, ['password' => 'autremotdepasse2028', 'password_confirmation' => 'autremotdepasse2028'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');
    });

    it('refuse un lien de réinitialisation expiré (60 min), même si une invitation dure 48 h', function (): void {
        $user = User::factory()->create(['email' => 'jean@exemple.org']);
        authForgot('jean@exemple.org');
        $token = authSentResetToken($user);

        $this->travel(61)->minutes();

        authResetPassword('jean@exemple.org', $token)
            ->assertStatus(422)
            ->assertExactJson([
                'message' => PasswordResetController::INVALID_LINK_MESSAGE,
                'code' => 'validation',
                'errors' => ['token' => [PasswordResetController::INVALID_LINK_MESSAGE]],
            ]);
        expect(Hash::check(UserFactory::PASSWORD, (string) $user->refresh()->password))->toBeTrue();
    });

    it('répond pareil pour un jeton faux et une adresse inconnue', function (): void {
        $user = User::factory()->create(['email' => 'jean@exemple.org']);
        authForgot('jean@exemple.org');
        $token = authSentResetToken($user);

        $wrongToken = authResetPassword('jean@exemple.org', str_repeat('a', 64))->assertStatus(422);
        $unknown = authResetPassword('inconnu@exemple.org', $token)->assertStatus(422);

        expect($wrongToken->json())->toBe($unknown->json());
    });

    it('applique la politique de mot de passe', function (): void {
        $user = User::factory()->create(['email' => 'jean@exemple.org']);
        authForgot('jean@exemple.org');
        $token = authSentResetToken($user);

        authResetPassword('jean@exemple.org', $token, ['password' => 'court1', 'password_confirmation' => 'court1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
        authResetPassword('jean@exemple.org', $token, ['password_confirmation' => 'different2027'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    });

    it('déverrouille un compte verrouillé une fois le mot de passe réinitialisé', function (): void {
        $user = User::factory()->locked()->create(['email' => 'jean@exemple.org']);
        authForgot('jean@exemple.org');

        authResetPassword('jean@exemple.org', authSentResetToken($user))->assertNoContent();

        expect($user->refresh()->isLocked())->toBeFalse()->and($user->failed_attempts)->toBe(0);
        authLogin('jean@exemple.org', 'nouveaumotdepasse2027')->assertOk();
    });
});

describe('invitation (broker « invitations », 48 h)', function (): void {
    beforeEach(function (): void {
        $this->admin = userWithRole(Role::SuperAdmin);
    });

    it('envoie un lien d\'invitation (…&invitation=1) et permet de choisir son mot de passe sous 48 h', function (): void {
        authActingAs($this->admin)
            ->postJson('/api/admin/users', ['name' => 'Marie D.', 'email' => 'marie@exemple.org', 'role' => 'lecteur'])
            ->assertCreated();
        $marie = User::query()->where('email', 'marie@exemple.org')->sole();

        Notification::assertSentTo($marie, InvitationNotification::class, function (InvitationNotification $notification) use ($marie): bool {
            $mail = $notification->toMail($marie);

            return $mail->actionUrl === 'http://localhost/admin/reinitialiser?token='.$notification->token
                .'&email='.rawurlencode('marie@exemple.org').'&invitation=1'
                && str_contains(implode(' ', $mail->outroLines), '48 heures')
                && str_contains(implode(' ', $mail->introLines), 'Lecteur');
        });
        $token = authSentInvitationToken($marie);

        $this->travel(47)->hours();
        authResetState();

        authResetPassword('marie@exemple.org', $token)->assertNoContent();

        $marie->refresh();
        expect($marie->isInvitationPending())->toBeFalse()
            ->and(substr_count((string) $marie->password, '$argon2id$'))->toBe(1)
            ->and(Hash::check('nouveaumotdepasse2027', (string) $marie->password))->toBeTrue();
        expect(AuditLog::query()->where('action', 'auth.password_changed')->sole()->meta)->toBe(['via' => 'invitation']);

        // Mot de passe jamais haché deux fois : la connexion fonctionne.
        authLogin('marie@exemple.org', 'nouveaumotdepasse2027')->assertOk()->assertJsonPath('user.invitation_pending', false);
    });

    it('refuse une invitation expirée (plus de 48 h)', function (): void {
        authActingAs($this->admin)
            ->postJson('/api/admin/users', ['name' => 'Marie D.', 'email' => 'marie@exemple.org', 'role' => 'lecteur'])
            ->assertCreated();
        $marie = User::query()->where('email', 'marie@exemple.org')->sole();
        $token = authSentInvitationToken($marie);

        $this->travel(49)->hours();
        authResetState();

        authResetPassword('marie@exemple.org', $token)->assertStatus(422)->assertJsonValidationErrors('token');
        expect($marie->refresh()->isInvitationPending())->toBeTrue();
    });

    it('invalide l\'ancien lien quand l\'invitation est renvoyée', function (): void {
        authActingAs($this->admin)
            ->postJson('/api/admin/users', ['name' => 'Marie D.', 'email' => 'marie@exemple.org', 'role' => 'lecteur'])
            ->assertCreated();
        $marie = User::query()->where('email', 'marie@exemple.org')->sole();
        $first = authSentInvitationToken($marie);

        authActingAs($this->admin)->postJson("/api/admin/users/{$marie->id}/invitation")->assertNoContent();
        Notification::assertSentToTimes($marie, InvitationNotification::class, 2);

        authResetState();
        authResetPassword('marie@exemple.org', $first)->assertStatus(422);
    });

    it('n\'accepte pas un jeton d\'invitation pour un compte qui a déjà un mot de passe', function (): void {
        $user = User::factory()->create(['email' => 'jean@exemple.org']);
        // Jeton inséré à la main (ne peut pas arriver par l'API) : un compte qui a un mot de passe
        // passe toujours par le broker « users » (password_reset_tokens), jamais par les invitations.
        DB::table('invitation_tokens')->insert([
            'email' => 'jean@exemple.org',
            'token' => Hash::make('jeton-connu'),
            'created_at' => now(),
        ]);

        authResetPassword('jean@exemple.org', 'jeton-connu')->assertStatus(422)->assertJsonValidationErrors('token');
        expect(Hash::check(UserFactory::PASSWORD, (string) $user->refresh()->password))->toBeTrue();
    });

    it('n\'accepte pas un lien de réinitialisation pour un compte invité', function (): void {
        $invited = User::factory()->invited()->create(['email' => 'marie@exemple.org']);
        DB::table('password_reset_tokens')->insert([
            'email' => 'marie@exemple.org',
            'token' => Hash::make('jeton-connu'),
            'created_at' => now(),
        ]);

        authResetPassword('marie@exemple.org', 'jeton-connu')->assertStatus(422)->assertJsonValidationErrors('token');
        expect($invited->refresh()->isInvitationPending())->toBeTrue();
    });
});

describe('purge quotidienne des liens expirés', function (): void {
    it('est planifiée chaque jour pour les deux brokers, sans chevauchement', function (): void {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'auth:clear-resets'))
            ->mapWithKeys(fn (Event $event): array => [trim(Str::after((string) $event->command, 'auth:clear-resets')) => $event]);

        expect($events->keys()->sort()->values()->all())->toBe(['', 'invitations']);

        foreach ($events as $event) {
            expect($event->expression)->toBe('0 2 * * *')
                ->and($event->withoutOverlapping)->toBeTrue();
        }
    });

    it('garde valable une invitation de 47 h après la purge des deux brokers', function (): void {
        $admin = userWithRole(Role::SuperAdmin);
        authActingAs($admin)
            ->postJson('/api/admin/users', ['name' => 'Marie D.', 'email' => 'marie@exemple.org', 'role' => 'lecteur'])
            ->assertCreated();
        $marie = User::query()->where('email', 'marie@exemple.org')->sole();
        $token = authSentInvitationToken($marie);

        $this->travel(47)->hours();
        $this->artisan('auth:clear-resets')->assertSuccessful();
        $this->artisan('auth:clear-resets', ['name' => 'invitations'])->assertSuccessful();

        expect(DB::table('invitation_tokens')->where('email', 'marie@exemple.org')->exists())->toBeTrue();

        authResetState();
        authResetPassword('marie@exemple.org', $token)->assertNoContent();
        expect($marie->refresh()->isInvitationPending())->toBeFalse();
    });

    it('supprime chaque jeton expiré selon la durée de son broker', function (): void {
        $rows = [
            ['password_reset_tokens', 'reset-recent@exemple.org', 59],
            ['password_reset_tokens', 'reset-expire@exemple.org', 61],
            ['invitation_tokens', 'invitation-recente@exemple.org', 47 * 60],
            ['invitation_tokens', 'invitation-expiree@exemple.org', 49 * 60],
        ];

        foreach ($rows as [$table, $email, $minutes]) {
            DB::table($table)->insert(['email' => $email, 'token' => Hash::make('x'), 'created_at' => now()->subMinutes($minutes)]);
        }

        $this->artisan('auth:clear-resets')->assertSuccessful();

        expect(DB::table('password_reset_tokens')->pluck('email')->all())->toBe(['reset-recent@exemple.org'])
            ->and(DB::table('invitation_tokens')->count())->toBe(2);

        $this->artisan('auth:clear-resets', ['name' => 'invitations'])->assertSuccessful();

        expect(DB::table('invitation_tokens')->pluck('email')->all())->toBe(['invitation-recente@exemple.org'])
            ->and(DB::table('password_reset_tokens')->pluck('email')->all())->toBe(['reset-recent@exemple.org']);
    });
});

describe('désactivation du compte (revue de sécurité)', function (): void {
    it('purge les liens en cours dès la désactivation du compte', function (): void {
        $admin = userWithRole(Role::SuperAdmin);
        $user = User::factory()->create(['email' => 'jean@exemple.org']);
        authForgot('jean@exemple.org');
        $token = authSentResetToken($user);

        expect(DB::table('password_reset_tokens')->where('email', 'jean@exemple.org')->exists())->toBeTrue();

        authActingAs($admin)->patchJson("/api/admin/users/{$user->id}", ['is_active' => false])->assertOk();

        expect(DB::table('password_reset_tokens')->where('email', 'jean@exemple.org')->exists())->toBeFalse();

        authResetPassword('jean@exemple.org', $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');
        expect(Hash::check(UserFactory::PASSWORD, (string) $user->refresh()->password))->toBeTrue();
    });

    it('refuse un lien de réinitialisation envoyé avant la désactivation (filtre is_active)', function (): void {
        $user = User::factory()->create(['email' => 'jean@exemple.org']);
        authForgot('jean@exemple.org');
        $token = authSentResetToken($user);

        // Désactivation « hors API » (commande, base) : le jeton survit, mais ne doit plus servir.
        $user->forceFill(['is_active' => false])->save();

        authResetPassword('jean@exemple.org', $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');

        expect(Hash::check(UserFactory::PASSWORD, (string) $user->refresh()->password))->toBeTrue();

        // Réactivé, le même lien redevient utilisable (aucun effet de bord indésirable).
        $user->forceFill(['is_active' => true])->save();
        authResetPassword('jean@exemple.org', $token)->assertNoContent();
    });

    it('refuse une invitation acceptée après la désactivation du compte', function (): void {
        $admin = userWithRole(Role::SuperAdmin);

        authActingAs($admin)
            ->postJson('/api/admin/users', ['name' => 'Marie D.', 'email' => 'marie@exemple.org', 'role' => 'lecteur'])
            ->assertCreated();
        $marie = User::query()->where('email', 'marie@exemple.org')->sole();
        $token = authSentInvitationToken($marie);

        $marie->forceFill(['is_active' => false])->save();

        authResetPassword('marie@exemple.org', $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');

        expect($marie->refresh()->isInvitationPending())->toBeTrue();
    });

    it('ne renvoie pas d\'invitation à un compte désactivé', function (): void {
        $admin = userWithRole(Role::SuperAdmin);
        $invited = User::factory()->invited()->create(['email' => 'marie@exemple.org']);
        $invited->forceFill(['is_active' => false])->save();

        authActingAs($admin)->postJson("/api/admin/users/{$invited->id}/invitation")
            ->assertStatus(409)
            ->assertJsonPath('code', 'invitation_not_pending');

        Notification::assertNothingSent();
    });
});
