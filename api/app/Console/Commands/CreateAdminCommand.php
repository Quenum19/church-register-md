<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Création interactive d'un super_admin (premier compte, exécuté en SSH).
 * Le mot de passe est TOUJOURS saisi de façon masquée, jamais passé en argument ni en option.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'admin:create
        {--name= : Nom affiché de l\'administrateur}
        {--email= : Adresse e-mail de connexion}';

    protected $description = 'Crée un compte super_admin actif (mot de passe demandé de façon masquée)';

    /** Nombre d'essais accordés pour saisir deux mots de passe identiques. */
    private const PASSWORD_ATTEMPTS = 3;

    public function handle(AuditLogger $audit): int
    {
        $name = $this->resolveName();
        $email = $name === null ? null : $this->resolveEmail();
        $password = $email === null ? null : $this->askPassword();

        if ($name === null || $email === null || $password === null) {
            return self::FAILURE;
        }

        $user = new User([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => Role::SuperAdmin,
            'is_active' => true,
        ]);
        $user->save();

        $audit->log('user.created', $user, ['role' => Role::SuperAdmin->value, 'via' => 'console']);

        $this->components->info("Super administrateur « {$user->name} » créé ({$user->email}).");

        return self::SUCCESS;
    }

    private function resolveName(): ?string
    {
        $rules = ['required', 'string', 'max:100'];
        $option = $this->option('name');

        if (is_string($option) && trim($option) !== '') {
            return $this->validOrNull('name', trim($option), $rules);
        }

        return trim(text(
            label: 'Nom',
            required: 'Le nom est obligatoire.',
            validate: fn (string $value): ?string => $this->firstError('name', trim($value), $rules),
        ));
    }

    private function resolveEmail(): ?string
    {
        $rules = ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')];
        $option = $this->option('email');

        if (is_string($option) && trim($option) !== '') {
            return $this->validOrNull('email', Str::lower(trim($option)), $rules);
        }

        return Str::lower(trim(text(
            label: 'Adresse e-mail',
            required: "L'adresse e-mail est obligatoire.",
            validate: fn (string $value): ?string => $this->firstError('email', Str::lower(trim($value)), $rules),
        )));
    }

    private function askPassword(): ?string
    {
        for ($attempt = 1; $attempt <= self::PASSWORD_ATTEMPTS; $attempt++) {
            $password = password(
                label: 'Mot de passe (12 caractères minimum, lettres et chiffres)',
                required: 'Le mot de passe est obligatoire.',
                validate: fn (string $value): ?string => $this->firstError('password', $value, ['required', 'string', Password::defaults()]),
            );

            $confirmation = password(
                label: 'Confirmation du mot de passe',
                required: 'La confirmation est obligatoire.',
            );

            if (hash_equals($password, $confirmation)) {
                return $password;
            }

            $this->components->error('Les deux mots de passe ne correspondent pas.');
        }

        $this->components->error('Abandon : trop de tentatives.');

        return null;
    }

    /**
     * @param  array<int, mixed>  $rules
     */
    private function validOrNull(string $field, string $value, array $rules): ?string
    {
        $error = $this->firstError($field, $value, $rules);

        if ($error !== null) {
            $this->components->error($error);

            return null;
        }

        return $value;
    }

    /**
     * @param  array<int, mixed>  $rules
     */
    private function firstError(string $field, string $value, array $rules): ?string
    {
        $validator = Validator::make([$field => $value], [$field => $rules], [], [
            'name' => 'nom',
            'email' => 'adresse e-mail',
            'password' => 'mot de passe',
        ]);

        return $validator->fails() ? $validator->errors()->first($field) : null;
    }
}
