<?php

namespace App\Http\Requests\Auth;

use Closure;
use Illuminate\Validation\Rules\Password;

/**
 * Règles d'un NOUVEAU mot de passe (contrat §3) : 12 caractères minimum, lettres et chiffres
 * (Password::defaults(), AppServiceProvider), confirmation, 255 caractères au plus.
 */
final class PasswordRules
{
    /**
     * @return list<mixed>
     */
    public static function new(): array
    {
        return [
            'required',
            'string',
            'max:255',
            Password::defaults(),
            'confirmed',
            // Le cast `hashed` stockerait tel quel une valeur ayant la forme d'un hachage : refusée.
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && password_get_info($value)['algo'] !== null) {
                    $fail("Ce mot de passe n'est pas accepté. Choisissez-en un autre.");
                }
            },
        ];
    }

    /**
     * Mot de passe saisi pour vérification (connexion, confirmation).
     *
     * @return list<string>
     */
    public static function current(): array
    {
        return ['required', 'string', 'max:255'];
    }
}
