<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Exception\InvalidOptionException;

const PASSWORD_LABEL = 'Mot de passe (12 caractères minimum, lettres et chiffres)';
const CONFIRMATION_LABEL = 'Confirmation du mot de passe';

it('crée un super_admin actif de façon interactive', function (): void {
    $this->artisan('admin:create')
        ->expectsQuestion('Nom', 'Jean Kouassi')
        ->expectsQuestion('Adresse e-mail', ' Jean.Kouassi@Exemple.org ')
        ->expectsQuestion(PASSWORD_LABEL, 'motdepasse2026')
        ->expectsQuestion(CONFIRMATION_LABEL, 'motdepasse2026')
        ->expectsOutputToContain('Super administrateur « Jean Kouassi » créé')
        ->assertSuccessful();

    $user = User::query()->sole();

    expect($user->name)->toBe('Jean Kouassi')
        ->and($user->email)->toBe('jean.kouassi@exemple.org')
        ->and($user->role)->toBe(Role::SuperAdmin)
        ->and($user->is_active)->toBeTrue()
        ->and($user->password)->toStartWith('$argon2id$')
        ->and(Hash::check('motdepasse2026', (string) $user->password))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'user.created')->where('subject_id', $user->id)->exists())->toBeTrue();
});

it('accepte --name et --email mais demande toujours le mot de passe', function (): void {
    $this->artisan('admin:create', ['--name' => 'Awa Traoré', '--email' => 'awa@exemple.org'])
        ->expectsQuestion(PASSWORD_LABEL, 'Louange2026abc')
        ->expectsQuestion(CONFIRMATION_LABEL, 'Louange2026abc')
        ->assertSuccessful();

    expect(User::query()->where('email', 'awa@exemple.org')->value('role'))->toBe(Role::SuperAdmin);
});

it('redemande le mot de passe si la confirmation diffère', function (): void {
    $this->artisan('admin:create', ['--name' => 'Awa Traoré', '--email' => 'awa@exemple.org'])
        ->expectsQuestion(PASSWORD_LABEL, 'motdepasse2026')
        ->expectsQuestion(CONFIRMATION_LABEL, 'autrechose2026')
        ->expectsOutputToContain('Les deux mots de passe ne correspondent pas.')
        ->expectsQuestion(PASSWORD_LABEL, 'motdepasse2026')
        ->expectsQuestion(CONFIRMATION_LABEL, 'motdepasse2026')
        ->assertSuccessful();

    expect(User::query()->count())->toBe(1);
});

it('refuse un mot de passe trop faible', function (string $password, string $message): void {
    // Hors tests, la question est reposée ; en test, la commande s'arrête en échec.
    $this->artisan('admin:create', ['--name' => 'Awa Traoré', '--email' => 'awa@exemple.org'])
        ->expectsQuestion(PASSWORD_LABEL, $password)
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
})->with([
    'trop court' => ['abc123', 'au moins 12 caractères'],
    'sans chiffre' => ['motdepassesanschiffre', 'au moins un chiffre'],
    'sans lettre' => ['123456789012345', 'au moins une lettre'],
]);

it('refuse un e-mail invalide ou déjà utilisé', function (): void {
    User::factory()->create(['email' => 'pris@exemple.org']);

    $this->artisan('admin:create', ['--name' => 'Test', '--email' => 'pris@exemple.org'])->assertFailed();
    $this->artisan('admin:create', ['--name' => 'Test', '--email' => 'pas-un-email'])->assertFailed();

    expect(User::query()->count())->toBe(1);
});

it('n\'accepte aucun mot de passe en option', function (): void {
    $this->artisan('admin:create', ['--name' => 'Test', '--email' => 'test@exemple.org', '--password' => 'motdepasse2026']);
})->throws(InvalidOptionException::class);
