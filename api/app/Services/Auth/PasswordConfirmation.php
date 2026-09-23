<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Validation\ValidationException;

/**
 * Ré-authentification avant une opération sensible (changement de mot de passe, activation ou
 * désactivation de la 2FA). Les échecs sont limités par compte (5 / 15 min → 429) : une session
 * volée ne permet pas de deviner le mot de passe par force brute.
 */
class PasswordConfirmation
{
    public const MAX_FAILURES = 5;

    public const DECAY_SECONDS = 15 * 60;

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly Hasher $hasher,
    ) {}

    /**
     * @throws ApiException 429 après trop d'échecs
     * @throws ValidationException 422 sur `$field` si le mot de passe est incorrect
     */
    public function confirm(User $user, string $password, string $field = 'password'): void
    {
        $key = 'password-confirmation|user:'.$user->getKey();

        if ($this->limiter->tooManyAttempts($key, self::MAX_FAILURES)) {
            $seconds = max(1, $this->limiter->availableIn($key));

            throw new ApiException(
                'Trop de tentatives. Réessayez dans '.(int) ceil($seconds / 60).' minute(s).',
                'too_many_requests',
                429,
                headers: ['Retry-After' => (string) $seconds],
            );
        }

        $hash = $user->password;

        if ($hash === null || ! $this->hasher->check($password, $hash)) {
            $this->limiter->hit($key, self::DECAY_SECONDS);

            throw ValidationException::withMessages([$field => 'Le mot de passe est incorrect.']);
        }

        $this->limiter->clear($key);
    }
}
