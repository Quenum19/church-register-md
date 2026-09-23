<?php

require_once __DIR__.'/../../Auth/helpers.php';

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\Auth\InvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->admin = userWithRole(Role::SuperAdmin, ['name' => 'Admin Principal', 'email' => 'admin@exemple.org']);
});

describe('GET /api/admin/users', function (): void {
    it('liste les administrateurs triés par nom, au format du contrat, sans aucun secret', function (): void {
        User::factory()->moderateur()->withTwoFactor()->create(['name' => 'Zoé M.']);
        User::factory()->lecteur()->invited()->create(['name' => 'Bernard L.']);
        User::factory()->locked()->create(['name' => 'Albert V.']);

        $response = authActingAs($this->admin)->getJson('/api/admin/users')->assertOk();

        expect(array_column($response->json('data'), 'name'))->toBe(['Admin Principal', 'Albert V.', 'Bernard L.', 'Zoé M.']);
        $response->assertExactJsonStructure(['data' => ['*' => [
            'id', 'name', 'email', 'role', 'is_active', 'two_factor_enabled', 'last_login_at', 'created_at', 'invitation_pending',
        ]]]);

        $byName = collect($response->json('data'))->keyBy('name');
        expect($byName['Zoé M.']['two_factor_enabled'])->toBeTrue()
            ->and($byName['Zoé M.']['role'])->toBe('moderateur')
            ->and($byName['Bernard L.']['invitation_pending'])->toBeTrue()
            ->and($byName['Albert V.']['invitation_pending'])->toBeFalse()
            ->and($byName['Admin Principal']['last_login_at'])->toBeNull();

        authAssertNoSecrets($response, [AUTH_TOTP_SECRET]);
    });
});

