<?php

namespace App\Services\Journey;

use App\Enums\PhoneCountry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Contenu déchiffré d'un jeton de parcours (contrat §2) :
 * { phone_e164, country, step, jti, exp }, plus `event_id` quand l'identification est venue
 * du lien dédié d'un événement.
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
        /** Événement d'origine (lien dédié), ou null pour le lien ordinaire. */
        public ?int $eventId = null,
    ) {}

    public function isExpired(?CarbonInterface $now = null): bool
    {
        return ($now ?? CarbonImmutable::now())->getTimestamp() >= $this->expiresAt;
    }
}
