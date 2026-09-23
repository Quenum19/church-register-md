<?php

namespace App\Services\Journey;

use App\Enums\PhoneCountry;
use App\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use InvalidArgumentException;
use JsonException;

/**
 * Jeton de parcours (contrat §2) : chaîne opaque chiffrée et authentifiée avec APP_KEY
 * (Crypt::encryptString, AES-256-CBC + HMAC-SHA256) contenant
 * { phone_e164, country, step, jti, exp }, valable 15 minutes, à usage unique.
 *
 * Le jeton ne quitte le serveur que chiffré : le client ne peut ni le lire ni le modifier.
 * L'usage unique repose sur le cache : le `jti` d'un jeton consommé y est conservé
 * jusqu'à l'expiration du jeton (plus une marge).
 */
class VisitTokenService
{
    /** Durée de validité d'un jeton (secondes) : `expires_in` de POST /identify. */
    public const TTL_SECONDS = 900;

    /** Marge de conservation du `jti` consommé après l'expiration du jeton (secondes). */
    public const CONSUMED_MARGIN_SECONDS = 300;

    private const CONSUMED_PREFIX = 'journey:token-used:';

    public function __construct(
        private readonly StringEncrypter $encrypter,
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Émet un jeton pour l'étape `$step` (1, 2 ou 3) du numéro `$phoneE164`.
     */
    public function issue(string $phoneE164, PhoneCountry $country, int $step): string
    {
        if (! in_array($step, [1, 2, 3], true)) {
            throw new InvalidArgumentException('Étape de parcours invalide.');
        }

        $payload = [
            'phone_e164' => $phoneE164,
            'country' => $country->value,
            'step' => $step,
            'jti' => bin2hex(random_bytes(16)),
            'exp' => CarbonImmutable::now()->getTimestamp() + self::TTL_SECONDS,
        ];

        return $this->encrypter->encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Déchiffre et vérifie un jeton.
     *
     * @param  bool  $ignoreExpiry  true pour le rejeu idempotent (seul le numéro compte)
     *
     * @throws ApiException 401 token_invalid (indéchiffrable, falsifié, contenu inattendu) ;
     *                      410 token_expired (sauf si `$ignoreExpiry`)
     */
    public function decode(string $token, bool $ignoreExpiry = false): VisitToken
    {
        try {
            $json = $this->encrypter->decryptString($token);
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw JourneyErrors::tokenInvalid();
        }

        $visitToken = is_array($data) ? $this->fromPayload($data) : null;

        if ($visitToken === null) {
            throw JourneyErrors::tokenInvalid();
        }

        if (! $ignoreExpiry && $visitToken->isExpired()) {
            throw JourneyErrors::tokenExpired();
        }

        return $visitToken;
    }

    public function isConsumed(VisitToken $token): bool
    {
        return $this->cache->has($this->consumedKey($token));
    }

    /**
     * Marque le jeton comme consommé, de façon atomique (Cache::add).
     *
     * @return bool false si le jeton avait déjà été consommé (requête concurrente)
     */
    public function markConsumed(VisitToken $token): bool
    {
        $ttl = max(
            self::CONSUMED_MARGIN_SECONDS,
            $token->expiresAt - CarbonImmutable::now()->getTimestamp() + self::CONSUMED_MARGIN_SECONDS,
        );

        return $this->cache->add($this->consumedKey($token), true, $ttl);
    }

    /**
     * @param  array<mixed>  $data
     */
    private function fromPayload(array $data): ?VisitToken
    {
        $phone = $data['phone_e164'] ?? null;
        $country = is_string($data['country'] ?? null) ? PhoneCountry::tryFrom($data['country']) : null;
        $step = $data['step'] ?? null;
        $jti = $data['jti'] ?? null;
        $exp = $data['exp'] ?? null;

        $valid = is_string($phone) && preg_match('/^\+[1-9][0-9]{6,14}$/', $phone) === 1
            && $country !== null
            && is_int($step) && in_array($step, [1, 2, 3], true)
            && is_string($jti) && preg_match('/^[0-9a-f]{32}$/', $jti) === 1
            && is_int($exp);

        return $valid ? new VisitToken($phone, $country, $step, $jti, $exp) : null;
    }

    private function consumedKey(VisitToken $token): string
    {
        return self::CONSUMED_PREFIX.$token->jti;
    }
}