describe('POST /api/admin/users', function (): void {
    it('crée un compte sans mot de passe, envoie l\'invitation et journalise user.created', function (): void {
        $response = authActingAs($this->admin)
            ->postJson('/api/admin/users', ['name' => 'Marie D.', 'email' => ' Marie@Exemple.org ', 'role' => 'moderateur'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Marie D.')
            ->assertJsonPath('data.email', 'marie@exemple.org')
            ->assertJsonPath('data.role', 'moderateur')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.two_factor_enabled', false)
            ->assertJsonPath('data.last_login_at', null)
            ->assertJsonPath('data.invitation_pending', true);
        authAssertNoSecrets($response);

        $marie = User::query()->findOrFail($response->json('data.id'));
        expect($marie->password)->toBeNull()
            ->and(DB::table('invitation_tokens')->where('email', 'marie@exemple.org')->exists())->toBeTrue()
            // Jeton d'invitation dans sa table dédiée, jamais parmi les liens de réinitialisation.
            ->and(DB::table('password_reset_tokens')->count())->toBe(0);
        Notification::assertSentToTimes($marie, InvitationNotification::class, 1);

        $log = AuditLog::query()->where('action', 'user.created')->sole();
        expect($log->user_id)->toBe($this->admin->id)
            ->and($log->subject_type)->toBe('user')
            ->and($log->subject_id)->toBe($marie->id)
            ->and($log->meta)->toBe(['role' => 'moderateur']);
    });

    it('valide nom, e-mail (unique, insensible à la casse) et rôle', function (): void {
        authActingAs($this->admin)->postJson('/api/admin/users', ['name' => str_repeat('a', 101), 'email' => 'ADMIN@exemple.org', 'role' => 'pasteur'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'role']);

        authActingAs($this->admin)->postJson('/api/admin/users', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'role']);

        Notification::assertNothingSent();
        expect(User::query()->count())->toBe(1);
    });
});

describe('PATCH /api/admin/users/{id}', function (): void {
    it('modifie nom, e-mail, rôle et état, et journalise les champs modifiés (sans secret)', function (): void {
        $user = User::factory()->lecteur()->create(['name' => 'Jean K.', 'email' => 'jean@exemple.org']);

        $response = authActingAs($this->admin)
            ->patchJson("/api/admin/users/{$user->id}", ['name' => 'Jean Kouassi', 'role' => 'moderateur', 'is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.name', 'Jean Kouassi')
            ->assertJsonPath('data.role', 'moderateur');
        authAssertNoSecrets($response);

        $log = AuditLog::query()->where('action', 'user.updated')->sole();
        expect($log->subject_id)->toBe($user->id)
            ->and($log->user_id)->toBe($this->admin->id)
            ->and($log->meta)->toBe(['fields' => ['name', 'role'], 'role' => 'moderateur']);
    });

    it('ne journalise rien quand rien ne change', function (): void {
        $user = User::factory()->lecteur()->create();

        authActingAs($this->admin)->patchJson("/api/admin/users/{$user->id}", ['role' => 'lecteur', 'is_active' => true])->assertOk();

        expect(AuditLog::query()->count())->toBe(0);
    });

    it('désactive un compte et supprime toutes ses sessions', function (): void {
        $user = User::factory()->create();
        DB::table('sessions')->insert([
            ['id' => 's1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 's2', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 's-admin', 'user_id' => $this->admin->id, 'payload' => '', 'last_activity' => time()],
        ]);

        authActingAs($this->admin)->patchJson("/api/admin/users/{$user->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        expect(DB::table('sessions')->pluck('id')->all())->toBe(['s-admin'])
            ->and(AuditLog::query()->where('action', 'user.updated')->sole()->meta)->toBe(['fields' => ['is_active'], 'is_active' => false]);
    });

    it('renvoie l\'invitation à la nouvelle adresse d\'un compte invité et invalide l\'ancien lien', function (): void {
        authActingAs($this->admin)->postJson('/api/admin/users', ['name' => 'Marie D.', 'email' => 'mari@exemple.org', 'role' => 'lecteur'])->assertCreated();
        $marie = User::query()->where('email', 'mari@exemple.org')->sole();

        authActingAs($this->admin)->patchJson("/api/admin/users/{$marie->id}", ['email' => 'marie@exemple.org'])->assertOk();

        expect(DB::table('invitation_tokens')->where('email', 'mari@exemple.org')->exists())->toBeFalse()
            ->and(DB::table('invitation_tokens')->where('email', 'marie@exemple.org')->exists())->toBeTrue();
        Notification::assertSentToTimes($marie->refresh(), InvitationNotification::class, 2);
    });

    it('valide les champs et l\'unicité de l\'e-mail', function (): void {
        $user = User::factory()->create(['email' => 'jean@exemple.org']);

        authActingAs($this->admin)->patchJson("/api/admin/users/{$user->id}", ['email' => 'Admin@exemple.org', 'role' => 'roi', 'is_active' => 'peut-être'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'role', 'is_active']);

        // Garder sa propre adresse n'est pas un doublon.
        authActingAs($this->admin)->patchJson("/api/admin/users/{$user->id}", ['email' => 'jean@exemple.org'])->assertOk();
    });

    it('répond 404 pour un compte inexistant', function (): void {
        authActingAs($this->admin)->patchJson('/api/admin/users/999999', ['name' => 'X'])->assertNotFound();
        authActingAs($this->admin)->patchJson('/api/admin/users/abc', ['name' => 'X'])->assertNotFound();
    });
});

describe('règles 409 (soi-même, dernier super_admin)', function (): void {
    it('interdit de se rétrograder, de se désactiver ou de se supprimer (forbidden_self_change)', function (string $method, array $body): void {
        $uri = "/api/admin/users/{$this->admin->id}";

        authActingAs($this->admin)->json($method, $uri, $body)
            ->assertStatus(409)
            ->assertJsonPath('code', 'forbidden_self_change');

        $admin = $this->admin->fresh();
        expect($admin)->not->toBeNull()
            ->and($admin?->role)->toBe(Role::SuperAdmin)
            ->and($admin?->is_active)->toBeTrue()
            ->and(AuditLog::query()->count())->toBe(0);
    })->with([
        'se rétrograder en modérateur' => ['PATCH', ['role' => 'moderateur']],
        'se rétrograder en lecteur' => ['PATCH', ['role' => 'lecteur', 'name' => 'Nouveau nom']],
        'se désactiver' => ['PATCH', ['is_active' => false]],
        'se supprimer' => ['DELETE', []],
    ]);

    it('autorise à modifier son propre nom ou e-mail via cette route', function (): void {
        authActingAs($this->admin)
            ->patchJson("/api/admin/users/{$this->admin->id}", ['name' => 'Admin Renommé', 'role' => 'super_admin', 'is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.name', 'Admin Renommé');
    });

    it('autorise à rétrograder, désactiver ou supprimer un autre super_admin tant qu\'il en reste un actif', function (): void {
        $other = userWithRole(Role::SuperAdmin);
        $third = userWithRole(Role::SuperAdmin);

        authActingAs($this->admin)->patchJson("/api/admin/users/{$other->id}", ['role' => 'lecteur'])->assertOk();
        authActingAs($this->admin)->patchJson("/api/admin/users/{$third->id}", ['is_active' => false])->assertOk();
        authActingAs($this->admin)->deleteJson("/api/admin/users/{$third->id}")->assertNoContent();
    });

    it('refuse de rétrograder, désactiver ou supprimer le dernier super_admin actif (last_super_admin)', function (string $method, array $body): void {
        // Course simulée : l'auteur de la requête vient d'être rétrogradé par un autre super_admin
        // (sa session le croit encore super_admin) ; la cible est alors le dernier super_admin actif.
        $target = userWithRole(Role::SuperAdmin);
        userWithRole(Role::SuperAdmin)->forceFill(['is_active' => false])->save();
        $actor = $this->admin->fresh();
        User::query()->whereKey($this->admin->id)->update(['role' => Role::Moderateur->value]);

        test()->actingAs($actor)->json($method, "/api/admin/users/{$target->id}", $body)
            ->assertStatus(409)
            ->assertJsonPath('code', 'last_super_admin');

        $target->refresh();
        expect($target->role)->toBe(Role::SuperAdmin)->and($target->is_active)->toBeTrue();
    })->with([
        'rétrograder' => ['PATCH', ['role' => 'moderateur']],
        'désactiver' => ['PATCH', ['is_active' => false]],
        'supprimer' => ['DELETE', []],
    ]);

    it('autorise toute modification d\'un super_admin déjà désactivé', function (): void {
        $inactive = userWithRole(Role::SuperAdmin);
        $inactive->forceFill(['is_active' => false])->save();

        authActingAs($this->admin)->patchJson("/api/admin/users/{$inactive->id}", ['role' => 'lecteur'])->assertOk();
    });
});

describe('DELETE /api/admin/users/{id}', function (): void {
    it('supprime le compte, ses sessions et ses liens, et journalise user.deleted', function (): void {
        $user = User::factory()->moderateur()->create(['email' => 'jean@exemple.org']);
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        foreach (['password_reset_tokens', 'invitation_tokens'] as $table) {
            DB::table($table)->insert(['email' => 'jean@exemple.org', 'token' => Hash::make('x'), 'created_at' => now()]);
        }

        authActingAs($this->admin)->deleteJson("/api/admin/users/{$user->id}")->assertNoContent();

        expect(User::query()->find($user->id))->toBeNull()
            ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
            ->and(DB::table('password_reset_tokens')->where('email', 'jean@exemple.org')->exists())->toBeFalse()
            ->and(DB::table('invitation_tokens')->where('email', 'jean@exemple.org')->exists())->toBeFalse();

        $log = AuditLog::query()->where('action', 'user.deleted')->sole();
        expect($log->subject_type)->toBe('user')
            ->and($log->subject_id)->toBe($user->id)
            ->and($log->user_id)->toBe($this->admin->id)
            ->and($log->meta)->toBe(['email' => 'jean@exemple.org', 'role' => 'moderateur']);
    });

    it('conserve le journal écrit par un compte supprimé (auteur à null)', function (): void {
        $user = User::factory()->create();
        $entry = AuditLog::factory()->create(['user_id' => $user->id, 'action' => 'auth.login']);

        authActingAs($this->admin)->deleteJson("/api/admin/users/{$user->id}")->assertNoContent();

        expect($entry->refresh()->user_id)->toBeNull();
    });

    it('répond 404 pour un compte inexistant', function (): void {
        authActingAs($this->admin)->deleteJson('/api/admin/users/999999')->assertNotFound()->assertJsonPath('code', 'not_found');
    });
});

describe('POST /api/admin/users/{id}/invitation', function (): void {
    it('renvoie l\'invitation d\'un compte en attente (204) et journalise', function (): void {
        $invited = User::factory()->invited()->create();

        authActingAs($this->admin)->postJson("/api/admin/users/{$invited->id}/invitation")->assertNoContent();

        Notification::assertSentToTimes($invited, InvitationNotification::class, 1);
        expect(AuditLog::query()->where('action', 'user.updated')->sole()->meta)->toBe(['invitation_resent' => true]);
    });

    it('répond 409 si le compte a déjà choisi son mot de passe', function (): void {
        $user = User::factory()->create();

        authActingAs($this->admin)->postJson("/api/admin/users/{$user->id}/invitation")
            ->assertStatus(409)
            ->assertJsonPath('code', 'invitation_not_pending');

        Notification::assertNothingSent();
    });
});

describe('permissions', function (): void {
    it('refuse users.* au lecteur et au modérateur (403)', function (Role $role, string $method, string $uri): void {
        $target = User::factory()->invited()->create();
        $uri = str_replace('{id}', (string) $target->id, $uri);

        authActingAs(userWithRole($role))->json($method, $uri, ['name' => 'X', 'email' => 'x@exemple.org', 'role' => 'lecteur'])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        Notification::assertNothingSent();
        expect(AuditLog::query()->count())->toBe(0);
    })->with([Role::Lecteur, Role::Moderateur])->with([
        ['GET', '/api/admin/users'],
        ['POST', '/api/admin/users'],
        ['PATCH', '/api/admin/users/{id}'],
        ['DELETE', '/api/admin/users/{id}'],
        ['POST', '/api/admin/users/{id}/invitation'],
    ]);

    it('répond 401 sans session et coupe un super_admin désactivé', function (): void {
        $this->getJson('/api/admin/users')->assertUnauthorized();

        $inactive = userWithRole(Role::SuperAdmin);
        $inactive->forceFill(['is_active' => false])->save();
        authActingAs($inactive)->getJson('/api/admin/users')->assertUnauthorized();
    });
});
