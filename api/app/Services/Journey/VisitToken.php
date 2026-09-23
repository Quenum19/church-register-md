<?php

namespace App\Services\Journey;

use App\Enums\PhoneCountry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Contenu déchiffré d'un jeton de parcours (contrat §2) : { phone_e164, country, step, jti, exp }.
 */
final readonly class VisitToken
{
    public function __construct(
        public string $phoneE164,
        public PhoneCountry $country,
        /** Étape à enregistrer : 1, 2 ou 3. */
        public int $step,
        /** Identifiant unique du jeton (usage unique). */
        public string $jti,
        /** Expiration (horodatage Unix). */
        public int $expiresAt,
    ) {}

    public function isExpired(?CarbonInterface $now = null): bool
    {
        return ($now ?? CarbonImmutable::now())->getTimestamp() >= $this->expiresAt;
    }
}
