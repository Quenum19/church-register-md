<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Exceptions\Google2FAException;
use PragmaRX\Google2FA\Google2FA;

/**
 * Double authentification TOTP (RFC 6238) et codes de récupération.
 *
 * - Secret base32 de 32 caractères (160 bits), stocké chiffré (cast `encrypted`).
 * - Code accepté dans une fenêtre de ±1 période (30 s), une seule fois : le dernier pas de temps
 *   utilisé est mémorisé en cache, un code déjà utilisé (ou plus ancien) est refusé.
 * - 8 codes de récupération à usage unique (chiffrés), retirés de la liste dès leur utilisation.
 */
class TwoFactorAuthenticator
{
    public const WINDOW = 1;

    public const RECOVERY_CODE_COUNT = 8;

    public const SECRET_LENGTH = 32;

    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly CacheRepository $cache,
        private readonly SettingsService $settings,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Prépare (sans la confirmer) la 2FA du compte : nouveau secret, nouveaux codes de récupération.
     *
     * @return array{secret: string, otpauth_url: string, recovery_codes: list<string>}
     */
    public function prepare(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey(self::SECRET_LENGTH);
        $codes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->cache->forget($this->timestepKey($user));

        return [
            'secret' => $secret,
            'otpauth_url' => $this->google2fa->getQRCodeUrl($this->settings->churchName(), $user->email, $secret),
            'recovery_codes' => $codes,
        ];
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->cache->forget($this->timestepKey($user));
    }

    /**
     * Vérifie un code TOTP à 6 chiffres (fenêtre ±1, rejeu refusé).
     */
    public function verifyCode(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;

        if ($secret === null || preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $key = $this->timestepKey($user);
        $lastTimestep = $this->cache->get($key);

        try {
            $timestep = $this->google2fa->verifyKeyNewer($secret, $code, is_int($lastTimestep) ? $lastTimestep : 0, self::WINDOW);
        } catch (Google2FAException) {
            return false;
        }

        if (! is_int($timestep)) {
            return false;
        }

        // Au-delà de la fenêtre, un ancien code est de toute façon refusé : 5 minutes suffisent.
        $this->cache->put($key, $timestep, 300);

        return true;
    }

    /**
     * Consomme un code de récupération (usage unique). Verrou de ligne : deux requêtes simultanées
     * avec le même code ne peuvent pas réussir toutes les deux.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $normalized = Str::lower((string) preg_replace('/\s+/', '', $code));

        if ($normalized === '') {
            return false;
        }

        return $this->db->transaction(function () use ($user, $normalized): bool {
            $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            $codes = $fresh->two_factor_recovery_codes ?? [];
            $matched = null;

            foreach ($codes as $index => $candidate) {
                if (hash_equals($candidate, $normalized)) {
                    $matched = $index;
                }
            }

            if ($fresh === null || $matched === null) {
                return false;
            }

            unset($codes[$matched]);
            $remaining = array_values($codes);
            $fresh->forceFill(['two_factor_recovery_codes' => $remaining])->save();
            $user->forceFill(['two_factor_recovery_codes' => $remaining])->syncOriginalAttribute('two_factor_recovery_codes');

            return true;
        });
    }

    /**
     * @return list<string> codes de la forme « abcde-12345 » (≈ 51 bits chacun)
     */
    public function generateRecoveryCodes(): array
    {
        return array_map(
            static fn (): string => Str::lower(Str::random(5)).'-'.Str::lower(Str::random(5)),
            range(1, self::RECOVERY_CODE_COUNT),
        );
    }

    private function timestepKey(User $user): string
    {
        return 'two_factor.last_timestep.'.$user->getKey();
    }
}
