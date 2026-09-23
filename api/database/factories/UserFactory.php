<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Comptes de test. Le mot de passe en clair est haché par le cast `hashed` du modèle.
 *
 * États : superAdmin(), moderateur(), lecteur(), inactive(), invited(), locked(), withTwoFactor().
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** Mot de passe des comptes de test (conforme à la politique : 12+ caractères, lettres et chiffres). */
    public const PASSWORD = 'motdepasse2026';

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->firstName().' '.fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => self::PASSWORD,
            'role' => Role::Lecteur,
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (User $user): void {
            $user->setRememberToken(Str::random(10));
        });
    }

    public function role(Role $role): static
    {
        return $this->state(fn (): array => ['role' => $role]);
    }

    public function superAdmin(): static
    {
        return $this->role(Role::SuperAdmin);
    }

    public function moderateur(): static
    {
        return $this->role(Role::Moderateur);
    }

    public function lecteur(): static
    {
        return $this->role(Role::Lecteur);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * Invitation en attente : aucun mot de passe choisi.
     */
    public function invited(): static
    {
        return $this->state(fn (): array => ['password' => null]);
    }

    /**
     * Compte verrouillé temporairement (15 min).
     */
    public function locked(): static
    {
        return $this->afterMaking(function (User $user): void {
            $user->forceFill(['failed_attempts' => 20, 'locked_until' => now()->addMinutes(15)]);
        });
    }

    /**
     * 2FA TOTP confirmée. Secret base32 de test (non sensible) et 8 codes de récupération.
     */
    public function withTwoFactor(string $secret = 'JBSWY3DPEHPK3PXP'): static
    {
        return $this->afterMaking(function (User $user) use ($secret): void {
            $user->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_recovery_codes' => array_map(
                    static fn (): string => Str::lower(Str::random(5).'-'.Str::random(5)),
                    range(1, 8),
                ),
                'two_factor_confirmed_at' => now(),
            ]);
        });
    }
}
